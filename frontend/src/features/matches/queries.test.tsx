import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import * as matchesApi from "./api";
import {
  useAnalyzeFbiFixtures,
  useApplyFfbbRencontres,
  useCompetitions,
  useConflicts,
  useCreateTeamMatchHabit,
  useCreateVenueMatchWindow,
  useCreateVenueUnavailability,
  useDeleteFixture,
  useDeleteVenueMatchWindow,
  useFfbbSalles,
  useFixtures,
  useImportFbiFixtures,
  useLatestFbiIngestion,
  useOpponentTravel,
  usePlaceMatches,
  useResolveOpponentTravel,
  useSetEntryDeadlines,
  useSetOpponentTravelAuto,
  useSetOpponentTravelManual,
  useSportCategoryDurations,
  useSwapFixtures,
  useTeamMatchHabits,
  useUnavailabilityImpact,
  useUpdateSportCategoryDuration,
  useVenueMatchWindows,
  useVenueUnavailabilities,
} from "./queries";

// FRT-20 (2ᵉ tranche) — on n'exerce QUE `queries.ts` : le module voisin `./api` est le SEUL
// double (patron vivant du dépôt, cf. wizard/queries.test.tsx & cockpit/queries.test.tsx). Les
// VRAIS hooks sont montés sur un vrai QueryClient : pour la classe de bug qu'on chasse
// (invalidation qui n'atteint pas le lecteur réel), un espion sur `invalidateQueries` ne suffit
// pas — il prouve l'appel, pas l'EFFET. Donc ici la majorité des cas montent le lecteur (la
// query) ET la mutation sur le MÊME client, déclenchent la mutation, et assertent que le
// lecteur REFETCHE (sa fn `./api` est rappelée une 2ᵉ fois). L'espion ne sert qu'aux clés
// secondaires (p. ex. `['wizard','teams']`, dont aucun lecteur ne vit dans ce module).
vi.mock("./api", () => ({
  getFixtures: vi.fn().mockResolvedValue([]),
  getConflicts: vi.fn().mockResolvedValue({ clubId: "c", seasonId: null, conflicts: [], seasonPlanChosen: true }),
  getCompetitions: vi.fn().mockResolvedValue([]),
  getOpponentTravel: vi.fn().mockResolvedValue([]),
  getVenueUnavailabilities: vi.fn().mockResolvedValue([]),
  getUnavailabilityImpact: vi.fn().mockResolvedValue({ clubId: "c", seasonId: null, items: [] }),
  getTeamMatchHabits: vi.fn().mockResolvedValue([]),
  getLatestFbiIngestion: vi.fn().mockResolvedValue({ latest: null }),
  getVenueMatchWindows: vi.fn().mockResolvedValue([]),
  getSportCategoryDurations: vi.fn().mockResolvedValue([]),
  listFfbbSalles: vi.fn().mockResolvedValue({ postalCode: null, salles: [] }),

  deleteFixture: vi.fn().mockResolvedValue(undefined),
  placeMatches: vi.fn().mockResolvedValue({ placed: 0, skipped: 0, unplaced: [], diagnostics: [] }),
  setOpponentTravelManual: vi.fn().mockResolvedValue({}),
  setOpponentTravelAuto: vi.fn().mockResolvedValue({}),
  resolveOpponentTravel: vi.fn().mockResolvedValue({ resolved: 2, unresolved: [], skippedManual: 0 }),
  createVenueUnavailability: vi.fn().mockResolvedValue({ id: "u1", venueId: "v", startDate: "2026-10-01", endDate: "2026-10-02", label: null }),
  createTeamMatchHabit: vi.fn().mockResolvedValue({ id: "h1", teamId: "t", dayOfWeek: 6, kickoffTime: "18:00", venueId: null }),
  setEntryDeadlines: vi.fn().mockResolvedValue({ updated: [], deadline: null }),
  swapFixtures: vi.fn().mockResolvedValue(undefined),
  analyzeFbiFixtures: vi.fn().mockResolvedValue({ divisions: [], totalRows: 0, exempted: 0, errors: [], deviations: [] }),
  importFbiFixtures: vi.fn().mockResolvedValue({
    message: "", created: 0, updated: 0, unchanged: 0, exempted: 0, errors: [], warnings: [], unmappedDivisions: [], completeness: [], unresolvedDeviations: [], depositedAt: "2026-08-28",
  }),
  applyFfbbRencontres: vi.fn().mockResolvedValue({ created: 0, updated: 0, unresolvedDeviations: [], depositedAt: "2026-08-28" }),
  updateSportCategoryDuration: vi.fn().mockResolvedValue({ id: "s1", sportId: "sp", name: "U11", matchMinutes: 40, warmupMinutes: 10, defaultMatchMinutes: 40, defaultWarmupMinutes: 10 }),
  createVenueMatchWindow: vi.fn().mockResolvedValue({ id: "w1", venueId: "v", dayOfWeek: 6, startTime: "14:00", endTime: "20:00" }),
  deleteVenueMatchWindow: vi.fn().mockResolvedValue(undefined),
}));

function makeClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function wrapperFor(client: QueryClient) {
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  vi.clearAllMocks();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. EFFET RÉEL — le radar de conflits est bien ATTEINT par les écritures qui le nourrissent
//
// `useConflicts` lit ['fixtures','conflicts']. TanStack invalide PAR PRÉFIXE : invalider
// ['fixtures'] atteint donc AUSSI ['fixtures','conflicts'] (préfixe plus court → query plus
// longue). C'est le sens qui compte, on le PROUVE en montant le vrai lecteur, pas en supposant.
// ─────────────────────────────────────────────────────────────────────────────

describe("matches queries — le radar de conflits est réellement rafraîchi (effet, pas espion)", () => {
  it("une écriture de rencontre (useDeleteFixture) refetche la LISTE ['fixtures'] ET le radar ['fixtures','conflicts'] (préfixe), + engage ['wizard','teams']", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(
      () => ({ fixtures: useFixtures(), conflicts: useConflicts(), del: useDeleteFixture() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.fixtures.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));
    expect(matchesApi.getFixtures).toHaveBeenCalledTimes(1);
    expect(matchesApi.getConflicts).toHaveBeenCalledTimes(1);

    result.current.del.mutate("f1");

    await waitFor(() => expect(result.current.del.isSuccess).toBe(true));
    // Les DEUX lecteurs vivants refetchent : la liste ET le radar. Si `invalidateFixtures`
    // n'invalidait que le radar (ou une clé fantôme), l'un des deux resterait à 1.
    await waitFor(() => expect(matchesApi.getFixtures).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
    // L'engagement d'équipe (isEngaged) ne voyage QUE dans ['wizard','teams'] — aucun lecteur
    // de ce module ne l'expose, donc c'est le seul cas où l'espion est la bonne preuve.
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "teams"] });
  });

  it("usePlaceMatches n'invalide QUE ['fixtures'] — et pourtant le radar refetche (le rail synchrone remet le radar à jour par préfixe)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ fixtures: useFixtures(), conflicts: useConflicts(), place: usePlaceMatches() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.fixtures.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));

    result.current.place.mutate(undefined as never);

    await waitFor(() => expect(result.current.place.isSuccess).toBe(true));
    await waitFor(() => expect(matchesApi.getFixtures).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. EFFET RÉEL — le radar SPATIAL (trajet adverse) : les 3 chemins atteignent la même donnée
//    (zone à risque #4 : « un seul qui oublie et la carte ment »).
// ─────────────────────────────────────────────────────────────────────────────

describe("matches queries — trajet adverse : les 3 écritures rafraîchissent trajet ET conflits", () => {
  it("useSetOpponentTravelManual refetche ['opponents','travel'] ET le radar ['fixtures','conflicts']", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ travel: useOpponentTravel(), conflicts: useConflicts(), pin: useSetOpponentTravelManual() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.travel.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));

    const input = { opponentOrganismeCode: "ORG9", venueLabel: "Salle X", venueExternalRef: null, latitude: 45.7, longitude: 4.8 };
    result.current.pin.mutate(input);

    await waitFor(() => expect(result.current.pin.isSuccess).toBe(true));
    expect(matchesApi.setOpponentTravelManual).toHaveBeenCalledWith(input);
    await waitFor(() => expect(matchesApi.getOpponentTravel).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
  });

  it("useSetOpponentTravelAuto refetche trajet ET radar (même invalidateTravel)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ travel: useOpponentTravel(), conflicts: useConflicts(), auto: useSetOpponentTravelAuto() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.travel.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));

    result.current.auto.mutate("ORG9");

    await waitFor(() => expect(result.current.auto.isSuccess).toBe(true));
    expect(matchesApi.setOpponentTravelAuto).toHaveBeenCalledWith("ORG9");
    await waitFor(() => expect(matchesApi.getOpponentTravel).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
  });

  it("useResolveOpponentTravel (recalcul global) refetche trajet ET radar", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ travel: useOpponentTravel(), conflicts: useConflicts(), resolve: useResolveOpponentTravel() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.travel.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));

    result.current.resolve.mutate(undefined as never);

    await waitFor(() => expect(result.current.resolve.isSuccess).toBe(true));
    await waitFor(() => expect(matchesApi.getOpponentTravel).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. EFFET RÉEL — les surfaces d'alerte multiples (indisponibilité, habitude)
// ─────────────────────────────────────────────────────────────────────────────

describe("matches queries — surfaces d'alerte multiples", () => {
  it("useCreateVenueUnavailability refetche les TROIS surfaces : liste, carte d'impact ET radar", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ list: useVenueUnavailabilities(), impact: useUnavailabilityImpact(), conflicts: useConflicts(), create: useCreateVenueUnavailability() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.list.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.impact.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));

    result.current.create.mutate({ venueId: "v", startDate: "2026-10-01", endDate: "2026-10-02" });

    await waitFor(() => expect(result.current.create.isSuccess).toBe(true));
    await waitFor(() => expect(matchesApi.getVenueUnavailabilities).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getUnavailabilityImpact).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
  });

  it("useCreateTeamMatchHabit refetche les habitudes ET le radar (l'estimation away déplace un conflit)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ habits: useTeamMatchHabits(), conflicts: useConflicts(), create: useCreateTeamMatchHabit() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.habits.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));

    result.current.create.mutate({ teamId: "t", dayOfWeek: 6, kickoffTime: "18:00" });

    await waitFor(() => expect(result.current.create.isSuccess).toBe(true));
    await waitFor(() => expect(matchesApi.getTeamMatchHabits).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. EFFET RÉEL — les imports/applications en masse (les plus coûteux à laisser périmés, zone #2)
// ─────────────────────────────────────────────────────────────────────────────

describe("matches queries — imports/applications en masse", () => {
  it("useImportFbiFixtures refetche fixtures + radar + compétitions + fraîcheur FBI, et applique le défaut decisions=[]", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({
        fixtures: useFixtures(),
        conflicts: useConflicts(),
        competitions: useCompetitions(),
        latest: useLatestFbiIngestion(),
        importFbi: useImportFbiFixtures(),
      }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.fixtures.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.competitions.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.latest.isSuccess).toBe(true));

    const file = new File(["x"], "fbi.xlsx");
    // `decisions` OMIS → le hook DOIT passer [] à l'API (le `?? []` de queries.ts) : sans lui,
    // le multipart n'ajoute pas la clé et le backend traite « aucune décision », ce qui est
    // justement le comportement voulu — mais c'est queries.ts qui doit poser le défaut.
    result.current.importFbi.mutate({ file, mappings: [] });

    await waitFor(() => expect(result.current.importFbi.isSuccess).toBe(true));
    expect(matchesApi.importFbiFixtures).toHaveBeenCalledWith(file, [], []);
    await waitFor(() => expect(matchesApi.getFixtures).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getCompetitions).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getLatestFbiIngestion).toHaveBeenCalledTimes(2));
  });

  it("useApplyFfbbRencontres refetche fixtures + radar + compétitions, et transmet (decisions, creations)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ fixtures: useFixtures(), conflicts: useConflicts(), competitions: useCompetitions(), apply: useApplyFfbbRencontres() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.fixtures.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.conflicts.isSuccess).toBe(true));
    await waitFor(() => expect(result.current.competitions.isSuccess).toBe(true));

    const decisions = [{ fixtureId: "f1", field: "date" as const, choice: "take_file" as const }];
    const creations = [{ rencontreId: "r1", teamId: "t1" }];
    result.current.apply.mutate({ decisions, creations });

    await waitFor(() => expect(result.current.apply.isSuccess).toBe(true));
    expect(matchesApi.applyFfbbRencontres).toHaveBeenCalledWith(decisions, creations);
    await waitFor(() => expect(matchesApi.getFixtures).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getConflicts).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(matchesApi.getCompetitions).toHaveBeenCalledTimes(2));
  });

  it("useSetEntryDeadlines refetche les compétitions et pose deadline=null EXPLICITE (effacer, positionnel)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ competitions: useCompetitions(), setDeadlines: useSetEntryDeadlines() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.competitions.isSuccess).toBe(true));

    result.current.setDeadlines.mutate({ competitionIds: ["c1", "c2"], deadline: null });

    await waitFor(() => expect(result.current.setDeadlines.isSuccess).toBe(true));
    // Contrat positionnel (ids, deadline) — un null explicite EFFACE, il ne doit pas devenir undefined.
    expect(matchesApi.setEntryDeadlines).toHaveBeenCalledWith(["c1", "c2"], null);
    await waitFor(() => expect(matchesApi.getCompetitions).toHaveBeenCalledTimes(2));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. EFFET RÉEL — les cas « en BOTH outcomes » et « dry-run »
// ─────────────────────────────────────────────────────────────────────────────

describe("matches queries — onSettled (échange) et dry-run", () => {
  it("useSwapFixtures refetche la grille MÊME quand le 2ᵉ PUT échoue (onSettled, pas onSuccess)", async () => {
    // Le 2ᵉ PUT a pu échouer APRÈS que le 1ᵉʳ a bougé : l'état serveur est mixte. C'est
    // exactement pourquoi l'invalidation est en onSettled — la grille doit montrer le réel
    // dans les DEUX issues. Un onSuccess laisserait ici une grille menteuse.
    vi.mocked(matchesApi.swapFixtures).mockRejectedValueOnce(new Error("2e PUT KO"));
    const client = makeClient();
    const { result } = renderHook(
      () => ({ fixtures: useFixtures(), swap: useSwapFixtures() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.fixtures.isSuccess).toBe(true));
    expect(matchesApi.getFixtures).toHaveBeenCalledTimes(1);

    result.current.swap.mutate({ a: {} as never, b: {} as never });

    await waitFor(() => expect(result.current.swap.isError).toBe(true));
    // L'échec n'a PAS empêché le rafraîchissement : la grille refetche.
    await waitFor(() => expect(matchesApi.getFixtures).toHaveBeenCalledTimes(2));
  });

  it("useAnalyzeFbiFixtures est un DRY-RUN : il n'invalide RIEN (la grille ne refetche pas)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ fixtures: useFixtures(), analyze: useAnalyzeFbiFixtures() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.fixtures.isSuccess).toBe(true));
    expect(matchesApi.getFixtures).toHaveBeenCalledTimes(1);

    result.current.analyze.mutate(new File(["x"], "fbi.xlsx"));

    await waitFor(() => expect(result.current.analyze.isSuccess).toBe(true));
    // Un dry-run n'écrit rien serveur : aucune invalidation. La grille reste à 1 appel.
    // (temporisation courte pour laisser une éventuelle invalidation fautive se manifester)
    await new Promise((r) => setTimeout(r, 40));
    expect(matchesApi.getFixtures).toHaveBeenCalledTimes(1);
  });

  it("useUpdateSportCategoryDuration refetche la liste des durées et transmet (category, input)", async () => {
    const client = makeClient();
    const { result } = renderHook(
      () => ({ durations: useSportCategoryDurations(), update: useUpdateSportCategoryDuration() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.durations.isSuccess).toBe(true));

    const category = { id: "s1", sportId: "sp", name: "U11", matchMinutes: null, warmupMinutes: null, defaultMatchMinutes: 40, defaultWarmupMinutes: 10 };
    const input = { matchMinutes: 30, warmupMinutes: 5 };
    result.current.update.mutate({ category, input });

    await waitFor(() => expect(result.current.update.isSuccess).toBe(true));
    expect(matchesApi.updateSportCategoryDuration).toHaveBeenCalledWith(category, input);
    await waitFor(() => expect(matchesApi.getSportCategoryDurations).toHaveBeenCalledTimes(2));
  });

  it("useCreateVenueMatchWindow / useDeleteVenueMatchWindow refetchent la liste ['venue_match_windows']", async () => {
    // NB (finding hors PR — cf. rapport) : ces écritures NE touchent PAS ['fixtures','conflicts']
    // alors que le conflit ACCESS_WINDOW_LOST dépend des fenêtres d'accès. Ce test ne verrouille
    // QUE le comportement CORRECT (la liste refetche) pour rester vert et ne pas figer l'asymétrie.
    const client = makeClient();
    const { result } = renderHook(
      () => ({ windows: useVenueMatchWindows(), create: useCreateVenueMatchWindow(), remove: useDeleteVenueMatchWindow() }),
      { wrapper: wrapperFor(client) },
    );

    await waitFor(() => expect(result.current.windows.isSuccess).toBe(true));

    result.current.create.mutate({ venueId: "v", dayOfWeek: 6, startTime: "14:00", endTime: "20:00" });
    await waitFor(() => expect(result.current.create.isSuccess).toBe(true));
    await waitFor(() => expect(matchesApi.getVenueMatchWindows).toHaveBeenCalledTimes(2));

    result.current.remove.mutate("w1");
    await waitFor(() => expect(result.current.remove.isSuccess).toBe(true));
    await waitFor(() => expect(matchesApi.getVenueMatchWindows).toHaveBeenCalledTimes(3));
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Clé de query COMPOSÉE — deux portées sous le même client ne partagent pas leur cache (zone #5)
// ─────────────────────────────────────────────────────────────────────────────

describe("matches queries — useFfbbSalles : la clé est scopée par code postal", () => {
  it("deux codes postaux distincts → deux fetches distincts, données non mélangées ; un code invalide reste idle", async () => {
    vi.mocked(matchesApi.listFfbbSalles).mockImplementation((pc: string) => Promise.resolve({ postalCode: pc, salles: [] }));
    const client = makeClient();
    const wrapper = wrapperFor(client);

    const lyon = renderHook(() => useFfbbSalles("69100"), { wrapper });
    const paris = renderHook(() => useFfbbSalles("75001"), { wrapper });

    await waitFor(() => expect(lyon.result.current.isSuccess).toBe(true));
    await waitFor(() => expect(paris.result.current.isSuccess).toBe(true));

    // Chaque portée a son propre appel ET son propre cache — pas de contamination.
    expect(matchesApi.listFfbbSalles).toHaveBeenCalledWith("69100");
    expect(matchesApi.listFfbbSalles).toHaveBeenCalledWith("75001");
    expect(lyon.result.current.data?.postalCode).toBe("69100");
    expect(paris.result.current.data?.postalCode).toBe("75001");

    // Garde `enabled` : un code non conforme (pas 5 chiffres) ne déclenche AUCUN fetch.
    const bad = renderHook(() => useFfbbSalles("abc"), { wrapper });
    expect(bad.result.current.fetchStatus).toBe("idle");
    expect(matchesApi.listFfbbSalles).not.toHaveBeenCalledWith("abc");
  });
});
