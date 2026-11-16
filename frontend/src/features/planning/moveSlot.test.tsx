import { HTTPError } from "ky";
import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { GenerationInProgressError, MoveRejectedError, moveGroup, moveSlot, placeSlot, removeSlot, removeSlotGroup, SlotEditError, TargetLockedError } from "./api";

// F2b — le déplacement passe désormais par POST /move (verdict moteur), plus par
// manual-edit/one-time (chevauchements bruts). Ce test garde le CHEMIN d'appel et la
// traduction des refus en erreurs typées que l'UI sait afficher.
vi.mock("@/shared/api/client", () => ({ api: { post: vi.fn(), get: vi.fn() } }));

const mockPost = vi.mocked(api.post);

function httpError(status: number, data: unknown): HTTPError {
  const response = new Response(JSON.stringify(data), { status });
  const request = new Request("http://localhost/api/schedule-slots/s1/move");
  const error = new HTTPError(response, request, {} as never);
  // ky 2.x expose le corps d'erreur parsé sur error.data (re-lire response throw).
  (error as unknown as { data: unknown }).data = data;
  return error;
}

describe("moveSlot — sous le verdict moteur (F2b)", () => {
  afterEach(() => mockPost.mockReset());

  it("appelle le rail /move (jamais manual-edit/one-time)", async () => {
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true }) } as never);
    await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" });

    expect(mockPost).toHaveBeenCalledTimes(1);
    const [path] = mockPost.mock.calls[0];
    expect(path).toBe("schedule-slots/s1/move");
    expect(path).not.toContain("one-time");
  });

  it("traduit un 422 en MoveRejectedError PORTANT les règles violées nommées", async () => {
    const violations = [{ rule: "coach_double_booking", message: "le coach Dupont a déjà les U15 à 20h." }];
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { valid: false, violations })) } as never);

    await expect(moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" })).rejects.toBeInstanceOf(MoveRejectedError);
    const caught = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" }).catch((e: unknown) => e);
    expect(caught).toBeInstanceOf(MoveRejectedError);
    expect((caught as MoveRejectedError).violations[0].message).toContain("Dupont");
  });

  it("porte les ids du verdict (surlignage du conflit) — conflictingTeamId compris", async () => {
    const violations = [
      {
        rule: "coach_double_booking",
        message: "le coach Dupont a déjà les U15 à 20h.",
        coachId: "coach-1",
        dayOfWeek: 4,
        startTime: "20:00",
        conflictingTeamId: "team-u15",
        teamId: null,
        venueId: null,
      },
    ];
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { valid: false, violations })) } as never);

    const caught = (await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" }).catch((e: unknown) => e)) as MoveRejectedError;
    expect(caught).toBeInstanceOf(MoveRejectedError);
    expect(caught.violations[0].conflictingTeamId).toBe("team-u15");
    expect(caught.violations[0].coachId).toBe("coach-1");
    expect(caught.violations[0].dayOfWeek).toBe(4);
    expect(caught.violations[0].teamId).toBeNull();
  });

  it("traduit un 409 generation_in_progress en GenerationInProgressError", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "generation_in_progress" })) } as never);
    await expect(moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" })).rejects.toBeInstanceOf(GenerationInProgressError);
  });

  // P2-30 PR B — éviction : le déplacement peut LIBÉRER une cible occupée.
  it("porte evictSlotId dans le corps et rend le bloc `evicted` d'un 200", async () => {
    const evicted = { slotId: "slot-y", teamId: "team-y", dayOfWeek: 2, startTime: "18:00", venueId: "v2", durationMinutes: 90 };
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, message: "Slot moved.", evicted }) } as never);

    const result = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2", evictSlotId: "slot-y" });

    expect(mockPost.mock.calls[0][1]).toMatchObject({ json: { evictSlotId: "slot-y" } });
    expect(result.evicted).toEqual(evicted);
  });

  it("traduit un 422 target_locked en TargetLockedError PORTANT un message propre", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "target_locked", error: "Ce créneau est verrouillé — déverrouillez-le d'abord." })) } as never);
    const caught = (await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2", evictSlotId: "y" }).catch((e: unknown) => e)) as TargetLockedError;
    expect(caught).toBeInstanceOf(TargetLockedError);
    expect(caught.message).toMatch(/verrouill/i);
  });

  it("traduit un autre code 422 (evict_target_mismatch) en SlotEditError typée", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "evict_target_mismatch", error: "Le créneau à libérer ne correspond pas." })) } as never);
    const caught = (await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2", evictSlotId: "y" }).catch((e: unknown) => e)) as SlotEditError;
    expect(caught).toBeInstanceOf(SlotEditError);
    expect(caught.code).toBe("evict_target_mismatch");
  });
});

