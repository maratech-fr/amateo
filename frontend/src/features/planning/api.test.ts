import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { EngineVerificationInterruptedError, listSchedules, moveSlot, placeSlot } from "./api";

// UX-02 silent regression: API Platform 4 OMITS null fields from JSON, so a null
// `planType`/`schedulePlanId` (an anomalous unlinked version) arrives ABSENT
// (undefined). Every `"SEASON" === planType` / grouping-by-plan check would then
// silently mis-fire. listSchedules must normalise the boundary to a real null.
vi.mock("@/shared/api/client", () => ({ api: { get: vi.fn(), post: vi.fn() } }));

const mockGet = vi.mocked(api.get);
const mockPost = vi.mocked(api.post);
const respondOnce = (member: unknown[]) => mockGet.mockReturnValueOnce({ json: async () => ({ member }) } as never);

describe("listSchedules — null normalisation (UX-02)", () => {
  afterEach(() => mockGet.mockReset());

  it("normalises ABSENT planType/schedulePlanId (unlinked anomaly) to null", async () => {
    // A version whose null plan fields the backend omitted entirely.
    respondOnce([{ id: "s1", name: "Plan", status: "COMPLETED", score: null, createdAt: "x", updatedAt: "y" }]);
    const [plan] = await listSchedules();
    expect(plan.planType).toBeNull(); // pre-fix: undefined → socle checks silently fail
    expect(plan.schedulePlanId).toBeNull();
  });

  it("preserves a present planType/schedulePlanId (period overlay)", async () => {
    respondOnce([{ id: "s2", name: "Overlay", status: "COMPLETED", score: null, createdAt: "x", updatedAt: "y", planType: "CLOSURE", schedulePlanId: "plan-9" }]);
    const [overlay] = await listSchedules();
    expect(overlay.planType).toBe("CLOSURE");
    expect(overlay.schedulePlanId).toBe("plan-9");
  });

  it("normalises an ABSENT score (DRAFT/in-flight plan) to null", async () => {
    // A null score is omitted too → would render the literal "score undefined".
    respondOnce([{ id: "s3", name: "Draft", status: "DRAFT", createdAt: "x", updatedAt: "y" }]);
    const [draft] = await listSchedules();
    expect(draft.score).toBeNull();
  });
});

// P4-119 (a) — le rail de retouche attend le verdict du moteur : côté serveur, build du snapshot
// PUIS un budget transport de 20 s (`MoveSlotService::VALIDATE_HTTP_TIMEOUT_SECONDS`), bout-en-bout
// mesuré > 30 s sur un club dense. Le timeout client par défaut de ky (10 s) raccrochait AVANT la
// réponse (nginx 499, faux « moteur indisponible »). On l'aligne au-dessus du pire cas serveur.
describe("moveSlot / placeSlot — timeout client aligné sur le budget serveur (P4-119 a)", () => {
  afterEach(() => mockPost.mockReset());

  const patch = { dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" };

  it("moveSlot attend jusqu'à 45 s (20 s transport + build + marge), pas les 10 s par défaut de ky", async () => {
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, compromises: [] }) } as never);
    await moveSlot("slot-1", patch);
    expect(mockPost).toHaveBeenCalledWith("schedule-slots/slot-1/move", { json: patch, timeout: 45_000 });
  });

  it("placeSlot attend le même budget de 45 s", async () => {
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, slotId: "n", compromises: [] }) } as never);
    await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" });
    expect(mockPost).toHaveBeenCalledWith("schedules/sched-1/place-slot", { json: { teamId: "team-1", dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" }, timeout: 45_000 });
  });
});

// P4-119 (b) — une interruption CÔTÉ CLIENT (timeout ky / abort) est distincte d'une panne moteur :
// le geste n'a pas abouti mais on n'a AUCUNE preuve d'indisponibilité. La couche api la traduit en
// un EngineVerificationInterruptedError NOMMÉ — jamais l'erreur nue qui devenait « indisponible ».
describe("moveSlot / placeSlot — l'abandon client devient EngineVerificationInterruptedError (P4-119 b)", () => {
  afterEach(() => mockPost.mockReset());

  const patch = { dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" };
  const rejectPostWith = (name: string) =>
    mockPost.mockReturnValueOnce({
      json: async () => {
        throw Object.assign(new Error("client gave up"), { name });
      },
    } as never);

  it("moveSlot : un timeout ky (TimeoutError) → EngineVerificationInterruptedError", async () => {
    rejectPostWith("TimeoutError");
    await expect(moveSlot("slot-1", patch)).rejects.toBeInstanceOf(EngineVerificationInterruptedError);
  });

  it("moveSlot : un abort (AbortError) → EngineVerificationInterruptedError", async () => {
    rejectPostWith("AbortError");
    await expect(moveSlot("slot-1", patch)).rejects.toBeInstanceOf(EngineVerificationInterruptedError);
  });

  it("placeSlot : un timeout ky (TimeoutError) → EngineVerificationInterruptedError", async () => {
    rejectPostWith("TimeoutError");
    await expect(placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" })).rejects.toBeInstanceOf(EngineVerificationInterruptedError);
  });
});

// ENG-51 — un verdict « indéterminé » (le solveur n'a pas tranché dans le temps imparti) arrive en
// 200 `{valid:false, indeterminate:true}` : ni accepté ni refusé (ce n'est PAS un 422). Le parseur
// le rend TEL QUEL (il ne lève pas), pour que l'UI montre un bandeau NEUTRE « réessayez ».
describe("moveSlot / placeSlot — verdict indéterminé (ENG-51) résolu, jamais levé", () => {
  afterEach(() => mockPost.mockReset());

  const patch = { dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" };

  it("moveSlot : 200 {valid:false, indeterminate:true} résout avec indeterminate=true, sans throw", async () => {
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: false, indeterminate: true }) } as never);
    const result = await moveSlot("slot-1", patch);
    expect(result.valid).toBe(false);
    expect(result.indeterminate).toBe(true);
    expect(result.compromises).toEqual([]); // normalisé par le parseur
  });

  it("placeSlot : 200 {valid:false, indeterminate:true} résout avec indeterminate=true, sans throw", async () => {
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: false, indeterminate: true }) } as never);
    const result = await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" });
    expect(result.valid).toBe(false);
    expect(result.indeterminate).toBe(true);
  });
});
