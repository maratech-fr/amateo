import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Competition, PriorityTier, Team } from "./api";
import { FixtureFormDialog } from "./FixtureFormDialog";

const { mutate, updateMutate } = vi.hoisted(() => ({ mutate: vi.fn(), updateMutate: vi.fn() }));

vi.mock("./queries", () => ({
  useCreateFixture: () => ({ mutate, isPending: false }),
  useUpdateFixture: () => ({ mutate: updateMutate, isPending: false }),
}));

const teams: Team[] = [
  { id: "team-1", name: "U13", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
  { id: "team-2", name: "Seniors", sportCategoryId: "cat2", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
];
const tiers: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
];
const competitions: Competition[] = [{ id: "comp-1", teamId: "team-1", name: "Championnat U13", competitionType: "CHAMPIONSHIP" }];

beforeEach(() => {
  mutate.mockClear();
  updateMutate.mockClear();
});

describe("FixtureFormDialog", () => {
  it("creates a friendly (no competition → competitionId null)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={[]} onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    expect(mutate).toHaveBeenCalledOnce();
    expect(mutate.mock.calls[0][0]).toEqual({ teamId: "team-1", matchDate: "2026-11-01", homeAway: "HOME", opponentLabel: "Amis", competitionId: null });
  });

  it("keeps Créer disabled until the required fields are filled", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={[]} onClose={vi.fn()} />);

    expect(screen.getByRole("button", { name: "Créer" })).toBeDisabled();
    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    expect(screen.getByRole("button", { name: "Créer" })).toBeEnabled();
  });

  it("drops the previous team's competition when the team changes", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={competitions} onClose={vi.fn()} />);

    // team-1 is selected by default → pick its competition, then switch to team-2.
    await user.selectOptions(screen.getByLabelText("Compétition"), "comp-1");
    await user.selectOptions(screen.getByLabelText("Équipe"), "team-2");
    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    // team-2 has no competition → the stale comp-1 must not be carried over.
    expect(mutate.mock.calls[0][0]).toEqual({ teamId: "team-2", matchDate: "2026-11-01", homeAway: "HOME", opponentLabel: "Amis", competitionId: null });
  });

  // ── Edit mode (P1-4 PR E1) ───────────────────────────────────────────────

  const existing = {
    id: "fx-1",
    teamId: "team-1",
    seasonId: "s",
    competitionId: "comp-1",
    matchDate: "2026-11-08",
    homeAway: "HOME" as const,
    opponentLabel: "Rivaux",
    status: "PLACED" as const,
    venueId: "venue-1",
    kickoffTime: "16:00",
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: "MANUAL" as const,
    unplacedReason: null,
  };

  it("edit mode: prefilled fields, fixed team, submits the changed identity fields", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={competitions} fixture={existing} onClose={vi.fn()} />);

    expect(screen.getByRole("heading", { name: "Modifier le match" })).toBeInTheDocument();
    expect(screen.getByLabelText("Équipe")).toBeDisabled(); // another team = another engagement
    expect(screen.getByLabelText("Date")).toHaveValue("2026-11-08");
    expect(screen.getByLabelText("Adversaire")).toHaveValue("Rivaux");

    await user.clear(screen.getByLabelText("Adversaire"));
    await user.type(screen.getByLabelText("Adversaire"), "Rivaux BC");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(mutate).not.toHaveBeenCalled();
    expect(updateMutate).toHaveBeenCalledOnce();
    expect(updateMutate.mock.calls[0][0]).toEqual({
      fixture: existing,
      input: { matchDate: "2026-11-08", homeAway: "HOME", opponentLabel: "Rivaux BC", competitionId: "comp-1" },
    });
  });

  it("edit mode: switching to Extérieur warns that the slot will be freed", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={competitions} fixture={existing} onClose={vi.fn()} />);

    await user.selectOptions(screen.getByLabelText("Domicile ou extérieur"), "AWAY");
    expect(screen.getByText(/libèrera son créneau/)).toBeInTheDocument();
  });
});
