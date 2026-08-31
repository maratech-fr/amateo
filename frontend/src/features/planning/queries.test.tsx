import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { toast } from "@/shared/stores/toastStore";

import * as planningApi from "./api";
import { OverlaysExistError } from "./api";
import {
  useCategories,
  useCoaches,
  useDeleteSchedule,
  useDiagnostics,
  useFillSchedule,
  useGenerate,
  useLockSlot,
  useMoveDryRun,
  useMoveGroup,
  useMoveSlot,
  usePlaceSlot,
  useRegenerate,
  useRegenerateFromVersion,
  useRegenerateOverlay,
  useReopenSchedule,
  useSchedules,
  useSlots,
  useSocleDeviation,
  useTeams,
  useValidateImpact,
  useValidateSchedule,
  useVenues,
} from "./queries";

// FRT-20 (3ᵉ tranche) — on n'exerce QUE `queries.ts` : le module voisin `./api` est le SEUL double
// (patron VIVANT du dépôt, cf. matches/queries.test.tsx & wizard/queries.test.tsx). Les VRAIS hooks
// sont montés sur un vrai QueryClient. Pour la classe de bug qu'on chasse — une invalidation qui
// n'ATTEINT PAS le lecteur réel (clé fantôme, mauvais préfixe, invalidation MANQUANTE) — un espion
// sur `invalidateQueries` ne suffit pas : il prouve l'appel, jamais l'EFFET, et ne voit JAMAIS un
// appel absent. Donc la majorité des cas montent le lecteur (la query) ET la mutation sur le MÊME
// client, déclenchent, et assertent que le lecteur REFETCHE (sa fn `./api` est rappelée). L'espion
// ne sert qu'aux clés dont AUCUN lecteur ne vit dans ce module (`['calendar-entries']`, `['me']`,
// `['entry-conflicts']`, `['wizard']`, `['priority_tiers']`).
//
// ⚠ TanStack invalide PAR PRÉFIXE : invalider `['schedules']` atteint `['schedules', id]` (préfixe
// plus court → query plus longue), JAMAIS l'inverse. Les mutations d'ici invalident TOUJOURS sans
// scheduleId (`['slots']`, `['diagnostics']`, `['socle-deviation']`), donc elles atteignent bien le
// lecteur scopé — c'est ce sens-là qu'on PROUVE en montant le vrai lecteur.
//
// `importOriginal` + spread : on garde les VRAIES classes d'erreur (`OverlaysExistError`,
// `VerdictAbandonedError`…, consommées en `instanceof` par les onError de queries.ts) et on ne
// remplace QUE les fonctions réseau. Sinon `error instanceof undefined` casserait au premier onError.
vi.mock("./api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("./api")>();
  return {
    ...actual,
    // lectures
    listSchedules: vi.fn().mockResolvedValue([]),
    getSlots: vi.fn().mockResolvedValue([]),
    getDiagnostics: vi.fn().mockResolvedValue([]),
    getSocleDeviation: vi.fn().mockResolvedValue({}),
    getValidateImpact: vi.fn().mockResolvedValue({ orphanedFixtures: 0, declaredOrphanedFixtures: 0 }),
    getTeams: vi.fn().mockResolvedValue([]),
    getVenues: vi.fn().mockResolvedValue([]),
    getCoaches: vi.fn().mockResolvedValue([]),
    getCategories: vi.fn().mockResolvedValue([]),
    getConstraints: vi.fn().mockResolvedValue([]),
    getTeamCoaches: vi.fn().mockResolvedValue([]),
    getCoachPlayers: vi.fn().mockResolvedValue([]),
    getTrainingSlots: vi.fn().mockResolvedValue([]),
    getSchedule: vi.fn().mockResolvedValue({}),
    // écritures
    lockSlot: vi.fn().mockResolvedValue({}),
    moveSlot: vi.fn().mockResolvedValue({ valid: true, compromises: [] }),
    moveGroup: vi.fn().mockResolvedValue({ valid: true, compromises: [], movedSlotIds: [] }),
    placeSlot: vi.fn().mockResolvedValue({ valid: true, compromises: [] }),
    generateSchedule: vi.fn().mockResolvedValue({}),
    validateSchedule: vi.fn().mockResolvedValue({}),
    reopenSchedule: vi.fn().mockResolvedValue({}),
    deleteSchedule: vi.fn().mockResolvedValue(undefined),
    regenerateFromVersion: vi.fn().mockResolvedValue({ id: "sched-loaded" }),
    regenerate: vi.fn().mockResolvedValue({ id: "sched-v2" }),
    createOverlayVersion: vi.fn().mockResolvedValue({ id: "overlay-1" }),
    fillSchedule: vi.fn().mockResolvedValue({ id: "sched-fill" }),
  };
});

function makeClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function wrapperFor(client: QueryClient) {
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

const SID = "sched-1";

beforeEach(() => {
  vi.clearAllMocks();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. EFFET RÉEL — la boucle de retouche (move/place ACCEPTÉ) atteint les QUATRE lecteurs
//    du paquet (`invalidateMovePacket`) : grille, liste des plannings, diagnostics, écart socle.
//    Un déplacement accepté change le placement (slots), périme le score (schedules) et fait
//    rejuger la légalité par le moteur (diagnostics) + rejoue le diff socle↔période.
// ─────────────────────────────────────────────────────────────────────────────

describe("planning queries — le paquet move/place accepté rafraîchit les 4 lecteurs (effet, pas espion)", () => {
  it("useMoveSlot accepté → refetche slots, schedules, diagnostics ET socle-deviation", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({
        slots: useSlots(SID),
        schedules: useSchedules(),
        diagnostics: useDiagnostics(SID),
        deviation: useSocleDeviation(SID),
        move: useMoveSlot(),
      }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.slots.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.diagnostics.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.deviation.isSuccess).toBe(true));
    expect(planningApi.getSlots).toHaveBeenCalledTimes(1);
    expect(planningApi.listSchedules).toHaveBeenCalledTimes(1);
    expect(planningApi.getDiagnostics).toHaveBeenCalledTimes(1);
    expect(planningApi.getSocleDeviation).toHaveBeenCalledTimes(1);

    result.current.move.mutate({ id: "s1", patch: {} as never });

    await waitFor(() => expect(result.current.move.isSuccess).toBe(true));
    // Les QUATRE lecteurs vivants refetchent : si l'un des quatre était une clé fantôme (mauvais
    // préfixe / oubli), il resterait bloqué à 1 — comme le radar de la 2ᵉ tranche.
    await waitFor(() => expect(planningApi.getSlots).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getDiagnostics).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getSocleDeviation).toHaveBeenCalledTimes(2));
  });

  // P2-51 PR-6 (falsification b) — le déplacement de GROUPE partage EXACTEMENT le même paquet : les
  // 4 lecteurs vivants refetchent par l'EFFET (pas un espion). Retirer une clé de `invalidateMovePacket`
  // (foyer unique) fait chuter CE test comme ceux de move/place — la garde tient sur les trois rails.
  it("useMoveGroup accepté → refetche slots, schedules, diagnostics ET socle-deviation (même paquet)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({
        slots: useSlots(SID),
        schedules: useSchedules(),
        diagnostics: useDiagnostics(SID),
        deviation: useSocleDeviation(SID),
        moveGroup: useMoveGroup(),
      }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.slots.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.diagnostics.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.deviation.isSuccess).toBe(true));

    result.current.moveGroup.mutate({ scheduleId: SID, blockId: "blk", source: {} as never, target: {} as never });

    await waitFor(() => expect(result.current.moveGroup.isSuccess).toBe(true));
    await waitFor(() => expect(planningApi.getSlots).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getDiagnostics).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getSocleDeviation).toHaveBeenCalledTimes(2));
  });

  it("usePlaceSlot accepté → même paquet (les 4 lecteurs refetchent)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({
        slots: useSlots(SID),
        schedules: useSchedules(),
        diagnostics: useDiagnostics(SID),
        deviation: useSocleDeviation(SID),
        place: usePlaceSlot(),
      }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.slots.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.diagnostics.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.deviation.isSuccess).toBe(true));

    result.current.place.mutate({ scheduleId: SID, body: {} as never });

    await waitFor(() => expect(result.current.place.isSuccess).toBe(true));
    await waitFor(() => expect(planningApi.getSlots).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getDiagnostics).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getSocleDeviation).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. VERDICT — useLockSlot diverge du paquet commun, ET C'EST CORRECT.
