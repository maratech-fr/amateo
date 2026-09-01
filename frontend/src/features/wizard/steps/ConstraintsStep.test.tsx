import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Constraint, ImplicitRuleSetting, SharedTrainingBlock } from "../api";
import { PRODUCT_RULES, WELLBEING_RULES } from "../lib/implicitRules";

const RESOLVED_IMPLICIT_RULES: ImplicitRuleSetting[] = [
  { ruleKey: "coachRestDay", intensity: "HARD", minRestDays: 1, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
  { ruleKey: "salarieDistribution", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
  { ruleKey: "maxConsecutiveSessions", intensity: "HARD", minRestDays: null, maxConsecutive: 3, maxConsecutiveDays: null, isDefault: true },
  { ruleKey: "ageAscending", intensity: "HARD", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: null, isDefault: true },
  // P2-42 — la 5e règle est servie par l'API même NON RÉGLÉE, avec l'intensité OFF : sans
  // cette ligne, l'écran ne la montrerait pas et le gestionnaire ne pourrait jamais l'activer.
  { ruleKey: "maxConsecutiveDays", intensity: "OFF", minRestDays: null, maxConsecutive: null, maxConsecutiveDays: 3, isDefault: true },
];

const h = vi.hoisted(() => ({
  createMut: vi.fn(),
  updateMut: vi.fn(),
  list: [] as Constraint[],
  resCreate: vi.fn(),
  resDelete: vi.fn(),
  resGroupCreate: vi.fn(),
  reservations: [] as { id: string; calendarEntryId: string | null; teamId: string; venueId: string; dayOfWeek: number; startTime: string; durationMinutes: number }[],
  teamCoaches: [] as { id: string; teamId: string; coachId: string; role: string }[] | undefined,
  coachesPending: false,
  coachesFailed: false,
  tags: [] as { id: string; name: string; color: string | null; isSystem: boolean; axis: "GENRE" | "NIVEAU" | "AGE" | null }[],
  tagAssignments: [] as { id: string; teamId: string; tagId: string; seasonId: string }[],
  implicitRules: [] as ImplicitRuleSetting[],
  // P2-51 — les blocs de mutualisation, posables dans Réserver.
  sharedBlocks: [] as SharedTrainingBlock[],
  // P2-59 — genèses/faits lus PAR entrée : la clé est l'id passé à useWizardConstraints
  // (la semaine pour ses genèses, la mère pour ses faits). Vide → repli sur `list`.
  byEntry: {} as Record<string, Constraint[]>,
}));

const SEASON_TEAMS = [
  { id: "t1", name: "SM1", sportCategoryId: "cat", priorityTierId: 3, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
  { id: "t2", name: "Fanion", sportCategoryId: "cat", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
];

const { activeVenuesState, activeTeamsState, reservationArgs, entryConflictsState, calendarEntryState, constraintsArg, calendarEntryById } = vi.hoisted(() => ({
  activeVenuesState: {
    venues: [{ id: "v1", name: "Gymnase A", isActive: true }, { id: "v2", name: "Gymnase B", isActive: true }] as { id: string; name: string; isActive: boolean }[],
    disabledIds: new Set<string>(),
    layerRead: "ready" as "loading" | "failed" | "ready",
  },
  activeTeamsState: { pausedIds: new Set<string>(), layerRead: "ready" as "loading" | "failed" | "ready" },
  // P2-22 — les fermetures servies par /calendar-entries/{id}/conflicts (D2) et l'entrée
  // courante (D5, parentEntryId). Défauts neutres : aucune fermeture, entrée racine.
  entryConflictsState: { data: { entryId: "e", venueIds: [], conflicts: [], closures: [], seasonPlanChosen: true } as unknown, isError: false },
  calendarEntryState: { data: { parentEntryId: null } as { parentEntryId: string | null } | undefined },
  // Capture le dernier id passé à useWizardConstraints (le composant appelle genèses puis faits).
  constraintsArg: { value: undefined as string | null | undefined },
  // P2-59 — les entrées de calendrier PAR id : la mère porte son `title` (pour le badge des
  // faits), l'enfant son `parentEntryId`. Absent → repli sur `calendarEntryState.data`.
  calendarEntryById: { map: {} as Record<string, { parentEntryId: string | null; title?: string }> },
  // ⚠ Le mock HONORE ses arguments : `useReservations(planId, enabled)` porte la garde qui
  // empêche une période non résolue de servir les réservations du SOCLE. Un mock qui rend
  // une constante rendait cette garde inobservable — c'est ainsi que l'issue de secours
  // « gymnase réservé » a pu être ajoutée sans un seul test (revue #342 round 2).
  reservationArgs: { planId: undefined as string | null | undefined, enabled: undefined as boolean | undefined },
}));

vi.mock("../queries", () => ({
  useWizardConstraints: (entryId?: string | null, enabled = true) => {
    constraintsArg.value = entryId;
    if (false === enabled) {
      return { data: [] };
    }
    if (null != entryId && entryId in h.byEntry) {
      return { data: h.byEntry[entryId] };
    }
    return { data: h.list };
  },
  useWizardTeams: () => ({ data: SEASON_TEAMS }),
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }] }),
  useWizardTeamTags: () => ({ data: h.tags }),
  useWizardTeamTagAssignments: () => ({ data: h.tagAssignments }),
  useWizardCoaches: () => ({ data: [{ id: "co1", firstName: "Jean", lastName: "Dupont", isEmployee: false, isActive: true, email: null }] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", isActive: true }, { id: "v2", name: "Gymnase B", isActive: true }] }),
  // P2-15 : les sélecteurs ne voient QUE les gymnases et les équipes actifs de la couche.
  useActiveVenues: () => ({ venues: activeVenuesState.venues, disabledIds: activeVenuesState.disabledIds, layerRead: activeVenuesState.layerRead }),
  useActiveTeams: () => ({ teams: SEASON_TEAMS.filter((t) => !activeTeamsState.pausedIds.has(t.id)), pausedIds: activeTeamsState.pausedIds, layerRead: activeTeamsState.layerRead }),
  useVenueSlots: () => ({ data: [{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }] }),
  // #8 — la grille de la couche ÉDITÉE : le socle en mode saison, la grille que la
  // période POSSÈDE sinon. Les deux couches portent volontairement des créneaux
  // DIFFÉRENTS ici : c'est ce qui rend observable le fait qu'on lise la bonne.
  useGridSlots: (schedulePlanId: string | null) => ({
    data:
      null === schedulePlanId
        ? [{ id: "s1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, capacity: 1 }]
        : [{ id: "p1", venueId: "v2", dayOfWeek: 4, startTime: "19:00", durationMinutes: 90, capacity: 1 }],
  }),
  useCreateConstraint: () => ({ mutate: h.createMut, isPending: false }),
  useUpdateConstraint: () => ({ mutate: h.updateMut, isPending: false }),
  useDeleteConstraint: () => ({ mutate: vi.fn() }),
  useReservations: (planId: string | null, enabled?: boolean) => {
    reservationArgs.planId = planId;
    reservationArgs.enabled = enabled;
    return { data: false === enabled ? [] : h.reservations };
  },
  // P2-9 PR C : la modale est transactionnelle (on compose, on valide) — elle appelle
  // donc mutateAsync et non mutate, et lit le lien équipe→coach pour refuser au clic une
  // affectation qui dédoublerait un coach.
  useWizardTeamCoaches: () => ({ data: h.teamCoaches, isPending: h.coachesPending, isError: h.coachesFailed, refetch: vi.fn() }),
  useCreateReservation: () => ({ mutateAsync: h.resCreate, isPending: false }),
  useDeleteReservation: () => ({ mutateAsync: h.resDelete, isPending: false }),
  useCreateGroupReservation: () => ({ mutateAsync: h.resGroupCreate, isPending: false }),
  // P2-51 — la mutualisation par bloc. Le mock HONORE `enabled` (comme useReservations) : une
  // période non résolue ne doit pas servir les blocs du SOCLE.
  useSharedTrainingBlocks: (_planId?: string | null, enabled?: boolean) => ({ data: false === enabled ? [] : h.sharedBlocks }),
  useTeamPeriodOverrides: () => ({ data: [] }),
  // P2-28 — les 4 règles bien-être résolues + leurs mutations (le détail du panneau est
  // couvert par ImplicitRulesPanel.test ; ici on ne garde que le CÂBLAGE dans l'étape).
  useImplicitRuleSettings: () => ({ data: h.implicitRules, isError: false }),
  useUpdateImplicitRuleSetting: () => ({ mutate: vi.fn(), isPending: false }),
  useResetImplicitRuleSetting: () => ({ mutate: vi.fn(), isPending: false }),
  // P2-53 RMM-8 — l'entrée « Trajet entre gymnases » (onglet Base) lit la matrice puis le levier.
  // Matrice vide par défaut → entrée absente ; le détail est couvert par TravelRuleNotice.test.
  useVenueTravelTimes: () => ({ data: [] }),
  useTravelRuleSetting: () => ({ data: undefined }),
  useUpdateTravelRuleSetting: () => ({ mutate: vi.fn(), isPending: false }),
}));

// Contrat réel de usePeriodAnchor : sans entrée (mode saison) l'ancre est la BASE
// (planId null, ready true) ; avec une entrée, le plan de la période — et `ready` reste
// FAUX tant qu'il n'est pas résolu. `periodAnchorReady` est pilotable pour que la garde
// anchorReady (qui protège les réservations d'un atterrissage sur le socle) reste
// exerçable par les tests (revue #284 round 2).
const periodAnchorFailed = { value: false };
const retryAnchor = vi.fn();
const periodAnchorReady = vi.hoisted(() => ({ value: true }));
vi.mock("@/features/cockpit/queries", () => ({
  usePeriodAnchor: (entryId: string | null) =>
    null === entryId
      ? { state: "base", planId: null }
      : periodAnchorReady.value
        ? { state: "period", planId: "plan-1" }
        : periodAnchorFailed.value
          ? { state: "failed", planId: null, retry: retryAnchor }
          : { state: "loading", planId: null },
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
  useEntryConflicts: () => ({ data: entryConflictsState.data, isError: entryConflictsState.isError, refetch: vi.fn() }),
  useCalendarEntry: (id: string | null) => ({ data: null != id && id in calendarEntryById.map ? calendarEntryById.map[id] : calendarEntryState.data }),
}));
// Stub : le comportement interne de PeriodConstraints est couvert par
// PeriodStructure.test — ici on ne teste que son PLACEMENT par onglet (#9).
vi.mock("./PeriodStructure", () => ({
  PeriodConstraints: ({ family }: { family?: string }) => <div data-testid="inherited-section">{family ?? "all"}</div>,
}));

import { ConstraintsStep } from "./ConstraintsStep";
import { useWizardStore } from "../store";

/**
 * Freezes the UI OFFER side of the constraint matrix (audit P0.1): the rule
 * options and the emitted configs are locked to what
 * engine/tests/semantic/constraint_matrix.py declares as honored. Any change
 * here must update the matrix (and its generated engine test) first.
 */
describe("ConstraintsStep — constraint-matrix offer lock", () => {
  beforeEach(() => {
    h.createMut.mockClear();
    h.updateMut.mockClear();
    h.list = [];
    h.tags = [];
    h.tagAssignments = [];
    h.implicitRules = RESOLVED_IMPLICIT_RULES;
    activeVenuesState.venues = [{ id: "v1", name: "Gymnase A", isActive: true }, { id: "v2", name: "Gymnase B", isActive: true }];
    activeVenuesState.disabledIds = new Set();
    activeVenuesState.layerRead = "ready";
    activeTeamsState.pausedIds = new Set();
    activeTeamsState.layerRead = "ready";
    h.reservations = [];
  });

  // Même règle qu'au récap : lecture ratée ⇒ on ne masque rien, mais on le DIT. Le silence
  // aurait laissé relier une contrainte à un gymnase en fait désactivé (revue #342).
  it("annonce que la liste est celle de la saison quand les réglages sont illisibles", () => {
    activeVenuesState.layerRead = "failed";
    renderWithProviders(<ConstraintsStep />);

    expect(screen.getByText(/n'ont pas pu être lus.*peut contenir un gymnase désactivé/)).toBeInTheDocument();
  });

  // CHARGER ≠ ÉCHOUER (revue #342 round 2) : replier `loading` sur `failed` faisait crier
  // « n'a pas pu être lu » à chaque ouverture. Un bandeau d'alerte qui se déclenche en
  // régime normal n'alerte plus de rien.
  it("dit « en cours de lecture » — pas « échec » — pendant le chargement des réglages", () => {
    activeVenuesState.layerRead = "loading";
    renderWithProviders(<ConstraintsStep />);

    expect(screen.getByText(/sont en cours de lecture/)).toBeInTheDocument();
    expect(screen.queryByText(/n'ont pas pu être lus/)).not.toBeInTheDocument();
  });

  // P2-15 (retour fondateur) : « ça n'a pas de sens que le gymnase Mateo soit désactivé
  // mais que je puisse encore y relier des contraintes ». Un gymnase désactivé sort du
  // payload solveur : l'offrir invitait à un geste sans effet, que le récap devait ensuite
  // avertir. Décision : dans les sélecteurs, on ne voit QUE les gymnases actifs.
  it("n'offre que les gymnases ACTIFS de la période", async () => {
    const user = userEvent.setup();
    activeVenuesState.venues = [{ id: "v1", name: "Gymnase A", isActive: true }];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    const picker = screen.getByLabelText("Gymnase");
    const options = Array.from(picker.querySelectorAll("option")).map((o) => o.textContent);
    expect(options).toContain("Gymnase A");
    expect(options).not.toContain("Gymnase B");
  });

  it("only offers groups (tags) that have at least one assigned team", () => {
    h.tags = [
      { id: "tag-fem", name: "FEMININE", color: null, isSystem: true, axis: "GENRE" },
      { id: "tag-adu", name: "ADULTE", color: null, isSystem: true, axis: "AGE" },
    ];
    // Only ADULTE is assigned to a team — FEMININE concerns no team.
    h.tagAssignments = [{ id: "a1", teamId: "t1", tagId: "tag-adu", seasonId: "s1" }];

    renderWithProviders(<ConstraintsStep />);
    const target = screen.getByRole("combobox", { name: "Cible" });
    const options = Array.from(target.querySelectorAll("option")).map((o) => o.textContent);
    // ADULTE is shown under the Âge axis, labelled « Adulte (+ de 18) »; FEMININE (unassigned) is absent.
    expect(within(target).getByRole("group", { name: "Âge" })).toBeInTheDocument();
    expect(options).toContain("Adulte (+ de 18)");
    expect(options).not.toContain("Femme");
    expect(options).not.toContain("FEMININE");
  });

  it("groups horaire/jours constraints by AXIS (Âge…) then per team in rank order", () => {
    h.tags = [{ id: "tag-adu", name: "ADULTE", color: null, isSystem: true, axis: "AGE" }];
    h.list = [
      { id: "cg", name: "Groupe Adulte (+ de 18) · pas après 21:00", scope: "CLUB", scopeTargetId: null, family: "TIME", ruleType: "PREFERRED", config: { targetTag: "ADULTE" }, isActive: true },
      { id: "cb", name: "SM1 · pas après 21:00", scope: "TEAM", scopeTargetId: "t1", family: "TIME", ruleType: "PREFERRED", config: {}, isActive: true }, // t1 = tier B
      { id: "cs", name: "Fanion · pas après 21:00", scope: "TEAM", scopeTargetId: "t2", family: "TIME", ruleType: "PREFERRED", config: {}, isActive: true }, // t2 = tier S
    ] as Constraint[];

    renderWithProviders(<ConstraintsStep />);

    // ADULTE tag → axis « Âge » ; team-targeted constraints go under their
    // team's RANG group (Fanion=S, SM1=B), in tier order.
    const sections = screen.getAllByTestId("constraint-section").map((e) => e.textContent);
    expect(sections).toEqual(["Âge", "S · Fanion", "B · Moyenne"]);
  });

  it("groups gymnase constraints by VENUE (A→Z)", async () => {
    const user = userEvent.setup();
    h.list = [
      { id: "cf1", name: "Fanion · préfère Gymnase B", scope: "TEAM", scopeTargetId: "t2", family: "FACILITY", ruleType: "PREFERRED", config: { preferredVenueId: "v2" }, isActive: true },
      { id: "cf2", name: "SM1 · impose Gymnase A", scope: "TEAM", scopeTargetId: "t1", family: "FACILITY", ruleType: "HARD", config: { forcedVenueId: "v1" }, isActive: true },
    ] as Constraint[];

    renderWithProviders(<ConstraintsStep />);
    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    // Sections are the venue names, sorted A→Z (Gymnase A before Gymnase B).
    const sections = screen.getAllByTestId("constraint-section").map((e) => e.textContent);
    expect(sections).toEqual(["Gymnase A", "Gymnase B"]);
  });

  it("groups coach constraints by staffing group (Salariés / Coachs-joueurs / Bénévoles)", async () => {
    const user = userEvent.setup();
    h.list = [
      { id: "cc", name: "Jean Dupont · indispo vendredi", scope: "COACH", scopeTargetId: "co1", family: "COACH_AVAILABILITY", ruleType: "HARD", config: {}, isActive: true },
    ] as Constraint[];

    renderWithProviders(<ConstraintsStep />);
    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    // co1 (isEmployee false, not a player) → « Bénévoles ».
    expect(screen.getByTestId("constraint-section")).toHaveTextContent("Bénévoles");
  });

  it("never drops a COACH constraint whose coach is absent from the list (revue #204)", async () => {
    const user = userEvent.setup();
    h.list = [
      // co-gone is NOT in useWizardCoaches (removed/deactivated) — must still show.
      { id: "cx", name: "Coach retiré · indispo vendredi", scope: "COACH", scopeTargetId: "co-gone", family: "COACH_AVAILABILITY", ruleType: "HARD", config: {}, isActive: true },
    ] as Constraint[];

    renderWithProviders(<ConstraintsStep />);
    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    // The constraint is visible under a fallback section — not silently dropped.
    expect(screen.getByText("Coach retiré · indispo vendredi")).toBeInTheDocument();
  });

  it("offers exactly Obligatoire/Préféré/Verrouillé — BONUS is gone (ENG-12)", () => {
    renderWithProviders(<ConstraintsStep />);
    const rule = screen.getByLabelText("Règle");
    const options = Array.from(rule.querySelectorAll("option")).map((o) => o.textContent);
    expect(options).toEqual(["Préféré", "Obligatoire", "Verrouillé"]);
  });

  it("forces HARD on coach availability (no rule selector — the engine always enforces it)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    expect(screen.queryByLabelText("Règle")).not.toBeInTheDocument();
    expect(screen.getByText("Obligatoire")).toBeInTheDocument();

    // Pick coach + a day, add → the payload pins ruleType HARD.
    await user.selectOptions(screen.getByLabelText("Coach"), "co1");
    await user.click(screen.getByRole("button", { name: "Lun" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut).toHaveBeenCalledOnce();
    // SEC-13 : la cible du coach vit dans le SCOPE, et NULLE PART ailleurs. Le
    // `config.coachId` d'avant valait exactement `scopeTargetId` — un doublon que
    // le solveur n'a jamais lu. Les deux assertions comptent : la première dit où
    // est la cible, la seconde interdit qu'elle revienne en double.
    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "COACH_AVAILABILITY", ruleType: "HARD", scopeTargetId: "co1", config: { unavailableDays: [1] } });
    expect(h.createMut.mock.calls[0][0].config).not.toHaveProperty("coachId");
  });

  it("names generated constraints with full day words (« jeudi », not « Jeu ») — founder 2026-08-12", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    // A coach indispo on Thursday: the day toggle stays short (« Jeu »), but the
    // auto-generated NAME must spell the day out.
    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    await user.selectOptions(screen.getByLabelText("Coach"), "co1");
    await user.click(screen.getByRole("button", { name: "Jeu" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0].name).toBe("Jean Dupont · indispo jeudi");
  });

  it("groups the coach picker (a non-employee non-player coach lands under « Bénévoles »)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    const benevoles = screen.getByRole("group", { name: "Bénévoles" });
    expect(within(benevoles).getByRole("option", { name: "Jean Dupont" })).toBeInTheDocument();
  });

  it("coach 'disponible uniquement' emits HARD availableDays (whitelist — ALIGN, engine already honored it)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    await user.selectOptions(screen.getByLabelText("Coach"), "co1");
    await user.selectOptions(screen.getByLabelText("Disponibilité"), "available");
    await user.click(screen.getByRole("button", { name: "Mar" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "COACH_AVAILABILITY", ruleType: "HARD", scopeTargetId: "co1", config: { availableDays: [2] } });
    expect(h.createMut.mock.calls[0][0].config).not.toHaveProperty("coachId"); // SEC-13
  });

  it("coach availability emits an optional time window (Lot C: fromTime/untilTime)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    await user.selectOptions(screen.getByLabelText("Coach"), "co1");
    await user.click(screen.getByRole("button", { name: "Mar" }));
    await user.type(screen.getByLabelText("Heure de début"), "20:00");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "COACH_AVAILABILITY", scopeTargetId: "co1", config: { unavailableDays: [2], fromTime: "20:00" } });
    expect(h.createMut.mock.calls[0][0].config).not.toHaveProperty("coachId"); // SEC-13
  });

  it("DAY emits forbiddenDays (the matrix key) whatever the ruleType", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.click(screen.getByRole("button", { name: "Mer" }));
    // default ruleType = PREFERRED (soft "avoid these days", ENG-10 fix engine-side)
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut).toHaveBeenCalledOnce();
    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "DAY", ruleType: "PREFERRED", config: { forbiddenDays: [3] } });
  });

  it("FACILITY emits preferredVenueId or forbiddenVenueId (matrix keys)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", config: { preferredVenueId: "v1" } });
  });

  it("FACILITY 'impose' emits a HARD forcedVenueId (matrix HONORED_HARD)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.selectOptions(screen.getByLabelText("Préférence"), "forced");
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", ruleType: "HARD", config: { forcedVenueId: "v1" } });
  });

  it("TIME 'Fini avant' emits a HARD maxEndTime (soft path can't honor an end-bound — ALIGN-04)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    // TIME is the default family. Setting an end-bound pins the rule HARD.
    await user.type(screen.getByLabelText("Fini avant"), "20:30");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "TIME", ruleType: "HARD", config: { maxEndTime: "20:30" } });
  });

  it("FACILITY 'au moins' emits a HARD minAtVenueId + minAtVenueCount (floor count — ALIGN-05)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    // "au moins N" is per-team → target a specific team (TEAM scope, the only shape the engine honors).
    await user.selectOptions(screen.getByLabelText("Cible"), "t1");
    await user.selectOptions(screen.getByLabelText("Préférence"), "min");
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", scope: "TEAM", ruleType: "HARD", config: { minAtVenueId: "v1", minAtVenueCount: 1 } });
  });

  it("DAY 'uniquement' emits HARD allowedDays (whitelist — only these days, ENG-16)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.selectOptions(screen.getByLabelText("Type de jour"), "only");
    await user.click(screen.getByRole("button", { name: "Ven" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "DAY", ruleType: "HARD", config: { allowedDays: [5] } });
  });

  it("DAY 'au moins une' emits HARD forcedDays (« at least one session on ONE of these days » — ALIGN-09)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    // "au moins une" pins HARD (no rule selector) and emits forcedDays, not allowedDays.
    await user.selectOptions(screen.getByLabelText("Type de jour"), "atLeast");
    expect(screen.queryByLabelText("Règle")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Dim" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "DAY", ruleType: "HARD", config: { forcedDays: [7] } });
    expect(h.createMut.mock.calls[0][0].config).not.toHaveProperty("allowedDays");
    expect(h.createMut.mock.calls[0][0].name).toBe("Toutes les équipes · au moins une séance dimanche");
  });

  it("names the day group after the polarity in force, so the gesture says its own sense (P4-58a)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));

    // ⚠ La couleur d'un jour coché est la MÊME dans les deux sens. Sans nom sur le
    // groupe, « Ven » activé se lit « vendredi retenu » aussi bien pour l'imposer que
    // pour l'éviter — et un lecteur d'écran n'annonce que « Ven ».
    expect(screen.getByRole("group", { name: "Jours à éviter" })).toBeInTheDocument();

    // « uniquement » = whitelist : la légende dit « Seuls jours autorisés » (le mensonge
    // « Jours imposés » corrigé — « imposés » est désormais le mot du mode « au moins une »).
    await user.selectOptions(screen.getByLabelText("Type de jour"), "only");
    expect(screen.getByRole("group", { name: "Seuls jours autorisés" })).toBeInTheDocument();

    // « au moins une » (ALIGN-09) : « au moins une séance l'un de ces jours » — l'agrégat sur
    // l'union, pas « chacun ». La légende le dit à la lettre.
    await user.selectOptions(screen.getByLabelText("Type de jour"), "atLeast");
    expect(screen.getByRole("group", { name: "Au moins une séance l'un de ces jours" })).toBeInTheDocument();

    // Et l'état de chaque jour est porté par le bouton lui-même, pas seulement par sa classe.
    await user.click(screen.getByRole("button", { name: "Ven" }));
    expect(screen.getByRole("button", { name: "Ven", pressed: true })).toBeInTheDocument();
  });

  it("keeps the target after a create so several constraints can be added in a row (F5)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.selectOptions(screen.getByLabelText("Cible"), "t1");
    await user.click(screen.getByRole("button", { name: "Mer" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut).toHaveBeenCalledOnce();
    // The target survives the add (only the value inputs are cleared).
    expect(screen.getByLabelText("Cible")).toHaveValue("t1");
  });
});

