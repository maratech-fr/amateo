import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { EntryConflictsResponse, SchedulePlan } from "@/features/cockpit/api";
import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders } from "@/test/utils";

import { EngineTimeoutError, EngineVerificationInterruptedError, fillSchedule, getDiagnostics, getSlots, getSocleDeviation, getTeams, getTrainingSlots, getVenues, listSchedules, lockSlot, moveSlot, MoveRejectedError, OverlaysExistError, placeSlot, reopenSchedule, TargetLockedError, validateSchedule } from "./api";
import type { Schedule } from "./api";
import { PlanningPage } from "./PlanningPage";
import { usePlanningStore } from "./store";
import { useWizardStore } from "@/features/wizard/store";

// The api layer is mocked (not HTTP): ky + jsdom + MSW disagree on AbortSignal,
// so we exercise the screen from the api boundary down — queries, PlanningPage
// logic, the grid + all its panels — with fixture data.
const SID = "sched-1";

vi.mock("./api", () => {
  // A real error class so PlanningPage's `error instanceof OverlaysExistError`
  // escalation branch fires from the mocked reopen/validate rejections.
  class OverlaysExistError extends Error {
    public count: number;
    public overlays: unknown[];

    constructor(count: number, overlays: unknown[]) {
      super("overlays");
      this.name = "OverlaysExistError";
      this.count = count;
      this.overlays = overlays;
    }
  }
  // F2b : mêmes classes réelles pour que `error instanceof …` branche depuis un moveSlot moqué.
  class MoveRejectedError extends Error {
    public violations: { rule: string; message: string; conflictingTeamId?: string | null; teamId?: string | null; coachId?: string | null; venueId?: string | null; dayOfWeek?: number | null; startTime?: string | null }[];

    constructor(violations: { rule: string; message: string; conflictingTeamId?: string | null; teamId?: string | null; coachId?: string | null; venueId?: string | null; dayOfWeek?: number | null; startTime?: string | null }[]) {
      super("move_rejected");
      this.name = "MoveRejectedError";
      this.violations = violations;
    }
  }
  class GenerationInProgressError extends Error {
    constructor() {
      super("generation_in_progress");
      this.name = "GenerationInProgressError";
    }
  }
  // P2-30 : verrou de cible / refus codé — classes RÉELLES pour que `instanceof` branche.
  class TargetLockedError extends Error {
    constructor(message: string) {
      super(message);
      this.name = "TargetLockedError";
    }
  }
  class SlotEditError extends Error {
    public code: string;

    constructor(code: string, message: string) {
      super(message);
      this.name = "SlotEditError";
      this.code = code;
    }
  }
  // Le timeout moteur (504) : classe RÉELLE pour que `error instanceof EngineTimeoutError` branche
  // vers l'état d'échec de la modale d'essai (et le toast nommé du geste réel).
  class EngineTimeoutError extends Error {
    constructor(message: string) {
      super(message);
      this.name = "EngineTimeoutError";
    }
  }
  // La vérification INTERROMPUE côté client (timeout ky / abort) — classe RÉELLE pour que
  // `error instanceof EngineVerificationInterruptedError` branche vers le message honnête.
  class EngineVerificationInterruptedError extends Error {
    constructor() {
      super("verification_interrupted");
      this.name = "EngineVerificationInterruptedError";
    }
  }
  // Lot C PR-2 : l'ABANDON volontaire (sous-classe) — classe RÉELLE pour que le garde
  // `error instanceof VerdictAbandonedError` de doPlace/doMove ne casse pas (instanceof undefined).
  class VerdictAbandonedError extends EngineVerificationInterruptedError {
    constructor() {
      super();
      this.name = "VerdictAbandonedError";
    }
  }
  return {
  OverlaysExistError,
  MoveRejectedError,
  GenerationInProgressError,
  TargetLockedError,
  SlotEditError,
  EngineTimeoutError,
  EngineVerificationInterruptedError,
  VerdictAbandonedError,
  reopenSchedule: vi.fn(),
  fillSchedule: vi.fn(() => Promise.resolve({ id: "sched-fill" })),
  listSchedules: vi.fn(() => Promise.resolve([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" }])),
  getSlots: vi.fn(() =>
    Promise.resolve([
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null },
    ]),
  ),
  getConstraints: vi.fn(() => Promise.resolve([])),
  // P2-44 PR-5 : par défaut aucun écart (le panneau ne rend rien) — les cas dédiés surchargent.
  getSocleDeviation: vi.fn(() => Promise.resolve({ socleScheduleId: "socle", moved: [], unplaced: [] })),
  // P2-52 : par défaut aucun match ne perd sa salle → l'annonce de validation ne s'affiche pas,
  // « Valider » part comme aujourd'hui (les cas dédiés surchargent).
  getValidateImpact: vi.fn(() => Promise.resolve({ orphanedFixtures: 0, declaredOrphanedFixtures: 0 })),
  getDiagnostics: vi.fn(() =>
    Promise.resolve([
      { id: "diag-1", scheduleId: SID, type: "conflict", severity: "ERROR", teamId: null, venueId: "venue-1", coachId: null, dayOfWeek: null, startTime: null, ruleKey: null, message: "Conflit de gymnase.", suggestions: [], causes: [], openCandidates: null },
      // The solver's own "unused_slot" warning for the ts-2 empty window.
      { id: "diag-unused-slot-venue-1-2-19:00", scheduleId: SID, type: "unused_slot", severity: "WARNING", teamId: null, venueId: "venue-1", coachId: null, dayOfWeek: null, startTime: null, ruleKey: null, message: "Créneau disponible non utilisé : Gymnase Alpha (mardi de 19:00 à 20:30).", suggestions: [], causes: [], openCandidates: null },
    ]),
  ),
  getTeams: vi.fn(() => Promise.resolve([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 }])),
  getVenues: vi.fn(() => Promise.resolve([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }])),
  // ts-1 matches slot-1 (filled) ; ts-2 is a defined-but-unfilled window → "vide".
  getTrainingSlots: vi.fn(() =>
    Promise.resolve([
      { id: "ts-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1 },
      { id: "ts-2", venueId: "venue-1", dayOfWeek: 2, startTime: "19:00:00", durationMinutes: 90, capacity: 1 },
    ]),
  ),
  getCoaches: vi.fn(() => Promise.resolve([{ id: "coach-1", firstName: "Jean", lastName: "Dupont" }])),
  getCategories: vi.fn(() => Promise.resolve([{ id: "cat-1", name: "U11" }])),
  getTeamCoaches: vi.fn(() => Promise.resolve([{ id: "tc-1", teamId: "team-1", coachId: "coach-1", role: "MAIN" }])),
  getCoachPlayers: vi.fn(() => Promise.resolve([])),
  lockSlot: vi.fn(),
  moveSlot: vi.fn(),
  placeSlot: vi.fn(),
  generateSchedule: vi.fn(),
  validateSchedule: vi.fn(),
  STATUS_LABELS: { DRAFT: "Brouillon", PENDING: "En attente", GENERATING: "Génération…", COMPLETED: "Terminé", FAILED: "Échec" },
  };
});

const navigate = vi.fn();
vi.mock("react-router", async (orig) => ({ ...(await orig<typeof import("react-router")>()), useNavigate: () => navigate }));

// Le flux Mercure ouvre un `EventSource` (absent de jsdom) dès qu'une génération est
// en vol : on le neutralise pour éprouver GenerationWaiting sans toucher au réseau.
vi.mock("@/features/planning/lib/scheduleStream", () => ({
  useScheduleStream: () => false,
  isScheduleStreamConnected: () => false,
}));

const { meState, renameSpy, plansState, conflictsState, reservationsState, teamOverridesState } = vi.hoisted(() => ({
  meState: { chosenScheduleId: null as string | null },
  renameSpy: vi.fn(),
  // Typé sur le VRAI contrat : un type inline recopié laisserait passer un champ ajouté à
  // `SchedulePlan` sans que ces fixtures soient recalées (revue #339 round 3).
  plansState: { plans: [] as SchedulePlan[] },
  // P2-43 volet (v) — l'état de fermeture SERVI (`/calendar-entries/{id}/conflicts`) : c'est
  // désormais LUI qui porte `disabledVenueIds` (plus la re-dérivation des overrides) ET les
  // couples fermés que la grille MARQUE. `data: undefined` = query non résolue (fail-open).
  conflictsState: { data: undefined as EntryConflictsResponse | undefined, isError: false },
  // Réservations servies sur un planning FAILED (pseudo-créneaux lecture seule).
  reservationsState: { rows: [] as { id: string; schedulePlanId: string | null; teamId: string; venueId: string; dayOfWeek: number; startTime: string; durationMinutes: number }[] },
  // P2-30 : les overrides d'équipe de la période (seuil/désactivation de dérive).
  teamOverridesState: { rows: [] as { id: string; schedulePlanId: string; teamId: string; isActive: boolean; sessionsPerWeek: number | null }[] },
}));

// P2-15 : les réglages de gymnases de la période — un gymnase DÉSACTIVÉ garde ses
// créneaux en base, l'écran doit malgré tout cesser de l'afficher.
vi.mock("@/features/wizard/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/wizard/queries")>()),
  useReservations: () => ({ data: reservationsState.rows }),
  // P2-30 : overrides d'équipe de la période (seuil de dérive). `data` défini → readState ready.
  useTeamPeriodOverrides: () => ({ data: teamOverridesState.rows, isError: false }),
  // Le wrap de créneau résout CLUB+tag via ces hooks (mêmes que le wizard) : mockés vides
  // ici, la résolution tag→équipes est gardée par applicableConstraints/SlotDetail.
  useWizardTeamTags: () => ({ data: [] }),
  useWizardTeamTagAssignments: () => ({ data: [] }),
}));

// Partiel : seul `useSchedulePlans` est simulé (l'en-tête y lit le nom du plan
// affiché) — le reste du module cockpit reste réel.
vi.mock("@/features/cockpit/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/cockpit/queries")>()),
  useSchedulePlans: () => ({ data: plansState.plans }),
  // P2-43 volet (v) — l'écran lit l'état de fermeture SERVI. Une entrée nulle (socle, ou plan de
  // période pas encore résolu) = pas de données, comme la vraie query désactivée.
  useEntryConflicts: (entryId: string | null) => ({ data: null === entryId ? undefined : conflictsState.data, isError: conflictsState.isError, isFetching: false, refetch: vi.fn() }),
}));

/** Un `EntryConflictsResponse` complet, aux défauts ouverts — les cas ne renseignent que ce qui compte. */
function makeConflicts(partial: Partial<EntryConflictsResponse> = {}): EntryConflictsResponse {
  return {
    entryId: "e",
    venueIds: [],
    conflicts: [],
    closures: [],
    fullyClosedVenueIds: [],
    effectiveClosedWeekdays: {},
    disabledVenueIds: [],
    seasonPlanChosen: true,
    ...partial,
  };
}

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({
    data: {
      id: "u1", membershipStatus: "active", role: "admin", club: { id: "c", name: "C" },
      seasonPlan: { id: "plan-1", name: "Planning A", chosenScheduleId: meState.chosenScheduleId, hasFinishedVersion: true },
      seasons: [{ id: "sn1", name: "2025-2026", startDate: "2025-09-01", endDate: "2026-06-30", isCurrent: true, isReadonly: false }], currentSeasonId: "sn1",
    },
  }),
  useWorkingSeason: () => ({ id: "sn1", name: "2025-2026", startDate: "2025-09-01", endDate: "2026-06-30", isCurrent: true, isReadonly: false }),
}));
vi.mock("@/features/auth/queries", () => ({
  useRenamePlanning: () => ({ mutate: renameSpy, isPending: false }),
}));

