import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { Closure } from "@/features/cockpit/api";
import type { TeamLink } from "@/features/matches/api";

import type { Constraint, SharedTrainingBlock, SharedTrainingGroup } from "../api";

const createOverride = vi.fn();
const updateOverride = vi.fn();
const deleteOverride = vi.fn();
const createSlot = vi.fn();
const deleteSlot = vi.fn();
// #8 PR-B : les gestes de mode et de grille par gymnase.
const setMode = vi.fn();
const clearMode = vi.fn();
const resetGrid = vi.fn();
const clearGrid = vi.fn();
const createConstraintOverride = vi.fn();
const updateConstraintOverride = vi.fn();
const deleteConstraintOverride = vi.fn();
const overridesState: { data: Array<{ id: string; teamId: string; isActive: boolean; sessionsPerWeek: number | null; schedulePlanId: string }> } = { data: [] };
// Le VRAI type, pas une copie étroite : une seconde description du même objet finit
// toujours par diverger — celle d'avant ignorait family/config/isActive, que les tests
// envoient pourtant. Le helper porte les défauts, chaque test ne dit que ce qui compte.
const constraintsState: { data: Constraint[]; isLoading: boolean; isError: boolean } = { data: [], isLoading: false, isError: false };

const constraint = (over: Partial<Constraint> & Pick<Constraint, "id" | "name">): Constraint => ({
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "PREFERRED",
  config: {},
  isActive: true,
  ...over,
});
const constraintOverridesState: { data: Array<{ id: string; constraintId: string; isActive: boolean; schedulePlanId: string }> } = { data: [] };
const tagsState: { data: Array<{ id: string; name: string; color: string | null; isSystem: boolean; axis: "GENRE" | "NIVEAU" | "AGE" | null }> } = { data: [] };
const tagsLoadingState = { value: false };
const tagAssignmentsState: { data: Array<{ id: string; teamId: string; tagId: string; seasonId: string }> } = { data: [] };
const teamOverridesLoadingState = { value: false };
const teamOverridesErrorState = { value: false };
const constraintOverridesLoadingState = { value: false };
const constraintOverridesErrorState = { value: false };
const conflictState: {
  venueIds: string[];
  closures: Closure[];
  fullyClosedVenueIds: string[];
  effectiveClosedWeekdays: Record<string, Record<string, "manual" | "default-incident">>;
  disabledVenueIds: string[];
} = { venueIds: [], closures: [], fullyClosedVenueIds: [], effectiveClosedWeekdays: {}, disabledVenueIds: [] };
const overridesVenueState: { data: Array<{ id: string; schedulePlanId: string; venueId: string; mode: "DISABLED" | "BLANK" | null; dayOverrides?: Record<string, "OPEN" | "CLOSED"> | null }> } = { data: [] };
const overridesFetchingState = { value: false };
const venueCanSplitState = { value: false };
// Le club n'a QU'UN gymnase par défaut : la plupart des tests portent sur une grille, et
// un second gymnase changerait ce que voient les assertions d'ensemble (« TOUS les
// gymnases interdits »). Les rares tests qui ont besoin d'en changer l'ajoutent ici.
const extraVenuesState: { value: Array<{ id: string; name: string; color: string | null; canSplit: boolean; isActive: boolean }> } = { value: [] };
const periodSlotOverride: { value: Array<Record<string, unknown>> | null } = { value: null };
const deletePeriodSlotImpl: { value: (...a: unknown[]) => void } = { value: () => {} };
// Fidèle : invoque le onSuccess passé (c'est là que vit le retrait des réservations).
const updateSlot = vi.fn((_body: unknown, opts?: { onSuccess?: () => void }) => opts?.onSuccess?.());
const delReservation = vi.fn();
const reservationsState: { data: Array<{ id: string; venueId: string; dayOfWeek?: number; startTime?: string }> } = { data: [] };
const venuePeriodOverridesAnchor: { value: string | null } = { value: null };
const entryState: { data: { periodType: string } | undefined } = { data: { periodType: "closure" } };
// ADR-0002 lot C: le garde de seed vit sur le PLAN, pas sur l'événement calendrier.
// Les ancres REÇUES par les hooks de lecture. Sans les capturer, aucun test ne peut voir
// qu'un composant lit par le déclencheur au lieu du plan : les deux sont des `string`, tsc
// est muet, et l'API répondrait 200 avec une liste vide — panne silencieuse (lot C2).
const teamOverridesAnchor: { value: string | null } = { value: null };
const periodSlotsAnchor: { value: string | null } = { value: null };
const periodSlotWriteAnchor: { value: string | null } = { value: null };
const constraintOverridesAnchor: { value: string | null } = { value: null };
const periodSlotsState = { failed: false, retry: vi.fn() };
const planState: { data: { id: string; teamSelectionInitialized: boolean } | null | undefined; failed: boolean; retry: () => void } = {
  data: { id: "plan-1", teamSelectionInitialized: false },
  failed: false,
  retry: vi.fn(),
};

// Teams + tiers stateful : le défaut d'équipes E3 (reprise = 2 premiers rangs, fermeture =
// tout le club) ne se prouve qu'avec un rang SOUS les importantes → certains tests ajoutent
// un tier B/t3. Défaut = 2 rangs (S, A) / 2 équipes, inchangé pour les tests existants.
const T1 = { id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true };
const T2 = { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 1, isActive: true };
const teamsState: { data: Array<typeof T1> } = { data: [T1, T2] };
const tiersState: { data: Array<{ id: number; label: string; name: string; color: string | null }> } = {
  data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Importante", color: null }],
};
// P2-27 — les groupes de mutualisation de la période (défaut vide : aucun repère).
const sharedGroupsState: { data: SharedTrainingGroup[] } = { data: [] };
// P2-51 PR-6 — les blocs de mutualisation de la période : la sous-ligne « Mutualisée avec … » les lit AUSSI.
const sharedBlocksState: { data: SharedTrainingBlock[] } = { data: [] };
// P2-45 — mutualisation ouverte depuis la modale Liens ; passerelles servies par le module matchs.
const stgCreate = vi.fn();
// P2-51 / D13 — la section « Mutualisation » de la modale est SharedTrainingBlockPanel : créer y
// appelle `useCreateSharedTrainingBlock` (un « bloc » côté backend), plus le groupe K.
const stbCreate = vi.fn();
const stgUpdate = vi.fn();
const stgDelete = vi.fn();
const teamLinksState: { data: TeamLink[] } = { data: [] };

vi.mock("../queries", () => ({
  useWizardTeams: () => ({ data: teamsState.data }),
  usePriorityTiers: () => ({ data: tiersState.data }),
  useTeamPeriodOverrides: (anchor: string | null) => {
    teamOverridesAnchor.value = anchor;

    // readState : un échec « sans rien à montrer » se signale par `data: undefined`
    // (une donnée en cache, même périmée, reste exploitable).
    return {
      data: teamOverridesErrorState.value || teamOverridesLoadingState.value ? undefined : overridesState.data,
      isLoading: teamOverridesLoadingState.value,
      isError: teamOverridesErrorState.value,
      refetch: vi.fn(),
    };
  },
  useCreateTeamPeriodOverride: () => ({ mutate: createOverride, mutateAsync: createOverride, isPending: false }),
  useUpdateTeamPeriodOverride: () => ({ mutate: updateOverride, mutateAsync: updateOverride, isPending: false }),
  useDeleteTeamPeriodOverride: () => ({ mutate: deleteOverride, mutateAsync: deleteOverride, isPending: false }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: "#ff0000", canSplit: venueCanSplitState.value, isActive: true }, ...extraVenuesState.value] }),
  useVenueSlots: () => ({ data: [] }),
  usePeriodSlots: (anchor: string | null) => {
    periodSlotsAnchor.value = anchor;

    return {
      data: periodSlotsState.failed
        ? undefined
        : (periodSlotOverride.value ?? [{ id: "ps1", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }]),
      isError: periodSlotsState.failed,
      refetch: periodSlotsState.retry,
    };
  },
  useCreatePeriodSlot: (anchor: string | null) => {
    periodSlotWriteAnchor.value = anchor;

    return { mutate: createSlot, isPending: false };
  },
  useDeletePeriodSlot: () => ({ mutate: (...a: unknown[]) => deletePeriodSlotImpl.value(...a), isPending: false }),
  useVenuePeriodOverrides: (anchor: string | null) => {
    venuePeriodOverridesAnchor.value = anchor;

    return { data: overridesVenueState.data, isFetching: overridesFetchingState.value, isError: false, refetch: vi.fn() };
  },
  useSetVenuePeriodMode: () => ({ mutate: setMode, isPending: false }),
  useUpdatePeriodSlot: () => ({ mutate: updateSlot, isPending: false }),
  // P4-108 — l'impact d'une suppression de créneau vient du SERVEUR (il voit les verrous
  // HARD que l'écran ne pouvait pas compter). Le mock rend l'épinglage de ce créneau.
  useDeletionImpact: () => ({
    data: { blocked: false, reason: null, lines: [{ key: "slot_reservation", count: 1, one: "réservation d'équipe", many: "réservations d'équipe" }], slotsInForce: 0, declaredFixtures: 0 },
    isPending: false,
    isError: false,
  }),
  useClearVenuePeriodMode: () => ({ mutate: clearMode, isPending: false }),
  useResetVenuePeriodGrid: () => ({ mutate: resetGrid, isPending: false }),
  useClearVenuePeriodGrid: () => ({ mutate: clearGrid, isPending: false }),
  useReservations: () => ({ data: reservationsState.data }),
  useDeleteReservation: () => ({ mutate: delReservation }),
  useWizardConstraints: () => ({ data: constraintsState.data, isLoading: constraintsState.isLoading, isError: constraintsState.isError }),
  usePeriodConstraintOverrides: (anchor: string | null) => {
    constraintOverridesAnchor.value = anchor;

    return { data: constraintOverridesState.data, isLoading: constraintOverridesLoadingState.value, isError: constraintOverridesErrorState.value };
  },
  useWizardTeamTags: () => ({ data: tagsState.data, isLoading: tagsLoadingState.value, isError: false }),
  useWizardTeamTagAssignments: () => ({ data: tagAssignmentsState.data, isLoading: tagsLoadingState.value, isError: false }),
  useCreatePeriodConstraintOverride: () => ({ mutate: createConstraintOverride, isPending: false }),
  useUpdatePeriodConstraintOverride: () => ({ mutate: updateConstraintOverride, isPending: false }),
  useDeletePeriodConstraintOverride: () => ({ mutate: deleteConstraintOverride, isPending: false }),
  useSharedTrainingGroups: () => ({ data: sharedGroupsState.data }),
  // P2-45 — la modale Liens (ouverte depuis une équipe de la période) embarque MutualisationPanel.
  useCreateSharedTrainingGroup: () => ({ mutateAsync: stgCreate, isPending: false }),
  useUpdateSharedTrainingGroup: () => ({ mutateAsync: stgUpdate, isPending: false }),
  useDeleteSharedTrainingGroup: () => ({ mutate: stgDelete }),
  // P2-51 / D13 — la modale Liens de période rend la section « Mutualisation » via SharedTrainingBlockPanel.
  // PR-6 — la sous-ligne les lit aussi : état pilotable comme les groupes.
  useSharedTrainingBlocks: () => ({ data: sharedBlocksState.data }),
  useCreateSharedTrainingBlock: () => ({ mutateAsync: stbCreate, isPending: false }),
  useUpdateSharedTrainingBlock: () => ({ mutateAsync: vi.fn(() => Promise.resolve({})), isPending: false }),
  useDeleteSharedTrainingBlock: () => ({ mutate: vi.fn() }),
}));
// P2-45 — passerelles SERVIES par le module matchs (lecture seule en période). On pilote ses hooks.
vi.mock("@/features/matches/queries", () => ({
  useTeamLinks: () => ({ data: teamLinksState.data, isError: false }),
  useCreateTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
}));
vi.mock("@/features/matches/HabitsLinksButton", () => ({
  HabitsLinksButton: () => <button type="button">Gérer les passerelles</button>,
}));
vi.mock("@/features/cockpit/queries", () => ({
  useEntryConflicts: () => ({ data: { venueIds: conflictState.venueIds, closures: conflictState.closures, fullyClosedVenueIds: conflictState.fullyClosedVenueIds, effectiveClosedWeekdays: conflictState.effectiveClosedWeekdays, disabledVenueIds: conflictState.disabledVenueIds }, isError: false, refetch: vi.fn() }),
  useCalendarEntry: () => ({ data: entryState.data, isLoading: false }),
  useSchedulePlanForEntry: () => ({ data: planState.data, isLoading: false }),
  // Dérivé de planState : un test qui met planState.data à undefined simule le plan pas
  // encore résolu, et `ready` bascule — c'est ce que le NR d'écriture exerce.
  // Union discriminée (P4-20) : `planState.failed` simule un GET du plan en ÉCHEC,
  // distinct de « pas encore résolu » (data undefined) que le NR d'écriture exerce.
  usePeriodAnchor: (calendarEntryId: string | null) => {
    const planId = planState.data?.id ?? null;

    if (null === calendarEntryId) {
      return { state: "base", planId: null };
    }
    if (null !== planId) {
      return { state: "period", planId };
    }
    if (planState.failed) {
      return { state: "failed", planId: null, retry: planState.retry };
    }

    return { state: "loading", planId: null };
  },
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
}));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import { toast } from "@/shared/stores/toastStore";

