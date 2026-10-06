import { screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";
import { setTodayOverride } from "@/shared/lib/clock";

/**
 * AUD-UXS-09 — l'accueil (route `/`) est multi-requêtes : une lecture en échec SANS cache ne doit
 * pas rendre un VIDE crédible (calendrier nu, bandeau plan calculé sur zéro), mais dégrader PAR
 * ZONE (décision fondateur). On exerce la VRAIE page + le VRAI `readState` ; seul le résultat des
 * hooks de lecture est muté (échec = `{ data: undefined, isError: true }`). Les enfants sont des
 * doublures pour isoler la logique de zone de CockpitPage (le radar a son propre test).
 */
let schedulesError = false;
let entriesError = false;
let schoolHolidaysError = false;

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { id: "p1", name: "Planning A", chosenScheduleId: "s1", hasFinishedVersion: true } }, isLoading: false, isError: false }),
}));

vi.mock("@/features/planning/queries", () => ({
  useSchedules: () =>
    schedulesError ? { data: undefined, isError: true, refetch: vi.fn() } : { data: [], isError: false, isLoading: false, refetch: vi.fn() },
}));

vi.mock("./queries", () => ({
  useCalendarEntries: () =>
    entriesError ? { data: undefined, isError: true, refetch: vi.fn() } : { data: [], isError: false, refetch: vi.fn() },
  useSchoolHolidays: () =>
    schoolHolidaysError ? { data: undefined, isError: true, refetch: vi.fn() } : { data: { zone: "A", items: [] }, isError: false, isLoading: false, refetch: vi.fn() },
  usePublicHolidays: () => ({ data: { zone: "A", items: [] }, isError: false, isLoading: false, refetch: vi.fn() }),
}));

// Enfants = doublures : on teste la DÉGRADATION PAR ZONE de CockpitPage, pas le rendu des enfants.
vi.mock("./SeasonPlanBanner", () => ({ SeasonPlanBanner: () => <div data-testid="season-banner">SEASON BANNER</div> }));
vi.mock("./FbiDeadlineCard", () => ({ FbiDeadlineCard: () => null }));
// UXS-09 (décision A) : la grille reste TOUJOURS montée (en-tête + flèches), l'échec/chargement de la
// lecture des entrées lui est passé en prop — la doublure expose ces props pour la zone CockpitPage.
vi.mock("./MonthCalendar", () => ({
  MonthCalendar: (p: { failed?: boolean; loading?: boolean }) => (
    <div data-testid="month-calendar" data-failed={String(Boolean(p.failed))} data-loading={String(Boolean(p.loading))}>
      MONTH
    </div>
  ),
}));
vi.mock("./RadarPanel", () => ({
  PUBLIC_HOLIDAY_HORIZON_DAYS: 30,
  RadarPanel: (p: { entriesFailed?: boolean; publicHolidaysFailed?: boolean }) => (
    <div data-testid="radar" data-entries-failed={String(Boolean(p.entriesFailed))} data-pubhol-failed={String(Boolean(p.publicHolidaysFailed))}>
      RADAR
    </div>
  ),
}));
vi.mock("./VenueUnavailabilityCard", () => ({ VenueUnavailabilityCard: () => null }));

import { CockpitPage } from "./CockpitPage";

describe("CockpitPage — échec de lecture dégradé par zone (UXS-09)", () => {
  beforeEach(() => {
    schedulesError = false;
    entriesError = false;
    schoolHolidaysError = false;
    setTodayOverride("2026-10-15");
  });
  afterEach(() => setTodayOverride(null));

  it("entries en échec → la grille reste montée et reçoit l'échec (en-tête/flèches jamais démontés) + l'échec parvient au radar", () => {
    entriesError = true;
    renderWithProviders(<CockpitPage />);

    // Décision A : le calendrier n'est JAMAIS démonté — il reste là et reçoit l'échec en prop
    // (c'est lui qui rend « Réessayer » dans sa zone grille, en gardant en-tête + flèches).
    expect(screen.getByTestId("month-calendar").dataset.failed).toBe("true");
    // Décision 2 : l'échec des entrées doit PARVENIR au radar (jamais « Rien à l'horizon »).
    expect(screen.getByTestId("radar").dataset.entriesFailed).toBe("true");
  });

  it("schedules en échec → LoadErrorHint à la place du bandeau plan de saison", () => {
    schedulesError = true;
    renderWithProviders(<CockpitPage />);

    expect(screen.getByText("Le planning de saison n'a pas pu être chargé.")).toBeInTheDocument();
    expect(screen.queryByTestId("season-banner")).not.toBeInTheDocument();
    // Le reste de l'écran vit : la grille du mois reste là.
    expect(screen.getByTestId("month-calendar")).toBeInTheDocument();
  });

  it("vacances/fériés du mois en échec → bandeau discret, le calendrier reste affiché", () => {
    schoolHolidaysError = true;
    renderWithProviders(<CockpitPage />);

    expect(screen.getByTestId("month-calendar")).toBeInTheDocument();
    expect(screen.getByText(/vacances scolaires.*fériés du mois.*réessayez/i)).toBeInTheDocument();
  });

  it("tout va bien → ni « Réessayer », ni bandeau d'échec", () => {
    renderWithProviders(<CockpitPage />);

    expect(screen.getByTestId("month-calendar")).toBeInTheDocument();
    expect(screen.getByTestId("season-banner")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Réessayer" })).not.toBeInTheDocument();
    expect(screen.getByTestId("radar").dataset.entriesFailed).toBe("false");
  });
});
