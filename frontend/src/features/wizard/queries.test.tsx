import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import * as wizardApi from "./api";
import {
  useActiveTeams,
  useCreateConstraint,
  useCreateTeam,
  useDeleteCoach,
  useDeleteVenue,
  useImplicitRuleSettings,
  useLaunchGeneration,
  useReservations,
  useResetImplicitRuleSetting,
  useSetVenuePeriodMode,
  useTranscribeFromSocle,
  useUpdateImplicitRuleSetting,
  useUpdatePeriodSlot,
} from "./queries";

// On mocke le module API voisin (`./api`) — le patron VIVANT du dépôt (cf.
// cockpit/queries.test.tsx). ⚠ frontend-strategy.md:39 prescrit de mocker `ky` : la doc
// a tort, ce n'est pas la passe de cette PR. Ici on n'exerce QUE `queries.ts` : les bonnes
// clés de cache, les payloads réellement passés à l'API, et les invalidations en onSuccess —
// c'est-à-dire la classe de bug (invalidation manquante / clé fantôme) qu'aucun test ne couvrait.
vi.mock("./api", () => ({
  createTeam: vi.fn().mockResolvedValue({ id: "t1" }),
  deleteVenue: vi.fn().mockResolvedValue(undefined),
  deleteCoach: vi.fn().mockResolvedValue(undefined),
  createConstraint: vi.fn().mockResolvedValue({ id: "c1" }),
  createVenuePeriodOverride: vi.fn().mockResolvedValue({ id: "o1" }),
  updateVenuePeriodOverride: vi.fn().mockResolvedValue({ id: "o1" }),
  updateSlot: vi.fn().mockResolvedValue({ id: "s1" }),
  transcribeFromSocle: vi.fn().mockResolvedValue({ toReplace: [] }),
  createSchedule: vi.fn().mockResolvedValue({ id: "sched-new" }),
  generateSchedule: vi.fn().mockResolvedValue({}),
  updateImplicitRuleSetting: vi.fn().mockResolvedValue({}),
  resetImplicitRuleSetting: vi.fn().mockResolvedValue(undefined),
  listImplicitRuleSettings: vi.fn().mockResolvedValue([]),
  listReservations: vi.fn().mockResolvedValue([]),
  listTeams: vi.fn().mockResolvedValue([]),
  listTeamPeriodOverrides: vi.fn().mockResolvedValue([]),
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

// --- Mutations : payload réellement passé + clés invalidées en onSuccess ---

describe("wizard queries — mutations, payloads & invalidations", () => {
  it("useCreateTeam invalide LES DEUX maisons (D-25) : ['wizard','teams'] ET ['teams']", async () => {
    // Le bug historique : le wizard n'invalidait que sa propre clé, Planning/Matchs lisaient
    // ['teams'] et restaient périmés 5 min. La double invalidation est le correctif — on la garde.
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useCreateTeam(), { wrapper: wrapperFor(client) });

    result.current.mutate({ name: "U11 Filles" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.createTeam).toHaveBeenCalledWith({ name: "U11 Filles" });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "teams"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["teams"] });
  });

  it("useDeleteVenue invalide venues PARTOUT plus la grille de créneaux ['wizard','venue_slots']", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useDeleteVenue(), { wrapper: wrapperFor(client) });

    result.current.mutate("v1");

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.deleteVenue).toHaveBeenCalledWith("v1");
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "venues"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["venues"] });
    // Supprimer un gymnase emporte ses créneaux : sans cette invalidation l'écran garderait
    // des créneaux orphelins d'un gymnase disparu.
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "venue_slots"] });
  });

  it("useDeleteCoach invalide coaches PARTOUT plus team_coaches et coach_players", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useDeleteCoach(), { wrapper: wrapperFor(client) });

    result.current.mutate("co1");

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.deleteCoach).toHaveBeenCalledWith("co1");
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "coaches"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["coaches"] });
    // Les liens qui référencent le coach supprimé (rôles d'équipe, mémberships joueur) doivent
    // aussi être rafraîchis, sinon ils pointent vers un coach fantôme.
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "team_coaches"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "coach_players"] });
  });

  it("useCreateConstraint invalide ['wizard','constraints'] ET ['entry-conflicts']", async () => {
    // Une contrainte datée (fermeture de gymnase) change les conflits servis par
    // /calendar-entries/{id}/conflicts : sans invalider entry-conflicts, la grille de période
    // reste périmée après saisie.
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useCreateConstraint(), { wrapper: wrapperFor(client) });

    result.current.mutate({ ruleType: "AVAILABILITY" } as never);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "constraints"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["entry-conflicts"] });
  });

  it("useTranscribeFromSocle invalide ['schedules'] ET ['calendar-entries'] (embarqué = la plus récente)", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useTranscribeFromSocle(), { wrapper: wrapperFor(client) });

    result.current.mutate("plan-1");

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.transcribeFromSocle).toHaveBeenCalledWith("plan-1");
    expect(spy).toHaveBeenCalledWith({ queryKey: ["schedules"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["calendar-entries"] });
  });
});