import { PeriodConstraints, PeriodTeams, PeriodVenues } from "./PeriodStructure";
import { resetPeriodSeed } from "./periodSeed";

afterEach(() => {
  // Les vi.fn() ACCUMULENT leurs appels d'un test à l'autre : sans ça, un
  // `not.toHaveBeenCalled` voit l'appel d'un test précédent et échoue à tort (ou pire,
  // un `toHaveBeenCalled` passe grâce à lui).
  vi.clearAllMocks();
  resetPeriodSeed();
  sharedGroupsState.data = [];
  sharedBlocksState.data = [];
  teamLinksState.data = [];
  extraVenuesState.value = [];
  overridesState.data = [];
  constraintsState.data = [];
  constraintsState.isLoading = false;
  constraintsState.isError = false;
  constraintOverridesState.data = [];
  tagsState.data = [];
  tagsLoadingState.value = false;
  tagAssignmentsState.data = [];
  teamOverridesLoadingState.value = false;
  teamOverridesErrorState.value = false;
  constraintOverridesLoadingState.value = false;
  constraintOverridesErrorState.value = false;
  conflictState.venueIds = [];
  conflictState.closures = [];
  conflictState.fullyClosedVenueIds = [];
  conflictState.effectiveClosedWeekdays = {};
  conflictState.disabledVenueIds = [];
  overridesVenueState.data = [];
  overridesFetchingState.value = false;
  deletePeriodSlotImpl.value = deleteSlot;
  updateSlot.mockClear();
  delReservation.mockClear();
  venueCanSplitState.value = false;
  reservationsState.data = [];
  periodSlotOverride.value = null;
  reservationsState.data = [];
  setMode.mockClear();
  clearMode.mockClear();
  resetGrid.mockClear();
  clearGrid.mockClear();
  stbCreate.mockClear();
  entryState.data = { periodType: "closure" };
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
  teamsState.data = [T1, T2];
  tiersState.data = [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Importante", color: null }];
  createOverride.mockClear();
  updateOverride.mockClear();
  deleteOverride.mockClear();
  createSlot.mockClear();
  deleteSlot.mockClear();
  createConstraintOverride.mockClear();
  updateConstraintOverride.mockClear();
  deleteConstraintOverride.mockClear();
});

describe("PeriodTeams — default team selection (E3) + toggles", () => {
  // Un 3e rang B (t3) SOUS les importantes rend la PROFONDEUR du seed observable : sans lui,
  // reprise (garde S+A) et fermeture (garde tout) désactiveraient tous deux personne.
  const T3 = { ...T2, id: "t3", name: "Loisir", priorityTierId: 3 };
  const withThreeTiers = () => {
    teamsState.data = [T1, T2, T3];
    tiersState.data = [
      { id: 1, label: "S", name: "Fanion", color: null },
      { id: 2, label: "A", name: "Importante", color: null },
      { id: 3, label: "B", name: "Loisir", color: null },
    ];
  };

  it("reprise (holiday): seeds Fanion + importantes active, deactivates the rest", () => {
    // Unique id: the module-level "already seeded" set persists across tests.
    entryState.data = { periodType: "holiday" };
    withThreeTiers();
    render(<PeriodTeams calendarEntryId="fresh-holiday" />);
    // t3 (rang B, sous les importantes) passe en pause ; t1 (Fanion) et t2 (importante) gardés.
    expect(createOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", teamId: "t3", isActive: false });
    expect(createOverride).toHaveBeenCalledTimes(1);
  });

  it("T5 — un GET d'overrides en échec n'écrit RIEN en arrière-plan (seed muselé)", () => {
    // Le piège : sur une query en ERREUR, `overrides` vaut `[]` et `isLoading` est faux —
    // le seed passait donc toutes ses gardes et désactivait la moitié du club PENDANT que
    // l'écran affichait « Impossible de charger la sélection ». Invisible, et le
    // gestionnaire retrouvait ensuite une sélection qu'il n'avait pas faite.
    // `holiday` + 3 rangs = la configuration qui seed RÉELLEMENT (une closure ne
    // désactive personne : le test ne pourrait alors pas échouer).
    entryState.data = { periodType: "holiday" };
    withThreeTiers();
    teamOverridesErrorState.value = true;
    planState.data = { id: "plan-1", teamSelectionInitialized: false };

    render(<PeriodTeams calendarEntryId="fresh-holiday-overrides-ko" />);

    expect(screen.getByRole("alert")).toHaveTextContent(/Impossible de charger la sélection/);
    expect(createOverride).not.toHaveBeenCalled();
    teamOverridesErrorState.value = false;
    entryState.data = { periodType: "closure" };
  });

  // NR — le contrat du toast de ramp (revue #303 r3 : l'ancien test avait été réécrit
  // en assertion d'absence, laissant le contrat upsertAsync/toast sans couverture).
  it("un ramp qui ÉCRIT confirme « Sélection appliquée »", async () => {
    const user = userEvent.setup();
    entryState.data = { periodType: "holiday" };
    withThreeTiers();
    planState.data = { id: "plan-1", teamSelectionInitialized: true }; // pas de seed

    render(<PeriodTeams calendarEntryId="ramp-writes" />);
    await user.click(screen.getByRole("button", { name: "Fanion seul" }));

    // t2 (importante) et t3 (loisir) sortent du défaut saison → 2 écritures réelles.
    expect(createOverride).toHaveBeenCalled();
    expect(toast.success).toHaveBeenCalledWith("Sélection appliquée");
    entryState.data = { periodType: "closure" };
  });

  it("un ramp SANS effet constate « Aucune modification » — jamais « appliquée »", async () => {
    const user = userEvent.setup();
    entryState.data = { periodType: "holiday" };
    withThreeTiers();
    planState.data = { id: "plan-1", teamSelectionInitialized: true };

    render(<PeriodTeams calendarEntryId="ramp-noop" />);
    // « Tout le club » quand tout le monde est déjà au défaut saison : zéro écriture.
    await user.click(screen.getByRole("button", { name: "Tout le club" }));

    expect(createOverride).not.toHaveBeenCalled();
    expect(toast.success).not.toHaveBeenCalledWith("Sélection appliquée");
    expect(toast.success).toHaveBeenCalledWith("Aucune modification à enregistrer");
    entryState.data = { periodType: "closure" };
  });

  it("fermeture (closure): seeds nobody — tout le club reste actif (structure verrouillée)", () => {
    entryState.data = { periodType: "closure" };
    withThreeTiers();
    render(<PeriodTeams calendarEntryId="fresh-closure" />);
    expect(createOverride).not.toHaveBeenCalled();
  });

  it("does NOT seed a period already configured server-side (teamSelectionInitialized)", () => {
    entryState.data = { periodType: "holiday" };
    withThreeTiers();
    planState.data = { id: "plan-1", teamSelectionInitialized: true }; // e.g. manager reset it to all-active, then reloaded
    render(<PeriodTeams calendarEntryId="already-init" />);
    expect(createOverride).not.toHaveBeenCalled();
  });

  it("does NOT re-seed on a re-render (idempotent — guards the removed retry double-write)", () => {
    entryState.data = { periodType: "holiday" };
    withThreeTiers();
    const { rerender } = render(<PeriodTeams calendarEntryId="fresh-holiday-2" />);
    expect(createOverride).toHaveBeenCalledTimes(1);
    // A re-render (effect re-runs) must not fire a second seed for the claimed period.
    rerender(<PeriodTeams calendarEntryId="fresh-holiday-2" />);
    expect(createOverride).toHaveBeenCalledTimes(1);
  });

  it("reprise: garde les rangs S+A par RANG même quand le Fanion est vide (pas par position)", () => {
    // Aucune équipe Fanion (rang S/1) ; A (t2) + B (t3) occupés. Un slice() positionnel des
    // groupes garderait A ET B (groups=[A,B]) ; le fix garde par RANG → A actif, B en pause.
    entryState.data = { periodType: "holiday" };
    teamsState.data = [T2, T3];
    tiersState.data = [
      { id: 1, label: "S", name: "Fanion", color: null },
      { id: 2, label: "A", name: "Importante", color: null },
      { id: 3, label: "B", name: "Loisir", color: null },
    ];
    render(<PeriodTeams calendarEntryId="fresh-holiday-no-fanion" />);
    expect(createOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", teamId: "t3", isActive: false });
    expect(createOverride).toHaveBeenCalledTimes(1); // t2 (rang A) reste actif
  });

  it("reprise: un club SANS Fanion ni importante ne voit pas tout le monde désactivé (garde-fou top rang présent)", () => {
    // Club tout en loisir : rangs B (t3) + C (t4), aucun S/A. Le fixe {S,A} éteindrait TOUT ;
    // le garde-fou garde le meilleur rang présent (B) et ne met en pause que C.
    entryState.data = { periodType: "holiday" };
    const tB = { ...T2, id: "tB", name: "Loisir 1", priorityTierId: 3 };
    const tC = { ...T2, id: "tC", name: "Loisir 2", priorityTierId: 4 };
    teamsState.data = [tB, tC];
    tiersState.data = [
      { id: 1, label: "S", name: "Fanion", color: null },
      { id: 2, label: "A", name: "Importante", color: null },
      { id: 3, label: "B", name: "Moyenne", color: null },
      { id: 4, label: "C", name: "De base", color: null },
    ];
    render(<PeriodTeams calendarEntryId="fresh-holiday-loisir" />);
    expect(createOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", teamId: "tC", isActive: false });
    expect(createOverride).toHaveBeenCalledTimes(1); // tB (meilleur rang présent) reste actif
  });

  it("fermeture: une équipe hors des rangs chargés (data drift) reste active — jamais désactivée", () => {
    entryState.data = { periodType: "closure" };
    const orphan = { ...T2, id: "torphan", priorityTierId: 9 }; // tier absent du set chargé
    teamsState.data = [T1, orphan];
    render(<PeriodTeams calendarEntryId="fresh-closure-orphan" />);
    expect(createOverride).not.toHaveBeenCalled();
  });

  it("« Tout le club » activates every team", async () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    render(<PeriodTeams calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("button", { name: "Tout le club" }));
    // The deactivated U13 override is removed → back to seasonal (active).
    expect(deleteOverride).toHaveBeenCalledWith("o2");
  });
});

describe("PeriodTeams — repère de mutualisation (P2-27)", () => {
  it("marque une équipe mutualisée en période, en nommant sa co-équipière", () => {
    // Défaut = closure (aucun seed) : le panneau rend les lignes telles quelles.
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: true, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    sharedGroupsState.data = [
      { id: "g1", version: 1, createdAt: "2026-08-17T00:00:00+00:00", updatedAt: "2026-08-17T00:00:00+00:00", schedulePlanId: "plan-1", teamIds: ["t1", "t2"], commonSessions: 1 },
    ];
    render(<PeriodTeams calendarEntryId="mutualise-marker" />);

    // Sans ce repère EN PÉRIODE, la mutualisation mentirait par omission (elle n'apparaîtrait
    // qu'en saison). SM1 (t1) est mutualisée avec U13 (t2).
    expect(screen.getByText(/Mutualisée avec U13/)).toBeInTheDocument();
  });

  // P2-51 PR-6 — en période aussi, la sous-ligne lit le NOUVEAU modèle (bloc), pas que le groupe K.
  it("marque une équipe mutualisée via un BLOC (nouveau modèle) en période", () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: true, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    sharedBlocksState.data = [
      { id: "b1", version: 1, createdAt: "2026-08-31T00:00:00+00:00", updatedAt: "2026-08-31T00:00:00+00:00", schedulePlanId: "plan-1", teamIds: ["t1", "t2"], commonSessions: 1 },
    ];
    render(<PeriodTeams calendarEntryId="mutualise-block-marker" />);

    expect(screen.getByText(/Mutualisée avec U13/)).toBeInTheDocument();
  });
});

describe("PeriodTeams — liens par équipe (P2-45)", () => {
  it("offre une affordance « Liens de … » par équipe", () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: true, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    render(<PeriodTeams calendarEntryId="links-affordance" />);
    expect(screen.getByRole("button", { name: "Liens de SM1" })).toBeInTheDocument();
  });

  it("étend le repère aux passerelles, avec l'intensité (donnée servie)", () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: true, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    teamLinksState.data = [{ id: "l1", teamAId: "t1", teamBId: "t2", linkType: "NOT_SIMULTANEOUS", trainingIntensity: "PREFERRED" }];
    render(<PeriodTeams calendarEntryId="links-marker" />);
    expect(screen.getByText(/Passerelle avec U13 \(Préféré\)/)).toBeInTheDocument();
  });

  // Falsification #2 — depuis PeriodTeamsPanel, la mutualisation écrit sur le PLAN de période.
  it("creating a group from the period Teams step anchors it on the period plan", async () => {
    const user = userEvent.setup();
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: true, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    render(<PeriodTeams calendarEntryId="period-anchor" />);

    await user.click(screen.getByRole("button", { name: "Liens de SM1" }));
    // SM1 pré-cochée (initialTeamId) ; on ajoute U13 puis on crée. D13 — la « Mutualisation » est
    // rendue par SharedTrainingBlockPanel : créer écrit un « bloc » ancré au plan de période.
    await user.click(screen.getByRole("checkbox", { name: "U13" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(stbCreate).toHaveBeenCalledOnce();
    expect(stbCreate.mock.calls[0][0]).toMatchObject({ schedulePlanId: "plan-1" });
  });

  // Falsification #5 — en période, les passerelles sont en LECTURE SEULE : la liste + l'intensité
  // s'affichent, mais aucun contrôle d'édition, et la raison est DITE à l'écran.
  it("shows bridges read-only in a period (list + intensity, no edit controls, explained)", async () => {
    const user = userEvent.setup();
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: true, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    teamLinksState.data = [{ id: "l1", teamAId: "t1", teamBId: "t2", linkType: "NOT_SIMULTANEOUS", trainingIntensity: "PREFERRED" }];
    render(<PeriodTeams calendarEntryId="readonly-links" />);

    await user.click(screen.getByRole("button", { name: "Liens de SM1" }));
    // La raison : jamais une section grisée muette.
    expect(screen.getByText(/se déclarent au niveau de la saison/)).toBeInTheDocument();
    // L'intensité est affichée en TEXTE (pas via un contrôle éditable).
    expect(screen.getByText(/Entraînement\s*:\s*Préféré/)).toBeInTheDocument();
    // Aucun contrôle d'édition de passerelle.
    expect(screen.queryByRole("button", { name: "Ajouter la passerelle" })).toBeNull();
    expect(screen.queryByLabelText(/Intensité d'entraînement, passerelle/)).toBeNull();
    // La mutualisation, elle, RESTE éditable en période (le formulaire est là).
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
  });
});

describe("PeriodTeams — session guard", () => {
  it("ignores an emptied sessions field instead of persisting 0", async () => {
    overridesState.data = [{ id: "o2", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }]; // suppress the seed
    render(<PeriodTeams calendarEntryId="e1" />);
    const input = screen.getByLabelText("Séances de SM1 cette période");
    await userEvent.clear(input); // → Number("") === 0
    expect(createOverride).not.toHaveBeenCalled();
    expect(updateOverride).not.toHaveBeenCalled();
  });
});

/**
 * #8 PR-B — une carte par gymnase : un ÉTAT (actif/désactivé) qui ne touche jamais la
 * grille, et deux ACTIONS destructives (reprendre / vider) confirmées avant de partir.
 */
describe("PeriodVenues — la grille de la période, gymnase par gymnase", () => {
  it("montre la grille de la période et ajoute un créneau au clic sur une case", async () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    // Le créneau de la période (Mer 20:00) est dessiné dans la grille.
    expect(screen.getByTitle(/^20:00 .* cliquer pour modifier$/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Lun 18:00" }));
    expect(createSlot).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 1, startTime: "18:00", capacity: 1 }));
  });

  it("autorise un ajout par clic en soir\u00e9e, comme la grille de saison (pas de borne 22:00)", async () => {
    // Revue #8 PR-B round 2 finding #3 : la saison n'a AUCUNE borne de fin de journ\u00e9e (un
    // 21:00\u201322:30 y est l\u00e9gitime). Ma borne END_MIN rendait la p\u00e9riode plus stricte que
    // la saison et bloquait m\u00eame un simple changement de jour. On l'a retir\u00e9e.
    render(<PeriodVenues calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("button", { name: "Mar 21:00" }));
    expect(createSlot).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 2, startTime: "21:00" }));
  });

  it("pose un cr\u00e9neau \u00e0 la dur\u00e9e choisie dans la barre \u00ab \u00c0 poser \u00bb (et non 90 min impos\u00e9es)", async () => {
    // P4-43 : la pose \u00e9tait fig\u00e9e \u00e0 90 min quoi qu'on fasse \u2014 un cr\u00e9neau de 2h se posait en
    // deux gestes (poser, puis rouvrir l'\u00e9diteur). La barre de la saison manquait ici.
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);

    await user.selectOptions(screen.getByLabelText("Dur\u00e9e \u00e0 poser"), "120");
    await user.click(screen.getByRole("button", { name: "Lun 18:00" }));

    expect(createSlot).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 120, capacity: 1 }));
  });

  it("la dur\u00e9e \u00e0 poser SURVIT au changement de gymnase (comme en saison)", async () => {
    // L'\u00e9tat vit dans le parent : le panneau est remont\u00e9 par `key={selected.id}`, donc l'y
    // loger remettrait 90 min \u00e0 chaque changement de gymnase \u2014 le gestionnaire qui r\u00e9partit
    // des cr\u00e9neaux de 2h sur trois gymnases le repaierait \u00e0 chaque fois, sans le voir.
    extraVenuesState.value = [{ id: "v2", name: "Gymnase B", color: "#00ff00", canSplit: false, isActive: true }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);

    await user.selectOptions(screen.getByLabelText("Dur\u00e9e \u00e0 poser"), "120");
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");

    expect(screen.getByLabelText("Dur\u00e9e \u00e0 poser")).toHaveValue("120");
  });

  it("REFUSE une pose qui franchirait minuit, en nommant la dur\u00e9e que l'\u00e9cran offre d\u00e9sormais", async () => {
    // Le message de minuit \u00e9tait surcharg\u00e9 ici (\u00ab ajustez-la dans l'\u00e9diteur \u00bb) parce que la
    // pose n'offrait aucun r\u00e9glage. La barre existe : le message par d\u00e9faut, qui NOMME la
    // dur\u00e9e, redevient vrai \u2014 et le point de surcharge de `slotPlacementError` est retir\u00e9.
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);

    await user.selectOptions(screen.getByLabelText("Dur\u00e9e \u00e0 poser"), "150");
    await user.click(screen.getByRole("button", { name: "Lun 22:45" }));

    expect(createSlot).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith(expect.stringMatching(/ne peut pas durer 2h30 .*apr\u00e8s minuit/));
  });

  it("clic sur un cr\u00e9neau ouvre l'\u00e9diteur avec un choix de DUR\u00c9E (r\u00e9gression saison combl\u00e9e)", async () => {
    // Finding #6 : le panneau n'offrait aucun \u00e9diteur, dur\u00e9e fig\u00e9e \u00e0 90 min. On r\u00e9utilise
    // le geste de la saison : clic sur le cr\u00e9neau \u2192 modale jour/heure/dur\u00e9e.
    render(<PeriodVenues calendarEntryId="e1" />);
    await userEvent.click(screen.getByTitle(/^20:00 .*modifier$/));
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(screen.getByLabelText("Dur\u00e9e")).toBeInTheDocument();
  });

  it("supprimer un cr\u00e9neau passe par une confirmation qui annonce l'impact (jamais en silence)", async () => {
    // Finding #9 : la suppression partait sans confirmation, alors que le m\u00eame geste en
    // saison passe par DeleteConfirm. Invariant n\u00b04.
    const del = vi.fn();
    deletePeriodSlotImpl.value = del;
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));
    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(del).not.toHaveBeenCalled(); // pas avant confirmation
    // Round 2 finding #7 : supprimer un créneau de PÉRIODE ne touche QUE cette période — la
    // confirmation ne doit pas laisser croire qu'elle atteint « les plannings de période ».
    expect(screen.queryByText(/plannings de période/)).toBeNull();
    // La confirmation propose un bouton « Supprimer » définitif.
    const confirmButtons = screen.getAllByRole("button", { name: /Supprimer/ });
    await user.click(confirmButtons[confirmButtons.length - 1]);
    expect(del).toHaveBeenCalledWith("ps1", expect.anything());
    deletePeriodSlotImpl.value = deleteSlot;
  updateSlot.mockClear();
  delReservation.mockClear();
  venueCanSplitState.value = false;
  reservationsState.data = [];
  periodSlotOverride.value = null;
  });

  it("gymnase d\u00e9sactiv\u00e9 : la grille est inerte au CLAVIER (fieldset disabled), pas juste \u00e0 la souris", () => {
    // Finding #8 : pointer-events-none ne bloque que la souris. Un <fieldset disabled>
    // sort les boutons de l'ordre de tabulation et les d\u00e9sactive.
    overridesVenueState.data = [{ id: "o1", schedulePlanId: "plan-1", venueId: "v1", mode: "DISABLED" }];
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByRole("button", { name: "Lun 18:00" })).toBeDisabled();
    // La barre « À poser » est DANS le fieldset (revue #351) : laissée dehors, son indice
    // « cliquez la grille » restait lisible en pleine opacité au-dessus d'une grille gelée.
    // Le geste et son réglage s'éteignent ensemble.
    expect(screen.getByLabelText("Durée à poser")).toBeDisabled();
  });

  it("verrouille l'\u00e9tat actif/d\u00e9sactiv\u00e9 pendant la synchro (anti double-clic \u2192 422)", () => {
    // Finding #4 : entre le succ\u00e8s d'une mutation et le refetch des overrides, un second
    // clic repartait en POST create \u2192 422. Le bouton reste inerte tant que syncing.
    overridesFetchingState.value = true;
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByRole("button", { name: "D\u00e9sactiver" })).toBeDisabled();
    overridesFetchingState.value = false;
  });

  it("liste TOUS les gymnases interdits, pas seulement celui sélectionné (indicateur d'ensemble)", () => {
    // Revue #8 PR-B round 2 finding #5 : avec un sélecteur (une grille à la fois), le badge
    // par gymnase ne montre que le gymnase choisi ; un gymnase interdit non sélectionné
    // passait inaperçu. Une alerte d'ensemble nomme tous les interdits.
    // D3 (P2-22) — le libellé est désormais au grain JOUR, plus « INTERDIT cette période ».
    conflictState.venueIds = ["v1"];
    conflictState.closures = [{ constraintId: "cc", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [5, 6, 7] }];
    render(<PeriodVenues calendarEntryId="e1" />);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent(/Gymnase A.*Indispo ven–dim du 1\/5 au 10\/5 — Travaux/);
  });

  // D3 (P2-22) — une fermeture qui ne couvre QUE ven–dim n'est plus présentée comme un
  // interdit « toute la période » : le grain est le JOUR, pris de `weekdays` (serveur).
  it("nomme la fermeture au grain JOUR (« ven–dim »), plus « INTERDIT cette période »", () => {
    conflictState.venueIds = ["v1"];
    conflictState.closures = [{ constraintId: "cc", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [5, 6, 7] }];
    render(<PeriodVenues calendarEntryId="e1" />);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("Indispo ven–dim du 1/5 au 10/5 — Travaux");
    expect(alert).not.toHaveTextContent("INTERDIT cette période");
    expect(screen.queryByText(/toute la période/)).toBeNull();
  });

  it("dit « toute la période » quand les 7 jours de la fenêtre sont fermés", () => {
    conflictState.venueIds = ["v1"];
    conflictState.closures = [{ constraintId: "cc", venueId: "v1", title: "Congés", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [1, 2, 3, 4, 5, 6, 7] }];
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByRole("alert")).toHaveTextContent("Indispo toute la période du 1/5 au 10/5 — Congés");
  });

  // Indispo INFORMATIVE (2026-08-18) — le surfaçage P2-37 D6 (interrupteur caché, raison à sa
  // place) est PÉRIMÉ : le serveur accepte désormais le geste. L'interrupteur REVIENT sur un
  // gymnase entièrement fermé ; la raison RESTE affichée (information), pas à la place du geste.
  it("gymnase entièrement fermé : l'interrupteur REVIENT, la raison reste en information", async () => {
    conflictState.venueIds = ["v1"];
    conflictState.closures = [{ constraintId: "cc", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [1, 2, 3, 4, 5, 6, 7] }];
    conflictState.fullyClosedVenueIds = ["v1"];
    conflictState.effectiveClosedWeekdays = { v1: { "1": "default-incident", "2": "default-incident", "3": "default-incident", "4": "default-incident", "5": "default-incident", "6": "default-incident", "7": "default-incident" } };
    render(<PeriodVenues calendarEntryId="e1" />);

    // L'interrupteur est là et fonctionne (le serveur ne refuse plus).
    const toggle = screen.getByRole("button", { name: "Désactiver" });
    expect(toggle).toBeInTheDocument();
    await userEvent.click(toggle);
    expect(setMode).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", mode: "DISABLED" }));
    // La raison reste dite en clair — information, pas remplacement du geste (bandeau + en-tête).
    expect(screen.getAllByText(/Indispo toute la période du 1\/5 au 10\/5 — Travaux/).length).toBeGreaterThan(0);
  });

  // TÉMOIN (P2-37 D6) — une fermeture PARTIELLE ne change RIEN : le gymnase reste ACTIF, son
  // interrupteur reste là et fonctionne (les jours fermés sont déjà barrés dans la grille depuis
  // P2-22). Ce test échouerait si l'on désactivait un gymnase seulement PARTIELLEMENT fermé.
  it("fermeture PARTIELLE : l'interrupteur RESTE et pose le mode (témoin)", async () => {
    conflictState.venueIds = ["v1"];
    conflictState.closures = [{ constraintId: "cc", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [5, 6, 7] }];
    conflictState.fullyClosedVenueIds = []; // partielle : pas entièrement fermé
    render(<PeriodVenues calendarEntryId="e1" />);

    const toggle = screen.getByRole("button", { name: "Désactiver" });
    expect(toggle).toBeInTheDocument();
    await userEvent.click(toggle);
    expect(setMode).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", mode: "DISABLED" }));
  });

  it("désactiver un gymnase pose le mode, sans toucher à sa grille", async () => {
    render(<PeriodVenues calendarEntryId="e1" />);

    await userEvent.click(screen.getByRole("button", { name: "Désactiver" }));
    expect(setMode).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", mode: "DISABLED" }));
    // Aucune écriture de grille : c'est toute la promesse du mode.
    expect(createSlot).not.toHaveBeenCalled();
    expect(deleteSlot).not.toHaveBeenCalled();
    expect(resetGrid).not.toHaveBeenCalled();
  });

  it("réactiver retire la ligne et ne détruit RIEN — la grille revient telle quelle", async () => {
    // Arbitrage fondateur : l'état actif/désactivé et la source de la grille sont deux
    // contrôles distincts. Avec un sélecteur à 3 positions, réactiver aurait voulu dire
    // « hériter », donc recopier la saison — et perdre les épinglages que désactiver
    // avait justement préservés (revue #8, round 4).
    overridesVenueState.data = [{ id: "o1", schedulePlanId: "plan-1", venueId: "v1", mode: "DISABLED" }];
    render(<PeriodVenues calendarEntryId="e1" />);

    await userEvent.click(screen.getByRole("button", { name: "Réactiver" }));
    expect(clearMode).toHaveBeenCalledWith("o1");
    expect(resetGrid).not.toHaveBeenCalled();
    expect(deleteSlot).not.toHaveBeenCalled();
  });

  it("préserve la capacité d'un créneau de gymnase divisible à l'édition (pas de downgrade silencieux)", async () => {
    venueCanSplitState.value = true;
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00", durationMinutes: 90, capacity: 2, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateSlot).toHaveBeenCalledWith(expect.objectContaining({ body: expect.objectContaining({ capacity: 2 }) }), expect.anything());
  });

  it("envoie le libellé de groupe sur un créneau partageable (P2-17)", async () => {
    venueCanSplitState.value = true;
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00", durationMinutes: 90, capacity: 2, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));
    await user.type(screen.getByRole("textbox", { name: "Libellé de groupe" }), "CEC3");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateSlot).toHaveBeenCalledWith(expect.objectContaining({ body: expect.objectContaining({ capacity: 2, groupLabel: "CEC3" }) }), expect.anything());
  });

  it("n'offre PAS le libellé sur un créneau de capacité 1, et n'en envoie aucun", async () => {
    venueCanSplitState.value = true;
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));
    expect(screen.queryByRole("textbox", { name: "Libellé de groupe" })).toBeNull();
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateSlot).toHaveBeenCalledWith(expect.objectContaining({ body: expect.objectContaining({ groupLabel: null }) }), expect.anything());
  });

  it("redescendre à une seule équipe efface le libellé — jamais de 422 (P2-17)", async () => {
    venueCanSplitState.value = true;
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00", durationMinutes: 90, capacity: 2, groupLabel: "CEC3", schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));
    // Le libellé existant est présenté dans le champ…
    expect(screen.getByRole("textbox", { name: "Libellé de groupe" })).toHaveValue("CEC3");
    // …et redescendre la capacité à 1 le retire de l'écran ET du payload.
    await user.selectOptions(screen.getByLabelText("Capacité"), "1");
    expect(screen.queryByRole("textbox", { name: "Libellé de groupe" })).toBeNull();
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateSlot).toHaveBeenCalledWith(expect.objectContaining({ body: expect.objectContaining({ capacity: 1, groupLabel: null }) }), expect.anything());
  });

  it("déplacer un créneau réservé confirme, puis retire la réservation orpheline", async () => {
    reservationsState.data = [{ id: "r1", venueId: "v1", dayOfWeek: 3, startTime: "20:00:00" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));
    await user.selectOptions(screen.getByLabelText("Jour"), "2");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateSlot).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "Déplacer" }));
    expect(updateSlot).toHaveBeenCalled();
    expect(delReservation).toHaveBeenCalledWith("r1");
  });

  it("autorise l'édition d'un créneau du soir qui finit après 22:00 (pas de borne côté période)", async () => {
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 1, startTime: "21:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^21:00 .*modifier$/));
    await user.selectOptions(screen.getByLabelText("Jour"), "3");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateSlot).toHaveBeenCalled();
  });

  it("REFUSE d'enregistrer un créneau qui franchirait minuit, et ne l'envoie pas", async () => {
    // ⚠ Ce test garde le CÂBLAGE, pas la règle : `slotPlacementError` a ses propres tests
    // unitaires, mais on pouvait supprimer ses quatre appels sans qu'un seul test rougisse
    // (revue #349 round 2, CLAUDE.md §7.2 pt 5). Il faut donc vérifier ici qu'un geste
    // réel est bloqué, pas seulement qu'une fonction pure rend le bon message.
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 1, startTime: "20:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));

    await user.clear(screen.getByLabelText("Début"));
    await user.type(screen.getByLabelText("Début"), "23:30");
    await user.selectOptions(screen.getByLabelText("Durée"), "90");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(updateSlot).not.toHaveBeenCalled();
    expect(screen.getByRole("alert")).toHaveTextContent(/après minuit/);
  });

  it("affiche la durée COURANTE d'un créneau même absente de la liste proposée", async () => {
    // Même règle que le select « Jour » : un select ne peut pas montrer une valeur qu'il
    // n'offre pas. 30 min n'est pas dans `DURATIONS` mais l'API l'accepte (`Range(min: 15)`),
    // et le champ s'ouvrait VIDE — le gestionnaire lisait « pas de durée » sur un créneau
    // qui en a une.
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 1, startTime: "20:00:00", durationMinutes: 30, capacity: 1, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));

    expect(screen.getByLabelText("Durée")).toHaveValue("30");
  });

  it("laisse déplacer un créneau AU dimanche depuis la modale d'édition", async () => {
    // ⚠ Le select « Jour » portait sa PROPRE liste amputée du dimanche, distincte de la
    // géométrie de la grille : rendre le dimanche posable au clic l'aurait laissé
    // inatteignable ici, et un créneau du dimanche s'y serait ouvert sur un champ VIDE.
    // La règle « les sept jours » vaut partout où un jour se choisit, pas seulement dans
    // la grille (P4-37, CLAUDE.md §7.2 pt 1 : corriger la RÈGLE, pas le cas).
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 1, startTime: "20:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));

    await user.selectOptions(screen.getByLabelText("Jour"), "7");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(updateSlot).toHaveBeenCalledWith(expect.objectContaining({ body: expect.objectContaining({ dayOfWeek: 7 }) }), expect.anything());
  });

  it("ouvre un créneau DU dimanche sur son vrai jour, pas sur un champ vide", async () => {
    periodSlotOverride.value = [{ id: "ps1", venueId: "v1", dayOfWeek: 7, startTime: "20:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);
    await user.click(screen.getByTitle(/^20:00 .*modifier$/));

    // Sans l'option, le `value={7}` du select ne correspondait à rien : le champ
    // s'affichait vide, et toute autre retouche l'enregistrait sur un jour non lu.
    expect(screen.getByLabelText("Jour")).toHaveValue("7");
  });

  it("rend visible et supprimable un créneau sur un jour ABERRANT", async () => {
    // Round 2 finding #6 : un créneau sur un jour que la grille ne montre pas serait servi
    // au solveur tout en restant invisible. On le montre dans un bandeau d'alerte, avec un
    // bouton Supprimer, plutôt que de le laisser agir en silence.
    // ⚠ Le cas d'origine était le DIMANCHE ; P4-37 a traité la cause (les sept jours sont
    // rendus). Le filet ne rattrape donc plus qu'un jour hors semaine — donnée importée ou
    // dérive — et c'est ce cas-là que ce test garde désormais.
    const del = vi.fn();
    deletePeriodSlotImpl.value = del;
    periodSlotOverride.value = [{ id: "odd1", venueId: "v1", dayOfWeek: 9, startTime: "10:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);

    expect(screen.getByText(/jour non affichable/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Supprimer/ }));
    expect(del).toHaveBeenCalledWith("odd1");
    deletePeriodSlotImpl.value = deleteSlot;
  });

  // P4-37 — la CAUSE : le dimanche n'était pas affichable, donc un créneau posé ce jour-là
  // n'existait que dans le filet ci-dessus. Il vit maintenant dans la grille.
  it("affiche un créneau du dimanche DANS la grille, plus dans le filet", () => {
    periodSlotOverride.value = [{ id: "sun1", venueId: "v1", dayOfWeek: 7, startTime: "10:00:00", durationMinutes: 90, capacity: 1, schedulePlanId: "plan-1" }];
    render(<PeriodVenues calendarEntryId="e1" />);

    expect(screen.queryByText(/jour non affichable/)).toBeNull();
  });

  it("gèle la grille d'un gymnase désactivé au lieu de la laisser éditer", async () => {
    // La table ne stocke qu'un mode par gymnase : « vider » écraserait l'état désactivé.
    // On gèle donc la grille et on le DIT, plutôt que de perdre l'état en silence.
    overridesVenueState.data = [{ id: "o1", schedulePlanId: "plan-1", venueId: "v1", mode: "DISABLED" }];
    render(<PeriodVenues calendarEntryId="e1" />);

    expect(screen.getByText(/Désactivé cette période/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Reprendre la grille/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Vider la grille" })).toBeDisabled();
  });

  it("reprendre la grille prévient de ce qu'elle emporte, puis appelle l'action atomique", async () => {
    // « J'informe le gestionnaire qu'un changement de créneau de gymnase va supprimer
    // tous les créneaux réservés du gymnase » (fondateur).
    reservationsState.data = [{ id: "r1", venueId: "v1" }, { id: "r2", venueId: "v1" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);

    await user.click(screen.getByRole("button", { name: /Reprendre la grille/ }));
    expect(screen.getByText(/2 réservations sur ce gymnase seront supprimées/)).toBeInTheDocument();
    expect(resetGrid).not.toHaveBeenCalled(); // rien avant la confirmation

    await user.click(screen.getByRole("button", { name: "Reprendre" }));
    expect(resetGrid).toHaveBeenCalledWith("v1", expect.anything());
  });

  it("vider la grille appelle l'action atomique clear-grid, apr\u00e8s confirmation", async () => {
    // « Vider » est une ACTION, pas un PUT de mode BLANK : router par le mode serait un
    // no-op quand le mode ne change pas (garde d'idempotence), donc un « vidé » mensonger.
    // L'endpoint clear-grid vide \u00e0 chaque appel (revue #8 PR-B, finding #1).
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);

    await user.click(screen.getByRole("button", { name: "Vider la grille" }));
    expect(clearGrid).not.toHaveBeenCalled(); // rien avant confirmation

    await user.click(screen.getByRole("button", { name: "Vider" }));
    expect(clearGrid).toHaveBeenCalledWith("v1", expect.anything());
    expect(setMode).not.toHaveBeenCalled(); // jamais par un mode
  });

  it("reprendre appelle toujours l'action atomique reset-grid, jamais un enchaînement d'appels", async () => {
    // reset-grid vide ET recopie c\u00f4t\u00e9 serveur en UN appel : jamais la s\u00e9quence « poser
    // VIERGE puis supprimer la ligne », qui laisserait une grille vid\u00e9e si le second
    // \u00e9chouait (revue #8 PR-B). Vrai quel que soit l'\u00e9tat de d\u00e9part.
    overridesVenueState.data = [{ id: "o1", schedulePlanId: "plan-1", venueId: "v1", mode: "BLANK" }];
    const user = userEvent.setup();
    render(<PeriodVenues calendarEntryId="e1" />);

    await user.click(screen.getByRole("button", { name: /Reprendre la grille/ }));
    await user.click(screen.getByRole("button", { name: "Reprendre" }));
    expect(resetGrid).toHaveBeenCalledWith("v1", expect.anything());
    expect(clearMode).not.toHaveBeenCalled();
  });
});

describe("PeriodVenues — coches jour (indispo informative, 2026-08-18)", () => {
  it("montre une rangée de 7 coches jour, cochées quand le jour est ouvert", () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByRole("checkbox", { name: "Lundi — Gymnase A" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "Dimanche — Gymnase A" })).toBeChecked();
  });

  it("un jour fermé par l'indisponibilité déclarée est décoché, avec sa provenance", () => {
    conflictState.effectiveClosedWeekdays = { v1: { "6": "default-incident" } };
    render(<PeriodVenues calendarEntryId="e1" />);
    const sat = screen.getByRole("checkbox", { name: "Samedi — Gymnase A" });
    expect(sat).not.toBeChecked();
    expect(sat.getAttribute("title")).toMatch(/indisponibilité déclarée/);
  });

  it("un jour décoché à la main porte la provenance « décoché manuellement »", () => {
    conflictState.effectiveClosedWeekdays = { v1: { "6": "manual" } };
    render(<PeriodVenues calendarEntryId="e1" />);
    const sat = screen.getByRole("checkbox", { name: "Samedi — Gymnase A" });
    expect(sat).not.toBeChecked();
    expect(sat.getAttribute("title")).toMatch(/décoché manuellement/);
  });

  it("un jour rouvert malgré l'indisponibilité est coché, avec sa provenance", () => {
    overridesVenueState.data = [{ id: "o1", schedulePlanId: "plan-1", venueId: "v1", mode: null, dayOverrides: { "6": "OPEN" } }];
    render(<PeriodVenues calendarEntryId="e1" />);
    const sat = screen.getByRole("checkbox", { name: "Samedi — Gymnase A" });
    expect(sat).toBeChecked();
    expect(sat.getAttribute("title")).toMatch(/réactivé malgré/);
  });

  it("décocher un jour ouvert écrit CLOSED dans le masque", async () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("checkbox", { name: "Samedi — Gymnase A" }));
    expect(setMode).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOverrides: { 6: "CLOSED" } }));
  });

  it("cocher un jour fermé par l'incident écrit OPEN dans le masque", async () => {
    conflictState.effectiveClosedWeekdays = { v1: { "6": "default-incident" } };
    render(<PeriodVenues calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("checkbox", { name: "Samedi — Gymnase A" }));
    expect(setMode).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOverrides: { 6: "OPEN" } }));
  });

  it("recliquer un jour vers son défaut RETIRE la ligne quand le masque redevient vide (DELETE)", async () => {
    overridesVenueState.data = [{ id: "o1", schedulePlanId: "plan-1", venueId: "v1", mode: null, dayOverrides: { "6": "OPEN" } }];
    render(<PeriodVenues calendarEntryId="e1" />);
    // Le jour est ouvert (réactivé) : le recliquer retire l'entrée OPEN → masque vide → DELETE.
    await userEvent.click(screen.getByRole("checkbox", { name: "Samedi — Gymnase A" }));
    expect(clearMode).toHaveBeenCalledWith("o1");
  });

  it("« Réactiver malgré l'indisponibilité » ouvre les 7 jours (masque OPEN×7)", async () => {
    conflictState.venueIds = ["v1"];
    conflictState.closures = [{ constraintId: "cc", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [1, 2, 3, 4, 5, 6, 7] }];
    conflictState.fullyClosedVenueIds = ["v1"];
    conflictState.effectiveClosedWeekdays = { v1: { "1": "default-incident", "2": "default-incident", "3": "default-incident", "4": "default-incident", "5": "default-incident", "6": "default-incident", "7": "default-incident" } };
    render(<PeriodVenues calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("button", { name: /Réactiver malgré/ }));
    expect(setMode).toHaveBeenCalledWith(expect.objectContaining({ venueId: "v1", dayOverrides: { 1: "OPEN", 2: "OPEN", 3: "OPEN", 4: "OPEN", 5: "OPEN", 6: "OPEN", 7: "OPEN" } }));
  });

  it("« Revenir au défaut » supprime la ligne (DELETE)", async () => {
    overridesVenueState.data = [{ id: "o1", schedulePlanId: "plan-1", venueId: "v1", mode: null, dayOverrides: { "6": "CLOSED" } }];
    conflictState.effectiveClosedWeekdays = { v1: { "6": "manual" } };
    render(<PeriodVenues calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("button", { name: "Revenir au défaut" }));
    expect(clearMode).toHaveBeenCalledWith("o1");
  });

  it("gymnase DÉSACTIVÉ : la rangée de jours est grisée et conserve les coches", () => {
    overridesVenueState.data = [{ id: "o1", schedulePlanId: "plan-1", venueId: "v1", mode: "DISABLED", dayOverrides: { "6": "CLOSED" } }];
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByText(/coches conservées/)).toBeInTheDocument();
    expect(screen.getByRole("checkbox", { name: "Samedi — Gymnase A" })).toBeDisabled();
  });

  it("le bandeau d'ensemble liste aussi les jours décochés à la main", () => {
    conflictState.effectiveClosedWeekdays = { v1: { "6": "manual" } };
    render(<PeriodVenues calendarEntryId="e1" />);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent(/décoché/);
  });
});