describe("placeSlot — placer une séance à la dérive sous le verdict moteur (P2-30 PR B)", () => {
  afterEach(() => mockPost.mockReset());

  it("appelle POST schedules/{id}/place-slot et rend {valid, slotId}", async () => {
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, slotId: "new-slot" }) } as never);

    const result = await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1" });

    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockPost.mock.calls[0][0]).toBe("schedules/sched-1/place-slot");
    expect(result.slotId).toBe("new-slot");
  });

  it("traduit un 422 avec violations en MoveRejectedError (parsing identique à moveSlot)", async () => {
    const violations = [{ rule: "coach_double_booking", message: "le coach a déjà les U13 ici." }];
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { valid: false, violations })) } as never);

    const caught = (await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1" }).catch((e: unknown) => e)) as MoveRejectedError;
    expect(caught).toBeInstanceOf(MoveRejectedError);
    expect(caught.violations[0].message).toContain("U13");
  });

  it("traduit un 422 code (slot_unavailable) en SlotEditError typée", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "slot_unavailable", error: "Aucun créneau de gymnase n'est ouvert à cet horaire." })) } as never);
    const caught = (await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1" }).catch((e: unknown) => e)) as SlotEditError;
    expect(caught).toBeInstanceOf(SlotEditError);
    expect(caught.code).toBe("slot_unavailable");
  });

  it("traduit un 409 generation_in_progress en GenerationInProgressError", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "generation_in_progress" })) } as never);
    await expect(placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1" })).rejects.toBeInstanceOf(GenerationInProgressError);
  });
});

// P2-32 — un ESSAI (dryRun) : le moteur juge SANS écrire. Un refus d'essai arrive en 200
// {valid:false} (PAS un 422) : le parsing doit RENDRE un résultat, jamais lever.
describe("dry-run — essai sans écriture (P2-32)", () => {
  afterEach(() => mockPost.mockReset());

  it("porte dryRun:true dans le corps et rend un essai ACCEPTÉ avec ses compromis", async () => {
    const compromises = [
      { family: "coach_rest", effect: "broken", message: "le coach Martin enchaîne deux séances.", coachId: "coach-1" },
      { family: "venue_pref", effect: "gained", message: "les U15 retrouvent leur gymnase habituel." },
    ];
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, dryRun: true, violations: [], compromises }) } as never);

    const result = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2", evictSlotId: "y", dryRun: true });

    expect(mockPost.mock.calls[0][1]).toMatchObject({ json: { dryRun: true, evictSlotId: "y" } });
    expect(result.valid).toBe(true);
    expect(result.dryRun).toBe(true);
    expect(result.compromises).toHaveLength(2);
    expect(result.compromises[0].effect).toBe("broken");
  });

  it("un essai REFUSÉ arrive en 200 {valid:false} : RÉSULTAT (pas un throw) portant violations + dryRun", async () => {
    const violations = [{ rule: "coach_double_booking", message: "le coach a déjà les U13 ici." }];
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: false, dryRun: true, violations, compromises: [] }) } as never);

    // Ne lève PAS (contrairement au refus réel 422) — c'est un verdict d'essai, rendu tel quel.
    const result = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2", dryRun: true });

    expect(result.valid).toBe(false);
    expect(result.dryRun).toBe(true);
    expect(result.violations?.[0].message).toContain("U13");
  });

  it("porte les compromis d'un déplacement RÉEL accepté, et défaut [] si absent", async () => {
    const compromises = [{ family: "coach_rest", effect: "broken", message: "repos raccourci." }];
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, message: "Slot moved.", compromises }) } as never);
    const withCompromises = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" });
    expect(withCompromises.compromises).toHaveLength(1);

    // Une réponse qui n'énumère aucun compromis se lit [] (jamais undefined).
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, message: "Slot moved." }) } as never);
    const withoutCompromises = await moveSlot("s1", { dayOfWeek: 4, startTime: "20:00", venueId: "v2" });
    expect(withoutCompromises.compromises).toEqual([]);
  });

  it("placeSlot : essai accepté porte ses compromis et dryRun:true", async () => {
    const compromises = [{ family: "venue_pref", effect: "gained", message: "gymnase habituel retrouvé." }];
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, dryRun: true, violations: [], compromises }) } as never);

    const result = await placeSlot("sched-1", { teamId: "team-1", dayOfWeek: 3, startTime: "18:00", venueId: "v1", dryRun: true });

    expect(mockPost.mock.calls[0][1]).toMatchObject({ json: { dryRun: true } });
    expect(result.dryRun).toBe(true);
    expect(result.compromises).toHaveLength(1);
  });
});

