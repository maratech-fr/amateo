import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Conflict, Fixture, Team, Venue } from "./api";
import { MatchRowsTable } from "./MatchRowsTable";

function fx(partial: Partial<Fixture> & Pick<Fixture, "id">): Fixture {
  return {
    matchDate: "2026-10-03",
    teamId: "team-1",
    seasonId: "s",
    competitionId: null,
    homeAway: "HOME",
    opponentLabel: "Voisins",
    status: "PLACED",
    venueId: "venue-1",
    kickoffTime: "16:00",
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: null,
    unplacedReason: null,
    reviewState: "NEW" as const,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    suggestedVenueId: null,
    ...partial,
  };
}

const teams = new Map<string, Team>([["team-1", { id: "team-1", name: "U13", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }]]);
const venues = new Map<string, Venue>([["venue-1", { id: "venue-1", name: "Gymnase Alpha", color: "#0a0", externalLabels: [] }]]);

function renderTable(props: Partial<Parameters<typeof MatchRowsTable>[0]> = {}) {
  const onSelectFixture = props.onSelectFixture ?? vi.fn();
  render(
    <MatchRowsTable
      caption="Matchs"
      groups={props.groups ?? [{ key: "2026-10-03", label: "Samedi 3 octobre", fixtures: [fx({ id: "fx-1" })] }]}
      teams={teams}
      venues={venues}
      conflictsByFixture={props.conflictsByFixture ?? new Map()}
      coachRoles={props.coachRoles}
      onSelectFixture={onSelectFixture}
    />,
  );
  return { onSelectFixture };
}

describe("MatchRowsTable (PR-2b — ligne de match partagée Mois/Phase)", () => {
  it("rend l'en-tête de groupe, l'équipe, l'adversaire, le gymnase, le statut et l'heure", () => {
    renderTable();
    expect(screen.getByText("Samedi 3 octobre")).toBeInTheDocument(); // en-tête de groupe
    expect(screen.getByText("sam. 3 oct.")).toBeInTheDocument(); // date de la ligne
    expect(screen.getByText("U13")).toBeInTheDocument();
    expect(screen.getByText("Voisins")).toBeInTheDocument();
    expect(screen.getByText("Gymnase Alpha")).toBeInTheDocument();
    expect(screen.getByText("Placé")).toBeInTheDocument();
    expect(screen.getByText(/16:00/)).toBeInTheDocument();
  });

  it("heure absente ⇒ « heure non publiée »", () => {
    renderTable({ groups: [{ key: "g", label: "g", fixtures: [fx({ id: "fx-1", kickoffTime: null })] }] });
    expect(screen.getByText(/heure non publiée/)).toBeInTheDocument();
  });

  it("gymnase non rattaché ⇒ fbiVenueLabel + « à rattacher dans Importer »", () => {
    renderTable({ groups: [{ key: "g", label: "g", fixtures: [fx({ id: "fx-1", venueId: null, fbiVenueLabel: "Halle Clemenceau" })] }] });
    expect(screen.getByText(/Halle Clemenceau/)).toBeInTheDocument();
    expect(screen.getByText(/à rattacher dans Importer/)).toBeInTheDocument();
  });

  it("ni gymnase ni libellé FBI ⇒ « — »", () => {
    renderTable({ groups: [{ key: "g", label: "g", fixtures: [fx({ id: "fx-1", venueId: null, fbiVenueLabel: null })] }] });
    expect(screen.getByText("—")).toBeInTheDocument();
  });

  it("montre une pastille par famille de conflit du match", () => {
    const overlap: Conflict = {
      type: "VENUE_OVERLAP",
      severity: 1,
      left: { fixtureId: "fx-1", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
    };
    renderTable({ conflictsByFixture: new Map([["fx-1", [overlap]]]) });
    expect(screen.getByText("Collision de gymnase")).toBeInTheDocument();
  });

  it("en vue coach, une pastille de rôle sur l'équipe", () => {
    renderTable({ coachRoles: new Map([["team-1", "assistant"]]) });
    expect(screen.getByText("assistant")).toBeInTheDocument();
  });

  it("cliquer la ligne (bouton accessible) appelle onSelectFixture", async () => {
    const user = userEvent.setup();
    const { onSelectFixture } = renderTable();
    // Un bouton par ligne (pas un onClick sur <tr> nu).
    const rowButton = screen.getByRole("button", { name: /U13.*Voisins|Voisins.*U13|Ouvrir/ });
    await user.click(rowButton);
    expect(onSelectFixture).toHaveBeenCalledWith("fx-1");
  });
});