// P2-8 : la toolbar lit les gestes (Valider…) du bloc `capabilities` serveur, plus d'un
// calcul client — une version de travail terminée « ordinaire » est validable.
const workVersion: Schedule[] = [{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", capabilities: { canDelete: false, canValidate: true, canRegenerateFrom: false, versionsDeletedOnValidate: 0, overlaysDroppedOnValidate: 0 } }];

beforeEach(() => {
  meState.chosenScheduleId = null;
  renameSpy.mockClear();
  plansState.plans = [];
  conflictsState.data = undefined;
  conflictsState.isError = false;
  reservationsState.rows = [];
  teamOverridesState.rows = [];
  // Ré-armement explicite : ces trois-là sont réécrits par des cas (couche de période,
  // gymnase désactivé) et `mockResolvedValue` SURVIT au test suivant — une fuite qui
  // rendrait un échec ultérieur incompréhensible.
  vi.mocked(getSlots).mockResolvedValue([
    { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null },
  ]);
  vi.mocked(getVenues).mockResolvedValue([{ id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" }]);
  vi.mocked(getTrainingSlots).mockResolvedValue([
    { id: "ts-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1 },
    { id: "ts-2", venueId: "venue-1", dayOfWeek: 2, startTime: "19:00:00", durationMinutes: 90, capacity: 1 },
  ]);
  // Default: an editable work version. Re-armed per test so a case that swaps in
  // an in-force version (read-only → panels hidden) cannot leak into the next.
  vi.mocked(listSchedules).mockResolvedValue(workVersion);
  // Ré-armement explicite : le cas « masque les diagnostics (aucune ERREUR) » réécrit
  // getDiagnostics en WARNING-only ; `mockResolvedValue` SURVIT au test suivant (même
  // piège que ci-dessus). Sans ce reset, « groups diagnostics » et le cas ERREUR
  // perdraient leur « Conflit de gymnase » (ERROR) et rougiraient sans rapport.
  vi.mocked(getDiagnostics).mockResolvedValue([
    { id: "diag-1", scheduleId: SID, type: "conflict", severity: "ERROR", teamId: null, venueId: "venue-1", coachId: null, dayOfWeek: null, startTime: null, ruleKey: null, message: "Conflit de gymnase.", suggestions: [], causes: [], openCandidates: null },
    { id: "diag-unused-slot-venue-1-2-19:00", scheduleId: SID, type: "unused_slot", severity: "WARNING", teamId: null, venueId: "venue-1", coachId: null, dayOfWeek: null, startTime: null, ruleKey: null, message: "Créneau disponible non utilisé : Gymnase Alpha (mardi de 19:00 à 20:30).", suggestions: [], causes: [], openCandidates: null },
  ]);
  // P2-44 PR-5 : ré-armement (mockResolvedValue survit) — défaut « aucun écart », les cas surchargent.
  vi.mocked(getSocleDeviation).mockResolvedValue({ socleScheduleId: "socle", moved: [], unplaced: [] });
  navigate.mockClear();
  usePlanningStore.setState({ viewMode: "gymnase", selectedScheduleId: null, selectedSlotId: null, resourceFilter: [] });
});

describe("PlanningPage (integration)", () => {
  // The "validate to unlock the cockpit" banner moved to the cockpit itself
  // (state 2) — PlanningPage no longer carries it (see CockpitPage.test).

  it("renders the base planning grid: team + coach on the slot, on an editable work version", async () => {
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(await screen.findByText("Jean Dupont")).toBeInTheDocument();
    // Standalone /planning (consultation) hides the toolbar's version SELECTOR — see
    // PlanningToolbar.test (le badge de statut, lui, s'affiche désormais en standalone).
    // ⚠ L'assertion sur le score a été retirée ici : P4-39 l'ayant supprimé partout, elle
    // ne distinguait plus rien (elle serait restée verte même si le standalone se mettait
    // à tout afficher). Le score est gardé là où il vivait, dans PlanningToolbar.test.
    expect(screen.queryByRole("combobox", { name: /version du planning/i })).not.toBeInTheDocument();
    // « principal » qualifie LE planning de la saison — il s'affiche donc aussi sur une
    // version de travail. Nouveau contrat (2026-08-20) : sur /planning autonome, les gestes
    // de TRAVAIL (Valider/Régénérer/Supprimer/sélecteur) vivent au wizard ; cette version
    // n'est pas en vigueur, donc pas de Rouvrir non plus — d'où l'absence de Valider ici.
    expect(screen.getByText("principal")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
  });

  it("lit les créneaux du SOCLE quand la version affichée est celle de la saison", async () => {
    // Revue #8 round 4 — l'écran calculait ses cases vides sur la grille de SAISON quelle
    // que soit la version affichée, alors que l'export PDF remis aux coachs lit celle de
    // la version affichée. Deux vues du même planning qui ne coïncidaient pas, alors que
    // le code affirme qu'elles montrent la même chose.
    vi.mocked(getTrainingSlots).mockClear();
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(vi.mocked(getTrainingSlots)).toHaveBeenCalledWith(null);
  });

  it("lit la grille DE LA PÉRIODE quand la version affichée est un planning de période", async () => {
    vi.mocked(getTrainingSlots).mockClear();
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Toussaint V1", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "toussaint-plan" },
    ]);
    // Le cockpit navigue vers un planning de période avec la version en sélection —
    // l'atterrissage par défaut, lui, vise le socle.
    usePlanningStore.setState({ selectedScheduleId: SID });
    renderWithProviders(<PlanningPage />);

    // La couche du socle peut être demandée le temps que la liste des versions arrive ;
    // ce qui compte est celle sur laquelle l'écran se STABILISE.
    await vi.waitFor(() => expect(vi.mocked(getTrainingSlots).mock.lastCall).toEqual(["toussaint-plan"]));
  });

  // P2-20 (retour fondateur 2026-07-31) — LE défaut : le stylo « Renommer » passait
  // `me.seasonPlan.id` EN DUR (PlanningPage.tsx), donc renommer un planning de période
  // renommait le planning de la SAISON. Le fondateur a renommé sa reprise du 17 août et
  // a retrouvé son planning de saison rebaptisé. ADR-0002 inv. 12 : le nom vit sur LE
  // plan — celui de la version affichée.
  it("renomme le plan de la PÉRIODE affichée, jamais celui de la saison", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Version de période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Reprise d'été S1", startDate: "2026-08-17", calendarEntryId: "e-ete", chosenScheduleId: null, teamSelectionInitialized: true }];
    usePlanningStore.setState({ selectedScheduleId: SID });
    renderWithProviders(<PlanningPage />);

    // Le titre montre le nom du PLAN de période — il affichait « Planning A » (la saison).
    expect(await screen.findByRole("heading", { name: "Reprise d'été S1" })).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /renommer le planning/i }));
    const field = screen.getByRole("textbox", { name: /nom du planning/i });
    // Le champ se pré-remplissait du nom de la SAISON : on prouve qu'il porte celui du plan affiché.
    expect(field).toHaveValue("Reprise d'été S1");
    await userEvent.clear(field);
    await userEvent.type(field, "Reprise d'été S2{Enter}");

    expect(renameSpy).toHaveBeenCalledWith({ planId: "ete-plan", name: "Reprise d'été S2" });
  });

  it("renomme bien le plan de SAISON quand c'est lui qui est affiché", async () => {
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByRole("heading", { name: "Planning A" })).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /renommer le planning/i }));
    await userEvent.clear(screen.getByRole("textbox", { name: /nom du planning/i }));
    await userEvent.type(screen.getByRole("textbox", { name: /nom du planning/i }), "Saison 26-27{Enter}");

    expect(renameSpy).toHaveBeenCalledWith({ planId: "plan-1", name: "Saison 26-27" });
  });

  // Retour fondateur : « quand l'écran charge pour le planning overlay il y a une
  // application qui mouline » — la grille restait affichée telle quelle, rien ne disait
  // que ça travaillait. On bascule d'une version à l'autre : les créneaux de la 2e sont
  // EN VOL (promesse non résolue), la grille reste visible mais VOILÉE (indicateur +
  // aria-busy), puis nette une fois les créneaux arrivés.
  it("voile la zone de grille pendant le (re)chargement des créneaux d'une version, puis le retire", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
      { id: "sched-2", name: "Toussaint V1", status: "COMPLETED", score: 8000, createdAt: "2026-01-02T00:00:00Z", updatedAt: "2026-01-02T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "toussaint-plan" },
    ]);
    let releaseSecond: (rows: unknown[]) => void = () => {};
    const secondSlots = new Promise<unknown[]>((resolve) => {
      releaseSecond = resolve;
    });
    vi.mocked(getSlots).mockImplementation((id: string) =>
      "sched-2" === id
        ? (secondSlots as Promise<Awaited<ReturnType<typeof getSlots>>>)
        : Promise.resolve([{ id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null }]),
    );

    renderWithProviders(<PlanningPage />);
    // La version initiale est affichée : la grille montre l'équipe.
    expect(await screen.findByText("U11")).toBeInTheDocument();
    // Rien ne mouline au repos.
    expect(screen.queryByText(/chargement des créneaux/i)).not.toBeInTheDocument();

    // Bascule vers la 2e version : ses créneaux sont en vol.
    act(() => usePlanningStore.setState({ selectedScheduleId: "sched-2" }));

    // La grille reste affichée (ancien contenu) MAIS voilée : indicateur + aria-busy.
    expect(await screen.findByText(/chargement des créneaux/i)).toBeInTheDocument();
    expect(screen.getByText("U11")).toBeInTheDocument();
    expect(document.querySelector('[aria-busy="true"]')).not.toBeNull();

    // Les créneaux arrivent : le voile disparaît, la grille redevient nette.
    await act(async () => {
      releaseSecond([{ id: "slot-2", scheduleId: "sched-2", teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 3, startTime: "17:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null }]);
      await secondSlots;
    });
    await waitFor(() => expect(screen.queryByText(/chargement des créneaux/i)).not.toBeInTheDocument());
    expect(document.querySelector('[aria-busy="true"]')).toBeNull();
  });

  // L'écran d'attente de génération a son propre rendu : le voile de chargement des
  // créneaux (qui, lui, s'attache à la GRILLE) ne doit pas s'y superposer.
  it("n'ajoute PAS le voile de chargement par-dessus l'écran d'attente de génération", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Planning A", status: "PENDING", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
    ]);
    // Les créneaux d'un planning en génération sont en vol : sans garde, le voile s'y collerait.
    vi.mocked(getSlots).mockImplementation(() => new Promise(() => {}));

    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText(/génération du planning/i)).toBeInTheDocument();
    expect(screen.queryByText(/chargement des créneaux/i)).not.toBeInTheDocument();
  });

  // Retour coordinateur : au PREMIER chargement d'une version (aucune donnée précédente),
  // l'écran affichait « Planning vide » PENDANT le fetch — un message FAUX. Tant que les
  // créneaux sont en vol sans données, on montre l'état de chargement, jamais « vide ».
  it("premier chargement (créneaux en vol, aucune donnée) : état de chargement, PAS « Planning vide »", async () => {
    vi.mocked(getSlots).mockImplementation(() => new Promise(() => {}));

    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText(/chargement des créneaux/i)).toBeInTheDocument();
    expect(screen.queryByText(/planning vide/i)).not.toBeInTheDocument();
  });

  // Une fois la réponse arrivée et RÉELLEMENT vide, « Planning vide » revient (le vrai vide).
  it("réponse arrivée réellement vide → « Planning vide »", async () => {
    vi.mocked(getSlots).mockResolvedValue([]);

    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText(/planning vide/i)).toBeInTheDocument();
    expect(screen.queryByText(/chargement des créneaux/i)).not.toBeInTheDocument();
  });

  // Revue #339 : un club qui n'a JAMAIS généré n'a aucune version, donc aucun plan
  // « affiché » — l'en-tête perdait le nom du planning de saison ET son stylo, si bien que
  // le gestionnaire ne pouvait plus le nommer avant d'avoir généré. Le contexte par défaut
  // EST la saison. PREUVE DE CHUTE : sans le repli, le titre vaut « Planning ».
  it("garde le nom et le stylo du plan de saison quand le club n'a aucune version", async () => {
    vi.mocked(listSchedules).mockResolvedValue([]);
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByRole("heading", { name: "Planning A" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /renommer le planning/i })).toBeInTheDocument();
  });

  // Un nom vidé n'est pas un renommage : on n'écrase pas une identité par du vide (la
  // colonne est NOT NULL). Le champ se referme, le titre reste — c'est le retour.
  it("n'écrit rien quand on valide un nom vidé", async () => {
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByRole("heading", { name: "Planning A" })).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /renommer le planning/i }));
    await userEvent.clear(screen.getByRole("textbox", { name: /nom du planning/i }));
    await userEvent.type(screen.getByRole("textbox", { name: /nom du planning/i }), "{Enter}");

    expect(renameSpy).not.toHaveBeenCalled();
    expect(screen.getByRole("heading", { name: "Planning A" })).toBeInTheDocument();
  });

  // Le plan d'une période pas encore chargé (collection en vol) : on ne propose pas un
  // geste dont on n'a pas la cible — c'est cette absence de cible qui faisait retomber
  // l'écriture sur le plan de saison.
  it("masque le stylo tant que le plan de la période affichée n'est pas résolu", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Version de période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    usePlanningStore.setState({ selectedScheduleId: SID });
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /renommer le planning/i })).not.toBeInTheDocument();
  });

  // P2-15 (retour fondateur) : « à la génération je vois TOUS les gymnases alors que je ne
  // travaille qu'avec un seul, ça fait du bruit pour rien ». Un gymnase désactivé pour la
  // période garde ses créneaux en base — le backend les écarte du payload, il ne les
  // supprime pas — donc l'écran doit filtrer à la SOURCE.
  // P2-15 round 2 — on filtre ce qui est OFFERT, jamais ce qui EXISTE. Une séance déjà
  // placée dans un gymnase depuis désactivé reste à l'écran ET dans l'export (rendu côté
  // serveur, il ne connaît pas ce filtre) : la cacher faisait diverger le PDF remis aux
  // coachs de ce que le gestionnaire voyait, et rendait une grille blanche sans un mot
  // quand toute la version y était placée. On l'annonce, on n'escamote pas.
  it("garde les séances déjà placées dans un gymnase désactivé, et le dit", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Été", startDate: "2026-08-17", calendarEntryId: "e", chosenScheduleId: null, teamSelectionInitialized: true }];
    // La séance par défaut (slot-1) est placée dans venue-1, que l'on désactive ensuite.
    conflictsState.data = makeConflicts({ disabledVenueIds: ["venue-1"] });
    usePlanningStore.setState({ selectedScheduleId: SID, viewMode: "gymnase" });
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText(/1 séance\(s\) de ce planning sont placées dans un gymnase désactivé/)).toBeInTheDocument();
    // La séance est TOUJOURS là : l'export la contient, l'écran ne doit pas la nier.
    expect(screen.getByText("Gymnase Alpha")).toBeInTheDocument();
  });

  // Génération en ÉCHEC : « par défaut il y a au moins les créneaux réservés qui
  // doivent s'afficher » (fondateur 2026-08-05). Les réservations sont des verrous
  // posés par le gestionnaire — elles existent indépendamment du résultat du solveur.
  it("affiche les créneaux réservés (lecture seule) quand la génération est en échec", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Planning A", status: "FAILED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
    ]);
    vi.mocked(getSlots).mockResolvedValue([]); // l'échec ne produit AUCUN créneau
    reservationsState.rows = [
      { id: "res-1", schedulePlanId: null, teamId: "team-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90 },
    ];
    usePlanningStore.setState({ selectedScheduleId: SID, viewMode: "equipe" });
    renderWithProviders(<PlanningPage />);

    // La réservation s'affiche dans la grille (équipe résolue), et le bandeau dit que
    // ce ne sont QUE les réservations — pas un planning généré, et l'export est vide.
    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(screen.getByText(/La génération a échoué/)).toBeInTheDocument();
    expect(screen.getByText(/Seuls vos créneaux réservés sont affichés/)).toBeInTheDocument();
    expect(screen.queryByText("Planning vide")).not.toBeInTheDocument();
  });

  it("échec SANS réservation : état vide dédié, pas « Planning vide »", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Planning A", status: "FAILED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
    ]);
    vi.mocked(getSlots).mockResolvedValue([]);
    usePlanningStore.setState({ selectedScheduleId: SID });
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("Génération en échec")).toBeInTheDocument();
    expect(screen.queryByText("Planning vide")).not.toBeInTheDocument();
  });

  it("une réservation d'un gymnase désactivé pour la période ne s'affiche pas sur un échec", async () => {
    // Miroir du payload : `reservationsInScope` écarte les réservations d'un gymnase
    // désactivé — l'écran ne doit pas montrer un créneau que le solveur n'a jamais vu.
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Période", status: "FAILED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Été", startDate: "2026-08-17", calendarEntryId: "e", chosenScheduleId: null, teamSelectionInitialized: true }];
    vi.mocked(getSlots).mockResolvedValue([]);
    conflictsState.data = makeConflicts({ disabledVenueIds: ["venue-1"] });
    reservationsState.rows = [
      { id: "res-1", schedulePlanId: "ete-plan", teamId: "team-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90 },
    ];
    usePlanningStore.setState({ selectedScheduleId: SID, viewMode: "equipe" });
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("Génération en échec")).toBeInTheDocument();
    expect(screen.queryByText("U11")).not.toBeInTheDocument();
  });

  it("n'affiche pas un gymnase désactivé pour la période", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Été", startDate: "2026-08-17", calendarEntryId: "e", chosenScheduleId: null, teamSelectionInitialized: true }];
    // DEUX gymnases : sans un gymnase qui RESTE, la grille est vide et l'assertion
    // négative passerait pour la mauvaise raison (rien ne s'affiche).
    vi.mocked(getVenues).mockResolvedValue([
      { id: "venue-1", name: "Gymnase Alpha", color: "#00aa00" },
      { id: "venue-2", name: "Gymnase Beta", color: "#0000aa" },
    ]);
    vi.mocked(getTrainingSlots).mockResolvedValue([
      { id: "ts-1", venueId: "venue-1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, capacity: 1 },
      { id: "ts-3", venueId: "venue-2", dayOfWeek: 3, startTime: "17:00:00", durationMinutes: 90, capacity: 1 },
    ]);
    // La séance placée est déplacée sur le gymnase qui RESTE : ce test épingle le sort des
    // FENÊTRES LIBRES d'un gymnase désactivé (le bruit du retour terrain), pas celui des
    // séances déjà placées — c'est le test au-dessus qui les garde.
    vi.mocked(getSlots).mockResolvedValue([
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-2", coachId: null, dayOfWeek: 3, startTime: "17:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null },
    ]);
    conflictsState.data = makeConflicts({ disabledVenueIds: ["venue-1"] });
    usePlanningStore.setState({ selectedScheduleId: SID, viewMode: "gymnase" });
    renderWithProviders(<PlanningPage />);

    // ⚠ On ATTEND d'abord que l'écran soit chargé (une assertion négative passe sinon dès
    // le premier tick, sur le spinner — ce test était un FAUX VERT, il restait vert avec le
    // filtre supprimé : constaté en revue #342). On s'ancre sur un contenu qui ne dépend
    // PAS du gymnase, puis on affirme l'absence.
    // Le gymnase ACTIF s'affiche — c'est lui qui prouve que l'écran a bien chargé (une
    // assertion négative seule passerait dès le premier tick, sur le spinner : ce test
    // était un FAUX VERT, vert même avec le filtre supprimé — constaté en revue #342).
    expect(await screen.findByText("Gymnase Beta")).toBeInTheDocument();
    expect(screen.queryByText("Gymnase Alpha")).not.toBeInTheDocument();
  });

  it("standalone /planning : sur la version EN VIGUEUR, offre « Rouvrir » et le badge, jamais « Valider »", async () => {
    // Nouveau contrat (2026-08-20) : /planning est l'écran de la version en vigueur —
    // il porte le badge de statut et « Rouvrir » (qui mène au wizard étape Génération).
    vi.mocked(listSchedules).mockResolvedValue([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true }]);
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    // Being pointed at IS being validated: the only way forward is « Rouvrir ».
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /rouvrir/i })).toBeInTheDocument();
    expect(screen.getByText("Terminé")).toBeInTheDocument();
  });

  it("embarqué (wizard) : sur la version EN VIGUEUR, n'offre PLUS « Rouvrir » (déplacé sur /planning) ni « Valider »", async () => {
    // La falsification symétrique : en embedded, la version en vigueur ne montre ni
    // Valider (no-op) ni Rouvrir (le geste vit désormais sur /planning autonome).
    vi.mocked(listSchedules).mockResolvedValue([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true }]);
    renderWithProviders(<PlanningPage embedded />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /valider/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /rouvrir/i })).not.toBeInTheDocument();
  });

  // F2c : une contrainte a changé depuis la génération → planning PÉRIMÉ (pas faux). Une seule
  // bannière, qui nomme la cause et propose de régénérer pour SAVOIR.
  it("shows the stale banner when a constraint changed since generation, on an editable plan", async () => {
    vi.mocked(listSchedules).mockResolvedValue([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", constraintsChangedSinceGeneration: true }]);
    renderWithProviders(<PlanningPage />);

    const banner = await screen.findByText(/une contrainte a changé/i);
    expect(banner).toBeInTheDocument();
    // Modifiable → « Régénérez » (pas de « Rouvrez »), et le mot est « périmé », jamais « faux ».
    expect(banner).toHaveTextContent(/Régénérez/);
    expect(banner).toHaveTextContent(/périmé/);
    expect(banner).not.toHaveTextContent(/Rouvrez ce planning/);
  });

  // P4-87 : une DONNÉE DU CLUB (gymnase, coach, créneau…) a changé depuis la génération →
  // même bannière unifiée, cause nommée « les données du club ont changé ».
  it("shows the stale banner when a club resource changed since generation", async () => {
    vi.mocked(listSchedules).mockResolvedValue([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", resourcesChangedSinceGeneration: true }]);
    renderWithProviders(<PlanningPage />);

    const banner = await screen.findByText(/les données du club ont changé/i);
    expect(banner).toHaveTextContent(/périmé/);
    expect(banner).toHaveTextContent(/Régénérez/);
  });

  // Le cas du planning VALIDÉ : marqué comme les autres, MAIS il est en lecture seule — la
  // bannière doit proposer rouvrir PUIS régénérer, jamais un « Régénérer » qui finit en 409.
  it("on a validated (in-force) plan, the stale banner offers reopen-then-regenerate", async () => {
    vi.mocked(listSchedules).mockResolvedValue([{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true, constraintsChangedSinceGeneration: true }]);
    renderWithProviders(<PlanningPage />);

    const banner = await screen.findByText(/une contrainte a changé/i);
    expect(banner).toHaveTextContent(/Rouvrez ce planning, puis régénérez/);
  });

  it("switches to the coach view (coach resolved from the team)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");

    await user.click(screen.getByRole("button", { name: "Par coach" }));

    // The coach becomes a column header; the slot's secondary line is now the venue.
    expect(await screen.findAllByText("Jean Dupont")).not.toHaveLength(0);
    expect(await screen.findByText("Gymnase Alpha")).toBeInTheDocument();
  });

  // P2-33 — la 4e vue « Par jour » : les gymnases restent en colonnes, et le changement de
  // vue applique la RÈGLE COMMUNE (setViewMode remet selectedSlotId + resourceFilter à zéro,
  // exactement comme gymnase/coach/équipe) — le détail ouvert se referme, ce n'est pas une
  // incohérence propre à cette vue.
  it("bascule en vue « Par jour » : grille conservée, et le créneau sélectionné se referme (règle commune)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    // Ouvrir le détail d'un créneau…
    await user.click(await screen.findByText("U11"));
    expect(await screen.findByText(/90 min/)).toBeInTheDocument();

    // …puis passer en vue jour : le détail se referme (selectedSlotId remis à zéro par
    // setViewMode, comme pour tout changement de vue), la grille reste affichée.
    await user.click(screen.getByRole("button", { name: "Par jour" }));
    expect(screen.queryByText(/90 min/)).not.toBeInTheDocument();
    expect(await screen.findByText("U11")).toBeInTheDocument();
    // Les gymnases restent en colonnes (au moins un en-tête « Gymnase Alpha »).
    expect((await screen.findAllByText("Gymnase Alpha")).length).toBeGreaterThan(0);
  });

  it("opens the slot detail on click", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    await user.click(await screen.findByText("U11"));

    // Le panneau ouvert affiche la sous-ligne compacte (B1) : catégorie · durée · Coach.
    expect(await screen.findByText(/90 min/)).toBeInTheDocument();
  });

  // Retour fondateur : « quand je sélectionne un créneau, réduire automatiquement le panel de
  // diagnostique (sinon c'est impossible de le relancer) ». On REPLIE (pas masque) : la barre
  // repliée garde le compte + la sévérité max visibles, rouvre d'un clic, et se restaure à la
  // fermeture du créneau. Rien n'est enterré.
  it("REPLIE les diagnostics à la sélection d'un créneau — la barre garde compte + sévérité — et les RESTAURE à la fermeture", async () => {
    const user = userEvent.setup();
    // Que des ALERTES : le cas ERREUR (exception d'hier retirée) est traité par le test suivant.
    vi.mocked(getDiagnostics).mockResolvedValue([
      { id: "w1", scheduleId: SID, type: "unused_slot", severity: "WARNING", teamId: null, venueId: "venue-1", coachId: null, dayOfWeek: null, startTime: null, ruleKey: null, message: "Créneau libre.", suggestions: [], causes: [], openCandidates: null },
    ]);
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");
    // Déplier les diagnostics (repliés par défaut en boucle de travail).
    await user.click(screen.getByRole("button", { name: /Diagnostics du système/ }));
    expect(await screen.findByRole("heading", { name: "Diagnostics du système" })).toBeInTheDocument();

    // Sélection d'un créneau → le PANNEAU se replie (plus de heading), le détail prend la place…
    await user.click(screen.getByText("U11"));
    expect(await screen.findByText(/90 min/)).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Diagnostics du système" })).not.toBeInTheDocument();
    // …mais la BARRE repliée garde l'essentiel VISIBLE : le compte et la sévérité la plus haute.
    const bar = screen.getByRole("button", { name: /Diagnostics du système/ });
    expect(bar).toHaveTextContent("(1)");
    expect(bar).toHaveTextContent("1 alerte");

    // Fermeture du créneau → le panneau REVIENT (état d'avant restauré, rien perdu).
    await user.click(screen.getByRole("button", { name: /Fermer/ }));
    expect(await screen.findByRole("heading", { name: "Diagnostics du système" })).toBeInTheDocument();
  });

  it("REPLIE AUSSI quand il reste une ERREUR — l'exception ERROR d'hier est retirée — mais la barre la signale", async () => {
    const user = userEvent.setup();
    // La fixture par défaut porte une ERREUR (« Conflit de gymnase »).
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");
    await user.click(screen.getByRole("button", { name: /Diagnostics du système/ }));
    expect(await screen.findByRole("heading", { name: "Diagnostics du système" })).toBeInTheDocument();

    await user.click(screen.getByText("U11"));
    expect(await screen.findByText(/90 min/)).toBeInTheDocument();
    // Plus d'exception : le panneau se replie même avec une ERREUR…
    expect(screen.queryByRole("heading", { name: "Diagnostics du système" })).not.toBeInTheDocument();
    // …mais l'erreur reste SIGNALÉE dans la barre (rien n'est enterré, elle reste atteignable).
    expect(screen.getByRole("button", { name: /Diagnostics du système/ })).toHaveTextContent("1 erreur");
  });

  it("groups diagnostics by severity", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    // Diagnostics collapsed by default → open the panel first (user request).
    await user.click(await screen.findByRole("button", { name: /Diagnostics du système/ }));
    const group = await screen.findByRole("button", { name: /Erreurs/ });
    expect(within(group).getByText("1")).toBeInTheDocument();
  });

  // P4-95 — un diagnostic `conflict` PORTANT (gymnase, jour, heure) devient cliquable jusqu'au
  // créneau fautif : il OUVRE le créneau (SlotDetail) et l'amène à l'écran (scrollIntoView).
  it("cliquer un conflict ciblé OUVRE le créneau fautif et l'amène à l'écran", async () => {
    const user = userEvent.setup();
    const raf = vi.spyOn(window, "requestAnimationFrame").mockImplementation((cb) => (cb(0), 0));
    const scrolled: Element[] = [];
    const originalScroll = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this);
    };
    // Un conflict désignant slot-1 (venue-1, lundi 18:00).
    vi.mocked(getDiagnostics).mockResolvedValue([
      { id: "cf", scheduleId: SID, type: "conflict", severity: "ERROR", teamId: null, venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00", ruleKey: null, message: "Conflit ciblé.", suggestions: [], causes: [], openCandidates: null },
    ]);
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");

    // Ouvrir le panneau, déplier le groupe Erreurs, cliquer le diagnostic.
    await user.click(screen.getByRole("button", { name: /Diagnostics du système/ }));
    await user.click(await screen.findByRole("button", { name: /Erreurs/ }));
    await user.click(screen.getByText("Conflit ciblé."));

    // Le créneau fautif est SÉLECTIONNÉ → SlotDetail s'ouvre…
    expect(await screen.findByText(/90 min/)).toBeInTheDocument();
    // …et sa cellule (data-slot-id) a été amenée à l'écran.
    expect(scrolled.some((el) => "slot-1" === el.getAttribute("data-slot-id"))).toBe(true);

    Element.prototype.scrollIntoView = originalScroll;
    raf.mockRestore();
  });

  // Retour fondateur 2026-08-15 — le chemin SURLIGNAGE (diagnostics sans jour/heure : warnings,
  // infos) illuminait les créneaux concernés SANS amener la grille dessus : « je dois chercher
  // pour le trouver ». Le premier créneau surligné est désormais centré, même recette que le
  // clic-conflict.
  it("cliquer un diagnostic à surlignage CENTRE le premier créneau concerné", async () => {
    const user = userEvent.setup();
    const raf = vi.spyOn(window, "requestAnimationFrame").mockImplementation((cb) => (cb(0), 0));
    const scrolled: Element[] = [];
    const originalScroll = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this);
    };
    // Un warning SANS jour/heure : il ne peut que surligner (rapprochement par équipe).
    vi.mocked(getDiagnostics).mockResolvedValue([
      { id: "wa", scheduleId: SID, type: "constraint_not_honored", severity: "WARNING", teamId: "team-1", venueId: null, coachId: null, dayOfWeek: null, startTime: null, ruleKey: null, message: "Règle non honorée.", suggestions: [], causes: [], openCandidates: null },
    ]);
    renderWithProviders(<PlanningPage />);
    await screen.findByText("U11");

    await user.click(screen.getByRole("button", { name: /Diagnostics du système/ }));
    await user.click(await screen.findByRole("button", { name: /Alertes/ }));
    await user.click(screen.getByText("Règle non honorée."));

    // La cellule surlignée (data-slot-id) a été amenée à l'écran.
    expect(scrolled.some((el) => null !== el.getAttribute("data-slot-id"))).toBe(true);

    Element.prototype.scrollIntoView = originalScroll;
    raf.mockRestore();
  });

  it("ouvre les diagnostics au sortir du WIZARD, et les laisse repliés en boucle de travail (P4-40)", async () => {
    // Retour terrain : « sinon on risque de ne pas le voir si on n'est pas familier avec
    // l'écran génération ». La règle est CONTEXTUELLE — elle ne contredit pas la demande
    // d'origine (replié par défaut), elle nomme un contexte qu'elle n'avait pas distingué.
    // ⚠ On teste le CÂBLAGE : `DiagnosticsPanel` a ses propres tests, mais rien ne
    // garantissait que `PlanningPage` lui passe bien le contexte.
    renderWithProviders(<PlanningPage embedded />);

    // Panneau déplié : son titre est un en-tête, pas le bouton de la barre repliée.
    expect(await screen.findByRole("heading", { name: "Diagnostics du système" })).toBeInTheDocument();

    // ⚠ ET le groupe le plus sévère est DÉPLIÉ. Sans cette seconde assertion, le test
    // restait vert en supprimant `openMostSevere` : le titre du panneau est rendu quoi
    // qu'il arrive, donc il ne prouvait que le repli de l'aside, pas le câblage qu'il
    // prétendait garder (revue #350). C'est le message d'un diagnostic qui le prouve.
    expect(await screen.findByText("Conflit de gymnase.")).toBeInTheDocument();
  });

  it("laisse l'aside REPLIÉ en embedded quand la génération est PROPRE", async () => {
    // Ouvrir l'aside sans rien à montrer volait 20rem de largeur à la grille, dans une
    // hauteur embarquée déjà courte, pour afficher « le planning est propre » (revue #350).
    // ⚠ L'amorce est indexée sur la VERSION : elle referme aussi bien qu'elle ouvre.
    vi.mocked(getDiagnostics).mockResolvedValueOnce([]);
    renderWithProviders(<PlanningPage embedded />);

    // La grille est bien là — donc la page a fini de charger…
    expect(await screen.findByText("U11")).toBeInTheDocument();
    // …et l'aside est resté la barre compacte, pas le panneau.
    expect(screen.queryByRole("heading", { name: "Diagnostics du système" })).not.toBeInTheDocument();
  });

  it("laisse les diagnostics REPLIÉS en boucle de travail", async () => {
    renderWithProviders(<PlanningPage />);

    // Barre compacte : un bouton qui rouvre, pas le panneau lui-même.
    expect(await screen.findByRole("button", { name: /Diagnostics du système/ })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Diagnostics du système" })).not.toBeInTheDocument();
  });

  it("renders defined-but-unfilled windows as 'vide' cells alongside the solver's unused_slot warning", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage />);
    // ts-2 (Gymnase Alpha, Mardi 19:00) has no placement → a `vide` cell in the grid.
    expect(await screen.findByText("vide")).toBeInTheDocument();
    // The solver's own unused_slot warning is listed under "Alertes" (panel opened).
    await user.click(await screen.findByRole("button", { name: /Diagnostics du système/ }));
    const warnGroup = await screen.findByRole("button", { name: /Alertes/ });
    expect(within(warnGroup).getByText("1")).toBeInTheDocument();
  });

  // P2-43 volet (v) — la vue planning d'une PÉRIODE ne doit plus OFFRIR ce que le moteur
  // refuse : une fenêtre vide sur un couple (gymnase, jour) FERMÉ (état effectif SERVI) est
  // MARQUÉE « fermé » (inerte + nommée), jamais rendue « vide » offrable. Grain JOUR : c'est le
  // seul mardi de venue-1 qui est fermé, pas le gymnase entier.
  it("marque « fermé » (inerte) une fenêtre vide sur un couple gymnase/jour FERMÉ d'une période, au lieu de l'offrir « vide »", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      { id: SID, name: "Période", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
    ]);
    plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Été", startDate: "2026-08-17", calendarEntryId: "e", chosenScheduleId: null, teamSelectionInitialized: true }];
    // venue-1 est fermé le MARDI (jour ISO 2) — la fenêtre ts-2 (mardi 19:00) tombe dessus.
    conflictsState.data = makeConflicts({ effectiveClosedWeekdays: { "venue-1": { "2": "default-incident" } } });
    usePlanningStore.setState({ selectedScheduleId: SID, viewMode: "gymnase" });
    renderWithProviders(<PlanningPage />);

    // La séance placée du lundi prouve que la grille a chargé (ancre non-fermée).
    expect(await screen.findByText("U11")).toBeInTheDocument();
    // La fenêtre du mardi n'est plus OFFERTE (« vide ») — elle est MARQUÉE « fermé » et nommée.
    expect(screen.queryByText("vide")).not.toBeInTheDocument();
    const closed = await screen.findByTitle(/Fermé —/);
    expect(closed).toHaveTextContent(/fermé/i);
    expect(closed.getAttribute("title")).toMatch(/mardi est fermé/);
  });

  // planning lifecycle (§7.1): reopening the version the plan POINTS at, when the
  // season has period overlays, 409s; the UI escalates to a proportional confirm,
  // then re-sends with the flag.
  describe("reopen escalation (the plan in force, with overlays)", () => {
    const validated: Schedule[] = [{ id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true }];

    beforeEach(() => {
      vi.mocked(reopenSchedule).mockReset(); // per-test call count + queued once-values
    });

    it("409 → confirm naming the overlay count → re-sends confirmDeleteOverlays", async () => {
      const user = userEvent.setup();
      vi.mocked(listSchedules).mockResolvedValue(validated);
      vi.mocked(reopenSchedule).mockRejectedValueOnce(new OverlaysExistError(2, [])).mockResolvedValueOnce({});
      renderWithProviders(<PlanningPage />); // Nouveau contrat (2026-08-20) : Rouvrir vit sur /planning autonome
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: /rouvrir/i }));
      // First reopen (no flag) → 409 → proportional confirm dialog.
      expect(await screen.findByText(/supprimera 2 plannings secondaires/i)).toBeInTheDocument();

      // P2-7 : le geste lourd exige de taper la phrase — le bouton reste grisé sans elle.
      const confirmButton = screen.getByRole("button", { name: "Rouvrir et supprimer" });
      expect(confirmButton).toBeDisabled();
      await user.type(within(screen.getByRole("dialog")).getByRole("textbox"), "modifier mon planning de saison");
      expect(confirmButton).toBeEnabled();

      await user.click(confirmButton);
      expect(vi.mocked(reopenSchedule)).toHaveBeenCalledTimes(2);
      expect(vi.mocked(reopenSchedule).mock.calls[1]).toEqual([SID, { confirmDeleteOverlays: true }]);
      // Reopened → back to the wizard's generation step.
      expect(navigate).toHaveBeenCalledWith("/wizard");
    });

    it("« Rouvrir » (no overlays) → wizard generation step", async () => {
      const user = userEvent.setup();
      vi.mocked(listSchedules).mockResolvedValue(validated);
      vi.mocked(reopenSchedule).mockResolvedValueOnce({});
      renderWithProviders(<PlanningPage />); // Nouveau contrat (2026-08-20) : Rouvrir vit sur /planning autonome
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: /rouvrir/i }));
      expect(vi.mocked(reopenSchedule)).toHaveBeenCalledTimes(1);
      expect(navigate).toHaveBeenCalledWith("/wizard");
    });

    // NR (défaut 3, axe planning lifecycle — LE bloquant) : toute navigation vers /wizard
    // DÉCLARE son mode ; aucun héritage du localStorage. Rouvrir la SAISON repasse en mode
    // saison même si un mode période traînait. Falsification : sans exitPeriodMode, la
    // période persistée resterait et le wizard s'ouvrirait sur la mauvaise portée.
    it("Rouvrir le SOCLE (SEASON) déclare le mode SAISON — une période persistée ne survit pas", async () => {
      const user = userEvent.setup();
      // Un mode période traîne du localStorage/écran précédent : le piège exact.
      useWizardStore.getState().startPeriodMode("stale-entry");
      vi.mocked(listSchedules).mockResolvedValue(validated);
      vi.mocked(reopenSchedule).mockResolvedValueOnce({});
      renderWithProviders(<PlanningPage />); // Nouveau contrat (2026-08-20) : Rouvrir vit sur /planning autonome
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: /rouvrir/i }));
      await waitFor(() => expect(navigate).toHaveBeenCalledWith("/wizard"));
      expect(useWizardStore.getState().mode).toBe("season");
      expect(useWizardStore.getState().calendarEntryId).toBeNull();
      expect(useWizardStore.getState().stepId).toBe("generate");
    });

    // NR (défaut 3) — le REVERS : rouvrir un OVERLAY déclare le mode PÉRIODE ancré sur
    // l'entrée DE CE plan (schedulePlanId → plan → calendarEntryId), jamais la saison.
    it("Rouvrir un OVERLAY déclare le mode PÉRIODE sur l'entrée de CE plan", async () => {
      const user = userEvent.setup();
      // On part en mode saison pour prouver le passage EN période (et la bonne entrée).
      useWizardStore.getState().exitPeriodMode();
      const overlayValidated: Schedule[] = [{ id: "ov-1", name: "Ajustement", status: "COMPLETED", score: null, createdAt: "2026-09-10T00:00:00Z", updatedAt: "2026-09-10T00:00:00Z", planType: "CLOSURE", schedulePlanId: "ete-plan", isChosen: true }];
      vi.mocked(listSchedules).mockResolvedValue(overlayValidated);
      plansState.plans = [{ id: "ete-plan", type: "CLOSURE", name: "Ajustement gymnase", startDate: "2026-09-07", calendarEntryId: "e-ete", chosenScheduleId: "ov-1", teamSelectionInitialized: true }];
      vi.mocked(reopenSchedule).mockResolvedValueOnce({});
      renderWithProviders(<PlanningPage scopePlanId="ete-plan" />); // Nouveau contrat : Rouvrir vit sur /planning autonome

      await user.click(await screen.findByRole("button", { name: /rouvrir/i }));
      await waitFor(() => expect(navigate).toHaveBeenCalledWith("/wizard"));
      expect(useWizardStore.getState().mode).toBe("period");
      expect(useWizardStore.getState().calendarEntryId).toBe("e-ete");
      expect(useWizardStore.getState().stepId).toBe("generate");
    });
  });

  it("« Valider » atterrit sur /planning — la sortie de l'espace de travail vers l'écran en vigueur", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PlanningPage embedded />); // Valider vit dans le wizard (embedded), sa SORTIE est /planning
    await screen.findByText("U11");

    // Toolbar "Valider" opens the confirm dialog; confirm inside it.
    await user.click(screen.getByRole("button", { name: /valider/i }));
    // P2-52 : le confirm attend l'impact (jamais confirmer sur un impact inconnu) — ici {0,0}.
    const confirm = within(screen.getByRole("dialog")).getByRole("button", { name: "Valider" });
    await waitFor(() => expect(confirm).toBeEnabled());
    await user.click(confirm);
    // Nouveau contrat (2026-08-20) : le succès NAVIGUE vers /planning — le socle validé
    // devient la version en vigueur, consultable, portant le badge et « Rouvrir » (journey e2e).
    await waitFor(() => expect(vi.mocked(validateSchedule)).toHaveBeenCalled());
    expect(navigate).toHaveBeenCalledWith("/planning");
  });

  // F2b — retouche du rail : le geste PARLE (toast + surlignage du conflit) et déverrouiller
  // une RÉSERVATION demande confirmation.
  describe("retouche : feedback des gestes", () => {
    beforeEach(() => {
      useToastStore.setState({ toasts: [] });
      vi.mocked(moveSlot).mockReset();
      vi.mocked(placeSlot).mockReset();
      vi.mocked(lockSlot).mockReset();
    });

    // P2-30 — mode cible click-click : sélectionne le créneau, ARME « Déplacer », puis clique la
    // fenêtre VIDE (ts-2, Gymnase Alpha Mardi 19:00) comme cible.
    async function requestMove(user: ReturnType<typeof userEvent.setup>): Promise<void> {
      await user.click(await screen.findByText("U11"));
      await user.click(screen.getByRole("button", { name: /Déplacer/ }));
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));
    }

    it("un déplacement REFUSÉ surligne le créneau de l'équipe en conflit (conflictingTeamId)", async () => {
      const user = userEvent.setup();
      // Deux équipes placées : l'une est déplacée, l'autre (team-2) est nommée par le verdict.
      vi.mocked(getTeams).mockResolvedValue([
        { id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 },
        { id: "team-2", name: "U13", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 1 },
      ]);
      vi.mocked(getSlots).mockResolvedValue([
        { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null },
        { id: "slot-2", scheduleId: SID, teamId: "team-2", venueId: "venue-1", coachId: null, dayOfWeek: 2, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null },
      ]);
      vi.mocked(moveSlot).mockRejectedValue(
        new MoveRejectedError([{ rule: "coach_double_booking", message: "le coach a déjà les U13 ici.", conflictingTeamId: "team-2" }]),
      );
      const { container } = renderWithProviders(<PlanningPage />);
      await requestMove(user);

      // Le refus s'affiche…
      expect(await screen.findByText(/Déplacement refusé/)).toBeInTheDocument();
      // …et la grille met en avant slot-2 (équipe en conflit) en estompant les AUTRES cases.
      await vi.waitFor(() => {
        const slot1 = container.querySelector('[data-slot-id="slot-1"]');
        const slot2 = container.querySelector('[data-slot-id="slot-2"]');
        expect(slot2?.className).not.toContain("opacity-30");
        expect(slot1?.className).toContain("opacity-30");
      });
      // P2-30 — le mode cible RESTE armé pour réessayer (le panneau montre la consigne de choix).
      expect(screen.getByRole("button", { name: /Choisir la case cible/ })).toBeInTheDocument();
    });

    it("un déplacement ACCEPTÉ affiche un toast de succès et rafraîchit les diagnostics", async () => {
      const user = userEvent.setup();
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, compromises: [] });
      renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");
      const diagBefore = vi.mocked(getDiagnostics).mock.calls.length;

      await requestMove(user);

      await vi.waitFor(() => expect(useToastStore.getState().toasts.some((t) => "success" === t.variant)).toBe(true));
      await vi.waitFor(() => expect(vi.mocked(getDiagnostics).mock.calls.length).toBeGreaterThan(diagBefore));
    });

    it("un verrouillage qui ÉCHOUE affiche un toast d'erreur", async () => {
      const user = userEvent.setup();
      vi.mocked(lockSlot).mockRejectedValue(new Error("boom"));
      renderWithProviders(<PlanningPage />);

      await user.click(await screen.findByText("U11"));
      // Nom EXACT « Verrouiller » = le bouton du PANNEAU ; le cadenas de la grille nomme
      // l'équipe (« Verrouiller U11 ») — la regex en attraperait deux depuis PR 2.
      await user.click(await screen.findByRole("button", { name: "Verrouiller" }));

      await vi.waitFor(() => expect(useToastStore.getState().toasts.some((t) => "error" === t.variant)).toBe(true));
    });

    it("déverrouiller une RÉSERVATION demande confirmation avant de muter", async () => {
      const user = userEvent.setup();
      vi.mocked(lockSlot).mockResolvedValue({});
      vi.mocked(getSlots).mockResolvedValue([
        { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "HARD", lockOrigin: "RESERVATION" },
      ]);
      renderWithProviders(<PlanningPage />);

      await user.click(await screen.findByText("U11"));
      // Nom EXACT = le bouton du PANNEAU (le cadenas de la grille nomme l'équipe depuis PR 2).
      await user.click(await screen.findByRole("button", { name: "Déverrouiller" }));

      // Un dialogue s'interpose — rien n'est muté tant qu'on n'a pas confirmé.
      const dialog = await screen.findByRole("dialog");
      expect(vi.mocked(lockSlot)).not.toHaveBeenCalled();

      await user.click(within(dialog).getByRole("button", { name: /Déverrouiller/ }));
      await vi.waitFor(() => expect(vi.mocked(lockSlot)).toHaveBeenCalledWith("slot-1", "NONE"));
    });

    it("déverrouiller un épinglage MANUEL mute directement, sans dialogue", async () => {
      const user = userEvent.setup();
      vi.mocked(lockSlot).mockResolvedValue({});
      vi.mocked(getSlots).mockResolvedValue([
        { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "HARD", lockOrigin: "MANUAL" },
      ]);
      renderWithProviders(<PlanningPage />);

      await user.click(await screen.findByText("U11"));
      // Nom EXACT = le bouton du PANNEAU (le cadenas de la grille nomme l'équipe depuis PR 2).
      await user.click(await screen.findByRole("button", { name: "Déverrouiller" }));

      await vi.waitFor(() => expect(vi.mocked(lockSlot)).toHaveBeenCalledWith("slot-1", "NONE"));
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    // Le cadenas de la GRILLE (PR 2) partage EXACTEMENT le handler du panneau : même bascule
    // directe, même ConfirmDialog RÉSERVATION — écrit une seule fois.
    it("cadenas de la GRILLE : bascule directe un créneau non verrouillé SANS ouvrir le panneau", async () => {
      const user = userEvent.setup();
      vi.mocked(lockSlot).mockResolvedValue({});
      renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");

      // Le cadenas de la grille nomme l'équipe (« Verrouiller U11 ») — distinct du bouton du
      // panneau (« Verrouiller » seul), qui n'est pas ouvert ici.
      await user.click(screen.getByRole("button", { name: "Verrouiller U11" }));

      await vi.waitFor(() => expect(vi.mocked(lockSlot)).toHaveBeenCalledWith("slot-1", "HARD"));
      // Un clic sur le cadenas ne SÉLECTIONNE pas : pas de panneau de détail (pas de « Déplacer »).
      expect(screen.queryByRole("button", { name: /Déplacer/ })).not.toBeInTheDocument();
    });

    it("cadenas de la GRILLE sur une RÉSERVATION : MÊME ConfirmDialog, mutation après confirmation seulement", async () => {
      const user = userEvent.setup();
      vi.mocked(lockSlot).mockResolvedValue({});
      vi.mocked(getSlots).mockResolvedValue([
        { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "HARD", lockOrigin: "RESERVATION" },
      ]);
      renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: "Déverrouiller U11" }));

      const dialog = await screen.findByRole("dialog");
      expect(vi.mocked(lockSlot)).not.toHaveBeenCalled();

      await user.click(within(dialog).getByRole("button", { name: /Déverrouiller/ }));
      await vi.waitFor(() => expect(vi.mocked(lockSlot)).toHaveBeenCalledWith("slot-1", "NONE"));
    });
  });

  // P2-30 PR B — mode cible click-click, éviction (D6), bandeau dérive (placement), undo.
  describe("P2-30 : mode cible, éviction, dérive, undo", () => {
    const twoTeams = [
      { id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 },
      { id: "team-2", name: "U13", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 1 },
    ];
    // Source (slot-1, lundi 18:00) + occupant (slot-2, U13, mercredi 18:00) — cible d'éviction.
    const twoSlots = [
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE" as const, lockOrigin: null },
      { id: "slot-2", scheduleId: SID, teamId: "team-2", venueId: "venue-1", coachId: null, dayOfWeek: 3, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE" as const, lockOrigin: null },
    ];
    const evictedBlock = { slotId: "slot-2", teamId: "team-2", dayOfWeek: 3, startTime: "18:00", venueId: "venue-1", durationMinutes: 90 };

    beforeEach(() => {
      useToastStore.setState({ toasts: [] });
      vi.mocked(moveSlot).mockReset();
      vi.mocked(placeSlot).mockReset();
    });

    async function armMoveFrom(user: ReturnType<typeof userEvent.setup>, label: string): Promise<void> {
      await user.click(await screen.findByText(label));
      await user.click(screen.getByRole("button", { name: /Déplacer/ }));
    }

    it("clic sur une case VIDE → moveSlot SANS evict", async () => {
      const user = userEvent.setup();
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, compromises: [] });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));

      await vi.waitFor(() => expect(vi.mocked(moveSlot)).toHaveBeenCalledWith("slot-1", { dayOfWeek: 2, startTime: "19:00", venueId: "venue-1" }, expect.any(AbortSignal)));
    });

    it("clic sur une case OCCUPÉE → modale (essai dryRun), puis move RÉEL AVEC evictSlotId à la confirmation SEULEMENT", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      // Essai accepté puis move réel accepté (mêmes valeurs — l'essai a `dryRun`, le réel non).
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, dryRun: true, compromises: [], evicted: evictedBlock });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");

      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));
      // D6 : une modale s'interpose (VÉRIFICATION → verdict), NOMMANT l'occupant. Seul l'ESSAI
      // (dryRun) part avant la confirmation.
      const dialog = await screen.findByRole("dialog");
      // Le nom de l'occupant est mis en emphase (span) — on attend le libellé porteur.
      expect(await within(dialog).findByText(/Ce créneau est occupé par/i)).toBeInTheDocument();
      expect(within(dialog).getByText("U13")).toBeInTheDocument();
      await vi.waitFor(() => expect(vi.mocked(moveSlot)).toHaveBeenCalledTimes(1));
      expect(vi.mocked(moveSlot).mock.calls[0]).toEqual(["slot-1", { dayOfWeek: 3, startTime: "18:00", venueId: "venue-1", evictSlotId: "slot-2", dryRun: true }, expect.any(AbortSignal)]);

      await user.click(await within(dialog).findByRole("button", { name: /Déplacer et évincer/ }));
      // Le move réel (sans dryRun) n'a lieu qu'à la confirmation.
      await vi.waitFor(() => expect(vi.mocked(moveSlot)).toHaveBeenCalledWith("slot-1", { dayOfWeek: 3, startTime: "18:00", venueId: "venue-1", evictSlotId: "slot-2" }, expect.any(AbortSignal)));
    });

    it("après éviction, le raccourci REMET l'évincée sur la case libérée par la source (placeSlot)", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, compromises: [], evicted: evictedBlock });
      vi.mocked(placeSlot).mockResolvedValue({ valid: true, slotId: "new", compromises: [] });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));
      await user.click(await within(await screen.findByRole("dialog")).findByRole("button", { name: /Déplacer et évincer/ }));

      // Le raccourci propose de remettre U13 sur l'ANCIENNE case de la source (lundi 18:00, venue-1).
      const shortcut = await screen.findByRole("button", { name: /Remettre U13/ });
      await user.click(shortcut);
      await vi.waitFor(() => expect(vi.mocked(placeSlot)).toHaveBeenCalledWith(SID, { teamId: "team-2", dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" }, expect.any(AbortSignal)));
    });

    it("cible verrouillée concurremment → 422 target_locked pendant l'essai : message propre, modale fermée, mode cible ARMÉ", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      // La cible n'était pas verrouillée en cache (donc cliquable) mais l'est côté serveur : le
      // verrou (D3) est tranché AVANT le moteur, donc l'ESSAI lui-même échoue.
      vi.mocked(moveSlot).mockRejectedValue(new TargetLockedError("Ce créneau est verrouillé — déverrouillez-le d'abord."));
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));

      await vi.waitFor(() => expect(useToastStore.getState().toasts.some((t) => "error" === t.variant && /verrouill/i.test(t.message))).toBe(true));
      // La modale s'est fermée, aucun move réel, et le mode reste armé pour réessayer ailleurs.
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
      expect(screen.getByRole("button", { name: /Choisir la case cible/ })).toBeInTheDocument();
    });

    it("bandeau dérive → arme le PLACEMENT → clic case vide → placeSlot pour l'équipe", async () => {
      const user = userEvent.setup();
      // U11 attend 2 séances, n'en a qu'une (slot-1) → dérive de 1.
      vi.mocked(getTeams).mockResolvedValue([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }]);
      vi.mocked(placeSlot).mockResolvedValue({ valid: true, slotId: "n", compromises: [] });
      renderWithProviders(<PlanningPage />);

      const banner = await screen.findByRole("region", { name: /séances à replacer/i });
      // Un seul bouton dans le bandeau (U11) — il libelle « U11 · 1 séance à replacer ».
      const driftButton = within(banner).getByRole("button");
      expect(driftButton).toHaveTextContent(/U11 .* à replacer/);
      await user.click(driftButton);
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));

      await vi.waitFor(() => expect(vi.mocked(placeSlot)).toHaveBeenCalledWith(SID, { teamId: "team-1", dayOfWeek: 2, startTime: "19:00", venueId: "venue-1" }, expect.any(AbortSignal)));
    });

    it("P2-44 comblement : une version de PÉRIODE à la dérive offre « Combler automatiquement » → fillSchedule sur SA version", async () => {
      const user = userEvent.setup();
      vi.mocked(listSchedules).mockResolvedValue([
        { id: SID, name: "Reprise été V1", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
      ]);
      plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Reprise d'été", startDate: "2026-08-17", calendarEntryId: "e-ete", chosenScheduleId: null, teamSelectionInitialized: true }];
      // U11 attend 2 séances, n'en a qu'une (slot-1) → 1 à replacer : le prédicat SERVI de la dérive.
      vi.mocked(getTeams).mockResolvedValue([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }]);
      vi.mocked(fillSchedule).mockResolvedValue({ id: "sched-fill" });
      usePlanningStore.setState({ selectedScheduleId: SID, viewMode: "gymnase" });
      renderWithProviders(<PlanningPage />);

      await screen.findByText("U11"); // la grille a chargé (séance placée) — ancre stable
      const fillButton = await screen.findByRole("button", { name: /Combler automatiquement/i });
      await user.click(fillButton);

      await vi.waitFor(() => expect(vi.mocked(fillSchedule)).toHaveBeenCalledWith(SID));
    });

    it("P2-44 : « Combler automatiquement » n'apparaît PAS sur le socle (saison), même à la dérive", async () => {
      // Socle par défaut (SEASON) : U11 attend 2, n'en a qu'une → le bandeau dérive s'affiche, mais
      // le comblement est un geste de PÉRIODE — jamais offert sur la saison (qui se régénère).
      vi.mocked(getTeams).mockResolvedValue([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }]);
      renderWithProviders(<PlanningPage />);

      await screen.findByRole("region", { name: /séances à replacer/i });
      expect(screen.queryByRole("button", { name: /Combler automatiquement/i })).not.toBeInTheDocument();
    });

    it("P2-44 : sans séance à replacer, une version de période n'offre PAS le comblement", async () => {
      vi.mocked(listSchedules).mockResolvedValue([
        { id: SID, name: "Reprise été V1", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "HOLIDAY", schedulePlanId: "ete-plan" },
      ]);
      plansState.plans = [{ id: "ete-plan", type: "HOLIDAY", name: "Reprise d'été", startDate: "2026-08-17", calendarEntryId: "e-ete", chosenScheduleId: null, teamSelectionInitialized: true }];
      // U11 attend 1 séance et l'a (slot-1 par défaut) → aucune à replacer → rien à combler.
      vi.mocked(getTeams).mockResolvedValue([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 }]);
      usePlanningStore.setState({ selectedScheduleId: SID });
      renderWithProviders(<PlanningPage />);

      // L'en-tête porte le nom du PLAN de période affiché : ancrage stable que l'écran a chargé.
      await screen.findByRole("heading", { name: "Reprise d'été" });
      expect(screen.queryByRole("button", { name: /Combler automatiquement/i })).not.toBeInTheDocument();
    });

    it("le bandeau dérive n'apparaît PAS hors planning COMPLETED", async () => {
      // Même sous-dotation (attendu 2, placé 1), mais la génération a ÉCHOUÉ → pas de dérive.
      vi.mocked(listSchedules).mockResolvedValue([
        { id: SID, name: "Planning A", status: "FAILED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
      ]);
      vi.mocked(getTeams).mockResolvedValue([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }]);
      vi.mocked(getSlots).mockResolvedValue([]);
      renderWithProviders(<PlanningPage />);

      expect(await screen.findByText("Génération en échec")).toBeInTheDocument();
      expect(screen.queryByRole("region", { name: /séances à replacer/i })).not.toBeInTheDocument();
    });

    it("undo d'un move simple = move inverse (position d'origine)", async () => {
      const user = userEvent.setup();
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, compromises: [] });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));

      // Le bouton d'annulation apparaît après un geste réussi.
      const undoButton = await screen.findByRole("button", { name: /Annuler le dernier geste/ });
      await user.click(undoButton);

      // Le 2e appel remet slot-1 à SA position d'origine (lundi 18:00, venue-1).
      await vi.waitFor(() => expect(vi.mocked(moveSlot)).toHaveBeenCalledTimes(2));
      expect(vi.mocked(moveSlot).mock.calls[1]).toEqual(["slot-1", { dayOfWeek: 1, startTime: "18:00", venueId: "venue-1" }, expect.any(AbortSignal)]);
    });

    it("undo d'une éviction : move inverse PUIS replacement ; échec partiel = message honnête", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      // 1er move = ESSAI (dryRun, porte `evicted`) ; 2e = move réel d'éviction ; 3e = inverse (undo).
      vi.mocked(moveSlot)
        .mockResolvedValueOnce({ valid: true, dryRun: true, compromises: [], evicted: evictedBlock })
        .mockResolvedValueOnce({ valid: true, compromises: [], evicted: evictedBlock })
        .mockResolvedValueOnce({ valid: true, compromises: [] });
      // Le replacement de l'évincée ÉCHOUE → échec partiel.
      vi.mocked(placeSlot).mockRejectedValue(new MoveRejectedError([{ rule: "coach_double_booking", message: "conflit." }]));
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));
      await user.click(await within(await screen.findByRole("dialog")).findByRole("button", { name: /Déplacer et évincer/ }));

      await user.click(await screen.findByRole("button", { name: /Annuler le dernier geste/ }));

      // Inverse tenté, replacement tenté, et le message dit la vérité : la source est revenue,
      // l'évincée reste à replacer.
      await vi.waitFor(() => expect(vi.mocked(placeSlot)).toHaveBeenCalledWith(SID, { teamId: "team-2", dayOfWeek: 3, startTime: "18:00", venueId: "venue-1", durationMinutes: 90 }, expect.any(AbortSignal)));
      await vi.waitFor(() => expect(useToastStore.getState().toasts.some((t) => "error" === t.variant && /U11 est revenue/.test(t.message) && /U13 reste à replacer/.test(t.message))).toBe(true));
    });
  });

  // P4-119 (d) — le mode cible (déplacer/placer) SUIT son ancre : le panneau du créneau source
  // pour un déplacement, l'entrée de dérive pour un placement. Fermer le panneau, changer de vue
  // ou de version = geste ANNULÉ. Avant ce correctif, l'armement survivait à son panneau et chaque
  // clic devenait une nouvelle tentative de déplacement — l'utilisateur piégé (fondateur 2026-08-19).
  describe("P4-119 : le mode cible suit son ancre", () => {
    const OTHER = "22222222-2222-2222-2222-222222222222";

    beforeEach(() => {
      useToastStore.setState({ toasts: [] });
      vi.mocked(moveSlot).mockReset();
      vi.mocked(placeSlot).mockReset();
      // Repartir d'une vue et d'une sélection neutres (le store persiste `viewMode`).
      usePlanningStore.setState({ viewMode: "gymnase", selectedScheduleId: null, selectedSlotId: null });
    });

    async function armMove(user: ReturnType<typeof userEvent.setup>): Promise<void> {
      await user.click(await screen.findByText("U11"));
      await user.click(screen.getByRole("button", { name: /Déplacer/ }));
      // Armé : le bouton bascule et les fenêtres vides deviennent des cibles « Placer ici »
      // (rendu STRICTEMENT gouverné par l'état armé — cf. WeekGrid `targetActive`).
      expect(await screen.findByRole("button", { name: /Choisir la case cible/ })).toBeInTheDocument();
      expect(await screen.findByRole("button", { name: /Placer ici/ })).toBeInTheDocument();
    }

    it("fermer le panneau du créneau source ANNULE le déplacement", async () => {
      const user = userEvent.setup();
      renderWithProviders(<PlanningPage />);
      await armMove(user);
      await user.click(screen.getByRole("button", { name: "Fermer" }));
      // Désarmé : plus AUCUNE cible « Placer ici », donc aucune tentative possible au clic suivant.
      await waitFor(() => expect(screen.queryByRole("button", { name: /Placer ici/ })).not.toBeInTheDocument());
      expect(vi.mocked(moveSlot)).not.toHaveBeenCalled();
    });

    it("changer de vue ANNULE le déplacement", async () => {
      const user = userEvent.setup();
      renderWithProviders(<PlanningPage />);
      await armMove(user);
      await user.click(screen.getByRole("button", { name: "Par équipe" }));
      await user.click(screen.getByRole("button", { name: "Par gymnase" }));
      await waitFor(() => expect(screen.queryByRole("button", { name: /Placer ici/ })).not.toBeInTheDocument());
      expect(vi.mocked(moveSlot)).not.toHaveBeenCalled();
    });

    it("changer de version ANNULE le déplacement", async () => {
      const user = userEvent.setup();
      vi.mocked(listSchedules).mockResolvedValue([
        { id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
        { id: OTHER, name: "Planning B", status: "COMPLETED", score: 9100, createdAt: "2026-01-02T00:00:00Z", updatedAt: "2026-01-02T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
      ]);
      usePlanningStore.setState({ selectedScheduleId: SID });
      renderWithProviders(<PlanningPage />);
      await armMove(user);
      usePlanningStore.setState({ selectedScheduleId: OTHER });
      await waitFor(() => expect(screen.queryByRole("button", { name: /Placer ici/ })).not.toBeInTheDocument());
      expect(vi.mocked(moveSlot)).not.toHaveBeenCalled();
    });

    it("Échap ANNULE le déplacement (a11y — le raccourci existant est préservé)", async () => {
      const user = userEvent.setup();
      renderWithProviders(<PlanningPage />);
      await armMove(user);
      await user.keyboard("{Escape}");
      await waitFor(() => expect(screen.queryByRole("button", { name: /Placer ici/ })).not.toBeInTheDocument());
      expect(vi.mocked(moveSlot)).not.toHaveBeenCalled();
    });

    it("changer de version ANNULE le placement d'une équipe à la dérive", async () => {
      const user = userEvent.setup();
      // U11 attend 2 séances, n'en a qu'une → dérive : le bandeau « séances à replacer » l'affiche.
      vi.mocked(getTeams).mockResolvedValue([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }]);
      vi.mocked(listSchedules).mockResolvedValue([
        { id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
        { id: OTHER, name: "Planning B", status: "COMPLETED", score: 9100, createdAt: "2026-01-02T00:00:00Z", updatedAt: "2026-01-02T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
      ]);
      usePlanningStore.setState({ selectedScheduleId: SID });
      renderWithProviders(<PlanningPage />);
      const banner = await screen.findByRole("region", { name: /séances à replacer/i });
      await user.click(within(banner).getByRole("button"));
      // Armé : les fenêtres vides deviennent des cibles « Placer ici ».
      expect(await screen.findByRole("button", { name: /Placer ici/ })).toBeInTheDocument();
      usePlanningStore.setState({ selectedScheduleId: OTHER });
      await waitFor(() => expect(screen.queryByRole("button", { name: /Placer ici/ })).not.toBeInTheDocument());
      expect(vi.mocked(placeSlot)).not.toHaveBeenCalled();
    });

    it("changer de vue ANNULE le placement d'une équipe à la dérive", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }]);
      renderWithProviders(<PlanningPage />);
      const banner = await screen.findByRole("region", { name: /séances à replacer/i });
      await user.click(within(banner).getByRole("button"));
      expect(await screen.findByRole("button", { name: /Placer ici/ })).toBeInTheDocument();
      await user.click(screen.getByRole("button", { name: "Par équipe" }));
      await user.click(screen.getByRole("button", { name: "Par gymnase" }));
      await waitFor(() => expect(screen.queryByRole("button", { name: /Placer ici/ })).not.toBeInTheDocument());
      expect(vi.mocked(placeSlot)).not.toHaveBeenCalled();
    });
  });

  // P4-119 (b) — l'abandon/timeout CÔTÉ CLIENT (le front raccroche avant la réponse serveur) ne
  // doit JAMAIS s'afficher « le moteur est indisponible » : c'est un mensonge (le moteur répondait
  // `valid` une seconde après le raccroché, nginx 499). On NOMME l'interruption. « Indisponible »
  // reste réservé à une vraie panne réseau/5xx du backend.
  describe("P4-119 : abandon client ≠ moteur indisponible (message honnête)", () => {
    const twoTeams = [
      { id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 },
      { id: "team-2", name: "U13", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 1 },
    ];
    const twoSlots = [
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE" as const, lockOrigin: null },
      { id: "slot-2", scheduleId: SID, teamId: "team-2", venueId: "venue-1", coachId: null, dayOfWeek: 3, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE" as const, lockOrigin: null },
    ];

    beforeEach(() => {
      useToastStore.setState({ toasts: [] });
      vi.mocked(moveSlot).mockReset();
      vi.mocked(placeSlot).mockReset();
    });

    async function armMoveFrom(user: ReturnType<typeof userEvent.setup>, label: string): Promise<void> {
      await user.click(await screen.findByText(label));
      await user.click(screen.getByRole("button", { name: /Déplacer/ }));
    }

    it("essai (case occupée) INTERROMPU côté client → la modale NOMME l'interruption, jamais « indisponible »", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      vi.mocked(moveSlot).mockRejectedValueOnce(new EngineVerificationInterruptedError());
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));

      const dialog = await screen.findByRole("dialog");
      expect(await within(dialog).findByText(/interrompue avant la réponse/i)).toBeInTheDocument();
      // Le mensonge d'origine ne doit PLUS apparaître.
      expect(within(dialog).queryByText(/moteur est indisponible/i)).not.toBeInTheDocument();
      // Rien n'est tranché : ni oui (pas d'éviction), et [Réessayer] reste offert.
      expect(await within(dialog).findByRole("button", { name: /Réessayer/ })).toBeInTheDocument();
      expect(within(dialog).queryByRole("button", { name: /Déplacer et évincer/ })).not.toBeInTheDocument();
    });

    it("déplacement (case vide) INTERROMPU côté client → le panneau NOMME l'interruption, jamais « indisponible »", async () => {
      const user = userEvent.setup();
      vi.mocked(moveSlot).mockRejectedValueOnce(new EngineVerificationInterruptedError());
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));

      // Le panneau du créneau source (toujours ouvert) porte le verdict : interruption NOMMÉE.
      expect(await screen.findByText(/interrompue avant la réponse/i)).toBeInTheDocument();
      expect(screen.queryByText(/moteur est indisponible/i)).not.toBeInTheDocument();
    });

    it("placement d'une équipe à la dérive INTERROMPU côté client → toast « interrompue », jamais « indisponible »", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue([{ id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }]);
      vi.mocked(placeSlot).mockRejectedValueOnce(new EngineVerificationInterruptedError());
      renderWithProviders(<PlanningPage />);
      const banner = await screen.findByRole("region", { name: /séances à replacer/i });
      await user.click(within(banner).getByRole("button"));
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));

      await waitFor(() => expect(useToastStore.getState().toasts.some((t) => /interrompue avant la réponse/i.test(t.message))).toBe(true));
      expect(useToastStore.getState().toasts.every((t) => !/indisponible/i.test(t.message))).toBe(true);
    });
  });

  // P2-32 — l'essai (dry-run) qui remplit la modale d'éviction, et les compromis nommés
  // affichés après un geste ÉCRIT accepté (toast suffixé + bandeau dismissible).
  describe("P2-32 : compromis nommés + essai (dry-run)", () => {
    const twoTeams = [
      { id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 },
      { id: "team-2", name: "U13", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 1 },
    ];
    const twoSlots = [
      { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE" as const, lockOrigin: null },
      { id: "slot-2", scheduleId: SID, teamId: "team-2", venueId: "venue-1", coachId: null, dayOfWeek: 3, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE" as const, lockOrigin: null },
    ];
    const evictedBlock = { slotId: "slot-2", teamId: "team-2", dayOfWeek: 3, startTime: "18:00", venueId: "venue-1", durationMinutes: 90 };
    const brokenGained = [
      { family: "coach_rest", effect: "broken" as const, message: "le coach Martin enchaîne deux séances." },
      { family: "venue_pref", effect: "gained" as const, message: "les U11 retrouvent leur gymnase habituel." },
    ];

    beforeEach(() => {
      useToastStore.setState({ toasts: [] });
      vi.mocked(moveSlot).mockReset();
      vi.mocked(placeSlot).mockReset();
    });

    async function armMoveFrom(user: ReturnType<typeof userEvent.setup>, label: string): Promise<void> {
      await user.click(await screen.findByText(label));
      await user.click(screen.getByRole("button", { name: /Déplacer/ }));
    }

    it("clic occupé → la modale s'ouvre en VÉRIFICATION pendant l'essai (pas de bouton confirmer)", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      let resolveDry: (v: unknown) => void = () => {};
      vi.mocked(moveSlot).mockReturnValueOnce(new Promise((r) => (resolveDry = r)) as never);
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));

      const dialog = await screen.findByRole("dialog");
      expect(within(dialog).getByText(/Vérification/)).toBeInTheDocument();
      expect(within(dialog).queryByRole("button", { name: /Déplacer et évincer/ })).not.toBeInTheDocument();

      resolveDry({ valid: true, dryRun: true, compromises: [], evicted: evictedBlock });
      await within(dialog).findByRole("button", { name: /Déplacer et évincer/ });
    });

    it("clic occupé → essai accepté LISTE les compromis → confirmer déclenche le move RÉEL sans dryRun", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      vi.mocked(moveSlot)
        .mockResolvedValueOnce({ valid: true, dryRun: true, compromises: brokenGained, evicted: evictedBlock })
        .mockResolvedValueOnce({ valid: true, compromises: brokenGained, evicted: evictedBlock });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));

      // L'essai part AUSSITÔT, en dryRun.
      await vi.waitFor(() =>
        expect(vi.mocked(moveSlot)).toHaveBeenCalledWith("slot-1", { dayOfWeek: 3, startTime: "18:00", venueId: "venue-1", evictSlotId: "slot-2", dryRun: true }, expect.any(AbortSignal)),
      );
      const dialog = await screen.findByRole("dialog");
      // Les compromis nommés s'affichent dans la modale (cassé ET rétabli).
      expect(await within(dialog).findByText(/le coach Martin enchaîne deux séances/)).toBeInTheDocument();
      expect(within(dialog).getByText(/les U11 retrouvent leur gymnase habituel/)).toBeInTheDocument();
      // Rien d'écrit tant qu'on n'a pas confirmé : un seul appel (l'essai).
      expect(vi.mocked(moveSlot)).toHaveBeenCalledTimes(1);

      await user.click(within(dialog).getByRole("button", { name: /Déplacer et évincer/ }));
      // Le move RÉEL part, SANS dryRun.
      await vi.waitFor(() =>
        expect(vi.mocked(moveSlot)).toHaveBeenCalledWith("slot-1", { dayOfWeek: 3, startTime: "18:00", venueId: "venue-1", evictSlotId: "slot-2" }, expect.any(AbortSignal)),
      );
    });

    it("essai accepté SANS compromis → la modale le dit (« Aucun compromis détecté. »)", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, dryRun: true, compromises: [], evicted: evictedBlock });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));

      const dialog = await screen.findByRole("dialog");
      expect(await within(dialog).findByText(/Aucun compromis détecté/)).toBeInTheDocument();
    });

    it("essai REFUSÉ (200 valid:false) → modale de refus SANS bouton confirmer, mode cible ARMÉ", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      vi.mocked(moveSlot).mockResolvedValueOnce({
        valid: false,
        dryRun: true,
        violations: [{ rule: "coach_double_booking", message: "le coach Dupont a déjà les U13 à 18h.", conflictingTeamId: "team-2" }],
        compromises: [],
      });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));

      const dialog = await screen.findByRole("dialog");
      expect(await within(dialog).findByText(/le coach Dupont a déjà les U13/)).toBeInTheDocument();
      // Un refus n'a rien à confirmer.
      expect(within(dialog).queryByRole("button", { name: /Déplacer et évincer/ })).not.toBeInTheDocument();
      // Aucun move réel.
      expect(vi.mocked(moveSlot)).toHaveBeenCalledTimes(1);

      await user.click(within(dialog).getByRole("button", { name: /Fermer/ }));
      // Le mode reste armé pour réessayer ailleurs.
      expect(screen.getByRole("button", { name: /Choisir la case cible/ })).toBeInTheDocument();
    });

    it("timeout moteur pendant l'essai → la modale RESTE ouverte et NOMME l'échec (pas un numéro nu), avec Réessayer", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      // Le moteur a été trop lent : le geste était peut-être légal, mais le backend a abandonné.
      vi.mocked(moveSlot).mockRejectedValueOnce(new EngineTimeoutError("Le moteur n'a pas répondu à temps — réessayez."));
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));

      // La modale NE se ferme PAS : elle dit ce qui s'est passé (demande fondateur).
      const dialog = await screen.findByRole("dialog");
      expect(await within(dialog).findByRole("button", { name: /Réessayer/ })).toBeInTheDocument();
      // Le message NOMME la cause (pas un numéro nu) — phrase unique au corps, pas au titre.
      expect(within(dialog).getByText(/n'a pas répondu à temps/i)).toBeInTheDocument();
      // Un échec n'est ni un « oui » (pas de [Déplacer et évincer]) ni un refus métier.
      expect(within(dialog).queryByRole("button", { name: /Déplacer et évincer/ })).not.toBeInTheDocument();
    });

    it("Réessayer depuis l'état d'échec REJOUE l'essai sur la MÊME cible → verdict rendu", async () => {
      const user = userEvent.setup();
      vi.mocked(getTeams).mockResolvedValue(twoTeams);
      vi.mocked(getSlots).mockResolvedValue(twoSlots);
      // 1er essai : timeout ; 2e essai (Réessayer) : accepté.
      vi.mocked(moveSlot)
        .mockRejectedValueOnce(new EngineTimeoutError("Le moteur n'a pas répondu à temps — réessayez."))
        .mockResolvedValueOnce({ valid: true, dryRun: true, compromises: [], evicted: evictedBlock });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(screen.getByTitle(/U13 · Gymnase Alpha/));

      const dialog = await screen.findByRole("dialog");
      await user.click(await within(dialog).findByRole("button", { name: /Réessayer/ }));

      // Le second essai porte le MÊME dryRun sur la MÊME cible, et le verdict s'affiche.
      await within(dialog).findByRole("button", { name: /Déplacer et évincer/ });
      expect(vi.mocked(moveSlot)).toHaveBeenCalledTimes(2);
      expect(vi.mocked(moveSlot).mock.calls[1]).toEqual(["slot-1", { dayOfWeek: 3, startTime: "18:00", venueId: "venue-1", evictSlotId: "slot-2", dryRun: true }, expect.any(AbortSignal)]);
    });

    it("case VIDE → AUCUN essai : moveSlot appelé une seule fois, SANS dryRun (D-8)", async () => {
      const user = userEvent.setup();
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, compromises: [] });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));

      await vi.waitFor(() => expect(vi.mocked(moveSlot)).toHaveBeenCalledTimes(1));
      expect(vi.mocked(moveSlot).mock.calls[0][1]).not.toHaveProperty("dryRun");
    });

    it("geste écrit accepté avec compromis → toast suffixé « — N compromis » + bandeau nommé", async () => {
      const user = userEvent.setup();
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, compromises: brokenGained });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));

      await vi.waitFor(() => expect(useToastStore.getState().toasts.some((t) => "success" === t.variant && /2 compromis/.test(t.message))).toBe(true));
      expect(await screen.findByText(/le coach Martin enchaîne deux séances/)).toBeInTheDocument();
      expect(screen.getByText(/les U11 retrouvent leur gymnase habituel/)).toBeInTheDocument();
    });

    it("geste écrit accepté SANS compromis → toast simple, PAS de bandeau", async () => {
      const user = userEvent.setup();
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, compromises: [] });
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));

      await vi.waitFor(() => expect(useToastStore.getState().toasts.some((t) => "success" === t.variant)).toBe(true));
      const success = useToastStore.getState().toasts.find((t) => "success" === t.variant);
      expect(success?.message).not.toMatch(/compromis/);
      expect(screen.queryByText(/compromis/i)).not.toBeInTheDocument();
    });

    it("le bandeau de compromis est purgé au changement de version", async () => {
      const user = userEvent.setup();
      const OTHER = "22222222-2222-2222-2222-222222222222";
      vi.mocked(listSchedules).mockResolvedValue([
        { id: SID, name: "Planning A", status: "COMPLETED", score: 9051, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
        { id: OTHER, name: "Planning B", status: "COMPLETED", score: 9100, createdAt: "2026-01-02T00:00:00Z", updatedAt: "2026-01-02T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan" },
      ]);
      vi.mocked(moveSlot).mockResolvedValue({ valid: true, compromises: brokenGained });
      usePlanningStore.setState({ selectedScheduleId: SID }); // partir de la version A, sans ambiguïté
      renderWithProviders(<PlanningPage />);
      await armMoveFrom(user, "U11");
      await user.click(await screen.findByRole("button", { name: /Placer ici/ }));
      expect(await screen.findByText(/le coach Martin enchaîne deux séances/)).toBeInTheDocument();

      // Changer de version affichée purge tout état éphémère de retouche, bandeau compris.
      usePlanningStore.setState({ selectedScheduleId: OTHER });
      await vi.waitFor(() => expect(screen.queryByText(/le coach Martin enchaîne deux séances/)).not.toBeInTheDocument());
    });
  });

  // PR 3 — « Verrous lisibles » : compteur + panneau latéral des verrous MANUELS, et la
  // lentille (surbrillance de la grille par origine de verrou).
  describe("verrous lisibles : panneau + lentille", () => {
    beforeEach(() => {
      vi.mocked(getTeams).mockResolvedValue([
        { id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 },
        { id: "team-2", name: "U13", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 1 },
      ]);
      // Un verrou MANUEL, une RÉSERVATION : seul le MANUEL doit compter et peupler le panneau.
      vi.mocked(getSlots).mockResolvedValue([
        { id: "slot-1", scheduleId: SID, teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "HARD", lockOrigin: "MANUAL" },
        { id: "slot-2", scheduleId: SID, teamId: "team-2", venueId: "venue-1", coachId: null, dayOfWeek: 2, startTime: "18:00:00", durationMinutes: 90, lockLevel: "HARD", lockOrigin: "RESERVATION" },
      ]);
    });

    it("compte les seuls MANUAL, ouvre le panneau qui liste le créneau, et se ferme proprement", async () => {
      const user = userEvent.setup();
      renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");

      // Compteur = 1 (le MANUEL seul ; la RÉSERVATION ne compte pas).
      const counter = screen.getByRole("button", { name: /verrous manuels \(1\)/i });
      await user.click(counter);

      // Le panneau liste l'équipe du verrou manuel (U11), pas celle de la réservation (U13).
      const panel = await screen.findByRole("region", { name: /verrous manuels/i });
      expect(within(panel).getByText("U11")).toBeInTheDocument();
      expect(within(panel).queryByText("U13")).not.toBeInTheDocument();

      // Repli propre (même affordance que Diagnostics) : le panneau disparaît,
      // le bouton de la barre revient.
      await user.click(within(panel).getByRole("button", { name: /réduire les verrous manuels/i }));
      expect(screen.queryByRole("region", { name: /verrous manuels/i })).not.toBeInTheDocument();
      expect(screen.getByRole("button", { name: /verrous manuels \(1\)/i })).toBeInTheDocument();
    });

    it("la lentille s'active depuis le panneau, colore la grille, et s'éteint à la fermeture", async () => {
      const user = userEvent.setup();
      const { container } = renderWithProviders(<PlanningPage />);
      await screen.findByText("U11");

      await user.click(screen.getByRole("button", { name: /verrous manuels \(1\)/i }));
      const panel = await screen.findByRole("region", { name: /verrous manuels/i });

      // Avant activation : aucune surbrillance de lentille.
      expect(container.querySelector('[data-lens="MANUAL"]')).toBeNull();

      await user.click(within(panel).getByRole("button", { name: /voir sur la grille/i }));
      // La grille distingue le MANUEL et la RÉSERVATION.
      await vi.waitFor(() => expect(container.querySelector('[data-lens="MANUAL"]')).not.toBeNull());
      expect(container.querySelector('[data-lens="RESERVATION"]')).not.toBeNull();

      // Replier le panneau coupe la lentille — pas d'état fantôme.
      await user.click(within(panel).getByRole("button", { name: /réduire les verrous manuels/i }));
      await vi.waitFor(() => expect(container.querySelector('[data-lens="MANUAL"]')).toBeNull());
    });
  });
});