/**
 * Editing an EXISTING constraint reuses the same form (PUT). The critical
 * guard: loading a forced-venue/day rule back into the form and saving must
 * NOT downgrade forcedVenueId→preferredVenueId (a silent §7.1 semantics break).
 */
describe("ConstraintsStep — edit an existing constraint", () => {
  const forcedFacility: Constraint = {
    id: "c-sm4",
    name: "SM1 · impose Gymnase A",
    scope: "TEAM",
    scopeTargetId: "t1",
    family: "FACILITY",
    ruleType: "HARD",
    config: { forcedVenueId: "v1" },
    isActive: true,
  };

  beforeEach(() => {
    h.createMut.mockClear();
    h.updateMut.mockClear();
    h.list = [forcedFacility];
  });

  it("round-trips a forced venue without downgrading it to preferred, and PUTs the same id", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    // P4-107 (4ᵉ tranche) : la ligne ne rend plus le nom composé mais ses COLONNES — on
    // assert donc la cible, le verbe et la valeur, ce qui en dit plus que l'ancien texte.
    const forcedRow = screen.getByRole("row", { name: /SM1/ });
    expect(forcedRow).toHaveTextContent("impose");
    expect(forcedRow).toHaveTextContent("Gymnase A");

    // Enter edit mode → the form pre-fills from config.
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    expect(screen.getByLabelText("Préférence")).toHaveValue("forced");
    expect(screen.getByLabelText("Gymnase")).toHaveValue("v1");

    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    expect(h.createMut).not.toHaveBeenCalled();
    expect(h.updateMut).toHaveBeenCalledOnce();
    const arg = h.updateMut.mock.calls[0][0] as { id: string; body: Constraint };
    expect(arg.id).toBe("c-sm4");
    expect(arg.body.config).toHaveProperty("forcedVenueId", "v1");
    expect(arg.body.config).not.toHaveProperty("preferredVenueId");
    expect(arg.body.ruleType).toBe("HARD");
  });

  it("softening a forced venue to 'préfère' emits a PREFERRED rule, not the inherited HARD (F1)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Switch impose → préfère: the inherited HARD must NOT leak (HARD preferredVenueId
    // is still a forced venue engine-side — the opposite of what the user wants).
    await user.selectOptions(screen.getByLabelText("Préférence"), "preferred");
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    const arg = h.updateMut.mock.calls[0][0] as { body: Constraint };
    expect(arg.body.ruleType).toBe("PREFERRED");
    expect(arg.body.config).toEqual({ preferredVenueId: "v1" });
  });

  it("persists an edited venue choice under the forced key", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    const arg = h.updateMut.mock.calls[0][0] as { body: Constraint };
    expect(arg.body.config).toEqual({ forcedVenueId: "v2" });
  });

  it("round-trips a forcedDays 'au moins une' rule without downgrading it (ALIGN-09)", async () => {
    const user = userEvent.setup();
    h.list = [
      {
        id: "c-atleast-day",
        name: "SM1 · au moins une séance vendredi",
        scope: "TEAM",
        scopeTargetId: "t1",
        family: "DAY",
        ruleType: "HARD",
        config: { forcedDays: [5] },
        isActive: true,
      },
    ];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // forcedDays loads as the "au moins une" mode (HARD-pinned, no rule selector), day preselected.
    expect(screen.getByLabelText("Type de jour")).toHaveValue("atLeast");
    expect(screen.getByRole("button", { name: "Ven", pressed: true })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    const arg = h.updateMut.mock.calls[0][0] as { body: Constraint };
    expect(arg.body.config).toEqual({ forcedDays: [5] });
    expect(arg.body.config).not.toHaveProperty("allowedDays");
    expect(arg.body.ruleType).toBe("HARD");
  });
});

