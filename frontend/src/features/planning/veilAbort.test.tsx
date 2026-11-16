import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { EngineVerificationInterruptedError, moveSlot, placeSlot, VerdictAbandonedError } from "./api";

// Lot C PR-2 — le rail de retouche gagne un `signal` optionnel (abandon volontaire du voile
// bloquant). Un abort du signal ≠ un timeout ky : il devient une VerdictAbandonedError DISTINCTE
// (sous-classe de EngineVerificationInterruptedError, pour que les branches existantes qui
// consomment la mère restent justes par héritage).
vi.mock("@/shared/api/client", () => ({ api: { post: vi.fn(), get: vi.fn() } }));

const mockPost = vi.mocked(api.post);

const abortError = (): Error => Object.assign(new Error("aborted"), { name: "AbortError" });
const timeoutError = (): Error => Object.assign(new Error("timeout"), { name: "TimeoutError" });

describe("moveSlot / placeSlot — le signal d'abandon (voile bloquant, lot C PR-2)", () => {
  afterEach(() => mockPost.mockReset());

  it("moveSlot passe le signal à ky", async () => {
    const controller = new AbortController();
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true }) } as never);

    await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" }, controller.signal);

    expect(mockPost.mock.calls[0][1]).toMatchObject({ signal: controller.signal });
  });

  it("moveSlot : un abort VOLONTAIRE (signal aborté) → VerdictAbandonedError", async () => {
    const controller = new AbortController();
    controller.abort();
    mockPost.mockReturnValue({ json: () => Promise.reject(abortError()) } as never);

    const caught = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" }, controller.signal).catch((e: unknown) => e);
    expect(caught).toBeInstanceOf(VerdictAbandonedError);
    // Héritage : les branches qui consomment la classe mère (panneau interrupted, modale d'essai)
    // restent justes.
    expect(caught).toBeInstanceOf(EngineVerificationInterruptedError);
  });

  it("moveSlot : un timeout ky (signal NON aborté) reste EngineVerificationInterruptedError, PAS un abandon", async () => {
    const controller = new AbortController();
    mockPost.mockReturnValue({ json: () => Promise.reject(timeoutError()) } as never);

    const caught = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" }, controller.signal).catch((e: unknown) => e);
    expect(caught).toBeInstanceOf(EngineVerificationInterruptedError);
    expect(caught).not.toBeInstanceOf(VerdictAbandonedError);
  });

  it("placeSlot passe le signal et traduit un abort volontaire en VerdictAbandonedError", async () => {
    const controller = new AbortController();
    controller.abort();
    mockPost.mockReturnValue({ json: () => Promise.reject(abortError()) } as never);

    const caught = await placeSlot("sched-1", { teamId: "t", dayOfWeek: 3, startTime: "18:00", venueId: "v1" }, controller.signal).catch((e: unknown) => e);
    expect(mockPost.mock.calls[0][1]).toMatchObject({ signal: controller.signal });
    expect(caught).toBeInstanceOf(VerdictAbandonedError);
  });
});