// P2-43 volet (ii) — le sélecteur de gymnase TAISAIT l'état : un gymnase DÉSACTIVÉ (mode) était
// invisible, une fermeture partielle indistincte d'une totale. Chaque option porte désormais son
// état effectif SERVI (désactivé / indisponible toute la période / fermé {jours} / rien).
describe("PeriodVenues — le sélecteur de gymnase porte l'état effectif (P2-43 volet ii)", () => {
  const picker = () => screen.getByLabelText("Gymnase");

  it("annonce « désactivé » (mode DISABLED — invisible auparavant)", () => {
    extraVenuesState.value = [{ id: "v2", name: "Gymnase B", color: null, canSplit: false, isActive: true }];
    conflictState.disabledVenueIds = ["v2"];
    render(<PeriodVenues calendarEntryId="e1" />);

    expect(within(picker()).getByRole("option", { name: "Gymnase B — désactivé" })).toBeInTheDocument();
  });

  it("annonce « indisponible toute la période » (gymnase entièrement fermé)", () => {
    conflictState.fullyClosedVenueIds = ["v1"];
    conflictState.effectiveClosedWeekdays = { v1: { "1": "default-incident", "2": "default-incident", "3": "default-incident", "4": "default-incident", "5": "default-incident", "6": "default-incident", "7": "default-incident" } };
    render(<PeriodVenues calendarEntryId="e1" />);

    expect(within(picker()).getByRole("option", { name: "Gymnase A — indisponible toute la période" })).toBeInTheDocument();
  });

  it("annonce « fermé {jours} » (fermeture partielle — masque manuel OU indispo déclarée)", () => {
    conflictState.effectiveClosedWeekdays = { v1: { "6": "default-incident", "7": "manual" } };
    render(<PeriodVenues calendarEntryId="e1" />);

    expect(within(picker()).getByRole("option", { name: "Gymnase A — fermé samedi, dimanche" })).toBeInTheDocument();
  });

  it("rien pour un gymnase OUVERT (pas de bruit)", () => {
    render(<PeriodVenues calendarEntryId="e1" />);

    expect(within(picker()).getByRole("option", { name: "Gymnase A" })).toBeInTheDocument();
  });
});