/**
 * P4-95 — la boucle de correction depuis le planning atterrit SUR la contrainte : sa ligne est
 * surlignée ET amenée à l'écran. Le surlignage vaut aussi pour le stylo manuel ; le scroll de la
 * LIGNE n'a lieu QUE sur consommation du deep-link (le stylo garde son seul scroll-formulaire P4-66).
 */
describe("ConstraintsStep — P4-95 : la ligne ciblée est surlignée et amenée à l'écran", () => {
  const timeRule: Constraint = { id: "c-time", name: "Toutes les équipes · pas après 21:00", scope: "CLUB", scopeTargetId: null, family: "TIME", ruleType: "PREFERRED", config: { maxStartTime: "21:00" }, isActive: true };

  let scrolled: Element[];
  let originalScroll: typeof Element.prototype.scrollIntoView;

  beforeEach(() => {
    useWizardStore.getState().exitPeriodMode();
    h.list = [timeRule];
    scrolled = [];
    originalScroll = Element.prototype.scrollIntoView;
    // On capture le `this` de chaque scroll pour distinguer la LIGNE (data-constraint-id) du
    // FORMULAIRE. jsdom n'implémente pas scrollIntoView.
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this);
    };
  });

  afterEach(() => {
    Element.prototype.scrollIntoView = originalScroll;
    useWizardStore.getState().exitPeriodMode();
  });

  const runRafImmediately = () => vi.spyOn(window, "requestAnimationFrame").mockImplementation((cb) => (cb(0), 0));

  it("deep-link ?edit= → la ligne cible est surlignée ET scrollée (block center)", async () => {
    const raf = runRafImmediately();
    const { container } = renderWithProviders(<ConstraintsStep />, { route: "/wizard?step=constraints&edit=c-time&from=planning" });

    // La ligne cible existe et porte la marque d'édition (surlignage accent).
    // ⚠ P4-107 (4ᵉ tranche) : on ne la cherche plus par son nom composé — la ligne le rend
    // désormais en COLONNES (cible / règle / valeur). On la cherche par son identifiant,
    // qui est justement ce que le deep-link vise.
    const li = await waitFor(() => {
      const found = container.querySelector('[data-constraint-id="c-time"]');
      expect(found).not.toBeNull();

      return found;
    });
    expect(li).not.toBeNull();
    expect(li?.className).toContain("ring-accent");
    // Et la LIGNE elle-même a été amenée à l'écran (pas seulement le formulaire).
    expect(scrolled).toContain(li);
    raf.mockRestore();
  });

  it("stylo MANUEL : la ligne se surligne aussi, mais SEUL le formulaire est scrollé (pas la ligne)", async () => {
    const user = userEvent.setup();
    const raf = runRafImmediately();
    const { container } = renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Modifier" }));

    const li = container.querySelector('[data-constraint-id="c-time"]');
    expect(li?.className).toContain("ring-accent");
    // Le formulaire (P4-66) a bien été ramené…
    expect(scrolled.length).toBeGreaterThan(0);
    // …mais la LIGNE, elle, n'est PAS scrollée par le stylo manuel (deep-link uniquement).
    expect(scrolled).not.toContain(li);
    raf.mockRestore();
  });

  it("edit= introuvable → atterrissage propre : aucune ligne surlignée, aucun crash", async () => {
    const { container } = renderWithProviders(<ConstraintsStep />, { route: "/wizard?step=constraints&edit=inconnue&from=planning" });

    // La contrainte s'affiche, mais AUCUNE ligne n'est en édition.
    await waitFor(() => expect(container.querySelector('[data-constraint-id="c-time"]')).not.toBeNull());
    expect(container.querySelector('[data-constraint-id="c-time"]')?.className).not.toContain("ring-accent");
  });
});

