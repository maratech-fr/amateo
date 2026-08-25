import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import type { MatchSlotRotation, Team, TeamMatchHabit, Venue } from "./api";
import { TypicalWeekendGrid } from "./TypicalWeekendGrid";

const VENUES = new Map<string, Venue>([
  ["v1", { id: "v1", name: "Alpha", color: null }],
  ["v9", { id: "v9", name: "Coubertin", color: null }],
]);
const TEAMS = new Map<string, Team>([
  ["ta", { id: "ta", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["tb", { id: "tb", name: "SM2", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["tc", { id: "tc", name: "SM3", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["tx", { id: "tx", name: "SF1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({ id: "h", teamId: "tx", dayOfWeek: 6, kickoffTime: "15:30", venueId: "v1", ...over });
const rotation = (over: Partial<MatchSlotRotation> = {}): MatchSlotRotation => ({ id: "rot-1", venueId: "v9", dayOfWeek: 6, kickoffTime: "20:30", teamIds: ["ta", "tb"], ...over });

describe("TypicalWeekendGrid — segmenté A/B (RMM-5 PR-4)", () => {
  it("SANS rotation : AUCUN segmenté (la grille reste comme avant)", () => {
    render(<TypicalWeekendGrid habits={[habit()]} rotations={[]} venues={VENUES} teams={TEAMS} />);
    expect(screen.queryByRole("tablist")).toBeNull();
    expect(screen.getByText("SF1")).toBeInTheDocument();
  });

  it("AVEC une rotation N=2 : un segmenté « Semaine A / Semaine B », A montre le membre 0, B le membre 1", async () => {
    const user = userEvent.setup();
    render(<TypicalWeekendGrid habits={[]} rotations={[rotation({ teamIds: ["ta", "tb"] })]} venues={VENUES} teams={TEAMS} />);
    const tablist = screen.getByRole("tablist", { name: "Semaine de l'alternance" });
    expect(tablist).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Semaine A" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Semaine B" })).toBeInTheDocument();
    // Semaine A → membre 0 (SM1).
    expect(screen.getByText("SM1")).toBeInTheDocument();
    expect(screen.queryByText("SM2")).toBeNull();
    // Bascule → Semaine B → membre 1 (SM2).
    await user.click(screen.getByRole("tab", { name: "Semaine B" }));
    expect(screen.getByText("SM2")).toBeInTheDocument();
    expect(screen.queryByText("SM1")).toBeNull();
  });

  it("N=3 : trois semaines A/B/C", () => {
    render(<TypicalWeekendGrid habits={[]} rotations={[rotation({ teamIds: ["ta", "tb", "tc"] })]} venues={VENUES} teams={TEAMS} />);
    expect(screen.getByRole("tab", { name: "Semaine A" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Semaine B" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Semaine C" })).toBeInTheDocument();
  });

  it("une habitude simple est IDENTIQUE sur toutes les semaines (elle ne tourne pas)", async () => {
    const user = userEvent.setup();
    render(<TypicalWeekendGrid habits={[habit({ teamId: "tx", venueId: "v1" })]} rotations={[rotation()]} venues={VENUES} teams={TEAMS} />);
    // Semaine A : l'habitude SF1 est là ET la rotation montre SM1.
    expect(screen.getByText("SF1")).toBeInTheDocument();
    await user.click(screen.getByRole("tab", { name: "Semaine B" }));
    // Semaine B : l'habitude SF1 est TOUJOURS là (identique), la rotation a tourné vers SM2.
    expect(screen.getByText("SF1")).toBeInTheDocument();
    expect(screen.getByText("SM2")).toBeInTheDocument();
  });
});
