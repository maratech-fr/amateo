import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";
import { useToastStore } from "@/shared/stores/toastStore";
import { renderWithProviders } from "@/test/utils";

import { COCKPIT_TEXTS } from "./texts";
import { VenueClosureCard } from "./VenueClosureCard";

/**
 * Promesse (chantier « Fermetures de gymnase », PR 1, C1 + C8 + corrections B et E4) : un gymnase
 * qui ferme est UN SEUL fait. La carte du cockpit liste les fermetures (`closure`) à venir avec leur
 * gymnase (servi par `/api/venue_closures` + `calendarEntryId`), ouvre la même saisie que le jour du
 * calendrier, et sa suppression dit HONNÊTEMENT ce qu'elle emporte : le planning de période (validé /
 * en cours / ses semaines), et — seulement si la déclaration a réellement retiré des épingles — que
 * rien n'est remis ; un plan régénéré sans le gymnase est nommé, dans la confirmation comme au toast.
 *
 * L'ancienne carte `VenueUnavailabilityCard` (indisponibilité séparée) disparaît avec l'entité.
 */

const FUTURE = "2999-03-09";
const FUTURE_END = "2999-03-13";

const entry = (over: Record<string, unknown> = {}) => ({
  id: "e1",
  kind: "period",
  periodType: "closure",
  title: "Mistral indisponible",
  startDate: FUTURE,
  endDate: FUTURE_END,
  isDisruptive: false,
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  redatable: true,
  redateNeedsPreview: false,
  ...over,
});

let entriesData: ReturnType<typeof entry>[] = [];
let venueClosuresData: { id: string; venueId: string; title: string; startDate: string; endDate: string; calendarEntryId: string }[] = [];
let plansData: { id: string; type: string; name: string; startDate: string; calendarEntryId: string | null; chosenScheduleId: string | null; teamSelectionInitialized: boolean }[] = [];
let schedulesData: { id: string; schedulePlanId: string; status: string }[] = [];
let entryImpactData: Record<string, unknown> | undefined;
const deleteMutate = vi.fn();

vi.mock("./queries", () => ({
  useCalendarEntries: () => ({ data: entriesData, isLoading: false, isError: false }),
  useSchedulePlans: () => ({ data: plansData }),
  useDeleteEntry: () => ({ mutate: deleteMutate, isPending: false }),
  useEntryImpact: (id: string | null) => ({ data: null === id ? undefined : entryImpactData }),
  useEntryImpactList: (ids: string[]) => ids.map(() => ({ data: entryImpactData, isPending: false })),
  useClosureImpactPreview: () => ({ data: undefined, isPending: true, isFetching: true, isError: false, isSuccess: false }),
  useCreateVenueClosure: () => ({ mutate: vi.fn(), isPending: false }),
}));
vi.mock("@/features/matches/queries", () => ({
  useVenueClosures: () => ({ data: venueClosuresData }),
  useVenues: () => ({ data: [{ id: "v-mistral", name: "Mistral", color: null }] }),
}));
vi.mock("@/features/planning/queries", () => ({
  useVenues: () => ({ data: [{ id: "v-mistral", name: "Mistral", color: null, canSplit: false, isActive: true }] }),
  useSchedules: () => ({ data: schedulesData }),
}));
vi.mock("@/shared/session/queries", () => ({
  useWorkingSeason: () => ({ id: "sn1", name: "2998-2999", startDate: "2998-07-15", endDate: "2999-07-14", isCurrent: true, isReadonly: false }),
  useMe: () => ({ data: { seasonPlan: { chosenScheduleId: "s-season" } } }),
}));

const impact = (over: Record<string, unknown> = {}) => ({
  venueIds: ["v-mistral"],
  startDate: FUTURE,
  endDate: FUTURE_END,
  seasonPlanChosen: true,
  teams: [],
  trainingOccurrences: 0,
  trainingSlotCount: 0,
  matchesToMove: [],
  governingPlans: [],
  fullyGoverned: false,
  closedSessions: [],
  // E4 — contrat SUPPOSÉ (à trancher) : l'audit porte-t-il des retraits (LOCK_CHANGED /
  // ENTITY_DELETED avec constraintId) liés à la contrainte de cette fermeture ?
  removedAtDeclaration: false,
  ...over,
});