//
// `invalidateMovePacket` = {slots, schedules, diagnostics, socle-deviation}. `useLockSlot`
// n'invalide QUE {slots, socle-deviation}. Même FORME que le bug de la 2ᵉ tranche (un chemin qui
// s'écarte du foyer commun) — mais ici la divergence est JUSTE, prouvé au code backend :
//   • Verrouiller/déverrouiller n'appelle PAS le moteur : `ManualEditService::applyLock` ne fait
//     que setLockLevel + setLockOrigin + flush (aucun re-solve, aucun verdict de légalité).
//   • Les diagnostics sont des lignes PERSISTÉES à la génération (`ScheduleDiagnosticsRecorder`,
//     appelé UNIQUEMENT depuis `GenerateScheduleHandler`) — figées jusqu'au prochain solve, et
//     AUCUN diagnostic ne dérive du `lockLevel` d'un créneau à la lecture. Invalider `['diagnostics']`
//     après un verrou ne ferait donc que re-fetcher des lignes IDENTIQUES : un no-op, pas un besoin.
//   • Le score/statut du planning (`['schedules']`) ne bouge pas non plus : un verrou ne déplace
//     rien, il ne pose pas le marqueur « score périmé » que pose un déplacement accepté.
// La SEULE chose que le verrou change vraiment, c'est le cadenas du créneau → `['slots']` (requis).
// `['socle-deviation']` est conservateur (le calcul ne lit pas non plus le lockLevel : identique),
// mais inoffensif. Ce test VERROUILLE ce comportement correct — et mord si un jour on ajoute une
// invalidation fantôme de diagnostics/schedules (cf. falsification #2).
// ─────────────────────────────────────────────────────────────────────────────

