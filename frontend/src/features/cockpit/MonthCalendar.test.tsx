import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { MonthCalendar } from "./MonthCalendar";

vi.mock("./queries", () => ({
  useCreateEvent: () => ({ mutate: vi.fn(), isPending: false }),
  useCreatePeriodPlan: () => ({ mutateAsync: vi.fn().mockResolvedValue({}), isPending: false }),
  useCreateVenueClosure: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateCutoff: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteEntry: () => ({ mutate: vi.fn(), isPending: false }),
  // P2-36 tranche 2 — DayList passe par useWeekAdapt (sélecteur de semaines), qui lit ces hooks.
  useCreateHolidayPeriod: () => ({ mutate: vi.fn(), mutateAsync: vi.fn().mockResolvedValue({}), isPending: false }),
  useCreateWeekChildren: () => ({ mutate: vi.fn(), isPending: false }),
  // DayDialog (ouvert au clic) dérive « overlay validé » du plan de la période (lot D-b).
  useSchedulePlanForEntry: () => ({ data: null, isSuccess: true }),
  // DayList liste les plannings couvrants (AJUSTER/Consulter) via les plans (B1).
  useSchedulePlans: () => ({ data: [] }),
  useCalendarEntries: () => ({ data: [] }),
  // P2-40 — useWeekAdapt (dans DayList) source les vacances pour l'offre des fermetures.
  useSchoolHolidays: () => ({ data: { zone: "A", items: [] } }),
  // P2-38 — useWeekAdapt (dans DayList) lit les fenêtres déjà planifiées ; ici jamais de picker
  // ouvert, mais le hook doit exister dans le mock.
  usePlannedWindows: () => ({ data: [], isError: false }),
}));
vi.mock("@/features/planning/queries", () => ({
  useVenues: () => ({ data: [] }),
  // DayDialog (ouvert au clic) lit les versions du plan pour la garde de suppression (lot D-b).
  useSchedules: () => ({ data: [], isSuccess: true }),
  // P2-36 tranche 2 — useWeekAdapt (dans DayList) lit useDeleteSchedule pour la découpe destructive.
  useDeleteSchedule: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));
// Pin "today" to mid-May 2026 so the past/future split is deterministic
// (real-clock today would make the whole rendered month past). Only todayISO
// is overridden — the date maths (grid, isWithin…) keep their real behaviour.
vi.mock("./lib/date", async (importActual) => ({
  ...(await importActual<typeof import("./lib/date")>()),
  todayISO: () => "2026-05-10",
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
  ...overrides,
});

const holidays: SchoolHoliday[] = [
  { id: "h1", label: "Pont de mai", holidayType: "printemps", startDate: "2026-05-14", endDate: "2026-05-17", schoolYear: "2025-2026" },
];

function renderMay(entries: CalendarEntry[] = [], hols: SchoolHoliday[] = [], publicHols: PublicHoliday[] = []) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        {/* May 2026 — month is 0-indexed */}
        <MonthCalendar year={2026} month={4} entries={entries} holidays={hols} publicHolidays={publicHols} onPrev={vi.fn()} onNext={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("MonthCalendar — projection of the exception layer", () => {
  it("renders the month header and every day of the month with a readable name", () => {
    renderMay();
    expect(screen.getByText(/mai 2026/i)).toBeInTheDocument();
    // Full 6-week grid = 42 day cells incl. the leading/trailing padding days that
    // keep dates aligned under their weekday column (May 1st 2026 is a Friday).
    // After A11Y-07 every cell reads "{jour} {mois}", padding days included.
    expect(screen.getAllByRole("button", { name: /^\d+ [A-Za-zÀ-ÿ]/ })).toHaveLength(42);
    // …of which the 31 in-month days read "{jour} Mai".
    expect(screen.getAllByRole("button", { name: /^\d+ Mai/ })).toHaveLength(31);
  });

  it("marks periods ⛔, disruptive events 🚫, plain events 🎉 and holidays 🏖 on their days", () => {
    renderMay(
      [
        entry({ id: "p1", kind: "period", periodType: "closure", title: "Gym fermé", startDate: "2026-05-04", endDate: "2026-05-05" }),
        entry({ id: "d1", isDisruptive: true, title: "Tournoi", startDate: "2026-05-20", endDate: "2026-05-20" }),
        entry({ id: "a1", title: "AG", startDate: "2026-05-26", endDate: "2026-05-26" }),
      ],
      holidays,
    );

    // A multi-day period marks every day of its window.
    expect(screen.getAllByTitle("Gym fermé")).toHaveLength(2);
    expect(screen.getAllByTitle("Gym fermé")[0]).toHaveTextContent("⛔");
    expect(screen.getByTitle("Tournoi")).toHaveTextContent("🚫");
    expect(screen.getByTitle("AG")).toHaveTextContent("🎉");
    // Holiday window 14→17 May = 4 beach markers, tooltip names the break.
    expect(screen.getAllByTitle("Vacances — Pont de mai")).toHaveLength(4);
    // The break name is also written in the cell (small label), one per covered day.
    expect(screen.getAllByText("Pont de mai")).toHaveLength(4);
  });

  it("hides holiday WEEK-CHILD markers (the amber highlight suffices) but keeps closure children ⛔", () => {
    // Fondateur 2026-07-24 : une semaine de vacances est un rail technique, pas
    // un événement — aucun ⛔ ; une semaine de FERMETURE garde le sien (seule
    // trace visible de la fermeture, pas de surlignage amber pour elle).
    renderMay(
      [
        entry({ id: "hw1", kind: "period", periodType: "holiday", parentEntryId: "mother-h", title: "Pont de mai — semaine du 11 mai", startDate: "2026-05-14", endDate: "2026-05-17" }),
        entry({ id: "cw1", kind: "period", periodType: "closure", parentEntryId: "mother-c", title: "Travaux — semaine du 4 mai", startDate: "2026-05-04", endDate: "2026-05-05" }),
      ],
      holidays,
    );

    // La semaine de vacances ne peint RIEN (ni marqueur, ni libellé a11y)…
    expect(screen.queryByTitle("Pont de mai — semaine du 11 mai")).toBeNull();
    expect(screen.queryByRole("button", { name: /semaine du 11 mai/ })).toBeNull();
    // …le surlignage vacances, lui, reste (la citrouille/plage marque la période).
    expect(screen.getAllByTitle("Vacances — Pont de mai")).toHaveLength(4);
    // La semaine de fermeture garde son ⛔ sur chaque jour couvert.
    expect(screen.getAllByTitle("Travaux — semaine du 4 mai")).toHaveLength(2);
    expect(screen.getAllByTitle("Travaux — semaine du 4 mai")[0]).toHaveTextContent("⛔");
  });

  it("marks a cutoff 🛑 (distinct from other periods) on every day of its window", () => {
    renderMay([entry({ id: "cut1", kind: "period", periodType: "cutoff", title: "Coupure de Noël", startDate: "2026-05-11", endDate: "2026-05-12" })]);

    const markers = screen.getAllByTitle("Coupure de Noël");
    expect(markers).toHaveLength(2);
    expect(markers[0]).toHaveTextContent("🛑");
  });

  it("uses a season-appropriate emoji per holiday type (not a beach for spring)", () => {
    renderMay([], [{ id: "hp", label: "Vacances de Printemps", holidayType: "printemps", startDate: "2026-05-14", endDate: "2026-05-17", schoolYear: "2025-2026" }]);
    // printemps → 🐰 (lapin de Pâques), pas 🏖 (plage réservée à l'été).
    expect(screen.getAllByTitle(/Vacances — Vacances de Printemps/)[0]).toHaveTextContent("🐰");
  });

  it("names the public holiday on its exact day, not colour alone (A11Y-08)", () => {
    renderMay([], [], [{ id: "ph1", date: "2026-05-01", label: "Fête du Travail", national: true }]);

    // A11Y-08: the férié is announced in the cell's accessible name (and shown as a
    // shape+letter marker, not a bare colour dot) — perceivable beyond the tooltip.
    expect(screen.getByRole("button", { name: /jour férié — Fête du Travail/ })).toBeInTheDocument();
  });

  it("carries every marker in the cell's accessible name, not colour/tooltip only (A11Y-05/07, WCAG 1.1.1)", async () => {
    const { container } = renderMay(
      [entry({ id: "a1", title: "AG du club", startDate: "2026-05-26", endDate: "2026-05-26" })],
      holidays,
    );

    // The 🏖 holiday and 🎉 event info reads from the composed button name (the
    // markers themselves are aria-hidden), so a screen reader never depends on the
    // hover title or colour — regression for the audit's title-only inconsistency.
    expect(screen.getAllByRole("button", { name: /vacances — Pont de mai/ })).toHaveLength(4);
    expect(screen.getByRole("button", { name: /AG du club/ })).toBeInTheDocument();
    expect(await axe(container)).toHaveNoViolations();
  });

  it("gives an empty-title entry a meaningful accessible name, never an empty one (review C3)", async () => {
    const { container } = renderMay([
      entry({ id: "cut", kind: "period", periodType: "cutoff", title: "", startDate: "2026-05-12", endDate: "2026-05-12" }),
    ]);

    // A blank title must not vanish from the cell name — it falls back to the
    // marker's meaning ("Coupure"), carried by the composed accessible name.
    expect(screen.getByRole("button", { name: /12 Mai, Coupure/ })).toBeInTheDocument();
    expect(await axe(container)).toHaveNoViolations();
  });

  it("makes past days non-interactive (on ne modifie pas le passé)", async () => {
    renderMay([entry({ id: "past", title: "Passé", startDate: "2026-05-05", endDate: "2026-05-05" })]);

    // A day before today (10 May) is disabled and labelled as such.
    const pastDay = screen.getByRole("button", { name: /^5 Mai/ });
    expect(pastDay).toBeDisabled();
    expect(pastDay).toHaveAccessibleName(/passé/);

    // Clicking it opens no dialog; today and future days stay interactive.
    await userEvent.click(pastDay);
    expect(screen.queryByText(/5 mai 2026/)).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^10 Mai/ })).toBeEnabled();
    expect(screen.getByRole("button", { name: /^26 Mai/ })).toBeEnabled();
  });

  it("opens the day dialog on click with that day's entries", async () => {
    renderMay([entry({ id: "a1", title: "AG", startDate: "2026-05-26", endDate: "2026-05-26" })]);

    await userEvent.click(screen.getByRole("button", { name: /26 Mai, AG/ }));

    // The DayDialog opened on that date and lists the day's entry.
    expect(screen.getByText(/26 mai 2026/)).toBeInTheDocument();
    expect(screen.getByText("AG")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Supprimer AG" })).toBeInTheDocument();
  });
});