// bug fondateur 2026-08-19 — l'écran EMBARQUÉ (étape Génération) d'une adaptation de période
// affichait le plan de SAISON (titre, badge « principal », versions de saison) au lieu de la
// période : `pickLandingScheduleId` retombait sur le socle, et une sélection laissée par un
// autre écran survivait. La PORTÉE (`scopePlanId`) corrige cela : l'écran ne connaît QUE les
// versions de ce plan, y atterrit, en tire titre/toolbar, et ne retombe JAMAIS sur la saison.
describe("PlanningPage — portée période (embedded + scopePlanId)", () => {
  const seasonV = (id: string, over: Partial<Schedule> = {}): Schedule => ({ id, name: "Planning A", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", ...over });
  const overlayV = (id: string, createdAt: string, over: Partial<Schedule> = {}): Schedule => ({ id, name: "Ajustement", status: "COMPLETED", score: null, createdAt, updatedAt: createdAt, planType: "CLOSURE", schedulePlanId: "ete-plan", ...over });
  const etePlan: SchedulePlan = { id: "ete-plan", type: "CLOSURE", name: "Ajustement gymnase", startDate: "2026-09-07", calendarEntryId: "e-ete", chosenScheduleId: null, teamSelectionInitialized: true };

  it("une sélection de SAISON périmée dans le planningStore ne survit PAS — atterrit dans la période, titre = période, sans badge « principal »", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      seasonV("season-v1"),
      overlayV("ov-1", "2026-09-10T00:00:00Z"),
      overlayV("ov-2", "2026-09-11T00:00:00Z"),
    ]);
    plansState.plans = [etePlan];
    // Sélection de saison laissée par un autre écran (le cœur du bug).
    usePlanningStore.setState({ selectedScheduleId: "season-v1" });
    renderWithProviders(<PlanningPage embedded scopePlanId="ete-plan" />);

    // Le titre est celui de la PÉRIODE, jamais « Planning A » (la saison)…
    expect(await screen.findByRole("heading", { name: "Ajustement gymnase" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Planning A" })).not.toBeInTheDocument();
    // …le badge « principal » (réservé au socle) ne peut pas apparaître…
    expect(screen.queryByText("principal")).not.toBeInTheDocument();
    // …et la sélection périmée a bien été remplacée par une version DE LA PÉRIODE.
    await waitFor(() => expect(["ov-1", "ov-2"]).toContain(usePlanningStore.getState().selectedScheduleId));
  });

  it("les 2 versions COMPLETED de la période sont listées (V1, V2), la plus récente affichée, et AUCUNE version de saison", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      seasonV("season-v1"),
      overlayV("ov-1", "2026-09-10T00:00:00Z"),
      overlayV("ov-2", "2026-09-11T00:00:00Z"),
    ]);
    plansState.plans = [etePlan];
    renderWithProviders(<PlanningPage embedded scopePlanId="ete-plan" />);

    const select = await screen.findByRole("combobox", { name: /version du planning/i });
    // Deux versions, celles de la période — pas la version de saison (3e schedule).
    const options = within(select).getAllByRole("option");
    expect(options).toHaveLength(2);
    expect(options.map((o) => o.textContent)).toEqual([expect.stringMatching(/^V1 — /), expect.stringMatching(/^V2 — /)]);
    // La plus récente (ov-2) est affichée par défaut.
    await waitFor(() => expect(select).toHaveValue("ov-2"));
  });

  it("plan de période SANS version : état vide EXPLICITE, jamais une version de saison", async () => {
    // La période n'a aucune version ; la saison en a une (le repli fautif d'avant).
    vi.mocked(listSchedules).mockResolvedValue([seasonV("season-v1")]);
    plansState.plans = [etePlan];
    renderWithProviders(<PlanningPage embedded scopePlanId="ete-plan" />);

    expect(await screen.findByText(/Aucune version pour cette période/i)).toBeInTheDocument();
    // Ni la grille de saison, ni son titre.
    expect(screen.queryByText("U11")).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Planning A" })).not.toBeInTheDocument();
  });
});