// P4-329 — déplacer un GROUPE sur une case OCCUPÉE peut évincer l'occupant (séance d'équipe), comme
// le move simple : le corps porte `evictSlotId`, et le geste se fait en DEUX temps — un ESSAI
// (dryRun, pour la modale) puis la confirmation RÉELLE. La réponse NOMME l'évincé (« à replacer »).
describe("moveGroup — éviction d'un groupe vers une case occupée (P4-329)", () => {
  afterEach(() => mockPost.mockReset());

  const source = { venueId: "v1", dayOfWeek: 2, startTime: "18:00" };
  const target = { venueId: "v2", dayOfWeek: 4, startTime: "20:00" };

  it("ESSAI : porte evictSlotId + dryRun:true et rend un verdict accepté avec l'évincé qui SERAIT retiré", async () => {
    const evicted = { slotId: "occ", teamId: "team-occ", dayOfWeek: 4, startTime: "20:00", venueId: "v2", durationMinutes: 90 };
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, dryRun: true, violations: [], compromises: [], evicted }) } as never);

    const result = await moveGroup({ scheduleId: "sch1", blockId: "b1", source, target, evictSlotId: "occ", dryRun: true });

    expect(mockPost.mock.calls[0][0]).toBe("schedule-slots/move-group");
    expect(mockPost.mock.calls[0][1]).toMatchObject({ json: { evictSlotId: "occ", dryRun: true } });
    expect(result.valid).toBe(true);
    expect(result.dryRun).toBe(true);
    expect(result.evicted).toEqual(evicted);
  });

  it("CONFIRMATION : porte evictSlotId (sans dryRun) et rend movedSlotIds + l'évincé réellement retiré", async () => {
    const evicted = { slotId: "occ", teamId: "team-occ", dayOfWeek: 4, startTime: "20:00", venueId: "v2", durationMinutes: 90 };
    mockPost.mockReturnValueOnce({ json: async () => ({ valid: true, message: "Group slot moved.", compromises: [], movedSlotIds: ["m1", "m2"], evicted }) } as never);

    const result = await moveGroup({ scheduleId: "sch1", blockId: "b1", source, target, evictSlotId: "occ" });

    expect(mockPost.mock.calls[0][1]).toMatchObject({ json: { evictSlotId: "occ" } });
    expect(mockPost.mock.calls[0][1]).not.toHaveProperty("json.dryRun");
    expect(result.movedSlotIds).toEqual(["m1", "m2"]);
    expect(result.evicted).toEqual(evicted);
  });

  it("un occupant lui-même de GROUPE (422 evict_target_is_group) devient SlotEditError typée", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "evict_target_is_group", error: "Cette case est occupée par un autre groupe — videz-la d'abord." })) } as never);
    const caught = (await moveGroup({ scheduleId: "sch1", blockId: "b1", source, target, evictSlotId: "grp" }).catch((e: unknown) => e)) as SlotEditError;
    expect(caught).toBeInstanceOf(SlotEditError);
    expect(caught.code).toBe("evict_target_is_group");
  });

  it("un occupant verrouillé (422 target_locked) devient SlotEditError PORTANT le message propre", async () => {
    // Le rail move-group traduit TOUT 422 à code en SlotEditError (il ne distingue pas
    // target_locked comme /move) — la page la TOASTE (message serveur) et reste en mode cible.
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "target_locked", error: "Ce créneau est verrouillé — déverrouillez-le d'abord." })) } as never);
    const caught = (await moveGroup({ scheduleId: "sch1", blockId: "b1", source, target, evictSlotId: "lck" }).catch((e: unknown) => e)) as SlotEditError;
    expect(caught).toBeInstanceOf(SlotEditError);
    expect(caught.code).toBe("target_locked");
    expect(caught.message).toMatch(/verrouill/i);
  });
});