/**
 * Réserver tab is now a per-venue slot grid: click a slot → modal to pin/remove
 * teams (server-backed Reservation entity, base/overlay). The rank-sorted summary
 * moved to the Récap step. These lock the grid+modal interaction + the team-cap.
 */
describe("ConstraintsStep — Réserver tab (slot grid + modal)", () => {
  beforeEach(() => {
    h.resCreate.mockClear();
    h.resDelete.mockClear();
    h.reservations = [];
    h.teamCoaches = []; // sans ça le cas « coach déjà ailleurs » contaminerait les suivants
    h.coachesPending = false;
    h.coachesFailed = false;
  });

  const openSlot = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]); // the family tab
    await user.click(screen.getByRole("button", { name: /Gymnase A.*cliquer pour gérer/ })); // the slot in the grid
  };

  it("clicking a reserved slot opens the modal with a removable team → useDeleteReservation", async () => {
    h.reservations = [{ id: "r1", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120 }];
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    const remove = screen.getByRole("button", { name: "Retirer SM1" });
    await user.click(remove);
    // Le retrait attend la validation lui aussi (décision fondateur) : une modale à
    // moitié transactionnelle serait plus déroutante que les deux options pures.
    expect(h.resDelete).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Valider" }));
    expect(h.resDelete).toHaveBeenCalledWith("r1");
  });

  // Hors période : la réservation est de BASE, son ancre est nulle (structure partagée,
  // inv. 6) — seul le NOM du champ change au lot C3, pas la sémantique.
  it("adding a team from the modal → useCreateReservation with the slot payload + base anchor (null)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    // Picker is rank-ordered (Fanion=S before SM1=B); pick the fanion.
    await user.selectOptions(screen.getByLabelText("Ajouter une équipe"), "t2");
    // P2-9 PR C : sélectionner ne réserve plus — la modale est transactionnelle, rien ne
    // part avant « Valider ». C'est ce qui laisse le contrôle s'interposer entre le choix
    // et l'écriture (décision fondateur : « le validator intervient au moment du ok »).
    expect(h.resCreate).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Valider" }));
    expect(h.resCreate).toHaveBeenCalledWith(expect.objectContaining({ teamId: "t2", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120, schedulePlanId: null }));
  });

  // P2-9 PR C — le cœur du lot : affecter une équipe dont le coach MAIN est déjà ailleurs
  // à la même heure est une impossibilité PHYSIQUE que le solveur ne peut pas rattraper (un
  // verrou HARD est pré-placé hors modèle). On le dit AU CLIC, avec le motif, plutôt que de
  // laisser le récap refuser plus tard sans que le gestionnaire comprenne pourquoi.
  it("refuse au clic une équipe dont le coach MAIN est déjà ailleurs, en disant pourquoi", async () => {
    // SM1 (t1) est déjà réservée le même jour, à la même heure, dans un AUTRE gymnase (v2),
    // et partage son coach MAIN avec Fanion (t2).
    h.reservations = [{ id: "rx", calendarEntryId: null, teamId: "t1", venueId: "v2", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120 }];
    h.teamCoaches = [
      { id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" },
      { id: "tc2", teamId: "t2", coachId: "c1", role: "MAIN" },
    ];
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    await user.selectOptions(screen.getByLabelText("Ajouter une équipe"), "t2");

    // Le message nomme l'équipe déjà coachée et l'heure : sans ça le gestionnaire sait
    // qu'on refuse, pas ce qu'il doit changer.
    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent(/SM1/);
    expect(alert).toHaveTextContent(/20:30/);
    // Et rien n'est mis au brouillon : « Valider » reste inerte.
    expect(screen.getByRole("button", { name: "Valider" })).toBeDisabled();
  });

  // Revue #334 — FAIL-OPEN : sans les liens équipe→coach, la règle ne trouve AUCUN conflit
  // (Map vide ⇒ aucun coach MAIN). Un repli `= []` éteignait donc le contrôle en silence
  // pendant le chargement. On ferme la saisie plutôt que d'autoriser en aveugle.
  it("ferme la saisie tant que les liens coach ne sont pas connus (jamais de fail-open)", async () => {
    h.teamCoaches = undefined;
    h.coachesPending = true;
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    expect(screen.queryByLabelText("Ajouter une équipe")).not.toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent(/Vérification des coachs/);
  });

  // Revue #334 — un lot à moitié appliqué ne doit ni se taire, ni se rejouer : la
  // suppression déjà passée repartirait en 404, ou une création se dupliquerait
  // (reservation n'a aucune contrainte d'unicité).
  it("sur échec partiel : le dit, garde la modale ouverte, et ne rejoue pas ce qui est passé", async () => {
    h.reservations = [{ id: "r1", calendarEntryId: null, teamId: "t1", venueId: "v1", dayOfWeek: 2, startTime: "20:30", durationMinutes: 120 }];
    h.resDelete.mockResolvedValueOnce(undefined); // le retrait passe…
    h.resCreate.mockRejectedValueOnce(new Error("réseau")); // …la création échoue
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    await user.click(screen.getByRole("button", { name: "Retirer SM1" }));
    await user.selectOptions(screen.getByLabelText("Ajouter une équipe"), "t2");
    await user.click(screen.getByRole("button", { name: "Valider" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/n'a pas pu être enregistrée/);
    expect(screen.getByRole("button", { name: "Valider" })).toBeInTheDocument(); // reste ouverte

    // Reprise : seule la création restante repart, le retrait déjà passé n'est PAS rejoué.
    h.resCreate.mockResolvedValueOnce(undefined);
    await user.click(screen.getByRole("button", { name: "Valider" }));
    expect(h.resDelete).toHaveBeenCalledTimes(1);
    expect(h.resCreate).toHaveBeenCalledTimes(2);
  });

  it("hides a team that reached its sessionsPerWeek from the picker", async () => {
    // t2 (Fanion) has 2 sessions and 2 reservations elsewhere → maxed, absent from the picker.
    h.reservations = [
      { id: "ra", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90 },
      { id: "rb", calendarEntryId: null, teamId: "t2", venueId: "v1", dayOfWeek: 4, startTime: "18:00", durationMinutes: 90 },
    ];
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await openSlot(user);
    const picker = screen.getByLabelText("Ajouter une équipe");
    expect(within(picker).queryByRole("option", { name: "Fanion" })).toBeNull();
    expect(within(picker).getByRole("option", { name: "SM1" })).toBeInTheDocument();
  });
});


// ── #9 (fondateur 2026-07-24) : la section héritée vit DANS l'onglet de sa famille ──
describe("ConstraintsStep — inherited section lives inside the family tabs (period mode)", () => {
  beforeEach(() => {
    h.list = [];
    periodAnchorReady.value = true;
    useWizardStore.getState().startPeriodMode("entry-9");
  });
  afterEach(() => {
    useWizardStore.getState().exitPeriodMode();
  });

  it("renders the inherited section inside the ACTIVE family tab and follows tab changes", async () => {
    renderWithProviders(<ConstraintsStep />);

    // Onglet par défaut = Horaires (TIME).
    expect(screen.getByTestId("inherited-section")).toHaveTextContent("TIME");

    await userEvent.click(screen.getByRole("button", { name: "Jours" }));
    expect(screen.getByTestId("inherited-section")).toHaveTextContent("DAY");

    // Onglet Réserver : la section est MASQUÉE (aria-hidden) mais reste MONTÉE — la
    // démonter perdrait la sérialisation des écritures d'override en vol (revue #284 R1).
    await userEvent.click(screen.getByRole("button", { name: /Réserver/ }));
    const section = screen.getByTestId("inherited-section");
    expect(section).toBeInTheDocument();
    expect(section.parentElement).toHaveAttribute("aria-hidden", "true");
    expect(section.parentElement).toHaveClass("hidden");
  });

  it("keeps the inherited section MOUNTED across tab switches (inflight write serialization)", async () => {
    renderWithProviders(<ConstraintsStep />);
    const before = screen.getByTestId("inherited-section");
    await userEvent.click(screen.getByRole("button", { name: /Réserver/ }));
    await userEvent.click(screen.getByRole("button", { name: "Horaires" }));
    // Même noeud DOM de bout en bout : aucun démontage/remontage entre les onglets.
    expect(screen.getByTestId("inherited-section")).toBe(before);
  });


  it("réserve sur la grille de la PÉRIODE, jamais sur celle de la saison", async () => {
    // Revue #8 round 4 — l'onglet listait les créneaux de SAISON tout en écrivant la
    // réservation sur le plan de la période. C'était sain tant que le backend unionnait
    // les deux couches ; il ne le fait plus. Une réservation posée sur un créneau absent
    // de la grille de la période devient un épinglage orphelin, et la génération de cette
    // période est alors refusée DÉFINITIVEMENT (OrphanPinGuard).
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]);

    // Gymnase A est sélectionné par défaut et porte le créneau que seule la SAISON
    // possède : sur la bonne couche, sa grille est vide.
    expect(screen.queryByRole("button", { name: /Gymnase A.*cliquer pour gérer/ })).toBeNull();

    // Gymnase B porte celui de la période : c'est lui qu'on doit pouvoir réserver.
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");
    expect(screen.getByRole("button", { name: /Gymnase B.*cliquer pour gérer/ })).toBeInTheDocument();
  });

  it("waits for the period plan before offering the reservation panel (no write on the base plan)", async () => {
    periodAnchorReady.value = false;
    renderWithProviders(<ConstraintsStep />);
    await userEvent.click(screen.getByRole("button", { name: /Réserver/ }));
    expect(screen.getByText(/Chargement du planning de la période/)).toBeInTheDocument();
  });

  // PR2 — l'onglet Bien-être passe sous la MÊME porte d'ancre que « Réserver » : tant que le plan
  // de la période n'est pas résolu, le panneau n'écrit pas sur le socle du club.
  it("Bien-être attend le plan de la période avant d'offrir le panneau (jamais le socle)", async () => {
    periodAnchorReady.value = false;
    renderWithProviders(<ConstraintsStep />);
    await userEvent.click(screen.getByRole("button", { name: "Bien-être" }));
    expect(screen.getByText(/Chargement du planning de la période/)).toBeInTheDocument();
  });

  it("Bien-être : plan résolu → les règles réglables sont rendues", async () => {
    periodAnchorReady.value = true;
    renderWithProviders(<ConstraintsStep />);
    await userEvent.click(screen.getByRole("button", { name: "Bien-être" }));
    expect(screen.getByRole("group", { name: "Intensité — Chaque coach garde un jour de repos" })).toBeInTheDocument();
  });

  it("renders NO inherited section outside period mode", () => {
    useWizardStore.getState().exitPeriodMode();
    renderWithProviders(<ConstraintsStep />);
    expect(screen.queryByTestId("inherited-section")).toBeNull();
  });
});