// Règle ARBITRÉE fondateur (2026-08-19) : l'écran EMBARQUÉ de l'étape Génération atterrit sur la
// version la plus RÉCENTE du plan en portée (en vol comprise) — le gestionnaire doit revoir la
// génération qu'il vient de lancer. Le POINTEUR ne l'emporte que sur `/planning` autonome et au
// cockpit (non embarqué). Le seed BCCL (V1 transcrite POINTÉE) rendait le bug visible : toute
// retombée « pointeur d'abord » ramenait la V1, jamais la V2 fraîche.
describe("PlanningPage — atterrissage EMBARQUÉ = version la plus récente, pas le pointeur", () => {
  const seasonV = (id: string, createdAt: string, over: Partial<Schedule> = {}): Schedule => ({ id, name: "Planning A", status: "COMPLETED", score: null, createdAt, updatedAt: createdAt, planType: "SEASON", schedulePlanId: "season-plan", ...over });
  const overlayV = (id: string, createdAt: string, over: Partial<Schedule> = {}): Schedule => ({ id, name: "Ajustement", status: "COMPLETED", score: null, createdAt, updatedAt: createdAt, planType: "CLOSURE", schedulePlanId: "ete-plan", ...over });
  const etePlan: SchedulePlan = { id: "ete-plan", type: "CLOSURE", name: "Ajustement gymnase", startDate: "2026-09-07", calendarEntryId: "e-ete", chosenScheduleId: null, teamSelectionInitialized: true };

  it("SAISON embarquée : une V1 POINTÉE plus ancienne cède la place à la V2 plus récente", async () => {
    // RED avant le fix : l'embarqué saison atterrissait via `pickLandingScheduleId` (pointeur
    // d'abord) → la V1 `isChosen`. La génération fraîche ne s'affichait jamais.
    vi.mocked(listSchedules).mockResolvedValue([
      seasonV("s-v1", "2026-01-01T00:00:00Z", { isChosen: true }),
      seasonV("s-v2", "2026-02-01T00:00:00Z"),
    ]);
    renderWithProviders(<PlanningPage embedded />);

    await waitFor(() => expect(usePlanningStore.getState().selectedScheduleId).toBe("s-v2"));
  });

  it("PÉRIODE embarquée : une version POINTÉE plus ancienne cède la place à la plus récente", async () => {
    // RED avant le fix : la portée atterrissait via `planRepresentative` (pointeur d'abord) → ov-1.
    vi.mocked(listSchedules).mockResolvedValue([
      seasonV("s-v1", "2026-01-01T00:00:00Z"),
      overlayV("ov-1", "2026-09-10T00:00:00Z", { isChosen: true }),
      overlayV("ov-2", "2026-09-11T00:00:00Z"),
    ]);
    plansState.plans = [etePlan];
    renderWithProviders(<PlanningPage embedded scopePlanId="ete-plan" />);

    await waitFor(() => expect(usePlanningStore.getState().selectedScheduleId).toBe("ov-2"));
  });

  it("NON embarqué (`/planning` autonome) : le POINTEUR gagne — comportement inchangé (frontière NR)", async () => {
    vi.mocked(listSchedules).mockResolvedValue([
      seasonV("s-v1", "2026-01-01T00:00:00Z", { isChosen: true }),
      seasonV("s-v2", "2026-02-01T00:00:00Z"),
    ]);
    renderWithProviders(<PlanningPage />);

    await waitFor(() => expect(usePlanningStore.getState().selectedScheduleId).toBe("s-v1"));
  });
});

