import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride } from "@/shared/lib/clock";

import { CockpitPage } from "./CockpitPage";
import { addDays, monthWindow, todayISO } from "./lib/date";
import { PUBLIC_HOLIDAY_HORIZON_DAYS } from "./RadarPanel";

let meData: { seasonPlan: { id: string; name: string; chosenScheduleId: string | null; hasFinishedVersion: boolean } } | null = null;

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: meData, isLoading: false }),
  useWorkingSeason: () => ({ id: "sn1", name: "2026-2027", startDate: "2026-08-01", endDate: "2027-07-31", isCurrent: true, isReadonly: false }),
}));

vi.mock("@/features/planning/queries", () => ({
  useSlots: () => ({ data: [], isLoading: false }),
  useSchedules: () => ({ data: [{ id: "s1", name: "Planning A", status: "COMPLETED", score: 9011, createdAt: "", updatedAt: "", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true }] }),
  useReopenSchedule: () => ({ mutate: vi.fn(), isPending: false }),
  // P2-36 — useWeekAdapt (dans RadarPanel) lit useDeleteSchedule pour la découpe destructive.
  useDeleteSchedule: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));

const publicHolidayWindows: [string, string][] = [];

vi.mock("./queries", () => ({
  useCalendarEntries: () => ({ data: [] }),
  useSchoolHolidays: () => ({ data: { zone: "A", items: [] } }),
  usePublicHolidays: (from: string, to: string) => {
    publicHolidayWindows.push([from, to]);
    return { data: { zone: "A", items: [] }, isLoading: false };
  },
  useCreateHolidayPeriod: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateWeekChildren: () => ({ mutate: vi.fn(), isPending: false }),
  useCreatePeriodPlan: () => ({ mutateAsync: vi.fn().mockResolvedValue({}), isPending: false }),
  useEntryConflicts: () => ({ data: undefined }),
  useEntryConflictsList: (ids: string[]) => ids.map(() => ({ data: undefined })),
  // RadarPanel dérive « version active » du plan de la période (lot D-b).
  useSchedulePlans: () => ({ data: [], isSuccess: true }),
  useCreateVenueClosure: () => ({ mutate: vi.fn(), isPending: false }),
  // P2-38 — useWeekAdapt (dans RadarPanel) lit les fenêtres déjà planifiées ; ici jamais de picker
  // ouvert, mais le hook doit exister dans le mock.
  usePlannedWindows: () => ({ data: [], isError: false }),
}));
// P4-68 — le radar lit les indispos gymnase (et la carte du cockpit les liste) :
// rien à signaler dans ces cas d'état, la machine à états n'en dépend pas.
vi.mock("@/features/matches/queries", () => ({
  useVenueUnavailabilities: () => ({ data: [] }),
  useVenues: () => ({ data: [] }),
  useUnavailabilityImpact: () => ({ data: { clubId: "c", seasonId: null, items: [] } }),
  useCreateVenueUnavailability: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteVenueUnavailability: () => ({ mutate: vi.fn(), isPending: false }),
}));

function renderCockpit() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={["/"]}>
        <Routes>
          <Route path="/" element={<CockpitPage />} />
          <Route path="/planning" element={<div>PLANNING SCREEN</div>} />
          <Route path="/wizard" element={<div>WIZARD SCREEN</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("CockpitPage state machine", () => {
  beforeEach(() => {
    meData = null;
    publicHolidayWindows.length = 0;
  });
  afterEach(() => setTodayOverride(null));

  // Revue #344 round 2 — l'horloge de dev décalait tous les filtres mais pas le mois
  // d'ouverture du calendrier : `?today=2026-12-20` ouvrait sur le mois RÉEL, où chaque
  // case est « passé (non modifiable) ». Le scénario que l'horloge existe pour rejouer
  // devenait injouable sans naviguer quatre mois à la main.
  it("ouvre le calendrier sur le mois de l'horloge, override de dev compris", () => {
    setTodayOverride("2026-12-20");
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "s1", hasFinishedVersion: true } };
    renderCockpit();

    expect(screen.getByText(/Décembre 2026/i)).toBeInTheDocument();
  });

  it("state 1 — no main plan (baseline null) → redirects to the wizard", () => {
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: null, hasFinishedVersion: false } };
    renderCockpit();
    expect(screen.getByText("WIZARD SCREEN")).toBeInTheDocument();
  });

  it("state 2 — baseline exists but not validated → cockpit unlocked with a lock hint", () => {
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: null, hasFinishedVersion: true } };
    renderCockpit();
    expect(screen.getByText("Planning")).toBeInTheDocument(); // le bandeau affiche le NOM du plan (me.seasonPlan.name)
    expect(screen.getByText(/validez-le pour débloquer/i)).toBeInTheDocument();
    expect(screen.queryByText("WIZARD SCREEN")).not.toBeInTheDocument();
  });

  it("state 3 — validated → full cockpit, no lock hint", () => {
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "s1", hasFinishedVersion: true } };
    renderCockpit();
    expect(screen.getByText("Planning")).toBeInTheDocument(); // le bandeau affiche le NOM du plan (me.seasonPlan.name)
    expect(screen.getByText("À traiter")).toBeInTheDocument();
    expect(screen.queryByText(/validez-le pour débloquer/i)).not.toBeInTheDocument();
  });

  it("fetches public holidays on two explicit windows: visible month grid + radar horizon", () => {
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "s1", hasFinishedVersion: true } };
    renderCockpit();

    const now = new Date();
    const grid = monthWindow(now.getFullYear(), now.getMonth());
    const today = todayISO();
    expect(publicHolidayWindows).toEqual([
      [grid.from, grid.to],
      [today, addDays(today, PUBLIC_HOLIDAY_HORIZON_DAYS)],
    ]);
  });
});
