import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Team, Venue } from "./api";
import { buildWeekendGrid } from "./lib/weekendGrid";
import { WeekendGrid } from "./WeekendGrid";

const venues = new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]);
const teams = new Map<string, Team>([
  { id: "tA", name: "U13" },
  { id: "tB", name: "Seniors" },
].map((t) => [t.id, t as Team]));

// Deux domiciles placés le même week-end, même gymnase, deux heures.
const fixtureBase = { seasonId: "s", competitionId: null, homeAway: "HOME" as const, fbiVenueLabel: null, unplacedReason: null };
const fixtures = [
  { ...fixtureBase, id: "fxA", teamId: "tA", matchDate: "2026-10-03", opponentLabel: "Voisins", status: "PLACED" as const, venueId: "v1", kickoffTime: "16:00", externalRef: "12", placementSource: "MANUAL" as const },
  { ...fixtureBase, id: "fxB", teamId: "tB", matchDate: "2026-10-03", opponentLabel: "Rivaux", status: "PLACED" as const, venueId: "v1", kickoffTime: "18:00", externalRef: "26", placementSource: "SOLVER" as const },
];

describe("WeekendGrid — mode échange (RMM-1 PR4, L6)", () => {
  it("hors échange, aucune cellule ne porte l'affordance de candidate", () => {
    const model = buildWeekendGrid(fixtures, venues, teams);
    const { container } = render(<WeekendGrid model={model} onSelectFixture={() => {}} selectedFixtureId="fxA" />);
    expect(container.querySelector('[data-swap-candidate="true"]')).toBeNull();
  });

  it("armé, les cellules candidates se VOIENT sur la grille ; la source n'est pas candidate", () => {
    const model = buildWeekendGrid(fixtures, venues, teams);
    // fxA est la source (sélectionnée) ; fxB est la seule candidate d'échange.
    const { container } = render(
      <WeekendGrid model={model} onSelectFixture={() => {}} selectedFixtureId="fxA" swapCandidateIds={new Set(["fxB"])} />,
    );
    const candidate = container.querySelector('[data-fixture-id="fxB"]');
    const source = container.querySelector('[data-fixture-id="fxA"]');
    expect(candidate).toHaveAttribute("data-swap-candidate", "true");
    // La source n'est jamais sa propre candidate d'échange.
    expect(source).not.toHaveAttribute("data-swap-candidate", "true");
  });
});