/**
 * P2-15 round 2 — les trois usages d'une liste, en mode PÉRIODE.
 *
 * CHOISIR n'offre que l'actif · NOMMER garde la liste complète · ATTEINDRE laisse arriver
 * jusqu'au geste correctif SANS rouvrir le geste fautif. Le round 1 avait posé la porte de
 * sortie « gymnase réservé » sans un seul test : la retirer laissait la suite verte.
 */
describe("ConstraintsStep — période : choisir, nommer, atteindre", () => {
  beforeEach(() => {
    h.list = [];
    h.reservations = [];
    periodAnchorReady.value = true;
    activeVenuesState.venues = [{ id: "v1", name: "Gymnase A", isActive: true }, { id: "v2", name: "Gymnase B", isActive: true }];
    activeVenuesState.disabledIds = new Set();
    activeTeamsState.pausedIds = new Set();
    useWizardStore.getState().startPeriodMode("entry-15");
  });
  afterEach(() => {
    useWizardStore.getState().exitPeriodMode();
  });

  // CHOISIR — une équipe en pause sort du payload solveur : l'épingler est un geste sans
  // effet que RIEN n'attrape (OrphanPinGuard ne regarde que salle/jour/heure), donc la
  // génération PASSE et l'équipe n'a de séance nulle part.
  it("n'offre pas une équipe en pause, ni en cible de contrainte ni en réservation", async () => {
    const user = userEvent.setup();
    activeTeamsState.pausedIds = new Set(["t2"]);
    renderWithProviders(<ConstraintsStep />);

    const target = screen.getByRole("combobox", { name: "Cible" });
    expect(within(target).queryByRole("option", { name: "Fanion" })).toBeNull();
    expect(within(target).getByRole("option", { name: "SM1" })).toBeInTheDocument();

    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]);
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");
    await user.click(screen.getByRole("button", { name: /Gymnase B.*cliquer pour gérer/ }));
    const picker = screen.getByLabelText("Ajouter une équipe");
    expect(within(picker).queryByRole("option", { name: "Fanion" })).toBeNull();
  });

  // ATTEINDRE — un gymnase désactivé qui porte ENCORE une réservation reste joignable,
  // sinon `OrphanPinGuard` refuse la génération (422) en nommant un gymnase que l'écran
  // capable d'enlever l'épinglage ne montre plus. La porte doit s'ouvrir dans UN sens.
  it("laisse joindre un gymnase désactivé qui porte encore une réservation, marqué", async () => {
    const user = userEvent.setup();
    activeVenuesState.venues = [{ id: "v1", name: "Gymnase A", isActive: true }];
    activeVenuesState.disabledIds = new Set(["v2"]);
    h.reservations = [{ id: "r9", calendarEntryId: null, teamId: "t1", venueId: "v2", dayOfWeek: 4, startTime: "19:00", durationMinutes: 90 }];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]);
    const venuePicker = screen.getByLabelText("Gymnase");
    expect(within(venuePicker).getByRole("option", { name: /Gymnase B \(désactivé pour cette période\)/ })).toBeInTheDocument();
  });

  // …et PAS dans l'autre : le round 1 réadmettait une grille pleinement éditable, donc on
  // pouvait y créer un NOUVEL épinglage orphelin et repartir en 422.
  it("ferme l'ajout sur un gymnase désactivé — on ne peut qu'y retirer", async () => {
    const user = userEvent.setup();
    activeVenuesState.venues = [{ id: "v1", name: "Gymnase A", isActive: true }];
    activeVenuesState.disabledIds = new Set(["v2"]);
    h.reservations = [{ id: "r9", calendarEntryId: null, teamId: "t1", venueId: "v2", dayOfWeek: 4, startTime: "19:00", durationMinutes: 90 }];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]);
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");
    await user.click(screen.getByRole("button", { name: /Gymnase B.*cliquer pour gérer/ }));

    expect(screen.queryByLabelText("Ajouter une équipe")).toBeNull();
    expect(screen.getByText(/on ne peut plus y ajouter d'équipe/)).toBeInTheDocument();
    // Retirer reste possible : c'est la raison d'être de la porte.
    expect(screen.getByRole("button", { name: "Retirer SM1" })).toBeInTheDocument();
  });

  // La lecture qui alimente cette porte suit la MÊME garde que le panneau : `null` est à la
  // fois l'ancre base légitime et un plan non résolu. Un `enabled` codé en dur allait
  // chercher les réservations du SOCLE et les publiait dans le cache partagé « base ».
  it("ne lit pas les réservations tant que l'ancre de la période n'est pas résolue", () => {
    periodAnchorReady.value = false;
    renderWithProviders(<ConstraintsStep />);

    expect(reservationArgs.enabled).toBe(false);
  });

  // NOMMER — un picker doit toujours pouvoir afficher SA PROPRE valeur : sans ça le select
  // rend blanc sur une contrainte qui nomme pourtant un gymnase, et « combler le trou »
  // repointe une règle HARD ailleurs.
  it("garde le gymnase d'une contrainte en édition dans le select, même désactivé", async () => {
    const user = userEvent.setup();
    activeVenuesState.venues = [{ id: "v1", name: "Gymnase A", isActive: true }];
    activeVenuesState.disabledIds = new Set(["v2"]);
    h.list = [{ id: "c1", name: "SM1 · impose Gymnase B", scope: "TEAM", scopeTargetId: "t1", family: "FACILITY", ruleType: "HARD", config: { forcedVenueId: "v2" }, isActive: true } as unknown as Constraint];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.click(screen.getByRole("button", { name: /modifier/i }));
    const picker = screen.getByLabelText("Gymnase");
    expect((picker as HTMLSelectElement).value).toBe("v2");
    expect(within(picker).getByRole("option", { name: /Gymnase B \(désactivé pour cette période\)/ })).toBeInTheDocument();
  });

  it("ramène le formulaire à l'écran quand on édite une ligne éloignée (P4-66)", async () => {
    // Retour fondateur 2026-08-02 : « le focus n'est pas automatique sur la ligne
    // d'édition, donc je dois scroller ». jsdom n'implémente pas scrollIntoView —
    // on l'espionne : ce qui compte est QUE le formulaire soit ramené, pas comment
    // le navigateur l'anime.
    const user = userEvent.setup();
    const scrollIntoView = vi.fn();
    Element.prototype.scrollIntoView = scrollIntoView;
    // requestAnimationFrame: exécuter tout de suite pour ne pas attendre une frame.
    const raf = vi.spyOn(window, "requestAnimationFrame").mockImplementation((cb) => {
      cb(0);
      return 0;
    });

    h.list = [{ id: "c1", name: "SM1 · impose Gymnase A", scope: "TEAM", scopeTargetId: "t1", family: "FACILITY", ruleType: "HARD", config: { forcedVenueId: "v1" }, isActive: true } as unknown as Constraint];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    expect(scrollIntoView).not.toHaveBeenCalled(); // rien ne bouge tant qu'on n'édite pas

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    expect(scrollIntoView).toHaveBeenCalled();

    raf.mockRestore();
  });
  it("« Base » puis « Bien-être » sont les DEUX PREMIERS onglets, inactifs par défaut (P2-28)", () => {
    // Décision fondateur : les règles du système vivent dans deux onglets à gauche d'« Horaires »,
    // pas dans un accordéon. Par défaut on est sur Horaires : ni les règles du produit ni les
    // réglables ne sont montées.
    renderWithProviders(<ConstraintsStep />);

    const tabs = screen.getAllByRole("button").map((b) => b.textContent);
    expect(tabs.slice(0, 2)).toEqual(["Base", "Bien-être"]);
    expect(screen.queryByText(PRODUCT_RULES[0].title)).toBeNull();
    expect(screen.queryByRole("group", { name: `Intensité — ${WELLBEING_RULES[0].title}` })).toBeNull();
  });

  it("l'onglet « Base » montre les règles immuables en LECTURE SEULE (aucun réglage) (P2-28)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Base" }));

    for (const rule of PRODUCT_RULES) {
      expect(screen.getByText(rule.title)).toBeInTheDocument();
    }
    // Aucun contrôle d'intensité ici : les règles réglables vivent dans l'onglet Bien-être.
    expect(screen.queryByRole("group", { name: `Intensité — ${WELLBEING_RULES[0].title}` })).toBeNull();
  });

  it("l'onglet « Bien-être » montre les 4 règles réglables avec leur sélecteur d'intensité (P2-28)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Bien-être" }));

    for (const meta of WELLBEING_RULES) {
      expect(screen.getByRole("group", { name: `Intensité — ${meta.title}` })).toBeInTheDocument();
    }
    // Les règles immuables ne sont PAS répétées ici.
    expect(screen.queryByText(PRODUCT_RULES[0].title)).toBeNull();
  });

  it("GÈLE le texte des règles du système — il doit rester d'accord avec le moteur (P2-28)", () => {
    // ⚠ Ce gel n'est pas de la cosmétique. Chaque ligne AFFIRME un comportement du solveur ;
    // la réécrire à la légère fait mentir le produit. Si vous changez ces tableaux, allez
    // d'abord relire `engine/app/solver/constraints.py`.
    expect(PRODUCT_RULES.map((r) => r.id)).toEqual([
      "venue-capacity",
      "coach-two-venues",
      "coach-player",
      "team-overlap",
      "one-session-per-day",
      "reservations-honored",
      "team-minimum-target",
    ]);

    // Le même gymnase est AUTORISÉ — la formulation inverse était affichée avant P4-55.
    const coachRule = PRODUCT_RULES.find((r) => "coach-two-venues" === r.id);
    expect(coachRule?.detail).toContain("MÊME gymnase");
    expect(coachRule?.detail).toContain("autorisées");

    // Aucune règle du produit ne doit renvoyer vers un réglage inexistant.
    const perDay = PRODUCT_RULES.find((r) => "one-session-per-day" === r.id);
    expect(perDay?.detail).not.toMatch(/sauf si|autoris/i);

    // Le minimum de séances est une CIBLE, pas une loi (honnêteté du produit).
    const minimum = PRODUCT_RULES.find((r) => "team-minimum-target" === r.id);
    expect(minimum?.detail).toMatch(/cible/i);

    // Les 4 règles réglables, dans l'ordre du contrat moteur 2.7.
    expect(WELLBEING_RULES.map((r) => r.ruleKey)).toEqual([
      "coachRestDay",
      "salarieDistribution",
      "maxConsecutiveSessions",
      // P2-42 — la 5e, ajoutée DÉLIBÉRÉMENT à ce gel : elle affirme au gestionnaire qu'une
      // équipe ne s'entraînera pas N jours de suite, et `test_consecutive_days.py` prouve
      // côté moteur que c'est vrai. Le gel n'est pas là pour interdire l'ajout, il est là
      // pour qu'aucun ajout ne passe SANS qu'on relise ce que le solveur fait vraiment.
      "maxConsecutiveDays",
      "ageAscending",
    ]);
  });
});

