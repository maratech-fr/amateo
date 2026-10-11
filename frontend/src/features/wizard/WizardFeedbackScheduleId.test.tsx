import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

/**
 * Lot B — la porte « Signaler » de l'en-tête du wizard joint le planning AFFICHÉ UNIQUEMENT à
 * l'étape Génération (remonté par `PlanningPage` via `store.displayedScheduleId`). Les autres
 * étapes n'affichent aucun planning : leur « Signaler » ne porte donc aucun `scheduleId`.
 *
 * On CAPTURE les props de `FeedbackButton` (mocké) et on STUBe `GenerateStep` (sa vraie version monte
 * tout `PlanningPage` — inutile pour éprouver le CÂBLAGE de l'en-tête). Le reste du harnais reproduit
 * `WizardPage.test.tsx`, qui monte `WizardPage` pour un club établi.
 */
const { fbCalls } = vi.hoisted(() => ({ fbCalls: [] as { screen?: string; scheduleId?: string | null }[] }));
vi.mock("@/shared/feedback/FeedbackButton", () => ({
  FeedbackButton: ({ screen: s, scheduleId }: { screen?: string; scheduleId?: string | null }) => {
    fbCalls.push({ screen: s, scheduleId });
    return <button type="button">Signaler</button>;
  },
}));
// La vraie étape Génération monte PlanningPage (lourd, hors sujet ici) : on la remplace par un stub.
vi.mock("./steps/GenerateStep", () => ({ GenerateStep: () => <div>Génération (stub)</div> }));

// ── Harnais (club établi), calqué sur WizardPage.test.tsx ──
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "b1", hasFinishedVersion: true }, club: { id: "c", name: "C", onboardingCompleted: true } } }),
  useWorkingSeason: () => null,
}));
vi.mock("@/features/cockpit/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/cockpit/queries")>()),
  useCalendarEntry: () => ({ data: undefined, error: null }),
  usePeriodAnchor: () => ({ state: "base", planId: null }),
  anchorIsWritable: (a: { state: string }) => "period" === a.state || "base" === a.state,
  useDeleteEntry: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(() => Promise.resolve({})), isPending: false }),
}));
vi.mock("@/features/planning/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/planning/queries")>()),
  useSchedules: () => ({ data: [] }),
}));
vi.mock("@/features/planning/api", async (orig) => ({
  ...(await orig<typeof import("@/features/planning/api")>()),
  listSchedules: vi.fn(() => Promise.resolve([])),
}));

import { useWizardStore } from "./store";
import { WizardPage } from "./WizardLayout";

vi.mock("./api", async (orig) => ({
  ...(await orig<typeof import("./api")>()),
  listTeams: vi.fn(() => Promise.resolve([{ id: "t1", name: "SF1", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }])),
  listSportCategories: vi.fn(() => Promise.resolve([{ id: "cat1", name: "U11", sortOrder: 0 }])),
  listPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Elite", color: null }])),
  listVenues: vi.fn(() => Promise.resolve([])),
  listVenueSlots: vi.fn(() => Promise.resolve([])),
  listCoaches: vi.fn(() => Promise.resolve([])),
  listTeamCoaches: vi.fn(() => Promise.resolve([])),
  listCoachPlayers: vi.fn(() => Promise.resolve([])),
  listConstraints: vi.fn(() => Promise.resolve([])),
  listImplicitRuleSettings: vi.fn(() => Promise.resolve([])),
  validateConstraints: vi.fn(() => Promise.resolve({ valid: true, errors: {}, conflicts: [] })),
}));

beforeEach(() => {
  fbCalls.length = 0;
  useWizardStore.setState({ stepId: "teams", mode: "season", calendarEntryId: null, displayedScheduleId: null });
});

/** La dernière capture de `FeedbackButton` pour un écran donné (l'en-tête du wizard). */
function lastCallForScreen(s: string): { screen?: string; scheduleId?: string | null } | undefined {
  return [...fbCalls].reverse().find((c) => c.screen === s);
}

describe("Wizard — « Signaler » joint le planning affiché À L'ÉTAPE GÉNÉRATION seulement (lot B)", () => {
  it("à l'étape Génération, « Signaler » porte le scheduleId affiché (remonté au store)", async () => {
    useWizardStore.setState({ stepId: "generate", displayedScheduleId: "sched-9" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    expect(await screen.findByText("Génération (stub)")).toBeInTheDocument();
    const header = lastCallForScreen("wizard/generate");
    expect(header, "l'en-tête du wizard rend « Signaler » à l'étape Génération").toBeDefined();
    expect(header?.scheduleId).toBe("sched-9");
  });

  it("sur une AUTRE étape, « Signaler » ne porte aucun scheduleId (même si le store en tient un)", async () => {
    // Le store porte un id (il est éphémère et pas effacé) ; l'en-tête ne doit le joindre qu'à
    // l'étape Génération — ailleurs, scheduleId reste indéfini.
    useWizardStore.setState({ stepId: "teams", displayedScheduleId: "sched-9" });
    renderWithProviders(<WizardPage />, { route: "/wizard" });

    expect(await screen.findByDisplayValue("SF1")).toBeInTheDocument();
    const header = lastCallForScreen("wizard/teams");
    expect(header, "l'en-tête du wizard rend « Signaler » à l'étape Équipes").toBeDefined();
    expect(header?.scheduleId).toBeUndefined();
  });
});
