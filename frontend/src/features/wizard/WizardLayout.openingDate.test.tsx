import { waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

/**
 * Promesse (D1bis) : à la première entrée d'un assistant guidé, un gymnase qui n'a qu'une ouverture datée
 * reste un trou : on atterrit sur Gymnases, pas sur Récap. Seul ce composant porte ce calcul
 * (`WizardLayout.tsx`, effet de positionnement). Jumeaux : `VenuesStep.test.tsx` (bandeau),
 * `useStepValidation.test.ts` (porte).
 */
const h = vi.hoisted(() => ({ windows: [] as unknown[] }));

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: null, club: { id: "c", name: "Club" } } }),
  useWorkingSeason: () => null,
}));
vi.mock("@/features/matches/queries", async (orig) => ({
  ...(await orig<typeof import("@/features/matches/queries")>()),
  useVenueMatchWindows: () => ({ data: h.windows, isLoading: false }),
}));
vi.mock("./api", async (orig) => ({
  ...(await orig<typeof import("./api")>()),
  listTeams: vi.fn(() => Promise.resolve([{ id: "t1", name: "SF1", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, gender: null, level: null }])),
  listSportCategories: vi.fn(() => Promise.resolve([{ id: "cat1", name: "U11", sortOrder: 0 }])),
  listPriorityTiers: vi.fn(() => Promise.resolve([{ id: 1, label: "S", name: "Elite", color: null }])),
  listVenues: vi.fn(() => Promise.resolve([{ id: "v1", name: "Gymnase Alpha", color: "#3498DB", canSplit: false, isActive: true, externalRef: null, address: null, latitude: null, longitude: null }])),
  listVenueSlots: vi.fn(() => Promise.resolve([])),
  listCoaches: vi.fn(() => Promise.resolve([{ id: "c1", firstName: "A", lastName: "B", gender: "UNSPECIFIED" }])),
  listTeamCoaches: vi.fn(() => Promise.resolve([])),
  listCoachPlayers: vi.fn(() => Promise.resolve([])),
  listConstraints: vi.fn(() => Promise.resolve([])),
  listImplicitRuleSettings: vi.fn(() => Promise.resolve([])),
  validateConstraints: vi.fn(() => Promise.resolve({ valid: true, errors: {}, conflicts: [] })),
}));

import { useWizardStore } from "./store";
import { WizardPage } from "./WizardLayout";

const line = { venueId: "v1", appliesOnPublicHolidays: false, label: null, startTime: "14:00", endTime: "20:00" };

beforeEach(() => {
  useWizardStore.setState({ stepId: "teams", mode: "season", calendarEntryId: null });
});

describe("WizardLayout — atterrissage guidé et ouverture datée", () => {
  it("un gymnase avec une ouverture seule reste le premier trou : Gymnases", async () => {
    h.windows = [{ ...line, id: "o", dayOfWeek: null, openingDate: "2026-11-11" }];
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    await waitFor(() => expect(useWizardStore.getState().stepId).toBe("venues"));
  });

  it("témoin : une ligne hebdo comble le trou, on atterrit sur Récap", async () => {
    h.windows = [{ ...line, id: "w", dayOfWeek: 6, openingDate: null }];
    renderWithProviders(<WizardPage />, { route: "/wizard" });
    await waitFor(() => expect(useWizardStore.getState().stepId).toBe("recap"));
  });
});