/**
 * P2-28 — la boucle S1 : un diagnostic « règle assouplie » du planning atterrit ici en ciblant
 * SA règle (`?rule=<ruleKey>`). Le panneau s'ouvre, surligne la règle et l'amène à l'écran.
 */
describe("ConstraintsStep — atterrissage d'un diagnostic sur une règle (P2-28)", () => {
  let scrolled: Element[];
  let originalScroll: typeof Element.prototype.scrollIntoView;

  beforeEach(() => {
    useWizardStore.getState().exitPeriodMode();
    h.list = [];
    h.implicitRules = RESOLVED_IMPLICIT_RULES;
    scrolled = [];
    originalScroll = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this);
    };
  });
  afterEach(() => {
    Element.prototype.scrollIntoView = originalScroll;
    useWizardStore.getState().exitPeriodMode();
  });

  it("?rule=<ruleKey> → bascule sur l'onglet Bien-être, règle surlignée ET amenée à l'écran", () => {
    const raf = vi.spyOn(window, "requestAnimationFrame").mockImplementation((cb) => (cb(0), 0));
    const { container } = renderWithProviders(<ConstraintsStep />, { route: "/wizard?step=constraints&rule=maxConsecutiveSessions&from=planning" });

    // L'onglet Bien-être est ACTIF (le panneau est monté → la ligne existe).
    const row = container.querySelector('[data-rule-key="maxConsecutiveSessions"]');
    expect(row).not.toBeNull();
    expect(row?.className).toContain("ring-accent");
    expect(scrolled).toContain(row);
    raf.mockRestore();
  });

  it("sans ?rule= l'onglet Bien-être n'est pas actif (panneau non monté)", () => {
    const { container } = renderWithProviders(<ConstraintsStep />, { route: "/wizard?step=constraints" });

    expect(container.querySelector('[data-rule-key="maxConsecutiveSessions"]')).toBeNull();
  });

  it("?rule= inconnu → atterrissage propre : onglet Bien-être non forcé, aucun crash", () => {
    const { container } = renderWithProviders(<ConstraintsStep />, { route: "/wizard?step=constraints&rule=pasUneRegle&from=planning" });

    // Clé inconnue : on ne bascule pas, le panneau n'est pas monté, rien n'est surligné.
    expect(container.querySelector('[data-rule-key="maxConsecutiveSessions"]')).toBeNull();
  });
});

/**
 * D2 (P2-22) — l'onglet Réserver honore les fermetures de gymnase de la période. Un créneau
 * d'un jour fermé se bloque à l'AJOUT tout en restant atteignable pour le RETRAIT (l'épinglage
 * orphelin bloque la génération) ; un échec de lecture des fermetures ferme la grille (fail-closed).
 */
describe("ConstraintsStep — Réserver : fermetures de gymnase (D2)", () => {
  beforeEach(() => {
    h.reservations = [];
    h.teamCoaches = [];
    h.coachesPending = false;
    h.coachesFailed = false;
    periodAnchorReady.value = true;
    activeVenuesState.venues = [{ id: "v1", name: "Gymnase A", isActive: true }, { id: "v2", name: "Gymnase B", isActive: true }];
    activeVenuesState.disabledIds = new Set();
    activeTeamsState.pausedIds = new Set();
    entryConflictsState.data = { entryId: "e", venueIds: [], conflicts: [], closures: [], seasonPlanChosen: true };
    entryConflictsState.isError = false;
    calendarEntryState.data = { parentEntryId: null };
    useWizardStore.getState().startPeriodMode("entry-closures");
  });
  afterEach(() => {
    useWizardStore.getState().exitPeriodMode();
    entryConflictsState.data = { entryId: "e", venueIds: [], conflicts: [], closures: [], seasonPlanChosen: true };
    entryConflictsState.isError = false;
  });

  it("bloque l'AJOUT sur un créneau fermé sans réservation (bouton de grille désactivé)", async () => {
    const user = userEvent.setup();
    // Le créneau de la période (v2, jeudi 19:00) tombe un jour fermé.
    entryConflictsState.data = { entryId: "e", venueIds: ["v2"], conflicts: [], closures: [{ constraintId: "cc", venueId: "v2", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [4] }], seasonPlanChosen: true };
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]);
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");

    const slot = screen.getByRole("button", { name: /Jeu 19:00 · Gymnase B/ });
    expect(slot).toBeDisabled();
    expect(slot).toHaveAccessibleName(/Indispo du 1\/5 au 10\/5 — Travaux/);
  });

  it("garde atteignable un créneau fermé RÉSERVÉ : la modale interdit l'ajout, permet le retrait", async () => {
    const user = userEvent.setup();
    entryConflictsState.data = { entryId: "e", venueIds: ["v2"], conflicts: [], closures: [{ constraintId: "cc", venueId: "v2", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [4] }], seasonPlanChosen: true };
    h.reservations = [{ id: "r9", calendarEntryId: null, teamId: "t1", venueId: "v2", dayOfWeek: 4, startTime: "19:00", durationMinutes: 90 }];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]);
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");
    await user.click(screen.getByRole("button", { name: /Jeu 19:00 · Gymnase B/ }));

    // Ajout fermé : pas de picker, un message qui dit pourquoi — aligné au refus serveur
    // (P2-37 D3) : « fermé ce jour-là » + le titre et les bornes de la fermeture.
    expect(screen.queryByLabelText("Ajouter une équipe")).toBeNull();
    expect(screen.getByText(/fermé ce jour-là.*Indispo du 1\/5 au 10\/5 — Travaux/)).toBeInTheDocument();
    // Retrait ouvert : c'est la raison d'être de la porte.
    expect(screen.getByRole("button", { name: "Retirer SM1" })).toBeInTheDocument();
  });

  // P2-37 D6 — un gymnase ENTIÈREMENT fermé : le refus est d'un cran plus fort qu'un jour fermé,
  // et la modale le dit comme le serveur (« indisponible sur toute la période » + titre/bornes),
  // pas « fermé ce jour-là » ni « désactivé ». Le retrait reste ouvert (geste correctif).
  it("gymnase entièrement fermé : la modale dit « indisponible sur toute la période » (aligné serveur), retrait ouvert", async () => {
    const user = userEvent.setup();
    entryConflictsState.data = { entryId: "e", venueIds: ["v2"], conflicts: [], closures: [{ constraintId: "cc", venueId: "v2", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [4] }], fullyClosedVenueIds: ["v2"], seasonPlanChosen: true };
    // `useActiveVenues` range un gymnase entièrement fermé dans `disabledIds` (le mock le reçoit tel quel).
    activeVenuesState.disabledIds = new Set(["v2"]);
    h.reservations = [{ id: "r9", calendarEntryId: null, teamId: "t1", venueId: "v2", dayOfWeek: 4, startTime: "19:00", durationMinutes: 90 }];
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]);
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");
    await user.click(screen.getByRole("button", { name: /Jeu 19:00 · Gymnase B/ }));

    expect(screen.queryByLabelText("Ajouter une équipe")).toBeNull();
    // Le motif SERVEUR, pas « fermé ce jour-là » ni « désactivé ».
    expect(screen.getByText(/indisponible sur toute la période.*Indispo du 1\/5 au 10\/5 — Travaux/)).toBeInTheDocument();
    expect(screen.queryByText(/fermé ce jour-là/)).toBeNull();
    // Retrait ouvert : on n'efface rien d'office, mais le geste correctif reste possible.
    expect(screen.getByRole("button", { name: "Retirer SM1" })).toBeInTheDocument();
  });

  it("FAIL-CLOSED : un échec de lecture des fermetures ferme la grille (pas de grille faussement réservable)", async () => {
    const user = userEvent.setup();
    entryConflictsState.data = undefined;
    entryConflictsState.isError = true;
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getAllByRole("button", { name: /Réserver/ })[0]);

    expect(screen.getByText(/Impossible de vérifier les fermetures de gymnase/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /cliquer pour gérer/ })).toBeNull();
  });
});