/**
 * P2-44 (PR-2) — surfaçage de la transcription depuis le socle. Sur l'écran de génération d'une
 * période (embarqué + porté), la liste « à replacer » SERVIE par la route s'affiche (le front ne
 * redérive rien), un bouton ouvre la comparaison avec le socle, et les vides sont mis en évidence.
 */
describe("PlanningPage — transcription depuis le socle (P2-44)", () => {
  const seasonV: Schedule = { id: "season-v1", name: "Planning A", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true };
  const overlayV: Schedule = { id: "ov-1", name: "Toussaint", status: "COMPLETED", score: null, createdAt: "2026-09-10T00:00:00Z", updatedAt: "2026-09-10T00:00:00Z", planType: "CLOSURE", schedulePlanId: "ete-plan" };
  const toReplace = [{ teamId: "team-1", dayOfWeek: 3, startTime: "18:00:00", venueId: "venue-1", reason: "venue_closed" }];

  beforeEach(() => {
    vi.mocked(listSchedules).mockResolvedValue([seasonV, overlayV]);
  });

  it("affiche le panneau « à replacer » (équipe, gymnase, RAISON en clair) servi par la route", async () => {
    renderWithProviders(<PlanningPage embedded scopePlanId="ete-plan" toReplace={toReplace} />);

    const region = await screen.findByRole("region", { name: /non reprises/i });
    expect(within(region).getByText("U11")).toBeInTheDocument();
    expect(within(region).getByText(/Gymnase Alpha/)).toBeInTheDocument();
    expect(within(region).getByText("Fermeture du gymnase")).toBeInTheDocument();
  });

  it("« Comparer avec la saison » ouvre une modale de consultation du socle", async () => {
    renderWithProviders(<PlanningPage embedded scopePlanId="ete-plan" toReplace={toReplace} />);

    const btn = await screen.findByRole("button", { name: /Comparer avec la saison/i });
    await userEvent.click(btn);
    expect(await screen.findByRole("dialog", { name: /saison/i })).toBeInTheDocument();
  });

  it("hors écran de génération de période (page autonome) : ni panneau ni bouton de comparaison", async () => {
    vi.mocked(listSchedules).mockResolvedValue([seasonV]);
    renderWithProviders(<PlanningPage />);

    await screen.findByText("Planning A");
    expect(screen.queryByRole("region", { name: /non reprises/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Comparer avec la saison/i })).not.toBeInTheDocument();
  });
});