// P2-43 volet (i) — la modale d'édition d'un créneau sur un jour fermé ne le disait pas. Décision
// fondateur (a) : poser reste PERMIS, l'éditeur doit seulement LE DIRE. Aucun blocage nouveau.
describe("PeriodSlotEditor — dit qu'un créneau tombe un jour fermé (P2-43 volet i)", () => {
  const openEditor = async () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    await userEvent.click(screen.getByTitle(/^20:00 .*modifier$/));
    return screen.getByRole("dialog");
  };

  it("indisponibilité déclarée : « Créneau inactif — le mercredi est fermé (indisponibilité déclarée…) »", async () => {
    // ps1 est un MERCREDI (jour 3) : on ferme le mercredi (indisponibilité déclarée).
    conflictState.effectiveClosedWeekdays = { v1: { "3": "default-incident" } };
    conflictState.closures = [{ constraintId: "cc", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [3] }];
    const dialog = await openEditor();

    expect(within(dialog).getByText(/Créneau inactif — le mercredi est fermé/)).toBeInTheDocument();
    expect(within(dialog).getByText(/indisponibilité déclarée/)).toBeInTheDocument();
    // Aucun blocage : « Enregistrer » reste actif.
    expect(within(dialog).getByRole("button", { name: "Enregistrer" })).toBeEnabled();
  });

  it("décoché à la main : la cause le dit (« décoché manuellement »)", async () => {
    conflictState.effectiveClosedWeekdays = { v1: { "3": "manual" } };
    const dialog = await openEditor();

    expect(within(dialog).getByText(/Créneau inactif — le mercredi est fermé/)).toBeInTheDocument();
    expect(within(dialog).getByText(/décoché manuellement/)).toBeInTheDocument();
  });

  it("jour OUVERT : aucune mention (pas de bruit, pas de blocage)", async () => {
    const dialog = await openEditor();

    expect(within(dialog).queryByText(/Créneau inactif/)).not.toBeInTheDocument();
  });
});

