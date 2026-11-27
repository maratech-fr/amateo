import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { listboxTrigger, pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";

import type { Competition, PriorityTier, Team } from "./api";
import { FixtureFormDialog } from "./FixtureFormDialog";
import { MATCHES_TEXTS } from "./texts";

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
const competitions: Competition[] = [{ id: "comp-1", engagements: [{ teamId: "team-1", fbiTeamLabel: null, ffbbPouleId: null, ffbbPouleName: null, expectedMatchdays: null }], name: "Championnat U13", competitionType: "CHAMPIONSHIP", defaultFriendly: false }];
/** Lot 7 PR 4a — la compétition « Amicaux » d'office de la saison, servie par GET /api/competitions ; team-1 y est déjà engagée. */
const amicaux: Competition = { id: "comp-amicaux", engagements: [{ teamId: "team-1", fbiTeamLabel: null, ffbbPouleId: null, ffbbPouleName: null, expectedMatchdays: null }], name: "Amicaux", competitionType: "FRIENDLY", defaultFriendly: true };

beforeEach(() => {
  mutate.mockClear();
  updateMutate.mockClear();
});

describe("FixtureFormDialog", () => {
  // Lot 7 PR 4a — poser un amical, c'est le ranger dans « Amicaux » : l'option envoie l'identifiant
  // de la compétition d'office quand la saison l'a, sinon "" (le serveur la crée) — jamais null.
  it("poser un amical sans « Amicaux » servie envoie competitionId \"\", jamais null", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={[]} onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    expect(mutate).toHaveBeenCalledOnce();
    expect(mutate.mock.calls[0][0]).toEqual({ teamId: "team-1", matchDate: "2026-11-01", homeAway: "HOME", opponentLabel: "Amis", competitionId: "" });
  });

  it("poser un amical quand « Amicaux » existe envoie son identifiant", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={[amicaux, ...competitions]} onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    expect(mutate.mock.calls[0][0]).toEqual({ teamId: "team-1", matchDate: "2026-11-01", homeAway: "HOME", opponentLabel: "Amis", competitionId: "comp-amicaux" });
  });

  it("« Amicaux » est la première option, une seule fois, puis les compétitions de l'équipe", () => {
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={[...competitions, amicaux]} onClose={vi.fn()} />);

    const options = within(screen.getByRole("combobox", { name: "Compétition" })).getAllByRole("option");
    expect(options.map((o) => (o as HTMLOptionElement).value)).toEqual(["comp-amicaux", "comp-1"]);
  });

  it("sans « Amicaux » servie, la première option porte le libellé d'office et la valeur \"\"", () => {
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={competitions} onClose={vi.fn()} />);

    expect(MATCHES_TEXTS.friendlyCompetitionOption).toEqual(expect.any(String));
    const first = within(screen.getByRole("combobox", { name: "Compétition" })).getAllByRole("option")[0] as HTMLOptionElement;
    expect(first.value).toBe("");
    expect(first).toHaveAccessibleName(MATCHES_TEXTS.friendlyCompetitionOption);
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
    await pickListboxOption(user, "Équipe", "Seniors"); // team-2
    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    // team-2 has no competition → the stale comp-1 must not be carried over: back to « Amicaux » ("" when the season has none served).
    expect(mutate.mock.calls[0][0]).toEqual({ teamId: "team-2", matchDate: "2026-11-01", homeAway: "HOME", opponentLabel: "Amis", competitionId: "" });
  });

  // ── Edit mode (P1-4 PR E1) ───────────────────────────────────────────────

  const existing = {
    id: "fx-1",
    teamId: "team-1",
    seasonId: "s",
    competitionId: "comp-1",
    competitionType: "CHAMPIONSHIP" as const,
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
    neutralGround: false,
    reviewState: "NEW" as const,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null,
  };

  it("edit mode: prefilled fields, fixed team, submits the changed identity fields", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={competitions} fixture={existing} onClose={vi.fn()} />);

    expect(screen.getByRole("heading", { name: "Modifier le match" })).toBeInTheDocument();
    expect(listboxTrigger("Équipe")).toBeDisabled(); // another team = another engagement
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

  it("edit mode: choisir « Amicaux » sur un match de championnat envoie l'identifiant d'« Amicaux »", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={[amicaux, ...competitions]} fixture={existing} onClose={vi.fn()} />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Compétition" }), "comp-amicaux");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(updateMutate.mock.calls[0][0].input.competitionId).toBe("comp-amicaux");
  });

  it("edit mode: un amical de « Amicaux » se modifie sans quitter « Amicaux »", async () => {
    const user = userEvent.setup();
    const friendly = { ...existing, competitionId: "comp-amicaux", competitionType: "FRIENDLY" as const };
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={[amicaux, ...competitions]} fixture={friendly} onClose={vi.fn()} />);

    expect(screen.getByRole("combobox", { name: "Compétition" })).toHaveValue("comp-amicaux");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(updateMutate.mock.calls[0][0].input.competitionId).toBe("comp-amicaux");
  });

  it("edit mode: switching to Extérieur warns that the slot will be freed", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} tiers={tiers} competitions={competitions} fixture={existing} onClose={vi.fn()} />);

    await user.selectOptions(screen.getByLabelText("Domicile ou extérieur"), "AWAY");
    expect(screen.getByText(/libèrera son créneau/)).toBeInTheDocument();
  });
});