const deleteButton = () => screen.getByRole("button", { name: /Supprimer.*Mistral indisponible/ });
const confirmDialog = () => screen.getByRole("dialog", { name: COCKPIT_TEXTS.deleteClosureTitle("Mistral indisponible") });

describe("VenueClosureCard — un gymnase qui ferme = un seul fait (C1, C8)", () => {
  beforeEach(() => {
    setTodayOverride("2999-02-01");
    entriesData = [entry()];
    venueClosuresData = [{ id: "k1", venueId: "v-mistral", title: "Mistral indisponible", startDate: FUTURE, endDate: FUTURE_END, calendarEntryId: "e1" }];
    plansData = [];
    schedulesData = [];
    entryImpactData = impact();
    deleteMutate.mockReset();
    useToastStore.setState({ toasts: [] });
  });
  afterEach(() => setTodayOverride(null));

  it("titre la carte et liste la fermeture à venir avec son gymnase", () => {
    renderWithProviders(<VenueClosureCard />);
    expect(screen.getByRole("heading", { name: COCKPIT_TEXTS.closureCardTitle })).toBeInTheDocument();
    expect(screen.getByText("Mistral indisponible")).toBeInTheDocument();
  });

  it("aucune fermeture à venir : le dit (jamais une carte muette)", () => {
    entriesData = [entry({ startDate: "2999-01-05", endDate: "2999-01-09" })]; // passée
    renderWithProviders(<VenueClosureCard />);
    expect(screen.getByText(COCKPIT_TEXTS.closureCardEmpty)).toBeInTheDocument();
    expect(screen.queryByText("Mistral indisponible")).not.toBeInTheDocument();
  });

  it("une fermeture SANS contrainte de gymnase est dite sans effet sur le planning", () => {
    venueClosuresData = [];
    renderWithProviders(<VenueClosureCard />);
    expect(screen.getByText(COCKPIT_TEXTS.closureNoVenue)).toBeInTheDocument();
  });

  it("« Déclarer » ouvre la saisie unique (dates vides depuis la carte)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<VenueClosureCard />);
    await user.click(screen.getByRole("button", { name: /Déclarer/ }));
    expect(screen.getByRole("dialog", { name: COCKPIT_TEXTS.closureDialogTitle })).toBeInTheDocument();
  });

  it("supprimer une fermeture SANS plan : confirmation « retirée du calendrier » (N2), aucune ligne de retrait", async () => {
    const user = userEvent.setup();
    renderWithProviders(<VenueClosureCard />);
    await user.click(deleteButton());

    const dialog = confirmDialog();
    expect(within(dialog).getByText(COCKPIT_TEXTS.deleteClosureBare)).toBeInTheDocument();
    expect(within(dialog).queryByText(COCKPIT_TEXTS.deleteClosureNothingRestored)).not.toBeInTheDocument();
    expect(deleteMutate).not.toHaveBeenCalled();
  });

  it("plan de période VALIDÉ (2 versions) : la confirmation dit qu'il part aussi", async () => {
    const user = userEvent.setup();
    plansData = [{ id: "pl-e1", type: "CLOSURE", name: "Plan", startDate: FUTURE, calendarEntryId: "e1", chosenScheduleId: "s1", teamSelectionInitialized: true }];
    schedulesData = [
      { id: "s1", schedulePlanId: "pl-e1", status: "COMPLETED" },
      { id: "s2", schedulePlanId: "pl-e1", status: "COMPLETED" },
    ];
    renderWithProviders(<VenueClosureCard />);
    await user.click(deleteButton());
    expect(within(confirmDialog()).getByText(COCKPIT_TEXTS.deleteClosureWithPlan(2))).toBeInTheDocument();
  });

  it("plan de période EN COURS (1 version, rien de pointé) : la confirmation le dit", async () => {
    const user = userEvent.setup();
    plansData = [{ id: "pl-e1", type: "CLOSURE", name: "Plan", startDate: FUTURE, calendarEntryId: "e1", chosenScheduleId: null, teamSelectionInitialized: true }];
    schedulesData = [{ id: "s1", schedulePlanId: "pl-e1", status: "COMPLETED" }];
    renderWithProviders(<VenueClosureCard />);
    await user.click(deleteButton());
    expect(within(confirmDialog()).getByText(COCKPIT_TEXTS.deleteClosureInProgress(1))).toBeInTheDocument();
  });

  it("fermeture DÉCOUPÉE en 3 semaines : la confirmation dit que les semaines et leurs plannings partent", async () => {
    const user = userEvent.setup();
    entriesData = [
      entry(),
      entry({ id: "c1", parentEntryId: "e1", title: "Mistral indisponible — semaine du 9/3" }),
      entry({ id: "c2", parentEntryId: "e1", title: "Mistral indisponible — semaine du 16/3" }),
      entry({ id: "c3", parentEntryId: "e1", title: "Mistral indisponible — semaine du 23/3" }),
    ];
    renderWithProviders(<VenueClosureCard />);
    await user.click(deleteButton());
    expect(within(confirmDialog()).getByText(COCKPIT_TEXTS.deleteClosureSplit(3))).toBeInTheDocument();
  });

  it("E4 — « rien n'est remis » SEULEMENT si la déclaration a réellement retiré des épingles", async () => {
    const user = userEvent.setup();
    entryImpactData = impact({ removedAtDeclaration: true });
    renderWithProviders(<VenueClosureCard />);
    await user.click(deleteButton());
    expect(within(confirmDialog()).getByText(COCKPIT_TEXTS.deleteClosureNothingRestored)).toBeInTheDocument();
  });

  it("E4 — plans gouvernants présents mais AUCUN retrait à la déclaration : pas de ligne « rien n'est remis »", async () => {
    const user = userEvent.setup();
    entryImpactData = impact({
      removedAtDeclaration: false,
      governingPlans: [{ entryId: "e-mars", title: "Vacances de mars", startDate: "2999-03-02", endDate: "2999-03-22", chosenScheduleId: "s-mars", loadedScheduleId: "s-mars", hasVersions: true, sessions: [], toRemove: { locks: 0, reservations: [] }, regeneratedWithout: false }],
    });
    renderWithProviders(<VenueClosureCard />);
    await user.click(deleteButton());
    expect(within(confirmDialog()).queryByText(COCKPIT_TEXTS.deleteClosureNothingRestored)).not.toBeInTheDocument();
  });

  it("plan régénéré SANS le gymnase : la confirmation le nomme, et le toast de suppression aussi (correction B)", async () => {
    const user = userEvent.setup();
    entryImpactData = impact({
      removedAtDeclaration: true,
      governingPlans: [{ entryId: "e-mars", title: "Vacances de mars", startDate: "2999-03-02", endDate: "2999-03-22", chosenScheduleId: "s-mars", loadedScheduleId: "s-mars", hasVersions: true, sessions: [], toRemove: { locks: 0, reservations: [] }, regeneratedWithout: true }],
    });
    deleteMutate.mockImplementation((_id: string, options?: { onSuccess?: () => void }) => options?.onSuccess?.());
    renderWithProviders(<VenueClosureCard />);
    await user.click(deleteButton());

    const dialog = confirmDialog();
    expect(within(dialog).getByText(COCKPIT_TEXTS.deleteClosureRegeneratedWithout("Vacances de mars", "Mistral"))).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));

    expect(deleteMutate).toHaveBeenCalledWith("e1", expect.anything());
    // Signature supposée : `closureDeletedToast(venue, planRegeneratedWithout?)`.
    expect(useToastStore.getState().toasts.map((t) => t.message)).toContain(COCKPIT_TEXTS.closureDeletedToast("Mistral", "Vacances de mars"));
  });

  it("suppression sans plan régénéré sans le gymnase : toast « les séances reprennent le planning en vigueur »", async () => {
    const user = userEvent.setup();
    deleteMutate.mockImplementation((_id: string, options?: { onSuccess?: () => void }) => options?.onSuccess?.());
    renderWithProviders(<VenueClosureCard />);
    await user.click(deleteButton());
    await user.click(within(confirmDialog()).getByRole("button", { name: "Supprimer" }));

    expect(useToastStore.getState().toasts.map((t) => t.message)).toContain(COCKPIT_TEXTS.closureDeletedToast("Mistral"));
  });
});