describe("PeriodStructure — l'ancre des réglages (ADR-0002 inv. 5, lot C2)", () => {
  // CE test est celui qui manquait : les deux composants lisaient par le DÉCLENCHEUR
  // pendant qu'ils écrivaient par le PLAN. Les deux ids sont des `string` → tsc muet,
  // et l'API répond 200 avec une liste vide → checklist silencieusement fausse, cases
  // qui reviennent au rechargement, 422 au re-clic. Aucun test ne pouvait le voir : les
  // mocks jetaient leur argument.
  it("PeriodTeams lit ses réglages par le PLAN, jamais par la période", () => {
    render(<PeriodTeams calendarEntryId="e1" />);
    expect(teamOverridesAnchor.value).toBe("plan-1");
  });

  it("PeriodConstraints lit ses deux jeux de réglages par le PLAN", () => {
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(constraintOverridesAnchor.value).toBe("plan-1");
    expect(teamOverridesAnchor.value).toBe("plan-1");
  });

  // Le NR de C2 ne couvrait QUE PeriodTeams et PeriodConstraints — PeriodVenues est
  // resté hors de vue, et le lot C3 l'a laissé lire par le déclencheur : le gymnase
  // prêté était confirmé à l'écran (l'UI relisait avec le MÊME mauvais id, donc
  // cohérente avec elle-même) mais n'atteignait jamais le solveur. D'où ce test.
  it("compte les créneaux de LA PÉRIODE par gymnase, pas ceux de la saison", () => {
    // Revue #8 round 4 — une période POSSÈDE sa grille : c'est elle qui part au solveur,
    // et elle peut différer de celle de la saison (gymnase vidé, désactivé, créneau
    // ajouté). Afficher le compte SAISONNIER en face de chaque gymnase annonçait donc au
    // gestionnaire une disponibilité qui n'était pas celle qu'on allait servir. Ici la
    // saison n'a aucun créneau et la période en a un : le compte doit être celui-là.
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(screen.getByText("1 créneau")).toBeInTheDocument();
  });

  it("PeriodVenues lit les créneaux de sa grille par le PLAN", () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(periodSlotsAnchor.value).toBe("plan-1");
  });

  // Le NR du round 1 n'assertait que la LECTURE — et c'est par l'ÉCRITURE que le bug est
  // passé au round 2 : le hook d'écriture recevait bien le plan, mais rien n'empêchait de
  // muter AVANT qu'il soit résolu (ancre null = « base » → le gymnase prêté atterrissait
  // sur le socle du club). On assert donc les deux.
  it("PeriodVenues ÉCRIT les créneaux de sa grille par le PLAN", () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    expect(periodSlotWriteAnchor.value).toBe("plan-1");
  });

  it("PeriodVenues n'écrit RIEN tant que le plan n'est pas résolu (sinon : sur le socle)", () => {
    // Sans ancre certaine, `null` est une ancre LÉGITIME (= le socle), donc le serveur
    // accepterait : le créneau deviendrait PERMANENT et nourrirait toutes les
    // générations de la saison. La carte n'est donc pas rendue du tout tant que le plan
    // n'est pas résolu — plus de surface d'écriture, plus de garde à oublier.
    planState.data = undefined; // plan en cours de chargement, ou GET en échec
    render(<PeriodVenues calendarEntryId="e1" />);

    expect(screen.getByText(/Chargement des créneaux de la période/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Lun 18:00" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Désactiver" })).toBeNull();
    expect(createSlot).not.toHaveBeenCalled();
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
  });

  // ── NR P4-20 / P4-1 — un écran qui échoue le DIT, et donne une issue ───────────
  //
  // Avant la porte, un GET du plan en échec produisait quatre mensonges différents
  // selon l'écran : « Chargement… » éternel, cases muettes, bouton mort, récap vert.
  // Le contrat est désormais dans le TYPE (union discriminée) : oublier `failed`
  // ne compile pas.

  it("T1 — PeriodVenues dit l'échec du plan et propose de réessayer", async () => {
    const user = userEvent.setup();
    planState.data = undefined;
    planState.failed = true;

    render(<PeriodVenues calendarEntryId="e1" />);

    expect(screen.queryByText(/Chargement des créneaux de la période/)).toBeNull();
    expect(screen.getByRole("alert")).toHaveTextContent(/Impossible de charger les créneaux/);
    await user.click(screen.getByRole("button", { name: "Réessayer" }));
    expect(planState.retry).toHaveBeenCalled();

    planState.failed = false;
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
  });

  it("T1 — PeriodTeams et PeriodConstraints disent l'échec plutôt que d'offrir des gestes muets", () => {
    planState.data = undefined;
    planState.failed = true;

    const teams = render(<PeriodTeams calendarEntryId="e1" />);
    expect(screen.getByRole("alert")).toHaveTextContent(/ne seraient pas enregistrées/);
    expect(screen.queryByRole("button", { name: "Fanion seul" })).toBeNull();
    teams.unmount();

    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.getByRole("alert")).toHaveTextContent(/ne seraient pas enregistrées/);

    planState.failed = false;
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
  });

  it("T2 — une ancre EN CACHE survit à un refetch raté : l'écran reste fonctionnel", () => {
    // react-query garde `data` et bascule `status` à error sur un refetch d'arrière-plan.
    // Basculer l'écran en erreur ici détruirait une vue qui marche (le bug de ma 1re
    // tentative) : tant qu'on a une ancre, on est en `period`, pas en `failed`.
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
    planState.failed = true;

    render(<PeriodVenues calendarEntryId="e1" />);

    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.getByRole("button", { name: "Lun 18:00" })).toBeInTheDocument();

    planState.failed = false;
  });

  it("T4 — une grille de période qui n'a pas chargé ne se rend PAS comme une grille vide", async () => {
    const user = userEvent.setup();
    // L'ancre est bonne ; ce sont les CRÉNEAUX qui échouent. Coercés en `[]`, ils
    // affichaient « 0 créneau » : le gestionnaire re-dessine sa semaine → doublons.
    periodSlotsState.failed = true;

    render(<PeriodVenues calendarEntryId="e1" />);

    expect(screen.getByRole("alert")).toHaveTextContent(/Impossible de charger la grille/);
    expect(screen.queryByRole("button", { name: "Lun 18:00" })).toBeNull();
    await user.click(screen.getByRole("button", { name: "Réessayer" }));
    expect(periodSlotsState.retry).toHaveBeenCalled();

    periodSlotsState.failed = false;
  });

  it("PeriodTeams n'offre AUCUN geste tant que le plan n'est pas résolu", () => {
    planState.data = undefined; // plan pas encore résolu → aucune écriture possible

    render(<PeriodTeams calendarEntryId="e1" />);

    // Le piège d'origine : sans ancre, chaque upsert bailait en Promise.resolve() → zéro
    // rejet → « Sélection appliquée » était confirmé alors qu'AUCUNE ligne n'était écrite,
    // et l'overlay partait ensuite avec tout le club actif. Un succès qui ment est pire
    // qu'une erreur. Depuis la PORTE (P4-20), la garantie est plus forte que « ne pas
    // confirmer » : la surface d'écriture n'existe pas du tout.
    expect(screen.queryByRole("button", { name: "Fanion seul" })).toBeNull();
    expect(screen.getByText(/Chargement des équipes de la période/)).toBeInTheDocument();
    expect(toast.success).not.toHaveBeenCalledWith("Sélection appliquée");
    expect(createOverride).not.toHaveBeenCalled();
    planState.data = { id: "plan-1", teamSelectionInitialized: false };
  });

  it("PeriodVenues écrit dès que le plan est résolu", async () => {
    render(<PeriodVenues calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("button", { name: "Lun 18:00" }));
    expect(createSlot).toHaveBeenCalled();
    expect(periodSlotWriteAnchor.value).toBe("plan-1");
  });
});

