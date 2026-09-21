import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Conflict, ConflictFixtureView, Team, Venue } from "./api";
import { FbiErrorDialog } from "./FbiErrorDialog";

const teams = new Map<string, Team>([
  ["team-sf1", { id: "team-sf1", name: "SF1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["team-u21", { id: "team-u21", name: "U21M2", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);
const venues = new Map<string, Venue>([["v-cl", { id: "v-cl", name: "Gymnase Croix-Luizet", color: null, externalLabels: [] }]]);

function homeSide(fixtureId: string, teamId: string, opponent: string): ConflictFixtureView {
  return { fixtureId, teamId, homeAway: "HOME", matchDate: "2026-03-14", kickoffTime: "15:30", windowStart: "", windowEnd: "", opponentLabel: opponent };
}

const conflict: Conflict = {
  type: "VENUE_OVERLAP",
  severity: 1,
  resolution: null,
  venueId: "v-cl",
  start: "2026-03-14T16:00:00",
  end: "2026-03-14T17:30:00",
  left: homeSide("fx-sf1", "team-sf1", "BC Villeurbanne"),
  right: homeSide("fx-u21", "team-u21", "ASVEL U21"),
};

describe("FbiErrorDialog", () => {
  it("propose les deux rencontres (détail équipe · adversaire · gymnase · date) et les trois champs", () => {
    render(<FbiErrorDialog conflict={conflict} teams={teams} venues={venues} busy={false} onConfirm={vi.fn()} onCancel={vi.fn()} />);
    // Les deux sélecteurs partagés (Listbox APG) portent des libellés explicites.
    expect(screen.getByRole("button", { name: /Quelle rencontre est fautive/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Quel champ est faux dans FBI/ })).toBeInTheDocument();
    // Le libellé du côté par défaut porte le détail du commit B (équipe · vs adversaire · gymnase · date).
    expect(screen.getByRole("button", { name: /Quelle rencontre est fautive/ })).toHaveAccessibleName(/SF1 vs BC Villeurbanne · Gymnase Croix-Luizet · 14 mars/);
    // Le dialogue DIT que la déclaration laisse une trace ailleurs (fermeture indépendante).
    expect(screen.getByText(/FBI — à faire/)).toBeInTheDocument();
    expect(screen.getByText(/à vérifier/)).toBeInTheDocument();
  });

  it("confirmer avec les défauts rend la première rencontre et le champ « salle »", async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(<FbiErrorDialog conflict={conflict} teams={teams} venues={venues} busy={false} onConfirm={onConfirm} onCancel={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: "Déclarer l'erreur FBI" }));
    expect(onConfirm).toHaveBeenCalledWith({ fixtureId: "fx-sf1", field: "venue" });
  });

  it("Annuler ferme sans confirmer", async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();
    const onConfirm = vi.fn();
    render(<FbiErrorDialog conflict={conflict} teams={teams} venues={venues} busy={false} onConfirm={onConfirm} onCancel={onCancel} />);
    await user.click(screen.getByRole("button", { name: "Annuler" }));
    expect(onCancel).toHaveBeenCalled();
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