// --- useSetVenuePeriodMode : branche POST/PUT + le paquet d'invalidation de la grille ---

describe("wizard queries — useSetVenuePeriodMode", () => {
  it("sans existingId : POST createVenuePeriodOverride avec l'état complet (défauts mode/masque null)", async () => {
    const client = makeClient();
    const { result } = renderHook(() => useSetVenuePeriodMode("plan-1"), { wrapper: wrapperFor(client) });

    result.current.mutate({ venueId: "v9" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.createVenuePeriodOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", venueId: "v9", mode: null, dayOverrides: null });
    expect(wizardApi.updateVenuePeriodOverride).not.toHaveBeenCalled();
  });

  it("avec existingId : PUT updateVenuePeriodOverride(id, …) — pas de POST", async () => {
    const client = makeClient();
    const { result } = renderHook(() => useSetVenuePeriodMode("plan-1"), { wrapper: wrapperFor(client) });

    result.current.mutate({ venueId: "v9", mode: "DISABLED", existingId: "o42" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.updateVenuePeriodOverride).toHaveBeenCalledWith("o42", { schedulePlanId: "plan-1", venueId: "v9", mode: "DISABLED", dayOverrides: null });
    expect(wizardApi.createVenuePeriodOverride).not.toHaveBeenCalled();
  });

  it("onSuccess invalide les 4 clés de la grille SCOPÉES par le plan (overrides, slots, conflicts, réservations)", async () => {
    // Une écriture de mode peut refaire la grille côté serveur ET purger les réservations en
    // cascade : les 4 caches doivent tomber, tous portés par le MÊME schedulePlanId (sauf
    // entry-conflicts, invalidé par préfixe).
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useSetVenuePeriodMode("plan-1"), { wrapper: wrapperFor(client) });

    result.current.mutate({ venueId: "v9", mode: "BLANK", existingId: "o42" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "venue_period_overrides", "plan-1"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "period_slots", "plan-1"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["entry-conflicts"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "reservations", "plan-1"] });
  });
});

// --- useUpdatePeriodSlot : fusion du schedulePlanId dans le corps + invalidations scopées ---

describe("wizard queries — useUpdatePeriodSlot", () => {
  it("fusionne schedulePlanId dans le corps et invalide period_slots + reservations (scopés)", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useUpdatePeriodSlot("plan-1"), { wrapper: wrapperFor(client) });

    result.current.mutate({ id: "s7", body: { venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90, capacity: 1 } as never });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.updateSlot).toHaveBeenCalledWith("s7", { venueId: "v1", dayOfWeek: 2, startTime: "18:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" });
    // Déplacer un créneau change quelles réservations retombent dessus — les deux caches tombent.
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "period_slots", "plan-1"] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ["wizard", "reservations", "plan-1"] });
  });
});

// --- useLaunchGeneration : le rail génération (réutiliser vs créer) ---