describe("PeriodConstraints — inherited constraints toggle", () => {
  // ── Fondateur 2026-07-24 (#9) : la section vit DANS l'onglet de sa famille ──
  it("family filter: only shows inherited constraints of the given family tab", () => {
    constraintsState.data = [
      constraint({ id: "kt", name: "Pas après 20h", family: "TIME" }),
      constraint({ id: "kd", name: "Pas le dimanche", family: "DAY" }),
    ];
    render(<PeriodConstraints calendarEntryId="e1" family="TIME" />);
    expect(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).toBeInTheDocument();
    expect(screen.queryByRole("checkbox", { name: "Pas le dimanche appliquée cette période" })).toBeNull();
  });

  it("family filter: renders NOTHING (not an empty section) when the tab has no inherited constraint", () => {
    constraintsState.data = [constraint({ id: "kt", name: "Pas après 20h", family: "TIME" })];
    const { container } = render(<PeriodConstraints calendarEntryId="e1" family="COACH_AVAILABILITY" />);
    expect(container).toBeEmptyDOMElement();
  });

  // Revue #284 : sur ERREUR on rend le panneau AVEC un message d'erreur explicite. Le
  // masquer, ou pire afficher « Aucune contrainte permanente », laisserait le gestionnaire
  // conclure que rien n'est hérité et valider la période à tort (P4-1).
  it("family filter: on a constraints-query ERROR, renders the panel and says so — never 'aucune contrainte'", () => {
    constraintsState.data = [];
    constraintsState.isError = true;
    render(<PeriodConstraints calendarEntryId="e1" family="TIME" />);
    expect(screen.getByText("Contraintes du planning principal")).toBeInTheDocument();
    expect(screen.getByText(/Impossible de charger les contraintes/)).toBeInTheDocument();
    expect(screen.queryByText("Aucune contrainte permanente.")).toBeNull();
  });

  // Revue #284 round 2 : la présence de la section attend que les contraintes soient
  // RÉSOLUES — sinon le cadre titré apparaît puis disparaît. Données NON VIDES : sans ça le
  // test passerait aussi sans la garde (il serait vacant — c'était le défaut du round 1).
  it("family filter: renders nothing WHILE the constraints query is loading, even with data pending", () => {
    constraintsState.data = [constraint({ id: "kt", name: "Pas après 20h", family: "TIME" })];
    constraintsState.isLoading = true;
    const { container } = render(<PeriodConstraints calendarEntryId="e1" family="TIME" />);
    expect(container).toBeEmptyDOMElement();
  });

  // Revue #284 round 2 : `hidden()` dépend de la résolution des TAGS — décider la présence
  // avant qu'ils n'arrivent ferait apparaître une contrainte taguée puis la retirer.
  it("family filter: renders nothing while the TAG queries are still resolving (hidden() depends on them)", () => {
    constraintsState.data = [constraint({ id: "kt", name: "Pas après 20h pour les féminines", family: "TIME", config: { targetTag: "FEMININE" } })];
    tagsLoadingState.value = true;
    const { container } = render(<PeriodConstraints calendarEntryId="e1" family="TIME" />);
    expect(container).toBeEmptyDOMElement();
  });

  // Revue #284 round 2 : une fois rendue, la section ne doit PLUS disparaître quand les
  // requêtes d'override basculent en chargement (plan qui se résout) — c'est le saut de
  // mise en page introduit par le round 1.
  it("family filter: stays rendered while the OVERRIDE queries load (they drive state, not presence)", () => {
    constraintsState.data = [constraint({ id: "kt", name: "Pas après 20h", family: "TIME" })];
    constraintOverridesLoadingState.value = true;
    render(<PeriodConstraints calendarEntryId="e1" family="TIME" />);
    expect(screen.getByText("Contraintes du planning principal")).toBeInTheDocument();
  });

  it("closure: lists the club's permanent constraints, all kept by default", () => {
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    const checkbox = screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" });
    expect(checkbox).toBeChecked();
    expect(createConstraintOverride).not.toHaveBeenCalled();
  });

  it("toggling a constraint off creates a disabling override", async () => {
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" }));
    expect(createConstraintOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", constraintId: "k1", isActive: false }, expect.anything());
  });

  it("toggling a disabled constraint back on deletes its override", async () => {
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    constraintOverridesState.data = [{ id: "ov1", constraintId: "k1", isActive: false, schedulePlanId: "plan-1" }];
    render(<PeriodConstraints calendarEntryId="e1" />);
    // Rendered unchecked (disabled) → clicking re-activates by removing the row.
    await userEvent.click(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" }));
    expect(deleteConstraintOverride).toHaveBeenCalledWith("ov1", expect.anything());
  });

  it("a rapid second click does NOT fire a duplicate create (in-flight guard)", async () => {
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    const checkbox = screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" });
    // The mock mutate never calls onSettled, so the write stays "in flight": the
    // optimistic state disables the box and a second click is swallowed.
    await userEvent.click(checkbox);
    await userEvent.click(checkbox);
    expect(createConstraintOverride).toHaveBeenCalledTimes(1);
  });

  it("blocks a second constraint's toggle while the first write is in flight (one mutation at a time)", async () => {
    constraintsState.data = [
      constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" }),
      constraint({ id: "k2", name: "Jamais le lundi", ruleType: "HARD" }),
    ];
    render(<PeriodConstraints calendarEntryId="e1" />);
    // Toggle k1 off → it stays in flight (mock never settles), so every checkbox
    // is disabled and k2 cannot fire a concurrent write (which would rebind the
    // shared mutation observer and strand k1's onSettled).
    await userEvent.click(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" }));
    expect(screen.getByRole("checkbox", { name: "Jamais le lundi appliquée cette période" })).toBeDisabled();
    await userEvent.click(screen.getByRole("checkbox", { name: "Jamais le lundi appliquée cette période" }));
    expect(createConstraintOverride).toHaveBeenCalledTimes(1);
  });

  it("reprise (holiday): default follows the team selection", () => {
    entryState.data = { periodType: "holiday" };
    overridesState.data = [{ id: "to1", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }]; // t2 en pause
    constraintsState.data = [
      constraint({ id: "kc", name: "Club rule", ruleType: "PREFERRED", scope: "CLUB", scopeTargetId: null }),
      constraint({ id: "kf", name: "Gym rule", ruleType: "PREFERRED", scope: "FACILITY", scopeTargetId: "v1" }),
      constraint({ id: "kt1", name: "SM1 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t1" }), // équipe active
      constraint({ id: "kt2", name: "U13 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t2" }), // équipe en pause
    ];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.getByRole("checkbox", { name: "Club rule appliquée cette période" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "Gym rule appliquée cette période" })).not.toBeChecked();
    expect(screen.getByRole("checkbox", { name: "SM1 rule appliquée cette période" })).toBeChecked();
    expect(screen.getByRole("checkbox", { name: "U13 rule appliquée cette période" })).not.toBeChecked();
  });

  it("reprise: waits for team overrides before rendering (no wrong-default flash)", () => {
    entryState.data = { periodType: "holiday" };
    teamOverridesLoadingState.value = true; // team overrides still fetching
    constraintsState.data = [constraint({ id: "kt", name: "U13 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t2" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    // The team-derived default isn't known yet → the checklist must not render (nor be clickable).
    expect(screen.queryByRole("checkbox", { name: "U13 rule appliquée cette période" })).not.toBeInTheDocument();
  });

  it("waits for the period overrides query too (no create→422 flash)", () => {
    constraintOverridesLoadingState.value = true; // period overrides still fetching
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.queryByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).not.toBeInTheDocument();
  });

  it("disables toggles (read-only) when the period overrides query errors — no create→422", async () => {
    constraintOverridesErrorState.value = true; // overrides list unavailable
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />); // closure, renders (not hidden)
    const checkbox = screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" });
    expect(checkbox).toBeDisabled();
    await userEvent.click(checkbox);
    expect(createConstraintOverride).not.toHaveBeenCalled();
  });

  it("locks only TEAM constraints on a team-overrides fetch error (non-TEAM stay usable)", () => {
    teamOverridesErrorState.value = true; // can't tell which teams are paused
    constraintsState.data = [
      constraint({ id: "kt", name: "U13 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t2" }),
      constraint({ id: "kc", name: "Club rule", ruleType: "PREFERRED", scope: "CLUB", scopeTargetId: null }),
    ];
    render(<PeriodConstraints calendarEntryId="e1" />); // closure
    expect(screen.getByRole("checkbox", { name: "U13 rule appliquée cette période" })).toBeDisabled();
    expect(screen.getByRole("checkbox", { name: "Club rule appliquée cette période" })).not.toBeDisabled();
  });

  it("a TEAM constraint of a paused team is non-applicable (disabled, struck, never toggled)", async () => {
    overridesState.data = [{ id: "to1", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }]; // t2 en pause
    constraintsState.data = [constraint({ id: "kt2", name: "U13 rule", ruleType: "PREFERRED", scope: "TEAM", scopeTargetId: "t2" })];
    render(<PeriodConstraints calendarEntryId="e1" />); // closure by default
    const checkbox = screen.getByRole("checkbox", { name: "U13 rule appliquée cette période" });
    expect(checkbox).toBeDisabled();
    expect(checkbox).not.toBeChecked();
    expect(screen.getByText("(équipe en pause)")).toBeInTheDocument();
    await userEvent.click(checkbox);
    expect(createConstraintOverride).not.toHaveBeenCalled();
  });

  it("reprise: keeping a facility (off by default) creates an isActive=true override", async () => {
    entryState.data = { periodType: "holiday" };
    constraintsState.data = [constraint({ id: "kf", name: "Gym rule", ruleType: "PREFERRED", scope: "FACILITY", scopeTargetId: "v1" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    await userEvent.click(screen.getByRole("checkbox", { name: "Gym rule appliquée cette période" }));
    expect(createConstraintOverride).toHaveBeenCalledWith({ schedulePlanId: "plan-1", constraintId: "kf", isActive: true }, expect.anything());
  });

  it("re-toggling a redundant isActive=true override updates instead of duplicating (P4-12d, no 422)", async () => {
    // closure default = kept; a stale isActive=true row → box checked. Unchecking must PUT, not POST.
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    constraintOverridesState.data = [{ id: "ov1", constraintId: "k1", isActive: true, schedulePlanId: "plan-1" }];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).toBeChecked();
    await userEvent.click(screen.getByRole("checkbox", { name: "Pas après 20h appliquée cette période" }));
    expect(updateConstraintOverride).toHaveBeenCalledWith({ id: "ov1", body: { schedulePlanId: "plan-1", constraintId: "k1", isActive: false } }, expect.anything());
    expect(createConstraintOverride).not.toHaveBeenCalled();
  });

  it("hides a CLUB+targetTag constraint when every tagged team is paused", () => {
    entryState.data = { periodType: "holiday" };
    tagsState.data = [{ id: "tag-adu", name: "ADULTE", color: null, isSystem: true, axis: "AGE" }];
    tagAssignmentsState.data = [{ id: "a1", teamId: "t2", tagId: "tag-adu", seasonId: "s1" }];
    overridesState.data = [{ id: "to1", teamId: "t2", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    constraintsState.data = [constraint({ id: "cg", name: "Groupe Adulte (+ de 18) · pas après 21:00", ruleType: "PREFERRED", scope: "CLUB", scopeTargetId: null, family: "TIME", config: { targetTag: "ADULTE" }, isActive: true })];

    render(<PeriodConstraints calendarEntryId="e1" />);

    expect(screen.queryByRole("checkbox", { name: "Groupe Adulte (+ de 18) · pas après 21:00 appliquée cette période" })).not.toBeInTheDocument();
  });

  // P2-29 — la cible peut être « (∩ targetTags) − (∪ excludeTags) » : on la masque quand la
  // résolution sur les équipes ACTIVES est vide, comme le backend saute la contrainte.
  it("hides a CLUB constraint with targetTags when its resolved ACTIVE set is empty", () => {
    entryState.data = { periodType: "holiday" };
    tagsState.data = [
      { id: "tag-adu", name: "ADULTE", color: null, isSystem: true, axis: "AGE" },
      { id: "tag-comp", name: "COMPETITION", color: null, isSystem: true, axis: "NIVEAU" },
    ];
    // t1 porte les DEUX tags → l'intersection le vise ; mais t1 est en pause → ensemble actif vide.
    tagAssignmentsState.data = [
      { id: "a1", teamId: "t1", tagId: "tag-adu", seasonId: "s1" },
      { id: "a2", teamId: "t1", tagId: "tag-comp", seasonId: "s1" },
    ];
    overridesState.data = [{ id: "to1", teamId: "t1", isActive: false, sessionsPerWeek: null, schedulePlanId: "plan-1" }];
    constraintsState.data = [constraint({ id: "cc", name: "Groupe combiné", ruleType: "PREFERRED", scope: "CLUB", scopeTargetId: null, family: "TIME", config: { targetTags: ["ADULTE", "COMPETITION"] }, isActive: true })];

    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.queryByRole("checkbox", { name: "Groupe combiné appliquée cette période" })).not.toBeInTheDocument();
  });

  it("keeps a CLUB constraint with targetTags visible while a resolved team stays active (P2-29)", () => {
    entryState.data = { periodType: "holiday" };
    tagsState.data = [
      { id: "tag-adu", name: "ADULTE", color: null, isSystem: true, axis: "AGE" },
      { id: "tag-comp", name: "COMPETITION", color: null, isSystem: true, axis: "NIVEAU" },
    ];
    tagAssignmentsState.data = [
      { id: "a1", teamId: "t1", tagId: "tag-adu", seasonId: "s1" },
      { id: "a2", teamId: "t1", tagId: "tag-comp", seasonId: "s1" },
    ];
    overridesState.data = []; // t1 actif
    constraintsState.data = [constraint({ id: "cc", name: "Groupe combiné", ruleType: "PREFERRED", scope: "CLUB", scopeTargetId: null, family: "TIME", config: { targetTags: ["ADULTE", "COMPETITION"] }, isActive: true })];

    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.getByRole("checkbox", { name: "Groupe combiné appliquée cette période" })).toBeInTheDocument();
  });

  it("does not render on a non-overlay period type (cutoff) — no dead override rows", () => {
    entryState.data = { periodType: "cutoff" };
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.queryByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).not.toBeInTheDocument();
  });

  it("does not render while the calendar entry is still loading (no holiday flash / dead override)", () => {
    entryState.data = undefined; // entry not resolved yet
    constraintsState.data = [constraint({ id: "k1", name: "Pas après 20h", ruleType: "PREFERRED" })];
    render(<PeriodConstraints calendarEntryId="e1" />);
    expect(screen.queryByRole("checkbox", { name: "Pas après 20h appliquée cette période" })).not.toBeInTheDocument();
  });
});
