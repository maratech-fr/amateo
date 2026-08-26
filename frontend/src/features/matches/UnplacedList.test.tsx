import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Fixture, Team } from "./api";
import { UnplacedList } from "./UnplacedList";

const team: Team = { id: "team-1", name: "SM1" } as Team;
const teams = new Map<string, Team>([[team.id, team]]);

const fixture = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03",
  homeAway: "HOME",
  opponentLabel: "Voisins",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null,
  ...over,
});

describe("UnplacedList — la raison persistante venue_lost (P2-52)", () => {
  it("affiche « Le gymnase n'est plus affilié au club » quand le match l'a perdue", () => {
    render(<UnplacedList fixtures={[fixture({ unplacedReason: "venue_lost" })]} teams={teams} selectedFixtureId={null} onSelect={vi.fn()} />);
    expect(screen.getByText("Le gymnase n'est plus affilié au club")).toBeInTheDocument();
  });

  it("ne l'affiche PAS quand il n'y a pas de raison (falsification)", () => {
    render(<UnplacedList fixtures={[fixture({ unplacedReason: null })]} teams={teams} selectedFixtureId={null} onSelect={vi.fn()} />);
    expect(screen.queryByText("Le gymnase n'est plus affilié au club")).toBeNull();
  });

  it("coexiste avec la raison volatile d'auto-placement, sans la remplacer", () => {
    render(
      <UnplacedList
        fixtures={[fixture({ unplacedReason: "venue_lost" })]}
        teams={teams}
        selectedFixtureId={null}
        unplacedReasons={new Map([["fx-1", "Aucun créneau d'accès ce jour-là"]])}
        onSelect={vi.fn()}
      />,
    );
    // Les DEUX raisons se lisent : la persistante (info) et la volatile (alerte).
    expect(screen.getByText("Le gymnase n'est plus affilié au club")).toBeInTheDocument();
    expect(screen.getByText("Aucun créneau d'accès ce jour-là")).toBeInTheDocument();
  });
});