describe("wizard queries — useLaunchGeneration", () => {
  it("réutilise existingScheduleId (regénération) : PAS de createSchedule, generate sur cet id, invalide ['schedules']", async () => {
    const client = makeClient();
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useLaunchGeneration(), { wrapper: wrapperFor(client) });

    result.current.mutate({ existingScheduleId: "sched-42" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.createSchedule).not.toHaveBeenCalled();
    expect(wizardApi.generateSchedule).toHaveBeenCalledWith("sched-42");
    expect(result.current.data).toBe("sched-42");
    expect(spy).toHaveBeenCalledWith({ queryKey: ["schedules"] });
  });

  it("sans existingScheduleId : createSchedule(schedulePlanId) puis generate sur l'id créé", async () => {
    const client = makeClient();
    const { result } = renderHook(() => useLaunchGeneration(), { wrapper: wrapperFor(client) });

    result.current.mutate({ schedulePlanId: "plan-1" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(wizardApi.createSchedule).toHaveBeenCalledWith("plan-1");
    expect(wizardApi.generateSchedule).toHaveBeenCalledWith("sched-new");
    expect(result.current.data).toBe("sched-new");
  });
});

// --- Clés de query scopées : saison vs période ne se contaminent JAMAIS ---

describe("wizard queries — scoping saison/période", () => {
  it("useImplicitRuleSettings : saison et période, sous le MÊME client, ne partagent pas leur cache", async () => {
    // La portée entre dans la clé (`… ?? 'season'`). Si elle n'y était pas, le second hook monté
    // rejouerait le cache du premier — et afficherait les valeurs de l'autre portée.
    // Marqueur porté par un vrai champ (minRestDays) : saison=11, période=22.
    vi.mocked(wizardApi.listImplicitRuleSettings).mockImplementation((planId?: string | null) =>
      Promise.resolve([{ ruleKey: "coachRestDay", minRestDays: null === planId || undefined === planId ? 11 : 22 }] as never),
    );
    const client = makeClient();
    const wrapper = wrapperFor(client);

    const season = renderHook(() => useImplicitRuleSettings(null), { wrapper });
    const period = renderHook(() => useImplicitRuleSettings("plan-1"), { wrapper });

    await waitFor(() => expect(season.result.current.data).toBeDefined());
    await waitFor(() => expect(period.result.current.data).toBeDefined());

    expect(wizardApi.listImplicitRuleSettings).toHaveBeenCalledWith(null);
    expect(wizardApi.listImplicitRuleSettings).toHaveBeenCalledWith("plan-1");
    expect(season.result.current.data?.[0].minRestDays).toBe(11);
    expect(period.result.current.data?.[0].minRestDays).toBe(22);
  });

  it("useUpdateImplicitRuleSetting : portée saison → corps NU ; portée période → corps + schedulePlanId ; invalide la clé scopée", async () => {
    const seasonClient = makeClient();
    const seasonSpy = vi.spyOn(seasonClient, "invalidateQueries");
    const season = renderHook(() => useUpdateImplicitRuleSetting(null), { wrapper: wrapperFor(seasonClient) });
    season.result.current.mutate({ ruleKey: "rest", body: { value: 3 } as never });
    await waitFor(() => expect(season.result.current.isSuccess).toBe(true));
    expect(wizardApi.updateImplicitRuleSetting).toHaveBeenCalledWith("rest", { value: 3 });
    expect(seasonSpy).toHaveBeenCalledWith({ queryKey: ["wizard", "implicit_rule_settings", "season"] });

    vi.mocked(wizardApi.updateImplicitRuleSetting).mockClear();

    const periodClient = makeClient();
    const periodSpy = vi.spyOn(periodClient, "invalidateQueries");
    const period = renderHook(() => useUpdateImplicitRuleSetting("plan-1"), { wrapper: wrapperFor(periodClient) });
    period.result.current.mutate({ ruleKey: "rest", body: { value: 3 } as never });
    await waitFor(() => expect(period.result.current.isSuccess).toBe(true));
    expect(wizardApi.updateImplicitRuleSetting).toHaveBeenCalledWith("rest", { value: 3, schedulePlanId: "plan-1" });
    expect(periodSpy).toHaveBeenCalledWith({ queryKey: ["wizard", "implicit_rule_settings", "plan-1"] });
  });

  it("useResetImplicitRuleSetting : saison appelle resetImplicitRuleSetting(ruleKey) ; période ajoute le schedulePlanId", async () => {
    const seasonClient = makeClient();
    const season = renderHook(() => useResetImplicitRuleSetting(null), { wrapper: wrapperFor(seasonClient) });
    season.result.current.mutate("rest");
    await waitFor(() => expect(season.result.current.isSuccess).toBe(true));
    expect(wizardApi.resetImplicitRuleSetting).toHaveBeenCalledWith("rest");

    vi.mocked(wizardApi.resetImplicitRuleSetting).mockClear();

    const periodClient = makeClient();
    const period = renderHook(() => useResetImplicitRuleSetting("plan-1"), { wrapper: wrapperFor(periodClient) });
    period.result.current.mutate("rest");
    await waitFor(() => expect(period.result.current.isSuccess).toBe(true));
    expect(wizardApi.resetImplicitRuleSetting).toHaveBeenCalledWith("rest", "plan-1");
  });

  it("useReservations : désarmé (enabled=false) ne fetche pas ; armé, la portée entre dans la clé et le param", async () => {
    const client = makeClient();
    const wrapper = wrapperFor(client);

    // enabled=false : aucune requête, même avec une portée nulle (l'appelant tranche via l'ancre).
    const off = renderHook(() => useReservations(null, false), { wrapper });
    expect(off.result.current.fetchStatus).toBe("idle");
    expect(wizardApi.listReservations).not.toHaveBeenCalled();

    // portée période : le param `{ schedulePlanId }` part vers l'API (base ⇒ undefined).
    const on = renderHook(() => useReservations("plan-1", true), { wrapper });
    await waitFor(() => expect(on.result.current.isSuccess).toBe(true));
    expect(wizardApi.listReservations).toHaveBeenCalledWith({ schedulePlanId: "plan-1" });
  });
});

// --- useActiveTeams : le fail-closed (loading ≠ failed) et le filtrage des équipes en pause ---

describe("wizard queries — useActiveTeams (fail-closed)", () => {
  const teams = [
    { id: "a", name: "A", isActive: true },
    { id: "b", name: "B", isActive: true },
  ];

  it("mode socle (plan null) : toutes les équipes, layerRead 'ready', aucun fetch d'override", async () => {
    vi.mocked(wizardApi.listTeams).mockResolvedValue(teams as never);
    const client = makeClient();
    const { result } = renderHook(() => useActiveTeams(null), { wrapper: wrapperFor(client) });

    await waitFor(() => expect(result.current.teams).toHaveLength(2));
    expect(result.current.layerRead).toBe("ready");
    expect(result.current.pausedIds.size).toBe(0);
    expect(wizardApi.listTeamPeriodOverrides).not.toHaveBeenCalled();
  });

  it("overrides EN VOL : ne masque RIEN et rapporte 'loading' — surtout PAS 'failed' (régression revue #342)", async () => {
    // Le premier jet repliait loading sur failed : l'écran criait « n'a pas pu être lu » à
    // chaque ouverture de période sur une lecture simplement en cours. Fail-closed = on montre
    // TOUT tant qu'on ne sait pas, et on dit 'loading', pas 'failed'.
    vi.mocked(wizardApi.listTeams).mockResolvedValue(teams as never);
    vi.mocked(wizardApi.listTeamPeriodOverrides).mockReturnValue(new Promise(() => {})); // ne résout jamais
    const client = makeClient();
    const { result } = renderHook(() => useActiveTeams("plan-1"), { wrapper: wrapperFor(client) });

    await waitFor(() => expect(result.current.teams).toHaveLength(2));
    expect(result.current.layerRead).toBe("loading");
  });

  it("overrides LUS : filtre les équipes en pause (isActive=false) et les expose dans pausedIds", async () => {
    vi.mocked(wizardApi.listTeams).mockResolvedValue(teams as never);
    vi.mocked(wizardApi.listTeamPeriodOverrides).mockResolvedValue([
      { id: "ov1", schedulePlanId: "plan-1", teamId: "b", isActive: false, sessionsPerWeek: null },
    ] as never);
    const client = makeClient();
    const { result } = renderHook(() => useActiveTeams("plan-1"), { wrapper: wrapperFor(client) });

    await waitFor(() => expect(result.current.layerRead).toBe("ready"));
    expect(result.current.teams.map((t) => t.id)).toEqual(["a"]);
    expect(result.current.pausedIds.has("b")).toBe(true);
  });
});