// F4 — « Vider un créneau » : les rails remove / remove-group N'APPELLENT PAS le moteur. On garde
// le CHEMIN d'appel (corps, dryRun) et la traduction des refus que l'UI sait montrer.
describe("removeSlot / removeSlotGroup — retrait d'une séance placée (F4)", () => {
  afterEach(() => mockPost.mockReset());

  it("removeSlot poste {} (retrait réel) sur le rail /remove et rend removed + lockLevel + unmetPresence", async () => {
    const body = { removed: { slotId: "s1", teamId: "team-A", dayOfWeek: 2, startTime: "18:00", venueId: "v1", durationMinutes: 90 }, lockLevel: "NONE", unmetPresence: [] };
    mockPost.mockReturnValueOnce({ json: async () => body } as never);

    const result = await removeSlot("s1");
    expect(mockPost).toHaveBeenCalledWith("schedule-slots/s1/remove", { json: {} });
    expect(result.removed.teamId).toBe("team-A");
    expect(result.lockLevel).toBe("NONE");
    expect(result.unmetPresence).toEqual([]);
  });

  it("removeSlot en dryRun poste {dryRun:true} et rend les conséquences nommées (verrou + présence)", async () => {
    const body = {
      removed: { slotId: "s1", teamId: "team-A", dayOfWeek: 2, startTime: "18:00", venueId: "v1", durationMinutes: 90 },
      lockLevel: "HARD",
      unmetPresence: [{ family: "SEASON_SLOTS", teamId: "team-A", message: "U11 : la demande « garder les créneaux de saison » n'est plus honorée." }],
      dryRun: true,
    };
    mockPost.mockReturnValueOnce({ json: async () => body } as never);

    const result = await removeSlot("s1", { dryRun: true });
    expect(mockPost).toHaveBeenCalledWith("schedule-slots/s1/remove", { json: { dryRun: true } });
    expect(result.dryRun).toBe(true);
    expect(result.lockLevel).toBe("HARD");
    expect(result.unmetPresence[0].message).toContain("garder les créneaux de saison");
  });

  it("removeSlot : une génération en cours (409) devient GenerationInProgressError", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "generation_in_progress" })) } as never);
    await expect(removeSlot("s1")).rejects.toBeInstanceOf(GenerationInProgressError);
  });

  it("removeSlotGroup poste le corps (scheduleId/blockId/source) et rend removedSlotIds", async () => {
    const source = { venueId: "v1", dayOfWeek: 2, startTime: "18:00" };
    mockPost.mockReturnValueOnce({ json: async () => ({ removedSlotIds: ["s1", "s2"], removed: [], unmetPresence: [] }) } as never);

    const result = await removeSlotGroup({ scheduleId: "sch1", blockId: "b1", source });
    expect(mockPost).toHaveBeenCalledWith("schedule-slots/remove-group", { json: { scheduleId: "sch1", blockId: "b1", source } });
    expect(result.removedSlotIds).toEqual(["s1", "s2"]);
  });

  it("removeSlotGroup : aucune séance de groupe à la case (422 slot_unavailable) → SlotEditError", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(422, { code: "slot_unavailable", error: "Aucune séance de groupe à retirer à cet horaire." })) } as never);
    const caught = await removeSlotGroup({ scheduleId: "sch1", blockId: "b1", source: { venueId: "v1", dayOfWeek: 5, startTime: "20:00" } }).catch((e: unknown) => e);
    expect(caught).toBeInstanceOf(SlotEditError);
    expect((caught as SlotEditError).code).toBe("slot_unavailable");
  });
});
