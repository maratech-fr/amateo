import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { DayDialog } from "./DayDialog";

const deleteMutate = vi.fn();
const cutoffMutate = vi.fn();
const closureMutate = vi.fn();
// "Adapter" (create branch) uses mutateAsync so the wizard navigation survives a
// mid-POST modal dismiss — the mock resolves with the created period's id.
// Entrée COMPLÈTE (comme l'API réelle) : requestAdapt lit startDate/endDate pour
// calculer les semaines — un {id} nu ferait jeter weeksCovering en silence.
const holidayMutateAsync = vi.fn(() =>
  Promise.resolve({ id: "created-hol", kind: "period", periodType: "holiday", title: "Vacances de Noël", startDate: "2026-05-10", endDate: "2026-05-20", isDisruptive: false, schoolHolidayId: "sh1", parentEntryId: null, status: "active", createdBy: null }),
);
const navigate = vi.fn();
const startPeriodMode = vi.fn();
const setSelectedScheduleId = vi.fn();
const weekChildrenMutate = vi.fn();
// P2-38 : « Adapter » (DayList adaptRoot) crée le plan via mutateAsync — mock partagé pour qu'un
// test le fasse REJETER avec un refus de chevauchement (409 window_already_planned).
const periodPlanMutateAsync = vi.fn().mockResolvedValue({});
// D3 v1 PR-2 — le geste « Modifier les dates » d'une fermeture : mutateAsync espionné (le corps du
// PUT est construit et testé au niveau hook, `windowConflict.test.tsx`).
const redateMutateAsync = vi.fn().mockResolvedValue({});
// Plans couvrant le jour (B1) : DayList lit chosenScheduleId par calendarEntryId.
let allPlansMock: { id: string; calendarEntryId: string | null; chosenScheduleId: string | null; staleness?: unknown }[] = [];

// ADR-0002 lot D-b : « overlay validé » (HolidayBlock « Voir le planning ») = plan de
// période avec chosenScheduleId ; « porte des versions » (garde destructive de suppression)
// = une Schedule pend au plan (schedulePlanId). Les deux se dérivent du plan, plus de
// pointeur sur l'entrée.
let plansByEntry: Record<string, { id: string; chosenScheduleId: string | null }> = {};
let schedulesData: { id: string; schedulePlanId: string | null; status?: string }[] = [];
// P2-36 — spy de la suppression de version (découpe destructive de l'état « bloc »).
const deleteScheduleMutateAsync = vi.fn().mockResolvedValue(undefined);
// undefined data = requêtes pas encore résolues (1er chargement ou 1er échec sans donnée) →
// fail-closed. Le code clé sur la PRÉSENCE de `data`, pas sur le statut (une donnée périmée
// après un refetch en échec reste exploitable).
let queriesNoData = false;
let childEntriesData: CalendarEntry[] = [];
// P2-40 — le feed des vacances scolaires que useWeekAdapt lit pour l'offre des fermetures.
let schoolHolidaysMock: { zone: string | null; items: SchoolHoliday[] } = { zone: "A", items: [] };
// #5 gating : socle (plan de saison) validé par défaut ; un test dédié le passe à null.
let meData: { seasonPlan: { chosenScheduleId: string | null } } = { seasonPlan: { chosenScheduleId: "s-season" } };

vi.mock("./queries", () => ({
  useCreateEvent: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateVenueClosure: () => ({ mutate: closureMutate, isPending: false }),
  useCreateCutoff: () => ({ mutate: cutoffMutate, isPending: false }),
  useCreateHolidayPeriod: () => ({ mutate: vi.fn(), mutateAsync: holidayMutateAsync, isPending: false }),
  useCreateWeekChildren: () => ({ mutate: weekChildrenMutate, isPending: false }),
  useCreatePeriodPlan: () => ({ mutateAsync: periodPlanMutateAsync, isPending: false }),
  useRedateEntry: () => ({ mutateAsync: redateMutateAsync, isPending: false }),
  useDeleteEntry: () => ({ mutate: deleteMutate, isPending: false }),
  useSchedulePlanForEntry: (id: string | null) => ({ data: null !== id && !queriesNoData ? (plansByEntry[id] ?? null) : undefined }),
  // P2-5 E1 : enfants de semaine — aucun par défaut dans ces tests (mutable pour l'encart).
  useCalendarEntries: () => ({ data: childEntriesData }),
  useSchedulePlans: () => ({ data: allPlansMock }),
  // P2-40 — useWeekAdapt source les vacances pour l'offre des fermetures. Vide par défaut :
  // les cas existants (fermeture sans vacances) sont inchangés.
  useSchoolHolidays: () => ({ data: schoolHolidaysMock }),
  // P2-38 (prévention) — les fenêtres déjà planifiées SERVIES. Vide + résolu par défaut : aucun
  // changement pour les cas existants.
  usePlannedWindows: () => ({ data: [], isError: false }),
}));
vi.mock("@/features/planning/queries", () => ({
  useVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, canSplit: false, isActive: true }] }),
  useSchedules: () => ({ data: queriesNoData ? undefined : schedulesData }),
  // P2-36 — la découpe destructive supprime les versions via useDeleteSchedule.
  useDeleteSchedule: () => ({ mutateAsync: deleteScheduleMutateAsync, isPending: false }),
}));
vi.mock("react-router", async (orig) => ({ ...(await orig<typeof import("react-router")>()), useNavigate: () => navigate }));
// Toast espionné : un refus de chevauchement s'affiche DANS le dialogue (proposition), jamais en
// toast générique par-dessus (P2-38).
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock("@/features/wizard/store", () => ({ useWizardStore: (sel: (s: unknown) => unknown) => sel({ startPeriodMode }) }));
vi.mock("@/features/planning/store", () => ({ usePlanningStore: (sel: (s: unknown) => unknown) => sel({ setSelectedScheduleId }) }));
// Freeze "today" so the fixed test date (2026-05-12) is not in the past (start ≥ today).
// Partiel : clampRangeToSeason (clamp saison des créations, revue #260) reste le vrai.
vi.mock("./lib/date", async (orig) => ({ ...(await orig<typeof import("./lib/date")>()), todayISO: () => "2026-05-12" }));
// Saison de travail couvrant les dates de test : le clamp laisse créer.
vi.mock("@/shared/session/queries", () => ({
  useWorkingSeason: () => ({ id: "sn1", name: "2025-2026", startDate: "2025-08-01", endDate: "2026-07-31", isCurrent: true, isReadonly: false }),
  useMe: () => ({ data: meData }),
}));