describe("planning queries — useLockSlot n'invalide QUE ce qu'un verrou change (verdict : choix, pas défaut)", () => {
  it("verrouiller un créneau → slots ET socle-deviation refetchent ; diagnostics ET schedules NE refetchent PAS", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({
        slots: useSlots(SID),
        schedules: useSchedules(),
        diagnostics: useDiagnostics(SID),
        deviation: useSocleDeviation(SID),
        lock: useLockSlot(),
      }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.slots.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.diagnostics.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.deviation.isSuccess).toBe(true));

    result.current.lock.mutate({ id: "s1", lockLevel: "HARD" });

    await waitFor(() => expect(result.current.lock.isSuccess).toBe(true));
    // Le cadenas a changé : la grille DOIT refetcher (requis) ; l'écart socle est réinvalidé.
    await waitFor(() => expect(planningApi.getSlots).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getSocleDeviation).toHaveBeenCalledTimes(2));
    // Ni les diagnostics (figés, persistés au solve) ni la liste (score inchangé) ne sont touchés :
    // les refetcher serait un no-op. On laisse une temporisation pour qu'une invalidation FAUTIVE
    // se manifeste, puis on affirme qu'ils sont RESTÉS à 1 appel.
    await new Promise((r) => setTimeout(r, 60));
    expect(planningApi.getDiagnostics).toHaveBeenCalledTimes(1);
    expect(planningApi.listSchedules).toHaveBeenCalledTimes(1);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. EFFET RÉEL — l'ESSAI (dry-run) n'écrit rien serveur → il n'invalide RIEN (la grille reste).
//    (Le chemin ABANDON du dry-run est couvert par abandon.test.tsx ; ici le SUCCÈS.)
// ─────────────────────────────────────────────────────────────────────────────

describe("planning queries — useMoveDryRun : un essai n'invalide rien", () => {
  it("un essai réussi → la grille NE refetche PAS (aucune écriture)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ slots: useSlots(SID), dry: useMoveDryRun() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.slots.isSuccess).toBe(true));
    expect(planningApi.getSlots).toHaveBeenCalledTimes(1);

    result.current.dry.mutate({ id: "s1", patch: {} as never });

    await waitFor(() => expect(result.current.dry.isSuccess).toBe(true));
    // L'essai passe `dryRun: true` à l'API et ne touche aucun cache.
    expect(planningApi.moveSlot).toHaveBeenCalledWith("s1", { dryRun: true }, expect.anything());
    await new Promise((r) => setTimeout(r, 40));
    expect(planningApi.getSlots).toHaveBeenCalledTimes(1);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. AXE §7.1 (planning lifecycle, ADR-0002) — valider / rouvrir DÉTRUIT les plans FUTURS.
//    Tout ce qui DÉRIVE du pointeur (liste des plannings, entrées de calendrier, radar de
//    conflits d'entrée, /me) doit être invalidé. Les DEUX chemins invalident le MÊME jeu
//    {schedules, calendar-entries, entry-conflicts, me} — la symétrie est prouvée ici (une
//    asymétrie valider↔rouvrir serait le signal d'un oubli). En revanche la grille et les
//    diagnostics du planning COURANT ne bougent pas : valider/rouvrir ne re-solve pas et ne
//    déplace aucun créneau de CE planning (les diagnostics sont figés au solve) — les plans
//    détruits, eux, quittent la liste via `['schedules']`. Le test verrouille les deux faces.
// ─────────────────────────────────────────────────────────────────────────────

describe("planning queries — valider/rouvrir : le jeu d'invalidation du cycle de vie (axe §7.1)", () => {
  it("useValidateSchedule → schedules refetche (réel) + calendar-entries/entry-conflicts/me (espion) ; slots & diagnostics du planning courant NE bougent PAS", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(
      () => ({ schedules: useSchedules(), slots: useSlots(SID), diagnostics: useDiagnostics(SID), validate: useValidateSchedule() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.slots.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.diagnostics.isSuccess).toBe(true));

    result.current.validate.mutate({ id: SID });

    await waitFor(() => expect(result.current.validate.isSuccess).toBe(true));
    // La liste des plannings (dérivée du pointeur) refetche : les plans futurs détruits en sortent.
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
    // Les 3 clés sans lecteur dans ce module — l'espion est la bonne preuve ICI (aucun refetch à voir).
    expect(spy).toHaveBeenCalledWith({ queryKey: ["calendar-entries"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["entry-conflicts"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["me"] });
    // Le planning COURANT n'est pas re-solvé : ses créneaux et diagnostics restent à 1 appel.
    await new Promise((r) => setTimeout(r, 40));
    expect(planningApi.getSlots).toHaveBeenCalledTimes(1);
    expect(planningApi.getDiagnostics).toHaveBeenCalledTimes(1);
  });

  it("useReopenSchedule → MÊME jeu que valider (symétrie) : schedules refetche + calendar-entries/entry-conflicts/me espionnés", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(
      () => ({ schedules: useSchedules(), reopen: useReopenSchedule() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));

    result.current.reopen.mutate({ id: SID });

    await waitFor(() => expect(result.current.reopen.isSuccess).toBe(true));
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
    expect(spy).toHaveBeenCalledWith({ queryKey: ["calendar-entries"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["entry-conflicts"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["me"] });
  });

  it("useReopenSchedule : OverlaysExistError est un état d'UI (dialogue d'escalade) → PAS de toast ; un échec générique EN toaste un", async () => {
    const errorSpy = vi.spyOn(toast, "error");
    // Cas 1 : OverlaysExistError → le hook se tait (le mutate-level de l'appelant ouvre le dialogue).
    vi.mocked(planningApi.reopenSchedule).mockRejectedValueOnce(new OverlaysExistError(3, []));
    const client = makeClient();
    const { result } = renderHook(() => useReopenSchedule(), { wrapper: wrapperFor(client) });

    result.current.mutate({ id: SID });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(errorSpy).not.toHaveBeenCalled();

    // Cas 2 : une erreur nue → un échec ne doit JAMAIS être silencieux.
    vi.mocked(planningApi.reopenSchedule).mockRejectedValueOnce(new Error("boom"));
    const { result: r2 } = renderHook(() => useReopenSchedule(), { wrapper: wrapperFor(client) });
    r2.current.mutate({ id: SID });
    await waitFor(() => expect(r2.current.isError).toBe(true));
    await waitFor(() => expect(errorSpy).toHaveBeenCalledWith("Réouverture impossible"));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. EFFET RÉEL — régénérations : clés dynamiques (zone #2) et onSettled sur les deux issues (zone #3).
// ─────────────────────────────────────────────────────────────────────────────

describe("planning queries — régénérations", () => {
  it("useRegenerateFromVersion → schedules + teams/venues/coaches/categories refetchent (clés dynamiques, effet réel) ; wizard/priority_tiers espionnés", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(
      () => ({
        schedules: useSchedules(),
        teams: useTeams(),
        venues: useVenues(),
        coaches: useCoaches(),
        categories: useCategories(),
        reload: useRegenerateFromVersion(),
      }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.teams.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.venues.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.coaches.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.categories.isSuccess).toBe(true));

    result.current.reload.mutate("sched-src");

    await waitFor(() => expect(result.current.reload.isSuccess).toBe(true));
    // La boucle sur des clés CONSTRUITES à l'exécution (l.421-423) atteint chaque lecteur vivant :
    // le terrain classique de la clé fantôme, prouvé par un refetch, pas par un espion.
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getTeams).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getVenues).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getCoaches).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(planningApi.getCategories).toHaveBeenCalledTimes(2));
    // Les deux familles sans lecteur monté ici → l'espion.
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["priority_tiers"] });
  });

  it("useRegenerateOverlay : onSettled → schedules refetche MÊME quand le generate échoue APRÈS le create (la version existe déjà serveur)", async () => {
    // Le create a réussi (une version existe serveur) mais le generate a échoué : l'état est mixte.
    // C'est EXACTEMENT pourquoi l'invalidation est en onSettled et non onSuccess — la liste doit
    // montrer le réel dans les DEUX issues. Un onSuccess laisserait ici une liste menteuse.
    vi.mocked(planningApi.generateSchedule).mockRejectedValueOnce(new Error("generate KO"));
    const client = makeClient();
    const { result } = renderHook(
      () => ({ schedules: useSchedules(), overlay: useRegenerateOverlay() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    expect(planningApi.listSchedules).toHaveBeenCalledTimes(1);

    result.current.overlay.mutate("plan-1");

    await waitFor(() => expect(result.current.overlay.isError).toBe(true));
    // L'échec du generate n'a PAS empêché le rafraîchissement de la liste.
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. EFFET RÉEL — les mutations « une nouvelle ligne apparaît / disparaît » rafraîchissent la liste.
//    Chacune garde un hook DISTINCT contre une clé fantôme sur `['schedules']`.
// ─────────────────────────────────────────────────────────────────────────────

describe("planning queries — les mutations de version rafraîchissent la liste des plannings", () => {
  it("useGenerate → la liste refetche (le controller bascule le planning en PENDING)", async () => {
    const client = makeClient();
    const { result } = renderHook(() => ({ schedules: useSchedules(), gen: useGenerate() }), { wrapper: wrapperFor(client) });
    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    expect(planningApi.listSchedules).toHaveBeenCalledTimes(1);
    result.current.gen.mutate(SID);
    await waitFor(() => expect(planningApi.generateSchedule).toHaveBeenCalledWith(SID));
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
  });

  it("useDeleteSchedule → la liste refetche (la version supprimée en sort)", async () => {
    const client = makeClient();
    const { result } = renderHook(() => ({ schedules: useSchedules(), del: useDeleteSchedule() }), { wrapper: wrapperFor(client) });
    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    expect(planningApi.listSchedules).toHaveBeenCalledTimes(1);
    result.current.del.mutate(SID);
    await waitFor(() => expect(planningApi.deleteSchedule).toHaveBeenCalledWith(SID));
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
  });

  it("useRegenerate → la liste refetche (une nouvelle version linéaire apparaît)", async () => {
    const client = makeClient();
    const { result } = renderHook(() => ({ schedules: useSchedules(), regen: useRegenerate() }), { wrapper: wrapperFor(client) });
    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    expect(planningApi.listSchedules).toHaveBeenCalledTimes(1);
    result.current.regen.mutate(SID);
    await waitFor(() => expect(planningApi.regenerate).toHaveBeenCalledWith(SID));
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
  });

  it("useFillSchedule → la liste refetche (une V+1 de comblement apparaît)", async () => {
    const client = makeClient();
    const { result } = renderHook(() => ({ schedules: useSchedules(), fill: useFillSchedule() }), { wrapper: wrapperFor(client) });
    await waitFor(() => expect(result.current.schedules.isSuccess).toBe(true));
    expect(planningApi.listSchedules).toHaveBeenCalledTimes(1);
    result.current.fill.mutate(SID);
    await waitFor(() => expect(planningApi.fillSchedule).toHaveBeenCalledWith(SID));
    await waitFor(() => expect(planningApi.listSchedules).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Clé de query PARAMÉTRÉE — useValidateImpact (zone #5) : `null` reste idle (aucun fetch) ;
//    deux scheduleId distincts sous le même client → deux caches distincts, pas de contamination.
// ─────────────────────────────────────────────────────────────────────────────

describe("planning queries — useValidateImpact : clé scopée par scheduleId, désarmée sur null", () => {
  it("scheduleId null → idle (aucun fetch) ; deux ids distincts → deux fetches distincts", async () => {
    vi.mocked(planningApi.getValidateImpact).mockImplementation((id: string) =>
      Promise.resolve({ orphanedFixtures: id === "s-a" ? 1 : 2, declaredOrphanedFixtures: 0 }),
    );
    const client = makeClient();
    const wrapper = wrapperFor(client);

    // null : le garde `enabled` empêche tout appel — la query reste idle.
    const off = renderHook(() => useValidateImpact(null), { wrapper });
    expect(off.result.current.fetchStatus).toBe("idle");
    expect(planningApi.getValidateImpact).not.toHaveBeenCalled();

    const a = renderHook(() => useValidateImpact("s-a"), { wrapper });
    const b = renderHook(() => useValidateImpact("s-b"), { wrapper });

    await waitFor(() => expect(a.result.current.isSuccess).toBe(true));
    await waitFor(() => expect(b.result.current.isSuccess).toBe(true));

    // Chaque portée a son propre appel ET son propre cache — pas de mélange entre les deux ids.
    expect(planningApi.getValidateImpact).toHaveBeenCalledWith("s-a");
    expect(planningApi.getValidateImpact).toHaveBeenCalledWith("s-b");
    expect(a.result.current.data?.orphanedFixtures).toBe(1);
    expect(b.result.current.data?.orphanedFixtures).toBe(2);
  });
});