/**
 * P2-59 — modèle FAIT/GENÈSE (miroir de CalendarEntry::datedConstraintSourceIds). Sur l'écran
 * d'une SEMAINE enfant :
 *  - ses GENÈSES (datées de la semaine) sont éditables — modifier / supprimer ;
 *  - les FAITS de sa MÈRE (datés de l'incident) sont AFFICHÉS mais badgés « Toutes les semaines
 *    de {mère} », SANS action : on ne les règle qu'à la source.
 * Créer une contrainte depuis la semaine l'attache à LA SEMAINE (jamais à la mère) — sinon deux
 * semaines sœurs partageraient toutes leurs règles, ce que le modèle d'indépendance refuse. Une
 * entrée RACINE n'a pas de mère : ses datées sont ses genèses, aucun fait hérité, aucun badge.
 */
describe("ConstraintsStep — genèses de la semaine vs faits de la mère (P2-59)", () => {
  const GENESIS = { id: "g1", name: "Fanion · pas lundi", scope: "TEAM", scopeTargetId: "t2", family: "DAY", ruleType: "HARD", config: { forbiddenDays: [1] }, isActive: true } as Constraint;
  const FACT = { id: "f1", name: "SM1 · pas dimanche", scope: "TEAM", scopeTargetId: "t1", family: "DAY", ruleType: "HARD", config: { forbiddenDays: [7] }, isActive: true } as Constraint;

  beforeEach(() => {
    h.list = [];
    h.byEntry = {};
    calendarEntryById.map = {};
    h.createMut.mockClear();
    periodAnchorReady.value = true;
    activeVenuesState.venues = [{ id: "v1", name: "Gymnase A", isActive: true }, { id: "v2", name: "Gymnase B", isActive: true }];
    activeVenuesState.disabledIds = new Set();
    activeTeamsState.pausedIds = new Set();
    entryConflictsState.data = { entryId: "e", venueIds: [], conflicts: [], closures: [], seasonPlanChosen: true };
    entryConflictsState.isError = false;
    // La semaine "child-week" pend à la mère "mother-1" (titrée « Vacances d'été »).
    calendarEntryState.data = { parentEntryId: "mother-1" };
    calendarEntryById.map = { "mother-1": { parentEntryId: null, title: "Vacances d'été" } };
    h.byEntry = { "child-week": [GENESIS], "mother-1": [FACT] };
    useWizardStore.getState().startPeriodMode("child-week");
  });
  afterEach(() => {
    useWizardStore.getState().exitPeriodMode();
    h.byEntry = {};
    calendarEntryById.map = {};
    calendarEntryState.data = { parentEntryId: null };
  });

  it("crée une datée en la rattachant à LA SEMAINE (child), jamais à la mère", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.click(screen.getByRole("button", { name: "Mer" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0].calendarEntryId).toBe("child-week");
  });

  it("liste la genèse (éditable) et le fait de la mère (badgé, sans action)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);
    await user.click(screen.getByRole("button", { name: "Jours" }));

    const genesisRow = document.querySelector('[data-constraint-id="g1"]') as HTMLElement;
    const factRow = document.querySelector('[data-constraint-id="f1"]') as HTMLElement;
    expect(genesisRow).not.toBeNull();
    expect(factRow).not.toBeNull();

    // Genèse : modifiable et supprimable ici.
    expect(within(genesisRow).getByRole("button", { name: "Modifier" })).toBeInTheDocument();
    expect(within(genesisRow).getByRole("button", { name: "Supprimer" })).toBeInTheDocument();

    // Fait : badge lisible « Toutes les semaines de {mère} », AUCUNE action.
    expect(within(factRow).getByText(/Toutes les semaines de Vacances d'été/)).toBeInTheDocument();
    expect(within(factRow).queryByRole("button", { name: "Modifier" })).toBeNull();
    expect(within(factRow).queryByRole("button", { name: "Supprimer" })).toBeNull();
  });

  it("entrée RACINE : ses datées restent éditables, aucun badge de mère", async () => {
    const user = userEvent.setup();
    calendarEntryState.data = { parentEntryId: null };
    calendarEntryById.map = {};
    h.byEntry = { "child-week": [GENESIS] };
    renderWithProviders(<ConstraintsStep />);
    await user.click(screen.getByRole("button", { name: "Jours" }));

    const genesisRow = document.querySelector('[data-constraint-id="g1"]') as HTMLElement;
    expect(within(genesisRow).getByRole("button", { name: "Modifier" })).toBeInTheDocument();
    expect(screen.queryByText(/Toutes les semaines de/)).toBeNull();
  });
});

/**
 * P2-29 (lot tags PR 3) — « Affiner ce groupe » : sous le sélecteur de cible, quand la cible est
 * un TAG, un lien discret déplie deux listes de cases — « ET AUSSI » (intersection → `targetTags`)
 * et « SAUF » (union soustraite → `excludeTags`). Le nom auto devient « A + B sauf C » (libellés
 * AFFICHÉS). Cas simple (aucun affinage) : `targetTag` seul, zéro churn. Jamais les deux formes
 * ensemble (le backend rend 422 sur l'intersection).
 */
describe("ConstraintsStep — affiner un groupe (targetTags / excludeTags)", () => {
  const REFINE_TAGS = [
    { id: "tag-adu", name: "ADULTE", color: null, isSystem: true, axis: "AGE" as const },
    { id: "tag-sen", name: "SENIOR", color: null, isSystem: true, axis: "AGE" as const },
    { id: "tag-comp", name: "COMPETITION", color: null, isSystem: true, axis: "NIVEAU" as const },
  ];
  const REFINE_ASSIGN = [
    { id: "a1", teamId: "t1", tagId: "tag-adu", seasonId: "s1" },
    { id: "a2", teamId: "t1", tagId: "tag-sen", seasonId: "s1" },
    { id: "a3", teamId: "t1", tagId: "tag-comp", seasonId: "s1" },
  ];

  beforeEach(() => {
    useWizardStore.getState().exitPeriodMode();
    h.createMut.mockClear();
    h.updateMut.mockClear();
    h.list = [];
    h.tags = REFINE_TAGS;
    h.tagAssignments = REFINE_ASSIGN;
    h.reservations = [];
  });
  afterEach(() => useWizardStore.getState().exitPeriodMode());

  const refineLink = /Affiner ce groupe/;

  it("n'affiche PAS « Affiner ce groupe » quand la cible est « Toutes les équipes »", () => {
    renderWithProviders(<ConstraintsStep />);
    expect(screen.queryByRole("button", { name: refineLink })).toBeNull();
  });

  it("n'affiche PAS « Affiner ce groupe » quand la cible est une équipe précise", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);
    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "t1");
    expect(screen.queryByRole("button", { name: refineLink })).toBeNull();
  });

  it("affiche le lien (aria-expanded) SEULEMENT pour un tag ; il déplie/replie", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);
    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "tag:ADULTE");
    const link = screen.getByRole("button", { name: refineLink });
    expect(link).toHaveAttribute("aria-expanded", "false");
    await user.click(link);
    expect(screen.getByRole("button", { name: refineLink })).toHaveAttribute("aria-expanded", "true");
    // Les deux groupes de cases sont nommés (a11y : fieldset/legend).
    expect(screen.getByRole("group", { name: /Et aussi/ })).toBeInTheDocument();
    expect(screen.getByRole("group", { name: /Sauf/ })).toBeInTheDocument();
  });

  it("le tag CIBLE n'apparaît pas dans les listes d'affinage", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);
    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "tag:ADULTE");
    await user.click(screen.getByRole("button", { name: refineLink }));
    const andGroup = screen.getByRole("group", { name: /Et aussi/ });
    expect(within(andGroup).queryByRole("checkbox", { name: "Adulte (+ de 18)" })).toBeNull();
    expect(within(andGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" })).toBeInTheDocument();
  });

  it("« ET AUSSI » → émet targetTags [cible, …] + nom « A + B », JAMAIS targetTag", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "tag:SENIOR");
    await user.click(screen.getByRole("button", { name: refineLink }));
    const andGroup = screen.getByRole("group", { name: /Et aussi/ });
    await user.click(within(andGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" }));
    await user.type(screen.getByLabelText("Pas avant"), "20:00");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    const payload = h.createMut.mock.calls[0][0];
    expect(payload.config).toMatchObject({ targetTags: ["SENIOR", "COMPETITION"], minStartTime: "20:00" });
    expect(payload.config).not.toHaveProperty("targetTag");
    expect(payload.config).not.toHaveProperty("excludeTags");
    expect(payload.name).toBe("Groupe Senior (+ de 22) + Compétition (hors loisir) · pas avant 20:00");
  });

  it("« SAUF » → émet targetTags [cible] + excludeTags + nom « A sauf C »", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "tag:ADULTE");
    await user.click(screen.getByRole("button", { name: refineLink }));
    const exceptGroup = screen.getByRole("group", { name: /Sauf/ });
    await user.click(within(exceptGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" }));
    await user.type(screen.getByLabelText("Pas avant"), "18:50");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    const payload = h.createMut.mock.calls[0][0];
    expect(payload.config).toMatchObject({ targetTags: ["ADULTE"], excludeTags: ["COMPETITION"], minStartTime: "18:50" });
    expect(payload.config).not.toHaveProperty("targetTag");
    expect(payload.name).toBe("Groupe Adulte (+ de 18) sauf Compétition (hors loisir) · pas avant 18:50");
  });

  it("un même tag ne peut pas être à la fois « ET AUSSI » et « SAUF » (le cocher d'un côté le décoche de l'autre)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "tag:ADULTE");
    await user.click(screen.getByRole("button", { name: refineLink }));
    const andGroup = screen.getByRole("group", { name: /Et aussi/ });
    const exceptGroup = screen.getByRole("group", { name: /Sauf/ });

    await user.click(within(andGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" }));
    expect(within(andGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" })).toBeChecked();

    // Le cocher en « Sauf » le retire de « Et aussi ».
    await user.click(within(exceptGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" }));
    expect(within(exceptGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" })).toBeChecked();
    expect(within(andGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" })).not.toBeChecked();
  });

  it("le cas SIMPLE (aucun affinage) émet targetTag SEUL — zéro churn", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "tag:ADULTE");
    await user.type(screen.getByLabelText("Pas avant"), "20:00");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    const payload = h.createMut.mock.calls[0][0];
    expect(payload.config).toMatchObject({ targetTag: "ADULTE", minStartTime: "20:00" });
    expect(payload.config).not.toHaveProperty("targetTags");
    expect(payload.config).not.toHaveProperty("excludeTags");
    expect(payload.name).toBe("Groupe Adulte (+ de 18) · pas avant 20:00");
  });

  it("changer la cible pour « Toutes les équipes » VIDE l'affinage (pas d'état fantôme)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "tag:SENIOR");
    await user.click(screen.getByRole("button", { name: refineLink }));
    await user.click(within(screen.getByRole("group", { name: /Et aussi/ })).getByRole("checkbox", { name: "Compétition (hors loisir)" }));

    // On repointe la cible sur « Toutes les équipes » : le lien disparaît, l'affinage se vide.
    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "");
    expect(screen.queryByRole("button", { name: refineLink })).toBeNull();

    // On revient sur un tag et on ajoute SANS re-cocher : aucune trace de l'ancien affinage.
    await user.selectOptions(screen.getByRole("combobox", { name: "Cible" }), "tag:SENIOR");
    await user.type(screen.getByLabelText("Pas avant"), "20:00");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));
    expect(h.createMut.mock.calls[0][0].config).toMatchObject({ targetTag: "SENIOR" });
    expect(h.createMut.mock.calls[0][0].config).not.toHaveProperty("targetTags");
  });

  it("recharge une contrainte à FORME COMBINÉE (targetTags/excludeTags) : cible + affinage dépliés", async () => {
    h.list = [
      {
        id: "c-combo",
        name: "Groupe Adulte (+ de 18) + Compétition (hors loisir) sauf Senior (+ de 22) · pas avant 20:00",
        scope: "CLUB",
        scopeTargetId: null,
        family: "TIME",
        ruleType: "PREFERRED",
        config: { targetTags: ["ADULTE", "COMPETITION"], excludeTags: ["SENIOR"], minStartTime: "20:00" },
        isActive: true,
      },
    ] as Constraint[];
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Modifier" }));

    // La cible principale = le 1er targetTags ; l'affinage est DÉPLIÉ (non vide) et pré-coché.
    expect(screen.getByRole("combobox", { name: "Cible" })).toHaveValue("tag:ADULTE");
    const andGroup = screen.getByRole("group", { name: /Et aussi/ });
    const exceptGroup = screen.getByRole("group", { name: /Sauf/ });
    expect(within(andGroup).getByRole("checkbox", { name: "Compétition (hors loisir)" })).toBeChecked();
    expect(within(exceptGroup).getByRole("checkbox", { name: "Senior (+ de 22)" })).toBeChecked();

    // Ré-enregistrer sans rien changer round-trip la forme combinée (jamais targetTag).
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));
    const body = (h.updateMut.mock.calls[0][0] as { body: Constraint }).body;
    expect(body.config).toMatchObject({ targetTags: ["ADULTE", "COMPETITION"], excludeTags: ["SENIOR"] });
    expect(body.config).not.toHaveProperty("targetTag");
  });

  it("recharge une contrainte LEGACY (targetTag) : cible remplie, affinage vide et replié", async () => {
    h.list = [
      { id: "c-legacy", name: "Groupe Adulte (+ de 18) · pas avant 20:00", scope: "CLUB", scopeTargetId: null, family: "TIME", ruleType: "PREFERRED", config: { targetTag: "ADULTE", minStartTime: "20:00" }, isActive: true },
    ] as Constraint[];
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    expect(screen.getByRole("combobox", { name: "Cible" })).toHaveValue("tag:ADULTE");
    // Affinage replié (rien à montrer) : le lien est là, aria-expanded false.
    expect(screen.getByRole("button", { name: refineLink })).toHaveAttribute("aria-expanded", "false");

    // Round-trip : reste un targetTag SEUL (zéro churn).
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));
    const body = (h.updateMut.mock.calls[0][0] as { body: Constraint }).body;
    expect(body.config).toMatchObject({ targetTag: "ADULTE" });
    expect(body.config).not.toHaveProperty("targetTags");
  });
});