describe("PlanningPage — écarts NOMMÉS vs le socle (P2-44 PR-5)", () => {
  const seasonV: Schedule = { id: "season-v1", name: "Planning A", status: "COMPLETED", score: null, createdAt: "2026-01-01T00:00:00Z", updatedAt: "2026-01-01T00:00:00Z", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true };
  const closureV: Schedule = { id: "ov-1", name: "Toussaint", status: "COMPLETED", score: null, createdAt: "2026-09-10T00:00:00Z", updatedAt: "2026-09-10T00:00:00Z", planType: "CLOSURE", schedulePlanId: "ete-plan" };
  const toReplace = [{ teamId: "team-1", dayOfWeek: 3, startTime: "18:00:00", venueId: "venue-1", reason: "venue_closed" }];
  const deviation = {
    socleScheduleId: "socle",
    moved: [{ teamId: "team-1", from: { dayOfWeek: 2, startTime: "18:30", venueId: "venue-1" }, to: { dayOfWeek: 4, startTime: "19:00", venueId: "venue-1" } }],
    unplaced: [{ teamId: "team-1", dayOfWeek: 5, startTime: "20:00", venueId: "venue-1", reason: "team_reduced" }],
  };

  beforeEach(() => {
    vi.mocked(listSchedules).mockResolvedValue([seasonV, closureV]);
    vi.mocked(getSocleDeviation).mockClear();
  });

  it("sur une FERMETURE embarquée + COMPLETED : les DEUX panneaux coexistent (décision fondateur)", async () => {
    vi.mocked(getSocleDeviation).mockResolvedValue(deviation);
    renderWithProviders(<PlanningPage embedded scopePlanId="ete-plan" isClosurePeriod toReplace={toReplace} />);

    // Le NOUVEAU panneau (déplacées + non replacées), SERVI par la route.
    const ecarts = await screen.findByRole("region", { name: /écarts avec le planning de saison/i });
    expect(within(ecarts).getByText(/1 séance déplacée/i)).toBeInTheDocument();
    expect(within(ecarts).getByText(/1 à replacer/i)).toBeInTheDocument();
    // L'ANCIEN panneau « à replacer » reste rendu, distinct (les deux affichés).
    expect(screen.getByRole("region", { name: /non reprises/i })).toBeInTheDocument();
    expect(vi.mocked(getSocleDeviation)).toHaveBeenCalledWith("ov-1");
  });

  it("sur une VACANCE (isClosurePeriod faux) : la route socle-deviation n'est JAMAIS appelée", async () => {
    vi.mocked(getSocleDeviation).mockResolvedValue(deviation);
    renderWithProviders(<PlanningPage embedded scopePlanId="ete-plan" toReplace={toReplace} />);

    // Le panneau « à replacer » (PR-2) reste, PR-2 inchangée à l'octet.
    await screen.findByRole("region", { name: /non reprises/i });
    expect(screen.queryByRole("region", { name: /écarts avec le planning de saison/i })).not.toBeInTheDocument();
    expect(vi.mocked(getSocleDeviation)).not.toHaveBeenCalled();
  });

  it("sur /planning autonome (non embarqué) : ni panneau d'écarts ni appel de la route", async () => {
    vi.mocked(getSocleDeviation).mockResolvedValue(deviation);
    vi.mocked(listSchedules).mockResolvedValue([seasonV]);
    renderWithProviders(<PlanningPage />);

    await screen.findByText("Planning A");
    expect(screen.queryByRole("region", { name: /écarts avec le planning de saison/i })).not.toBeInTheDocument();
    expect(vi.mocked(getSocleDeviation)).not.toHaveBeenCalled();
  });
});

