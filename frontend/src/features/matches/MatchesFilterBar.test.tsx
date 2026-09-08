import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Coach, PriorityTier, Team, Venue } from "./api";
import { MatchesFilterBar } from "./MatchesFilterBar";

const teams: Team[] = [
  { id: "sm1", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
  { id: "u13", name: "U13", sportCategoryId: "c", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
];
const tiers: PriorityTier[] = [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }];
const coaches: Coach[] = [{ id: "thomas", firstName: "Thomas", lastName: "Martin" }, { id: "jean", firstName: "Jean", lastName: "Dupont" }];
const venues: Venue[] = [{ id: "v1", name: "Gymnase Alpha", color: null }];

function setup(over: Partial<Parameters<typeof MatchesFilterBar>[0]> = {}) {
  const onModeChange = vi.fn();
  const onToggle = vi.fn();
  const onClear = vi.fn();
  render(<MatchesFilterBar mode="equipe" selected={[]} teams={teams} coaches={coaches} venues={venues} tiers={tiers} onModeChange={onModeChange} onToggle={onToggle} onClear={onClear} {...over} />);
  return { onModeChange, onToggle, onClear };
}

describe("MatchesFilterBar", () => {
  it("rend un contrôle segmenté à 3 axes, avec aria-pressed sur l'axe courant", () => {
    setup({ mode: "coach" });
    expect(screen.getByRole("button", { name: "Par équipe" })).toHaveAttribute("aria-pressed", "false");
    expect(screen.getByRole("button", { name: "Par coach" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "Par gymnase" })).toHaveAttribute("aria-pressed", "false");
  });

  it("cliquer un axe appelle onModeChange", async () => {
    const user = userEvent.setup();
    const { onModeChange } = setup({ mode: "equipe" });
    await user.click(screen.getByRole("button", { name: "Par coach" }));
    expect(onModeChange).toHaveBeenCalledWith("coach");
  });

  it("vue coach : la puce liste les coachs à plat", async () => {
    const user = userEvent.setup();
    setup({ mode: "coach" });
    await user.click(screen.getByRole("button", { name: /Coachs :/ }));
    expect(await screen.findByText("Thomas Martin")).toBeInTheDocument();
    expect(screen.getByText("Jean Dupont")).toBeInTheDocument();
  });

  it("vue gymnase : la puce liste les gymnases", async () => {
    const user = userEvent.setup();
    setup({ mode: "gymnase" });
    await user.click(screen.getByRole("button", { name: /Gymnases :/ }));
    expect(await screen.findByText("Gymnase Alpha")).toBeInTheDocument();
  });
});
