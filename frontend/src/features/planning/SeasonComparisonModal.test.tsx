import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Slot } from "./api";

const state = {
  slots: [] as Slot[],
};

vi.mock("./queries", () => ({
  useSlots: () => ({ data: state.slots }),
  useTeams: () => ({ data: [{ id: "t1", name: "U11 M1", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }] }),
  useVenues: () => ({ data: [{ id: "v1", name: "Gymnase Matéo", color: "#00aa00" }] }),
  useCoaches: () => ({ data: [] }),
  useTeamCoaches: () => ({ data: [] }),
  useCoachPlayers: () => ({ data: [] }),
  useTrainingSlots: () => ({ data: [] }),
}));

import { SeasonComparisonModal } from "./SeasonComparisonModal";

const slot: Slot = {
  id: "s1",
  scheduleId: "season-v1",
  teamId: "t1",
  venueId: "v1",
  coachId: null,
  dayOfWeek: 1,
  startTime: "18:00:00",
  durationMinutes: 90,
  lockLevel: "NONE",
  lockOrigin: null,
  sharedTrainingBlockId: null,
};

beforeEach(() => {
  state.slots = [slot];
});

describe("SeasonComparisonModal", () => {
  it("ouvre une modale nommée qui montre le planning de saison (réutilise la vue planning)", () => {
    render(<SeasonComparisonModal seasonScheduleId="season-v1" viewMode="gymnase" onClose={() => {}} />);

    const dialog = screen.getByRole("dialog", { name: /saison/i });
    expect(dialog).toBeInTheDocument();
    // La séance du socle s'affiche dans la grille.
    expect(screen.getByText("U11 M1")).toBeInTheDocument();
  });

  it("est en CONSULTATION : aucun geste d'écriture (ni verrou, ni « Placer ici »)", () => {
    render(<SeasonComparisonModal seasonScheduleId="season-v1" viewMode="gymnase" onClose={() => {}} />);

    expect(screen.queryByRole("button", { name: /Verrouiller|Déverrouiller/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Placer ici/ })).not.toBeInTheDocument();
  });

  it("se ferme proprement (bouton Fermer)", async () => {
    const onClose = vi.fn();
    render(<SeasonComparisonModal seasonScheduleId="season-v1" viewMode="gymnase" onClose={onClose} />);

    await userEvent.click(screen.getByRole("button", { name: "Fermer" }));
    expect(onClose).toHaveBeenCalled();
  });
});