/**
 * Lot C, PR-1 (défaut terrain fondateur 2026-08-21) — « quand je lance une génération sur un
 * overlay, un petit chargement mouline au lieu du MÊME écran qu'en saison ». Cause 1 : `isGenerating`
 * dérive de la SEULE sélection (:480). Au lancement, une nouvelle version PENDING naît alors que la
 * sélection embarquée pointe encore l'ancienne COMPLETED → `isGenerating` faux → on tombait sur le
 * voile « Chargement des créneaux… » de la branche `slotsBusy`.
 *
 * Règle posée (maison unique ici) : l'écran de génération s'affiche dès qu'une version DU PLAN EN
 * PORTÉE est en vol — saison comme période. La portée est une VRAIE borne : une version en vol d'un
 * autre plan ne la déclenche pas.
 */
describe("PlanningPage — écran de génération dès qu'une version EN PORTÉE est en vol (lot C)", () => {
  const seasonV = (id: string, status: Schedule["status"], createdAt: string): Schedule => ({ id, name: "Planning A", status, score: null, createdAt, updatedAt: createdAt, planType: "SEASON", schedulePlanId: "season-plan" });
  const overlayV = (id: string, status: Schedule["status"], createdAt: string): Schedule => ({ id, name: "Fermeture", status, score: null, createdAt, updatedAt: createdAt, planType: "CLOSURE", schedulePlanId: "plan-p" });
  const closurePlan: SchedulePlan = { id: "plan-p", type: "CLOSURE", name: "Fermeture", startDate: "2026-09-10", calendarEntryId: "e-p", chosenScheduleId: null, teamSelectionInitialized: true };

  // DÉCISION FONDATEUR 2026-08-21 (assumée) : pendant une régénération de saison, sélectionner
  // manuellement une ancienne version COMPLETED montre malgré tout l'écran d'attente jusqu'à la
  // fin du vol — c'est la lettre de la règle (« une version du plan en portée est en vol »).
  it("standalone saison : une version en vol affiche l'attente même si la sélection pointe une ancienne COMPLETED", async () => {
    vi.mocked(listSchedules).mockResolvedValue([seasonV("v1", "COMPLETED", "2026-01-01T00:00:00Z"), seasonV("v2", "PENDING", "2026-01-02T00:00:00Z")]);
    usePlanningStore.setState({ selectedScheduleId: "v1" });
    renderWithProviders(<PlanningPage />);

    // RED avant le fix : `isGenerating` (faux sur la COMPLETED sélectionnée) laissait la grille.
    expect(await screen.findByText(/génération du planning/i)).toBeInTheDocument();
  });

  it("falsification saison : une version de PÉRIODE en vol ne déclenche PAS l'attente (la portée est une borne)", async () => {
    vi.mocked(listSchedules).mockResolvedValue([seasonV("v1", "COMPLETED", "2026-01-01T00:00:00Z"), overlayV("ov-pending", "PENDING", "2026-01-02T00:00:00Z")]);
    usePlanningStore.setState({ selectedScheduleId: "v1" });
    renderWithProviders(<PlanningPage />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(screen.queryByText(/génération du planning/i)).not.toBeInTheDocument();
  });

  it("période portée : une version du plan en vol affiche l'attente même sur une ancienne COMPLETED sélectionnée", async () => {
    vi.mocked(listSchedules).mockResolvedValue([overlayV("ov-1", "COMPLETED", "2026-09-10T00:00:00Z"), overlayV("ov-2", "PENDING", "2026-09-11T00:00:00Z")]);
    plansState.plans = [closurePlan];
    usePlanningStore.setState({ selectedScheduleId: "ov-1" });
    renderWithProviders(<PlanningPage embedded scopePlanId="plan-p" calendarEntryId="e-p" />);

    expect(await screen.findByText(/génération du planning/i)).toBeInTheDocument();
  });

  /**
   * Retour fondateur 2026-08-21, en relisant PR-1 : l'écran d'attente REMPLACE le contenu — les
   * bannières et les gestes ne doivent pas flotter au-dessus. Toutes les gardes qui se taisent
   * « pendant une génération » lisent donc `showGenerationWaiting`, pas `isGenerating` seul.
   *
   * ⚠ Le premier test est le TÉMOIN : sans lui, le second serait vide de sens (une bannière absente
   * parce qu'elle n'aurait de toute façon jamais eu de quoi s'afficher).
   */
  function twoTeamsOneUnplaced(): void {
    vi.mocked(getTeams).mockResolvedValue([
      { id: "team-1", name: "U11", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 1 },
      { id: "team-2", name: "U13", sportCategoryId: "cat-1", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 1 },
    ]);
    vi.mocked(getSlots).mockResolvedValue([
      { id: "slot-1", scheduleId: "ov-1", teamId: "team-1", venueId: "venue-1", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null },
    ]);
  }

  it("TÉMOIN — sans version en vol, la bannière de dérive s'affiche bien (sinon le test suivant ne prouverait rien)", async () => {
    twoTeamsOneUnplaced();
    vi.mocked(listSchedules).mockResolvedValue([overlayV("ov-1", "COMPLETED", "2026-09-10T00:00:00Z")]);
    plansState.plans = [closurePlan];
    usePlanningStore.setState({ selectedScheduleId: "ov-1" });
    renderWithProviders(<PlanningPage embedded scopePlanId="plan-p" calendarEntryId="e-p" />);

    expect(await screen.findByRole("region", { name: "Séances à replacer" })).toBeInTheDocument();
  });

  it("l'écran d'attente REMPLACE le contenu : aucune bannière de dérive ne flotte au-dessus", async () => {
    twoTeamsOneUnplaced();
    vi.mocked(listSchedules).mockResolvedValue([overlayV("ov-1", "COMPLETED", "2026-09-10T00:00:00Z"), overlayV("ov-2", "PENDING", "2026-09-11T00:00:00Z")]);
    plansState.plans = [closurePlan];
    usePlanningStore.setState({ selectedScheduleId: "ov-1" });
    renderWithProviders(<PlanningPage embedded scopePlanId="plan-p" calendarEntryId="e-p" />);

    expect(await screen.findByText(/génération du planning/i)).toBeInTheDocument();
    expect(screen.queryByRole("region", { name: "Séances à replacer" })).not.toBeInTheDocument();
  });

  it("falsification période : en portée période, une génération de SAISON en vol ne déclenche PAS l'attente", async () => {
    vi.mocked(listSchedules).mockResolvedValue([overlayV("ov-1", "COMPLETED", "2026-09-10T00:00:00Z"), seasonV("s-pending", "PENDING", "2026-09-11T00:00:00Z")]);
    plansState.plans = [closurePlan];
    usePlanningStore.setState({ selectedScheduleId: "ov-1" });
    renderWithProviders(<PlanningPage embedded scopePlanId="plan-p" calendarEntryId="e-p" />);

    expect(await screen.findByText("U11")).toBeInTheDocument();
    expect(screen.queryByText(/génération du planning/i)).not.toBeInTheDocument();
  });
});