const entry = (overrides: Partial<CalendarEntry>): CalendarEntry => ({
  id: "e1",
  kind: "event",
  title: "AG du club",
  startDate: "2026-05-12",
  endDate: "2026-05-12",
  isDisruptive: false,
  periodType: null,
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  redatable: false,
  ...overrides,
});

function renderDialog(entries: CalendarEntry[], holidays: { holiday?: SchoolHoliday; publicHoliday?: PublicHoliday } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DayDialog iso="2026-05-12" entries={entries} holiday={holidays.holiday} publicHoliday={holidays.publicHoliday} onClose={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const schoolHoliday = (over: Partial<SchoolHoliday> = {}): SchoolHoliday => ({ id: "sh1", label: "Vacances de Noël", holidayType: "noel", startDate: "2026-05-10", endDate: "2026-05-20", schoolYear: "2025-2026", ...over });

describe("DayDialog — deletion is always confirmed", () => {
  beforeEach(() => {
    deleteMutate.mockReset();
    cutoffMutate.mockReset();
    closureMutate.mockReset();
    holidayMutateAsync.mockClear();
    weekChildrenMutate.mockReset();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
    childEntriesData = [];
  });

  it("asks for confirmation before deleting, then deletes on confirm", async () => {
    renderDialog([entry({})]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer AG du club" }));
    // Nothing deleted yet — a confirmation dialog opened instead.
    expect(deleteMutate).not.toHaveBeenCalled();
    expect(screen.getByText(/Supprimer « AG du club » \?/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(deleteMutate).toHaveBeenCalledWith("e1", expect.anything());
  });

  it("cancel closes the confirmation without deleting", async () => {
    renderDialog([entry({})]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer AG du club" }));
    await userEvent.click(screen.getByRole("button", { name: "Annuler" }));

    expect(deleteMutate).not.toHaveBeenCalled();
    expect(screen.queryByText(/Supprimer « AG du club » \?/)).not.toBeInTheDocument();
  });

  it("P4-173 — shows the « à régénérer » pill next to a stale period's title (outside the truncated node)", () => {
    allPlansMock = [{ id: "plan-p1", calendarEntryId: "p1", chosenScheduleId: "ov1", staleness: { manuallyEdited: false, constraintsChanged: true, resourcesChanged: false } }];
    renderDialog([entry({ id: "p1", kind: "period", periodType: "closure", title: "Gym fermé" })]);

    const pill = screen.getByText("À régénérer — une contrainte a changé");
    expect(pill).toBeInTheDocument();
    // Jamais dans un nœud tronqué : la cause s'enveloppe, elle ne se coupe pas.
    expect(pill.closest(".truncate")).toBeNull();
  });

  it("P4-173 — no pill when the period plan carries no staleness (null)", () => {
    allPlansMock = [{ id: "plan-p1", calendarEntryId: "p1", chosenScheduleId: "ov1", staleness: null }];
    renderDialog([entry({ id: "p1", kind: "period", periodType: "closure", title: "Gym fermé" })]);

    expect(screen.queryByText(/À régénérer/)).not.toBeInTheDocument();
  });

  it("warns that deleting a period cascades to its plan and all its versions", async () => {
    // Décision fondateur : la suppression emporte le plan ET toutes ses versions —
    // on avertit dès qu'une version pend au plan (brouillon inclus), pas seulement validée.
    plansByEntry = { p1: { id: "plan-p1", chosenScheduleId: null } };
    schedulesData = [{ id: "draft1", schedulePlanId: "plan-p1" }];
    renderDialog([entry({ id: "p1", kind: "period", periodType: "closure", title: "Gym fermé" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Gym fermé" }));

    expect(screen.getByText(/son plan et toutes ses versions/i)).toBeInTheDocument();
  });

  it("keeps the benign message when the period plan carries no version yet", async () => {
    plansByEntry = { p2: { id: "plan-p2", chosenScheduleId: null } };
    schedulesData = []; // plan vide → la suppression ne perd rien
    renderDialog([entry({ id: "p2", kind: "period", periodType: "closure", title: "Vide" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Vide" }));

    expect(screen.getByText("Cette entrée sera retirée du calendrier.")).toBeInTheDocument();
  });

  it("fail-closed: while the period's plan/versions are unresolved (no data yet), warns about the cascade (never under-warns)", async () => {
    // Le dialogue s'ouvre avant que le plan réponde (1er chargement, ou 1er échec sans donnée) :
    // sous-avertir ferait perdre des versions après un message bénin (régression P4-19).
    queriesNoData = true;
    plansByEntry = {}; // plan pas encore résolu
    schedulesData = [];
    renderDialog([entry({ id: "p3", kind: "period", periodType: "closure", title: "En cours" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer En cours" }));

    expect(screen.getByText(/son plan et toutes ses versions/i)).toBeInTheDocument();
  });

  it("resolved data stays benign for an empty plan even if a background refetch errors (keys on data, not status)", async () => {
    // TanStack passe en error sur un refetch d'arrière-plan en gardant la donnée : un plan
    // VIDE résolu doit rester bénin — s'y fier sur isSuccess sur-avertirait à chaque blip.
    plansByEntry = { p4: { id: "plan-p4", chosenScheduleId: null } };
    schedulesData = []; // plan résolu et vide → rien à perdre
    renderDialog([entry({ id: "p4", kind: "period", periodType: "holiday", title: "Vacances" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Vacances" }));

    expect(screen.getByText("Cette entrée sera retirée du calendrier.")).toBeInTheDocument();
  });

  it("never flashes the cascade warning for a cutoff, even while unresolved (no plan, inv. 9)", async () => {
    // Régression évitée : le fail-closed ne doit pas s'armer sur un type non overlayable —
    // cutoff/mutualisation/custom ne portent jamais de plan, aucune cascade à annoncer.
    queriesNoData = true;
    renderDialog([entry({ id: "p5", kind: "period", periodType: "cutoff", title: "Coupure" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Coupure" }));

    expect(screen.getByText("Cette entrée sera retirée du calendrier.")).toBeInTheDocument();
  });

  it("keeps the custom period button disabled (deferred palier B/C)", () => {
    renderDialog([]);

    expect(screen.getByRole("button", { name: "Créer une période…" })).toBeDisabled();
  });

  it("creates a cutoff with the default title when left empty", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-12", endDate: "2026-05-12" }, expect.anything());
  });

  it("creates a cutoff with a custom title and end date", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Coupure de Noël");
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-05-18");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure de Noël", startDate: "2026-05-12", endDate: "2026-05-18" }, expect.anything());
  });

  it("builds a structured '{venue} — {reason}' closure title, defaulting the reason to 'fermé'", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A — fermé", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  it("puts the typed reason after the venue in the closure title", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Travaux");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A — Travaux", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  it("does not repeat the venue when the typed reason already names it", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Gymnase A en travaux");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A en travaux", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  // Lot B — item 2: the clicked day is only a DEFAULT start; both ends are editable.
  it("lets the start date be changed (clicked day is only the default)", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    const startInput = screen.getByLabelText("Du");
    await userEvent.clear(startInput);
    await userEvent.type(startInput, "2026-05-15");
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-05-18");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-15", endDate: "2026-05-18" }, expect.anything());
  });

  // Moving the start past the end must bump the end so the window never inverts.
  it("clamps the end forward when the start is moved past it", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    const startInput = screen.getByLabelText("Du");
    await userEvent.clear(startInput);
    await userEvent.type(startInput, "2026-05-20"); // later than the default end (2026-05-12)
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-20", endDate: "2026-05-20" }, expect.anything());
  });
});

describe("DayDialog — holiday awareness (Lot B)", () => {
  beforeEach(() => {
    holidayMutateAsync.mockClear();
    navigate.mockClear();
    startPeriodMode.mockClear();
    setSelectedScheduleId.mockClear();
    weekChildrenMutate.mockReset();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
    childEntriesData = [];
  });

  // item 1: a public holiday (jour férié) shows read-only info.
  it("shows the public-holiday info banner", () => {
    renderDialog([], { publicHoliday: { id: "ph1", date: "2026-05-12", label: "Ascension", national: true } });
    expect(screen.getByText("Jour férié")).toBeInTheDocument();
    expect(screen.getByText(/Ascension/)).toBeInTheDocument();
  });

  // item 1 + 3: a school holiday shows info AND the "Adapter" entry point.
  // NR #1 (retour fondateur 2026-07-19) : ces vacances couvrent PLUSIEURS semaines
  // → « Adapter » ouvre le CHOIX DES SEMAINES SANS matérialiser la mère (annuler ne
  // doit laisser aucun événement fantôme). La mère naît à la confirmation.
  it("shows the school-holiday info + « Adapter » opens the week picker WITHOUT creating anything on a multi-week holiday", async () => {
    // Vacances 10/05 (dim) → 22/05 (ven) : DEUX semaines DE VACANCES (lun→ven couvert) — 11–17 et
    // 18–24 (le vendredi 22 couvre la seconde) ; la semaine d'entame du 04–10 reste de saison.
    renderDialog([], { holiday: schoolHoliday({ endDate: "2026-05-22" }) });
    expect(screen.getByText("Vacances")).toBeInTheDocument();
    expect(screen.getByText(/Vacances de Noël/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    // Le picker s'ouvre immédiatement — AUCUNE création tant que non confirmé.
    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    expect(holidayMutateAsync).not.toHaveBeenCalled();
    expect(startPeriodMode).not.toHaveBeenCalled();
    // Le chemin « d'un bloc » matérialise ALORS la mère puis mène au wizard.
    await userEvent.click(screen.getByRole("button", { name: /d'un bloc/i }));
    expect(holidayMutateAsync).toHaveBeenCalledWith({ schoolHolidayId: "sh1", label: "Vacances de Noël", startDate: "2026-05-10", endDate: "2026-05-22" });
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("created-hol"));
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  // La branche à UNE seule semaine calendaire va DIRECT au wizard (pas de picker) —
  // le test multi-semaines ci-dessus ne la couvre plus (revue #262 round 3).
  it("adapts a single-calendar-week holiday directly, without the week picker", async () => {
    // Vacances d'UNE semaine pleine (lun→dim) → weeksCovering rend 1 semaine.
    holidayMutateAsync.mockResolvedValueOnce({ id: "hol-1w", kind: "period", periodType: "holiday", title: "Court", startDate: "2026-05-11", endDate: "2026-05-15", isDisruptive: false, schoolHolidayId: "sh-1w", parentEntryId: null, status: "active", createdBy: null });
    renderDialog([], { holiday: schoolHoliday({ id: "sh-1w", label: "Court", startDate: "2026-05-11", endDate: "2026-05-15" }) });

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("hol-1w"));
    expect(screen.queryByText("Quelles semaines ajuster ?")).not.toBeInTheDocument();
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  // finding [1]: an existing overlay stays viewable even for a summer holiday (legacy data).
  it("still offers « Voir le planning » for a summer holiday that already has an overlay", () => {
    plansByEntry = { pe: { id: "plan-pe", chosenScheduleId: "ov-ete" } };
    const periodEntry = entry({ id: "pe", kind: "period", periodType: "holiday", schoolHolidayId: "sh-ete", startDate: "2026-05-10", endDate: "2026-05-20" });
    renderDialog([periodEntry], { holiday: schoolHoliday({ id: "sh-ete", label: "Vacances d'Été", holidayType: "ete" }) });
    expect(screen.getByRole("button", { name: "Voir le planning" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });

  // item 3: once the holiday overlay is generated, offer "Voir le planning" instead.
  it("offers « Voir le planning » when the holiday's overlay is already generated", () => {
    plansByEntry = { p9: { id: "plan-p9", chosenScheduleId: "ov9" } };
    const periodEntry = entry({ id: "p9", kind: "period", periodType: "holiday", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-20" });
    renderDialog([periodEntry], { holiday: schoolHoliday() });
    expect(screen.getByRole("button", { name: "Voir le planning" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });

  // The modal reuses the calendar's emoji markers (same look in both places).
  it("marks day entries with the same calendar emojis (⛔ closure, 🛑 cutoff)", () => {
    renderDialog([entry({ id: "c1", kind: "period", periodType: "closure", title: "Gym fermé" }), entry({ id: "c2", kind: "period", periodType: "cutoff", title: "Coupure de Noël" })]);
    expect(screen.getByText("⛔")).toBeInTheDocument();
    expect(screen.getByText("🛑")).toBeInTheDocument();
  });

  // L'été s'adapte comme les autres vacances (planning de reprise — retour
  // fondateur 2026-07-18, P2-5 E2 : l'exclusion `ete` est levée).
  it("offers « Adapter » on a summer holiday (planning de reprise)", () => {
    renderDialog([], { holiday: schoolHoliday({ id: "sh-ete", label: "Vacances d'Été", holidayType: "ete" }) });
    expect(screen.getByText(/Vacances d'Été/)).toBeInTheDocument();
    expect(screen.queryByText(/hors saison/i)).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Adapter" })).toBeInTheDocument();
  });

  // Fenêtre entièrement hors de la saison de travail : un message, pas un
  // bouton mort inexpliqué (revue #260 round 2).
  it("explains instead of a dead button when the holiday is fully outside the season", () => {
    renderDialog([], { holiday: schoolHoliday({ id: "sh-out", label: "Vacances lointaines", startDate: "2027-10-01", endDate: "2027-10-15" }) });
    // P4-150 — copie d'écran EXACTE (le « rien à adapter » est protégé, pas seulement l'entête).
    expect(screen.getByText("Hors de la saison en cours — rien à adapter.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });

  // NR #1 : la mère vacances est un ANCRAGE invisible — jamais listée comme entrée
  // supprimable (la vacance scolaire EST déjà l'événement). Les autres entrées, si.
  it("never lists the holiday mother as a deletable entry (invisible anchor, not a phantom event)", () => {
    const mother = entry({ id: "hm", kind: "period", periodType: "holiday", title: "Vacances de Noël", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-20" });
    const other = entry({ id: "ev", title: "AG du club" });
    renderDialog([mother, other], { holiday: schoolHoliday() });

    expect(screen.queryByRole("button", { name: /Supprimer Vacances de Noël/ })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Supprimer AG du club" })).toBeInTheDocument();
    expect(screen.getByText("Vacances")).toBeInTheDocument();
  });

  // Revue F2 : sur un jour de vacances « bloc » dont la seule entrée est la mère
  // (masquée), la liste supprimable est vide — mais « Rien ce jour-là » NE doit PAS
  // s'afficher sous le bloc vacances (ce serait se contredire).
  it("never shows the « rien ce jour-là » message on a holiday day whose only entry is the hidden mother", () => {
    const mother = entry({ id: "hm", kind: "period", periodType: "holiday", title: "Vacances de Noël", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-20" });
    renderDialog([mother], { holiday: schoolHoliday() });

    expect(screen.queryByText(/Rien ce jour-là/)).not.toBeInTheDocument();
    expect(screen.getByText("Vacances")).toBeInTheDocument();
  });

  // NR #5 : plan de saison non validé → l'ajustement d'une vacance est désactivé.
  it("disables « Adapter » while the season plan is not validated (#5)", () => {
    meData = { seasonPlan: { chosenScheduleId: null } };
    renderDialog([], { holiday: schoolHoliday() });

    expect(screen.getByRole("button", { name: "Adapter" })).toBeDisabled();
  });

  // B1 (retour fondateur 2026-07-19) : clic-jour → les plannings couvrants (fermeture
  // + semaine de vacances) sont accessibles en AJUSTER (en cours) / Consulter (validé).
  it("lists the day's covering plannings (closure + holiday week) with AJUSTER / Consulter", async () => {
    allPlansMock = [
      { id: "pl-cl", calendarEntryId: "cl1", chosenScheduleId: null }, // fermeture en cours → Ajuster
      { id: "pl-w1", calendarEntryId: "w1", chosenScheduleId: "sched-9" }, // semaine validée → Consulter
    ];
    renderDialog([
      entry({ id: "cl1", kind: "period", periodType: "closure", title: "Gym fermé" }),
      entry({ id: "w1", kind: "period", periodType: "holiday", parentEntryId: "m1", title: "Toussaint S1" }),
    ]);

    await userEvent.click(screen.getByRole("button", { name: "Ajuster" }));
    // P2-36 tranche 2 : « Ajuster » passe par la maison unique — cl1 est sur une seule semaine,
    // donc pas de dialogue, on file au wizard (le plan de bloc naît idempotent, comme au radar).
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("cl1"));
    expect(navigate).toHaveBeenCalledWith("/wizard");

    await userEvent.click(screen.getByRole("button", { name: "Consulter" }));
    expect(setSelectedScheduleId).toHaveBeenCalledWith("sched-9");
    expect(navigate).toHaveBeenCalledWith("/planning");
  });

  // PR C : une vacance démarrant vendredi → le picker n'offre PAS la semaine partielle
  // de début (impact réel = semaines suivantes). 2026-05-15 est un vendredi.
  it("skips the partial start week in the picker for a Friday-starting holiday", async () => {
    renderDialog([], { holiday: schoolHoliday({ id: "sh-fri", label: "Toussaint", startDate: "2026-05-15", endDate: "2026-05-31" }) });

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    // La semaine partielle du 11–17 mai (contenant le vendredi) n'est pas proposée.
    expect(screen.queryByText(/11 mai/)).not.toBeInTheDocument();
    // P2-41 — les deux semaines pleines restantes (18–24 + 25–31) sont un SEGMENT unique,
    // proposé d'un bloc à partir du lundi suivant (18 mai). A2 — la fenêtre tient dans la
    // saison affichée → le libellé omet l'année.
    expect(screen.getByText(/Semaines du 18 mai au 31 mai — d'un bloc/)).toBeInTheDocument();
  });

  // Revue C F2 : une vacance démarrant vendredi qui, une fois la semaine partielle
  // écartée, ne laisse qu'UNE semaine → pas de picker à une seule option : adapt direct.
  it("adapts directly (no picker) when a Friday holiday leaves a single week after skipping the partial start", async () => {
    holidayMutateAsync.mockResolvedValueOnce({ id: "hol-1w", kind: "period", periodType: "holiday", title: "Court", startDate: "2026-05-15", endDate: "2026-05-20", isDisruptive: false, schoolHolidayId: "sh-1w", parentEntryId: null, status: "active", createdBy: null });
    // Ven 15 → mer 20 mai : weeksCovering = 11–17 + 18–24 (2), mais periodAdjustWeeks = 18–24 (1).
    renderDialog([], { holiday: schoolHoliday({ id: "sh-1w", label: "Court", startDate: "2026-05-15", endDate: "2026-05-20" }) });

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("hol-1w"));
    expect(screen.queryByText("Quelles semaines ajuster ?")).not.toBeInTheDocument();
  });

  // B1 : après avoir choisi ≥2 semaines, le wizard s'ouvre sur la 1ʳᵉ semaine créée.
  it("opens the wizard on the FIRST created week after picking several weeks", async () => {
    weekChildrenMutate.mockImplementation((_payload: unknown, opts?: { onSuccess?: (r: { created: { id: string }[]; failedCount: number }) => void }) =>
      opts?.onSuccess?.({ created: [{ id: "wk-1" }, { id: "wk-2" }], failedCount: 0 }),
    );
    renderDialog([], { holiday: schoolHoliday({ endDate: "2026-05-22" }) }); // vacances multi-semaines (11–17 + 18–24 couvertes lun→ven)

    await userEvent.click(screen.getByRole("button", { name: "Adapter" })); // ouvre le picker (pending)
    await userEvent.click(screen.getByRole("button", { name: /^Créer les/ })); // confirme les semaines cochées
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("wk-1"));
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  // Revue B1 F2 : échec PARTIEL (des semaines n'ont pas été créées) → on NE navigue
  // PAS (le gestionnaire doit lire le toast d'erreur, pas être emmené au wizard).
  it("does NOT navigate to the wizard when some weeks failed to be created", async () => {
    weekChildrenMutate.mockImplementation((_payload: unknown, opts?: { onSuccess?: (r: { created: { id: string }[]; failedCount: number }) => void }) =>
      opts?.onSuccess?.({ created: [{ id: "wk-1" }], failedCount: 2 }),
    );
    renderDialog([], { holiday: schoolHoliday({ endDate: "2026-05-22" }) }); // multi-semaines (couvertes lun→ven)

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    await userEvent.click(screen.getByRole("button", { name: /^Créer les/ }));
    await waitFor(() => expect(weekChildrenMutate).toHaveBeenCalled());
    expect(startPeriodMode).not.toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalledWith("/wizard");
  });
});

// ── Encart vacances : chips 3 états + suppression intégrée (fondateur 2026-07-24) ──
describe("DayDialog — holiday block chips (3 states) and integrated week deletion", () => {
  beforeEach(() => {
    deleteMutate.mockReset();
    startPeriodMode.mockClear();
    navigate.mockClear();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
    childEntriesData = [];
  });

  const mother = () => entry({ id: "mh1", kind: "period", periodType: "holiday", title: "Vacances de Noël", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-20" });
  const week = () => entry({ id: "wk1", kind: "period", periodType: "holiday", title: "Vacances de Noël — semaine du 11 mai", parentEntryId: "mh1", startDate: "2026-05-11", endDate: "2026-05-17" });

  it("shows « · en cours » on a week whose plan has versions but no validated one, clickable even if the season plan is reopened", async () => {
    meData = { seasonPlan: { chosenScheduleId: null } }; // socle non validé
    childEntriesData = [mother(), week()];
    allPlansMock = [{ id: "wp1", calendarEntryId: "wk1", chosenScheduleId: null }];
    schedulesData = [{ id: "s1", schedulePlanId: "wp1" }]; // versions → en cours
    renderDialog([mother(), week()], { holiday: schoolHoliday() });

    const chip = await screen.findByRole("button", { name: /sem\. du 11 mai .*· en cours/ });
    expect(chip).toBeEnabled(); // reprise jamais bloquée par le socle (parité radar)
    await userEvent.click(chip);
    expect(startPeriodMode).toHaveBeenCalledWith("wk1");
  });

  it("shows « · à faire » (locked while the season plan is not validated) on a 0-version week", async () => {
    meData = { seasonPlan: { chosenScheduleId: null } };
    childEntriesData = [mother(), week()];
    allPlansMock = [{ id: "wp1", calendarEntryId: "wk1", chosenScheduleId: null }];
    renderDialog([mother(), week()], { holiday: schoolHoliday() });

    const chip = await screen.findByRole("button", { name: /sem\. du 11 mai .*· à faire/ });
    expect(chip).toBeDisabled();
  });

  // A11Y-18 — l'état validé d'une chip de semaine ne peut reposer sur le seul emoji ✅ : il porte
  // un TEXTE (« validée »), de même nature que « · en cours »/« · à faire », et cohérent avec la
  // même chip de la carte radar. Un ✅ nu est muet/aléatoire au lecteur d'écran selon la plateforme.
  it("annonce l'état « validée » par un texte, pas le seul emoji ✅ (A11Y-18)", async () => {
    childEntriesData = [mother(), week()];
    allPlansMock = [{ id: "wp1", calendarEntryId: "wk1", chosenScheduleId: "sched-ok" }]; // semaine validée
    renderDialog([mother(), week()], { holiday: schoolHoliday() });

    expect(await screen.findByRole("button", { name: /sem\. du 11 mai.*validée/ })).toBeInTheDocument();
  });

  it("moves the week's delete action INTO the holiday block (no separate list row) and confirms it", async () => {
    childEntriesData = [mother(), week()];
    allPlansMock = [{ id: "wp1", calendarEntryId: "wk1", chosenScheduleId: null }];
    renderDialog([mother(), week()], { holiday: schoolHoliday() });

    // Plus de ligne séparée « ⛔ … semaine du 11 mai [🗑] » dans la liste du jour…
    expect(screen.queryByRole("button", { name: "Supprimer Vacances de Noël — semaine du 11 mai" })).toBeNull();
    // …la poubelle vit DANS l'encart, à côté de la chip.
    await userEvent.click(await screen.findByRole("button", { name: "Supprimer la semaine du 11 mai 2026" }));
    expect(deleteMutate).not.toHaveBeenCalled(); // jamais sans confirmation
    await userEvent.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(deleteMutate).toHaveBeenCalledWith("wk1", expect.anything());
  });
});

// ── P2-38 PR3 : « Adapter » (adaptRoot) refusé pour chevauchement → proposition dans le dialogue ──
describe("DayDialog — refus de chevauchement sur « Adapter » (P2-38)", () => {
  const conflictMessage =
    "Ces dates sont déjà planifiées par « Vacances de Toussaint » (du 19 octobre 2026 au 2 novembre 2026). Modifiez ce planning existant ou supprimez-le avant d’en créer un autre ici. Vous pouvez aussi découper la période en semaines.";

  beforeEach(async () => {
    periodPlanMutateAsync.mockReset();
    startPeriodMode.mockClear();
    navigate.mockClear();
    const { toast } = await import("@/shared/stores/toastStore");
    vi.mocked(toast.error).mockClear();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
    childEntriesData = [];
  });

  // Fermeture RACINE sans plan → bouton « Adapter » (adaptRoot). Le POST /schedule_plans est
  // refusé (409) : le dialogue AFFICHE le message du serveur et propose d'ouvrir le planning en
  // place — pas de toast générique par-dessus.
  it("affiche la proposition serveur (nomme la période) et n'émet aucun toast générique", async () => {
    const { WindowAlreadyPlannedError } = await import("./api");
    periodPlanMutateAsync.mockRejectedValueOnce(new WindowAlreadyPlannedError(conflictMessage, "toussaint-entry"));
    const { toast } = await import("@/shared/stores/toastStore");
    renderDialog([entry({ id: "cl9", kind: "period", periodType: "closure", title: "Gymnase A — travaux" })]);

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));

    expect(await screen.findByText(/déjà planifiées par « Vacances de Toussaint »/)).toBeInTheDocument();
    expect(toast.error).not.toHaveBeenCalled();
    // On n'a PAS navigué vers le wizard : le geste a été refusé, il propose au lieu d'emmener.
    expect(navigate).not.toHaveBeenCalledWith("/wizard");
  });

  it("« Ouvrir le planning en place » mène à la période reçue dans entryId", async () => {
    const { WindowAlreadyPlannedError } = await import("./api");
    periodPlanMutateAsync.mockRejectedValueOnce(new WindowAlreadyPlannedError(conflictMessage, "toussaint-entry"));
    renderDialog([entry({ id: "cl9", kind: "period", periodType: "closure", title: "Gymnase A — travaux" })]);

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    await userEvent.click(await screen.findByRole("button", { name: /ouvrir le planning en place/i }));

    expect(startPeriodMode).toHaveBeenCalledWith("toussaint-entry");
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  it("témoin de non-régression : une AUTRE erreur (réseau/500) n'affiche PAS le bloc de refus", async () => {
    periodPlanMutateAsync.mockRejectedValueOnce(new Error("network down"));
    renderDialog([entry({ id: "cl9", kind: "period", periodType: "closure", title: "Gymnase A — travaux" })]);

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));

    // Laisse la rejection se propager, puis vérifie qu'aucun bloc de chevauchement n'a surgi.
    await waitFor(() => expect(periodPlanMutateAsync).toHaveBeenCalled());
    expect(screen.queryByRole("button", { name: /ouvrir le planning en place/i })).not.toBeInTheDocument();
  });
});

// P2-36 — plus de bascule silencieuse en bloc depuis le DayDialog non plus : « Adapter » d'une
// vacance MATÉRIALISÉE déjà générée d'un bloc ouvre le picker qui NOMME le fait, et propose la
// découpe destructive (versions supprimées une par une, jamais le plan ni l'entrée).
describe("DayDialog — P2-36 : le picker nomme l'état « bloc déjà généré »", () => {
  // Vacance MATÉRIALISÉE multi-semaines (10/05 dim → 22/05 ven : 11–17 + 18–24 couvertes lun→ven),
  // plan de bloc non validé portant une/des version(s).
  const materialisedHoliday = () => entry({ id: "pe", kind: "period", periodType: "holiday", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-22" });

  beforeEach(() => {
    holidayMutateAsync.mockClear();
    navigate.mockClear();
    startPeriodMode.mockClear();
    weekChildrenMutate.mockReset();
    deleteScheduleMutateAsync.mockClear();
    deleteMutate.mockClear();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    plansByEntry = { pe: { id: "plan-pe", chosenScheduleId: null } }; // plan de bloc non validé
    allPlansMock = [{ id: "plan-pe", calendarEntryId: "pe", chosenScheduleId: null }];
    schedulesData = [{ id: "sv1", schedulePlanId: "plan-pe", status: "COMPLETED" }]; // ≥ 1 version
    queriesNoData = false;
    childEntriesData = []; // pas de semaines-enfants
  });

  it("ne bascule plus en bloc en silence : « Adapter » ouvre le picker qui NOMME le bloc généré", async () => {
    renderDialog([materialisedHoliday()], { holiday: schoolHoliday() });

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    expect(screen.getByText(/déjà été adaptée d'un bloc — 1 version/)).toBeInTheDocument();
    // Rien n'a été matérialisé/navigué en silence.
    expect(startPeriodMode).not.toHaveBeenCalled();
  });

  it("découpe destructive : supprime la version du plan de bloc, sans toucher au plan ni à l'entrée", async () => {
    schedulesData = [
      { id: "sv1", schedulePlanId: "plan-pe", status: "COMPLETED" },
      { id: "sv2", schedulePlanId: "plan-pe", status: "COMPLETED" },
    ];
    renderDialog([materialisedHoliday()], { holiday: schoolHoliday() });

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    await userEvent.click(screen.getByRole("button", { name: /Supprimer les versions et découper en semaines/i }));
    await userEvent.click(screen.getByRole("button", { name: "Supprimer et découper" }));

    expect(deleteScheduleMutateAsync).toHaveBeenCalledWith("sv1");
    expect(deleteScheduleMutateAsync).toHaveBeenCalledWith("sv2");
    expect(deleteMutate).not.toHaveBeenCalled(); // jamais l'entrée (DELETE calendar_entry)
  });
});

// ── P2-36 (tranche 2) : la LISTE DU JOUR passe elle aussi par la maison unique ──
// Deux gestes créaient jusqu'ici le plan de bloc SANS consulter le sélecteur, même sur une
// période de plusieurs semaines : « Adapter » une fermeture RACINE et « Ajuster » une fermeture
// qui a déjà un plan. Ils passent désormais par requestAdapt (useWeekAdapt), avec les mêmes états
// nommés que le radar. Témoins : une seule semaine va droit au bloc, et « Ajuster » une période
// déjà découpée mène au wizard sans dialogue.
describe("DayDialog — P2-36 tranche 2 : la liste du jour passe par le sélecteur de semaines", () => {
  beforeEach(() => {
    periodPlanMutateAsync.mockReset();
    periodPlanMutateAsync.mockResolvedValue({});
    startPeriodMode.mockClear();
    navigate.mockClear();
    weekChildrenMutate.mockReset();
    deleteScheduleMutateAsync.mockClear();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
    childEntriesData = [];
  });

  // Fermeture RACINE couvrant plusieurs semaines calendaires (10 → 20 mai, today = 12 mai).
  const multiWeekClosure = (over: Partial<CalendarEntry> = {}): CalendarEntry =>
    entry({ id: "clm", kind: "period", periodType: "closure", title: "Gymnase A — travaux", startDate: "2026-05-10", endDate: "2026-05-20", ...over });

  it("« Adapter » une fermeture RACINE multi-semaines OUVRE le choix des semaines au lieu de créer le plan de bloc", async () => {
    renderDialog([multiWeekClosure()]);

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));

    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    // Le plan de bloc N'EST PAS créé tant que le gestionnaire n'a pas tranché.
    expect(periodPlanMutateAsync).not.toHaveBeenCalled();
    expect(startPeriodMode).not.toHaveBeenCalled();
  });

  it("témoin : une fermeture d'UNE seule semaine va droit au bloc (création + wizard, sans picker)", async () => {
    renderDialog([entry({ id: "cl1", kind: "period", periodType: "closure", title: "Gym fermé", startDate: "2026-05-12", endDate: "2026-05-12" })]);

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));

    await waitFor(() => expect(periodPlanMutateAsync).toHaveBeenCalledWith("cl1"));
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("cl1"));
    expect(screen.queryByText("Quelles semaines ajuster ?")).not.toBeInTheDocument();
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  it("« Ajuster » une fermeture déjà générée d'un bloc (multi-semaines) ouvre le picker qui NOMME le bloc", async () => {
    allPlansMock = [{ id: "pg", calendarEntryId: "clg", chosenScheduleId: null }];
    schedulesData = [{ id: "v1", schedulePlanId: "pg", status: "COMPLETED" }];
    renderDialog([multiWeekClosure({ id: "clg" })]);

    await userEvent.click(screen.getByRole("button", { name: "Ajuster" }));

    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    expect(screen.getByText(/déjà été adaptée d'un bloc — 1 version/)).toBeInTheDocument();
    // Rien n'a été navigué en silence — le picker attend la décision.
    expect(startPeriodMode).not.toHaveBeenCalled();
  });

  it("témoin : « Ajuster » une période DÉJÀ DÉCOUPÉE mène au wizard sans dialogue (rien à choisir)", async () => {
    allPlansMock = [{ id: "ps", calendarEntryId: "cls", chosenScheduleId: null }];
    renderDialog([
      multiWeekClosure({ id: "cls" }),
      entry({ id: "cls-w1", kind: "period", periodType: "closure", title: "Gymnase A — travaux — semaine du 11 mai", parentEntryId: "cls", startDate: "2026-05-11", endDate: "2026-05-17" }),
    ]);

    await userEvent.click(screen.getByRole("button", { name: "Ajuster" }));

    expect(screen.queryByText("Quelles semaines ajuster ?")).not.toBeInTheDocument();
    await waitFor(() => expect(startPeriodMode).toHaveBeenCalledWith("cls"));
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });
});

// P2-40 — « Adapter »/« Ajuster » une fermeture qui chevauche des vacances passe par le picker,
// qui EXCLUT (pas grise) les semaines gouvernées par les vacances et RETIRE le chemin « d'un bloc ».
// L'entrée existe déjà en base ici → jamais de bouton « Consigner » (rien à consigner).
describe("DayDialog — fermeture chevauchant des vacances (P2-40)", () => {
  beforeEach(() => {
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
    childEntriesData = [];
    schoolHolidaysMock = { zone: "A", items: [] };
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
  });

  const spanning = (over: Partial<CalendarEntry> = {}) =>
    entry({ id: "cl1", kind: "period", periodType: "closure", title: "Gym fermé", startDate: "2026-05-11", endDate: "2026-05-31", ...over });

  it("« Adapter » ouvre le picker qui EXCLUT les semaines de vacances et retire le chemin d'un bloc", async () => {
    schoolHolidaysMock = { zone: "A", items: [{ id: "h1", label: "Petites vacances", holidayType: "custom", startDate: "2026-05-11", endDate: "2026-05-17", schoolYear: "2025-2026" }] };
    renderDialog([spanning()]);

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));

    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    expect(screen.getByText(/couvertes par Petites vacances/)).toBeInTheDocument();
    // P2-41 — les deux semaines pleines hors vacances forment UN segment (une coche). ALIGNEMENT
    // fondateur (P2-38) : le chemin « d'un bloc » n'est plus CACHÉ mais DÉSACTIVÉ avec sa raison
    // (bascule voulue) ; « Continuer d'un bloc » (libellé de l'état block) reste absent.
    expect(screen.getAllByRole("checkbox")).toHaveLength(1);
    expect(screen.getByRole("button", { name: /adapter toute la période d'un bloc/i })).toBeDisabled();
    expect(screen.queryByRole("button", { name: /continuer d'un bloc/i })).not.toBeInTheDocument();
    // Entrée déjà en base : rien à consigner.
    expect(screen.queryByRole("button", { name: /consigner l'indisponibilité/i })).not.toBeInTheDocument();
  });

  // Règle début·milieu·fin (fondateur 2026-09-05) : une fermeture ALIGNÉE lun→dim (un seul segment
  // « milieu ») part DIRECTEMENT d'un bloc — pas de picker. La fermeture 11→31 mai est Mon→Sun.
  it("témoin : une fermeture alignée lun→dim (un seul segment) part directement d'un bloc, sans picker", async () => {
    renderDialog([spanning()]);

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));

    await waitFor(() => expect(periodPlanMutateAsync).toHaveBeenCalledWith("cl1"));
    expect(screen.queryByText("Quelles semaines ajuster ?")).not.toBeInTheDocument();
  });

  // Une fermeture À SEMAINE ENTAMÉE (mardi 12 → dimanche 31 : début partiel + milieu) OUVRE le
  // picker, et le chemin « d'un bloc » y est DÉSACTIVÉ (le serveur refuserait un bloc à semaine
  // entamée — WeekSegmentationRule). Découpage début·milieu·fin imposé.
  it("témoin : une fermeture à semaine entamée ouvre le picker, chemin d'un bloc désactivé", async () => {
    renderDialog([spanning({ startDate: "2026-05-12" })]);

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));

    expect(screen.getByText("Quelles semaines ajuster ?")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Adapter toute la période d'un bloc/i })).toBeDisabled();
    expect(screen.queryByText(/couvertes par/)).not.toBeInTheDocument();
  });
});

// ── D3 v1 PR-2 : le geste « Modifier les dates » d'une fermeture (re-datage) ──
// L'unité UI d'un choix DÉJÀ permis par le backend (`CalendarEntry.redatable`) : le front n'a
// rien à recalculer (règle d'or) — il rend le bouton si et seulement si le serveur dit re-datable,
// laisse re-saisir la fenêtre, et le 409/422 reste le juge.
describe("DayDialog — re-datage d'une fermeture (« Modifier les dates », D3 v1 PR-2)", () => {
  const redateLabel = /Modifier les dates de Gymnase Matéo indisponible/;
  const incident = (over: Partial<CalendarEntry> = {}): CalendarEntry =>
    entry({ id: "inc", kind: "period", periodType: "closure", title: "Gymnase Matéo indisponible", startDate: "2026-05-12", endDate: "2026-06-16", redatable: true, ...over });

  beforeEach(async () => {
    redateMutateAsync.mockReset();
    redateMutateAsync.mockResolvedValue({});
    navigate.mockClear();
    startPeriodMode.mockClear();
    setSelectedScheduleId.mockClear();
    const { toast } = await import("@/shared/stores/toastStore");
    vi.mocked(toast.success).mockClear();
    vi.mocked(toast.error).mockClear();
    meData = { seasonPlan: { chosenScheduleId: "s-season" } };
    allPlansMock = [];
    plansByEntry = {};
    schedulesData = [];
    queriesNoData = false;
    childEntriesData = [];
  });

  function renderWith(entries: CalendarEntry[], onClose = vi.fn()) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <DayDialog iso="2026-05-12" entries={entries} onClose={onClose} />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    return onClose;
  }

  // Aucun levier n'existe pour une entrée que le serveur ne dit pas re-datable : le bouton est
  // ABSENT (jamais désactivé — Supprimer est à côté). `redatable` vient du serveur, prédicat unique.
  it("ne rend PAS le bouton « Modifier les dates » quand le serveur dit redatable=false", () => {
    renderWith([incident({ redatable: false })]);
    expect(screen.queryByRole("button", { name: redateLabel })).not.toBeInTheDocument();
  });

  it("rend le bouton « Modifier les dates » quand redatable=true", () => {
    renderWith([incident()]);
    expect(screen.getByRole("button", { name: redateLabel })).toBeInTheDocument();
  });

  it("ouvre un mode re-datage avec les dates SERVIES pré-remplies", async () => {
    renderWith([incident()]);

    await userEvent.click(screen.getByRole("button", { name: redateLabel }));

    expect((screen.getByLabelText("Du") as HTMLInputElement).value).toBe("2026-05-12");
    expect((screen.getByLabelText("Jusqu'au") as HTMLInputElement).value).toBe("2026-06-16");
  });

  it("désactive « Enregistrer » tant qu'aucune date n'a changé, avec une raison en title", async () => {
    renderWith([incident()]);

    await userEvent.click(screen.getByRole("button", { name: redateLabel }));
    const save = screen.getByRole("button", { name: "Enregistrer" });
    expect(save).toBeDisabled();
    expect(save).toHaveAttribute("title");
    expect(redateMutateAsync).not.toHaveBeenCalled();
  });

  it("désactive « Enregistrer » quand la fin précède le début", async () => {
    renderWith([incident()]);

    await userEvent.click(screen.getByRole("button", { name: redateLabel }));
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-05-01"); // avant le début servi (12 mai)
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();
  });

  it("succès : appelle le re-datage avec la fenêtre saisie, ferme le dialogue et annonce la phrase unique", async () => {
    const { toast } = await import("@/shared/stores/toastStore");
    const onClose = renderWith([incident()]);

    await userEvent.click(screen.getByRole("button", { name: redateLabel }));
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-06-20");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() =>
      expect(redateMutateAsync).toHaveBeenCalledWith(expect.objectContaining({ entry: expect.objectContaining({ id: "inc" }), startDate: "2026-05-12", endDate: "2026-06-20" })),
    );
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith("Fermeture re-datée du 12 mai 2026 au 20 juin 2026 — planning à régénérer"));
    expect(onClose).toHaveBeenCalled();
  });

  it("409 : rend la proposition serveur DANS le mode re-datage, garde le dialogue et les valeurs, déplace le focus sur « Ouvrir »", async () => {
    const { WindowAlreadyPlannedError } = await import("./api");
    const { toast } = await import("@/shared/stores/toastStore");
    const conflictMessage = "Ces dates sont déjà planifiées par « Vacances de Toussaint ». Modifiez ce planning existant ou supprimez-le.";
    redateMutateAsync.mockRejectedValueOnce(new WindowAlreadyPlannedError(conflictMessage, "toussaint-entry"));
    renderWith([incident()]);

    await userEvent.click(screen.getByRole("button", { name: redateLabel }));
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-06-20");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    // La proposition serveur s'affiche à l'endroit du geste ; aucun toast générique par-dessus.
    expect(await screen.findByText(/déjà planifiées par « Vacances de Toussaint »/)).toBeInTheDocument();
    expect(toast.error).not.toHaveBeenCalled();
    // Le dialogue reste OUVERT, les valeurs conservées (on n'a pas fermé ni perdu la saisie).
    expect((screen.getByLabelText("Jusqu'au") as HTMLInputElement).value).toBe("2026-06-20");
    // Le focus part sur le bouton d'action de la notice.
    const open = await screen.findByRole("button", { name: /ouvrir le planning en place/i });
    await waitFor(() => expect(open).toHaveFocus());
  });

  it("« Ouvrir le planning en place » mène à la période reçue dans entryId (409)", async () => {
    const { WindowAlreadyPlannedError } = await import("./api");
    redateMutateAsync.mockRejectedValueOnce(new WindowAlreadyPlannedError("déjà planifié", "toussaint-entry"));
    renderWith([incident()]);

    await userEvent.click(screen.getByRole("button", { name: redateLabel }));
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-06-20");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));
    await userEvent.click(await screen.findByRole("button", { name: /ouvrir le planning en place/i }));

    expect(startPeriodMode).toHaveBeenCalledWith("toussaint-entry");
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  it("le refus est RÉINITIALISÉ à la soumission suivante (une nouvelle tentative ne colle pas l'ancien refus)", async () => {
    const { WindowAlreadyPlannedError } = await import("./api");
    redateMutateAsync.mockRejectedValueOnce(new WindowAlreadyPlannedError("déjà planifié", "toussaint-entry"));
    redateMutateAsync.mockResolvedValueOnce({});
    renderWith([incident()]);

    await userEvent.click(screen.getByRole("button", { name: redateLabel }));
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-06-20");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(await screen.findByRole("button", { name: /ouvrir le planning en place/i })).toBeInTheDocument();

    // Deuxième tentative (résout) : la notice de refus disparaît.
    await userEvent.clear(screen.getByLabelText("Jusqu'au"));
    await userEvent.type(screen.getByLabelText("Jusqu'au"), "2026-06-21");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));
    await waitFor(() => expect(screen.queryByRole("button", { name: /ouvrir le planning en place/i })).not.toBeInTheDocument());
  });
});
