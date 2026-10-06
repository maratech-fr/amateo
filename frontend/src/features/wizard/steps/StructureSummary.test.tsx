import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("../queries", () => ({
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Importante", color: null }] }),
  useVenueSlots: () => ({ data: [{ id: "s1", venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, capacity: 1 }, { id: "s2", venueId: "v1", dayOfWeek: 3, startTime: "18:00", durationMinutes: 90, capacity: 1 }] }),
  useWizardCoachPlayers: () => ({ data: [{ id: "cp1", teamId: "t1", coachId: "player-bob", isActive: true }] }),
  useWizardCoaches: () => ({
    data: [
      { id: "other-zoe", firstName: "Zoe", lastName: "Z", email: null, isEmployee: false, isActive: true, gender: "UNSPECIFIED" },
      { id: "player-bob", firstName: "Bob", lastName: "B", email: null, isEmployee: false, isActive: true, gender: "UNSPECIFIED" },
      { id: "salaried-ana", firstName: "Ana", lastName: "A", email: null, isEmployee: true, isActive: true, gender: "UNSPECIFIED" },
    ],
  }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardTeams: () => ({
    data: [
      { id: "t1", name: "SM1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true },
      { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 1, isActive: true },
    ],
  }),
  useWizardVenues: () => ({
    data: [
      { id: "v1", name: "Gymnase A", color: "#ff0000", canSplit: false, isActive: true },
      { id: "v2", name: "Gymnase B", color: "#00ff00", canSplit: false, isActive: true },
    ],
  }),
}));

vi.mock("@/features/cockpit/queries", () => ({
  useEntryConflicts: () => ({ data: { venueIds: ["v2"] } }),
}));

import { ReadonlyCoaches } from "./StructureSummary";

describe("ReadonlyCoaches (period, read-only)", () => {
  it("orders salaried → coach-player → other", () => {
    render(<ReadonlyCoaches />);
    const rows = screen.getAllByRole("listitem").map((li) => li.textContent ?? "");
    const anaIdx = rows.findIndex((t) => t.includes("Ana"));
    const bobIdx = rows.findIndex((t) => t.includes("Bob"));
    const zoeIdx = rows.findIndex((t) => t.includes("Zoe"));
    expect(anaIdx).toBeLessThan(bobIdx);
    expect(bobIdx).toBeLessThan(zoeIdx);
    // Tags surface the staffing type.
    expect(screen.getByText("salarié·e")).toBeInTheDocument();
    expect(screen.getByText("coach-joueur·euse")).toBeInTheDocument();
  });
});
