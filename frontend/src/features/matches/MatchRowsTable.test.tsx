import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Coach, Conflict, Fixture, Team, Venue } from "./api";
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
    ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null,
    suggestedVenueId: null,
    ...partial,
  };
}

const teams = new Map<string, Team>([["team-1", { id: "team-1", name: "U13", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }]]);
const venues = new Map<string, Venue>([["venue-1", { id: "venue-1", name: "Gymnase Alpha", color: "#0a0", externalLabels: [] }]]);

const coaches = new Map<string, Coach>([["coach-1", { id: "coach-1", firstName: "Emerick", lastName: "", gender: "UNSPECIFIED" }]]);

function renderTable(props: Partial<Parameters<typeof MatchRowsTable>[0]> = {}) {
  const onSelectFixture = props.onSelectFixture ?? vi.fn();
  const onFocusConflict = props.onFocusConflict ?? vi.fn();
  render(
    <MatchRowsTable
      caption="Matchs"
      groups={props.groups ?? [{ key: "2026-10-03", label: "Samedi 3 octobre", fixtures: [fx({ id: "fx-1" })] }]}
      teams={teams}
      venues={venues}
      coaches={props.coaches ?? coaches}
      conflictsByFixture={props.conflictsByFixture ?? new Map()}
      coachRoles={props.coachRoles}
      onSelectFixture={onSelectFixture}
      onFocusConflict={onFocusConflict}
    />,
  );
  return { onSelectFixture, onFocusConflict };
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

  it("gymnase non rattaché à DOMICILE ⇒ fbiVenueLabel + « à rattacher dans Importer »", () => {
    renderTable({ groups: [{ key: "g", label: "g", fixtures: [fx({ id: "fx-1", homeAway: "HOME", venueId: null, fbiVenueLabel: "Halle Clemenceau" })] }] });
    expect(screen.getByText(/Halle Clemenceau/)).toBeInTheDocument();
    expect(screen.getByText(/à rattacher dans Importer/)).toBeInTheDocument();
  });

  it("un EXTÉRIEUR avec libellé FBI ⇒ le libellé SEUL (jamais « à rattacher » — c'est la salle de l'adversaire)", () => {
    renderTable({ groups: [{ key: "g", label: "g", fixtures: [fx({ id: "fx-1", homeAway: "AWAY", venueId: null, fbiVenueLabel: "Gymnase Chanfray" })] }] });
    expect(screen.getByText("Gymnase Chanfray")).toBeInTheDocument();
    expect(screen.queryByText(/à rattacher/)).not.toBeInTheDocument();
  });

  it("ni gymnase ni libellé FBI ⇒ « — »", () => {
    renderTable({ groups: [{ key: "g", label: "g", fixtures: [fx({ id: "fx-1", venueId: null, fbiVenueLabel: null })] }] });
    expect(screen.getByText("—")).toBeInTheDocument();
  });

  it("montre une pastille par famille de conflit du match", () => {
    const overlap: Conflict = {
      type: "VENUE_OVERLAP",
      severity: 1, resolution: null,
      left: { fixtureId: "fx-1", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
    };
    renderTable({ conflictsByFixture: new Map([["fx-1", [overlap]]]) });
    expect(screen.getByText("Collision de gymnase")).toBeInTheDocument();
  });

  it("en vue coach, une pastille de rôle sur l'équipe", () => {
    renderTable({ coachRoles: new Map([["team-1", "assistant"]]) });
    expect(screen.getByText("assistant")).toBeInTheDocument();
  });

  // ── Correctif 6 — la pastille de conflit NOMME la personne et s'ouvre en panneau ─────
  const mm = (over: Partial<Conflict> = {}): Conflict => ({
    type: "MATCH_MATCH",
    severity: 1,
    resolution: null,
    coachId: "coach-1",
    left: { fixtureId: "fx-1", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", role: "MAIN", windowStart: "2026-10-03T16:00:00", windowEnd: "2026-10-03T18:00:00" },
    right: { fixtureId: "fx-2", teamId: "team-1", homeAway: "AWAY", matchDate: "2026-10-03", kickoffTime: "18:00", role: "MAIN", windowStart: "2026-10-03T18:00:00", windowEnd: "2026-10-03T20:00:00" },
    start: "2026-10-03T18:00:00",
    end: "2026-10-03T18:00:00",
    ...over,
  });

  it("la pastille de PERSONNE en double NOMME la personne (« Emerick en double »)", () => {
    renderTable({ conflictsByFixture: new Map([["fx-1", [mm()]]]), coaches });
    expect(screen.getByRole("button", { name: /Emerick en double/ })).toBeInTheDocument();
  });

  it("deux personnes en double sur le même match → « Emerick +1 »", () => {
    const withSecond = new Map<string, Coach>([...coaches, ["coach-2", { id: "coach-2", firstName: "Nadia", lastName: "", gender: "UNSPECIFIED" }]]);
    const second = mm({ coachId: "coach-2" });
    renderTable({ conflictsByFixture: new Map([["fx-1", [mm(), second]]]), coaches: withSecond });
    expect(screen.getByRole("button", { name: /Emerick \+1/ })).toBeInTheDocument();
  });

  it("cliquer la pastille ouvre un panneau qui liste le conflit ; « Voir la semaine » déclenche le focus", async () => {
    const user = userEvent.setup();
    const { onFocusConflict } = renderTable({ conflictsByFixture: new Map([["fx-1", [mm()]]]), coaches });
    await user.click(screen.getByRole("button", { name: /Emerick en double/ }));
    const dialog = await screen.findByRole("dialog");
    // Le chevauchement est rendu par ConflictLine dans le panneau.
    expect(within(dialog).getByText(/Chevauchement/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: /Voir la semaine/ }));
    expect(onFocusConflict).toHaveBeenCalledWith(expect.objectContaining({ type: "MATCH_MATCH", coachId: "coach-1" }));
  });

  it("une famille SANS personne (collision de gymnase) est aussi un bouton qui ouvre le panneau", async () => {
    const user = userEvent.setup();
    const overlap: Conflict = {
      type: "VENUE_OVERLAP",
      severity: 1,
      resolution: null,
      venueId: "venue-1",
      left: { fixtureId: "fx-1", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "2026-10-03T16:00:00", windowEnd: "2026-10-03T18:00:00" },
      right: { fixtureId: "fx-2", teamId: "team-1", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:30", windowStart: "2026-10-03T16:30:00", windowEnd: "2026-10-03T18:30:00" },
      start: "2026-10-03T16:30:00",
      end: "2026-10-03T18:00:00",
    };
    renderTable({ conflictsByFixture: new Map([["fx-1", [overlap]]]), coaches });
    await user.click(screen.getByRole("button", { name: /Collision de gymnase/ }));
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
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