/**
 * P2-45 — l'onglet « Mutualisation » A DÉMÉNAGÉ dans l'étape Équipes (modale par équipe) : il
 * n'existe plus DANS ConstraintsStep, ni en saison ni en période. La création d'un groupe et son
 * ancrage (socle `schedulePlanId` null / plan de période) sont désormais gardés depuis leurs
 * nouveaux hôtes (TeamsStep.test, PeriodStructure.test). Le wrapper `describe` survit pour son
 * `beforeEach`, dont dépend « le tableau des contraintes » niché ci-dessous.
 */
describe("ConstraintsStep — l'onglet Mutualisation a déménagé (P2-45)", () => {
  beforeEach(() => {
    h.list = [];
    periodAnchorReady.value = true;
    activeTeamsState.pausedIds = new Set();
    useWizardStore.getState().exitPeriodMode();
  });
  afterEach(() => useWizardStore.getState().exitPeriodMode());

  it("n'offre plus d'onglet « Mutualisation » en saison", () => {
    renderWithProviders(<ConstraintsStep />);
    expect(screen.queryByRole("button", { name: "Mutualisation" })).toBeNull();
    // Falsification : « Réserver », l'onglet voisin, n'a PAS bougé — c'est bien Mutualisation qui part.
    expect(screen.getByRole("button", { name: "Réserver" })).toBeInTheDocument();
  });

  it("n'offre plus d'onglet « Mutualisation » en période non plus", () => {
    useWizardStore.getState().startPeriodMode("entry-27");
    renderWithProviders(<ConstraintsStep />);
    expect(screen.queryByRole("button", { name: "Mutualisation" })).toBeNull();
  });

  /**
   * P4-107 (4ᵉ tranche) — **la liste des contraintes devient un TABLEAU.**
   *
   * Elle vivait en barres pleine largeur : ~50 caractères étalés sur 1650 px à 1920, et les
   * icônes modifier/supprimer à ~1400 px du libellé qu'elles concernent. Le tableau ramène
   * les actions près du contenu et rend la colonne « Règle » balayable.
   *
   * ⚠ **`<table>` sémantique, avec `<thead>`/`<tbody>` — pas une grille de `<div>`.** C'est la
   * seule règle de sévérité HAUTE qu'ait rendue la passe de design `ui-ux-pro-max` sur ce lot
   * (« Missing thead or tbody » / « Div grid for table-like layouts »). Un tableau de données
   * qui n'en est pas un est illisible au lecteur d'écran : pas d'en-tête annoncé par cellule.
   *
   * ⚑ Les REGROUPEMENTS survivent (décision fondateur) : « Âge », « S · Fanion »… restent des
   * lignes d'en-tête de groupe, et les trois tests de groupement ci-dessus continuent de lire
   * `constraint-section`. Le corpus de design est MUET sur les lignes de groupe dans un
   * tableau — le choix d'un `<tbody>` par groupe est le nôtre, pas le sien.
   */
  describe("le tableau des contraintes", () => {
    const timeRow = {
      id: "c-cols",
      name: "SM1 · pas après 21:00",
      scope: "TEAM",
      scopeTargetId: "t1",
      family: "TIME",
      ruleType: "PREFERRED",
      config: { maxStartTime: "21:00" },
      isActive: true,
    } as Constraint;

    it("est un vrai tableau : en-têtes Cible / Règle / Valeur / Niveau", () => {
      h.list = [timeRow];
      renderWithProviders(<ConstraintsStep />);

      // La 5ᵉ colonne porte un nom RÉSERVÉ AU LECTEUR D'ÉCRAN : un en-tête muet laisserait une
      // colonne sans annonce. Les lignes de groupe (« Âge », « S · Fanion ») sont des
      // `rowheader`, pas des `columnheader` — sinon elles pollueraient cette liste.
      expect(screen.getAllByRole("columnheader").map((e) => e.textContent)).toEqual(["Cible", "Règle", "Valeur", "Niveau", "Actions"]);
    });

    it("sépare le verbe de sa valeur, et NOMME la cible dans sa colonne", () => {
      h.list = [timeRow];
      renderWithProviders(<ConstraintsStep />);

      const cells = screen.getAllByRole("cell").map((e) => e.textContent);
      // La cible est dérivée du scope (jamais du `name`, texte libre qui peut mentir) ; le
      // verbe et la valeur viennent du foyer unique `describeConstraint`.
      expect(cells.slice(0, 4)).toEqual(["SM1", "pas après", "21:00", "Préféré"]);
    });

    it("rend les TROIS bornes d'une contrainte horaire — n'en montrer qu'une mentirait par omission", () => {
      h.list = [{ ...timeRow, config: { maxStartTime: "21:00", minStartTime: "17:00", maxEndTime: "22:30" } }];
      renderWithProviders(<ConstraintsStep />);

      const row = screen.getAllByRole("row").find((r) => null !== r.querySelector("[data-constraint-id]") || null !== r.closest("[data-constraint-id]"));
      expect(row?.textContent).toContain("pas après");
      expect(row?.textContent).toContain("21:00");
      expect(row?.textContent).toContain("pas avant");
      expect(row?.textContent).toContain("17:00");
      expect(row?.textContent).toContain("fini avant");
      expect(row?.textContent).toContain("22:30");
    });

    it("retombe sur le NOM quand la règle n'est pas descriptible — jamais une cellule vide", () => {
      // Clé inconnue (`legacyUnknownKey`) : `describeConstraint` refuse de la décrire.
      // Une cellule vide laisserait croire qu'il n'y a rien à appliquer.
      // ⚠ Rester dans la famille de l'onglet ACTIF (TIME) : une contrainte d'une autre
      // famille n'est pas listée du tout, et le test passerait à vide.
      h.list = [{ ...timeRow, id: "c-legacy", name: "SM1 · règle héritée", config: { legacyUnknownKey: true } }];
      renderWithProviders(<ConstraintsStep />);

      expect(screen.getByText("SM1 · règle héritée")).toBeInTheDocument();
    });
  });
});

