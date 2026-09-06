import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { TeamLike, TierLike } from "@/shared/lib/teamTiers";

import { TeamSelect } from "./team-select";

type TierWithColor = TierLike & { color?: string | null };

const tiers: TierWithColor[] = [
  { id: 1, label: "S", name: "Fanion", color: "#ff0000" },
  { id: 3, label: "B", name: "Moyenne", color: "#0000ff" },
];
const teams: TeamLike[] = [
  { id: "b1", name: "U15", priorityTierId: 3, tierOrder: 0 },
  { id: "s1", name: "SM1", priorityTierId: 1, tierOrder: 0 },
];

const trigger = () => screen.getByRole("button", { name: /Équipe/ });

describe("TeamSelect", () => {
  it("groups teams by tier (S before B), the tier colour as the option swatch", async () => {
    const user = userEvent.setup();
    render(<TeamSelect aria-label="Équipe" teams={teams} tiers={tiers} value="" onValueChange={vi.fn()} />);
    await user.click(trigger());
    // Tier groups, ordered by importance — named "<letter> <meaning>".
    const groups = screen.getAllByRole("group");
    expect(groups[0]).toHaveAccessibleName("S Fanion");
    expect(groups[1]).toHaveAccessibleName("B Moyenne");
    // SM1 (tier S) is under the first group, and its swatch carries the tier colour.
    const sm1 = within(groups[0]).getByRole("option", { name: "SM1" });
    // jsdom normalise le hex du niveau en rgb — le point de couleur porte bien la couleur du niveau.
    expect(sm1.querySelector('[style*="background"]')).toHaveStyle({ backgroundColor: "rgb(255, 0, 0)" });
  });

  it("renders the « Entraînements mutualisés » group first when provided", async () => {
    const user = userEvent.setup();
    render(
      <TeamSelect
        aria-label="Équipe"
        teams={teams}
        tiers={tiers}
        value=""
        onValueChange={vi.fn()}
        mutualisationGroups={[{ value: "block:g", label: "SM1 + U15" }]}
      />,
    );
    await user.click(trigger());
    const groups = screen.getAllByRole("group");
    expect(groups[0]).toHaveAccessibleName("Entraînements mutualisés");
    expect(within(groups[0]).getByRole("option", { name: "SM1 + U15" })).toBeInTheDocument();
  });

  it("falls back to a flat list (no groups) when tiers are not loaded", async () => {
    const user = userEvent.setup();
    render(<TeamSelect aria-label="Équipe" teams={teams} tiers={[]} value="" onValueChange={vi.fn()} />);
    await user.click(trigger());
    expect(screen.queryAllByRole("group")).toHaveLength(0);
    expect(screen.getAllByRole("option")).toHaveLength(2);
  });

  it("routes an orphan team (unknown tier) to 'Autres' — never dropped", async () => {
    const user = userEvent.setup();
    const withOrphan: TeamLike[] = [...teams, { id: "x", name: "Mystère", priorityTierId: 99, tierOrder: 0 }];
    render(<TeamSelect aria-label="Équipe" teams={withOrphan} tiers={tiers} value="" onValueChange={vi.fn()} />);
    await user.click(trigger());
    expect(screen.getByRole("group", { name: "Autres" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Mystère" })).toBeInTheDocument();
  });

  it("shows the placeholder on the trigger and fires onValueChange with the team id on pick", async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    render(<TeamSelect aria-label="Équipe" teams={teams} tiers={tiers} placeholder="— équipe —" value="" onValueChange={onValueChange} />);
    expect(screen.getByRole("button", { name: "Équipe — équipe —" })).toBeInTheDocument();
    await user.click(trigger());
    await user.click(screen.getByRole("option", { name: "SM1" }));
    expect(onValueChange).toHaveBeenCalledWith("s1");
  });
});
