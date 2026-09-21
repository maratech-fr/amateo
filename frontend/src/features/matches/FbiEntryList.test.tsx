import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Competition, FbiCorrection, Fixture, Team, Venue } from "./api";
import { FbiEntryList } from "./FbiEntryList";

const teams = new Map<string, Team>([
  { id: "tA", name: "U13" },
  { id: "tB", name: "Seniors" },
].map((t) => [t.id, t as Team]));
const venues = new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: null, externalLabels: [] }]]);

const base = { seasonId: "s", competitionId: null, homeAway: "HOME" as const, fbiVenueLabel: null, placementSource: "MANUAL" as const, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null, fbiEcho: null };

function fx(over: Partial<Fixture>): Fixture {
  return { ...base, id: "fx", teamId: "tA", matchDate: "2026-10-03", opponentLabel: "Voisins", status: "PLACED", venueId: "v1", kickoffTime: "16:00", externalRef: "12", ...over };
}

function correction(over: Partial<FbiCorrection>): FbiCorrection {
  return { id: "c1", fixtureId: "fxA1", field: "kickoff", appValue: "16:00", fbiValue: "18:00", venueFbiLabel: null, decidedAt: "2026-09-14T10:00:00+02:00", lastSeenInFbiAt: "2026-09-14T10:00:00+02:00", ...over };
}

function renderList(fixtures: Fixture[], corrections: FbiCorrection[], overrides: Partial<Parameters<typeof FbiEntryList>[0]> = {}) {
  const onSubmit = vi.fn();
  const onCorrected = vi.fn();
  const onUndoCorrected = vi.fn();
  render(<FbiEntryList fixtures={fixtures} corrections={corrections} teams={teams} venues={venues} today="2026-09-20" busy={false} onSubmit={onSubmit} onCorrected={onCorrected} onUndoCorrected={onUndoCorrected} {...overrides} />);
  return { onSubmit, onCorrected, onUndoCorrected };
}

describe("FbiEntryList — « FBI — à faire »", () => {
  it("deux sections : « À corriger dans FBI » EN PREMIER, « À saisir » ensuite", () => {
    const fixtures = [fx({ id: "fxA1", status: "SUBMITTED" }), fx({ id: "fxB1", teamId: "tB", opponentLabel: "Rivaux" })];
    renderList(fixtures, [correction({ id: "c1", fixtureId: "fxA1" })]);
    const headings = screen.getAllByRole("heading").map((h) => h.textContent);
    expect(headings[0]).toContain("À corriger dans FBI · 1");
    expect(headings[1]).toContain("À saisir · 1");
  });

  it("une famille vide est masquée (seulement des corrections → pas de section « À saisir »)", () => {
    renderList([fx({ id: "fxA1", status: "SUBMITTED" })], [correction({ id: "c1", fixtureId: "fxA1" })]);
    expect(screen.getByRole("heading", { name: /À corriger dans FBI/ })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: /À saisir/ })).not.toBeInTheDocument();
  });

  it("erreur FBI sur une salle sans libellé ni valeur cible : « à vérifier » plutôt qu'un « ? » nu (lot N)", () => {
    renderList(
      [fx({ id: "fxA1", status: "SUBMITTED" })],
      [correction({ id: "cv", fixtureId: "fxA1", field: "venue", appValue: null, fbiValue: null, venueFbiLabel: null })],
    );
    expect(screen.getByText("à vérifier")).toBeInTheDocument();
  });

  it("les deux vides → « Rien à faire dans FBI. »", () => {
    renderList([fx({ id: "u", status: "UNPLACED" }), fx({ id: "away", homeAway: "AWAY" })], []);
    expect(screen.getByText("Rien à faire dans FBI.")).toBeInTheDocument();
  });

  it("« À corriger » : une ligne PAR MATCH — deux champs du même match tiennent une seule ligne", () => {
    const fixtures = [fx({ id: "fxA1", status: "SUBMITTED", opponentLabel: "Voisins", externalRef: "123456" })];
    const corrections = [
      correction({ id: "c1", fixtureId: "fxA1", field: "kickoff", appValue: "15:00", fbiValue: "15:30" }),
      correction({ id: "c2", fixtureId: "fxA1", field: "date", appValue: "2026-10-03", fbiValue: "2026-10-10" }),
    ];
    renderList(fixtures, corrections);
    // Un seul bouton « Corrigé dans FBI » pour le match (une ligne).
    expect(screen.getAllByRole("button", { name: /Corrigé dans FBI/ })).toHaveLength(1);
    // Les deux champs sont listés sous la même ligne.
    expect(screen.getByText(/Heure :/)).toBeInTheDocument();
    expect(screen.getByText(/Date :/)).toBeInTheDocument();
    expect(screen.getByText(/n° 123456/)).toBeInTheDocument();
  });

  it("gras = la valeur Amateo (à taper) ; la valeur FBI en sourdine, JAMAIS barrée", () => {
    renderList([fx({ id: "fxA1", status: "SUBMITTED" })], [correction({ id: "c1", fixtureId: "fxA1", field: "kickoff", appValue: "15:00", fbiValue: "15:30" })]);
    const bold = screen.getByText("15:00");
    expect(bold).toHaveClass("font-medium");
    // La valeur FBI est présente et sans line-through.
    const fbi = screen.getByText(/FBI affiche 15:30/);
    expect(fbi.className).not.toContain("line-through");
  });

  it("clic « Corrigé dans FBI » → callback + la ligne offre « Annuler » ; Annuler → onUndoCorrected", async () => {
    const user = userEvent.setup();
    const { onCorrected, onUndoCorrected } = renderList([fx({ id: "fxA1", status: "SUBMITTED" })], [correction({ id: "c1", fixtureId: "fxA1" })]);
    await user.click(screen.getByRole("button", { name: /Corrigé dans FBI/ }));
    expect(onCorrected).toHaveBeenCalledWith([expect.objectContaining({ id: "c1" })]);
    // La ligne grisée porte « Annuler ».
    const undo = await screen.findByRole("button", { name: "Annuler" });
    await user.click(undo);
    expect(onUndoCorrected).toHaveBeenCalledWith([expect.objectContaining({ id: "c1" })]);
  });

  it("tri par date de match croissante (les corrections d'un match plus proche avant)", () => {
    const fixtures = [fx({ id: "late", status: "SUBMITTED", matchDate: "2026-10-31", opponentLabel: "Tardif" }), fx({ id: "soon", status: "SUBMITTED", matchDate: "2026-10-03", opponentLabel: "Proche" })];
    renderList(fixtures, [correction({ id: "cl", fixtureId: "late" }), correction({ id: "cs", fixtureId: "soon" })]);
    const buttons = screen.getAllByRole("button", { name: /Corrigé dans FBI/ });
    expect(buttons[0]).toHaveAccessibleName(/Proche/);
    expect(buttons[1]).toHaveAccessibleName(/Tardif/);
  });

  it("« À saisir » avec fbiEcho → mention « FBI affiche 15:30 »", () => {
    renderList([fx({ id: "fxA1", status: "PLACED", fbiEcho: { field: "kickoff", value: "15:30", at: "2026-09-14T10:00:00+02:00" } })], []);
    expect(screen.getByText(/FBI affiche 15:30/)).toBeInTheDocument();
  });

  it("« Tout marquer saisi » ne touche JAMAIS les corrections (onCorrected jamais appelé)", async () => {
    const user = userEvent.setup();
    const fixtures = [fx({ id: "fxA1", status: "SUBMITTED" }), fx({ id: "fxB1", teamId: "tB", opponentLabel: "Rivaux" })];
    const { onSubmit, onCorrected } = renderList(fixtures, [correction({ id: "c1", fixtureId: "fxA1" })]);
    await user.click(screen.getByRole("button", { name: "Tout marquer saisi" }));
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Confirmer" }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ id: "fxB1" }));
    expect(onCorrected).not.toHaveBeenCalled();
  });

  it("cocher une ligne « à saisir » PLACÉE = onSubmit", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderList([fx({ id: "fxB1", teamId: "tB", opponentLabel: "Rivaux" })], []);
    await user.click(screen.getByRole("button", { name: /Marquer saisi.*Rivaux/ }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ id: "fxB1" }));
  });
});

// ── L'échéance de saisie SERVIE, sur la ligne « à saisir » ────────────────────
const comp = (id: string, over: Partial<Competition>): Competition => ({
  id,
  teamId: "tA",
  name: "Départemental",
  competitionType: "CHAMPIONSHIP",
  effectiveEntryDeadline: null,
  deadlineSource: null,
  ...over,
});

function renderDeadline(competitionId: string | null, competitions: Map<string, Competition>, today: string) {
  render(
    <FbiEntryList
      fixtures={[fx({ id: "fxD", competitionId, matchDate: "2026-10-31", status: "PLACED" })]}
      corrections={[]}
      teams={teams}
      venues={venues}
      competitions={competitions}
      today={today}
      busy={false}
      onSubmit={vi.fn()}
      onCorrected={vi.fn()}
      onUndoCorrected={vi.fn()}
    />,
  );
}

describe("FbiEntryList — l'échéance de saisie servie (ligne « à saisir »)", () => {
  it("à venir : « avant le … (J-3) »", () => {
    renderDeadline("c", new Map([["c", comp("c", { effectiveEntryDeadline: "2026-10-06", deadlineSource: "club" })]]), "2026-10-03");
    expect(screen.getByText(/avant le .*\(J-3\)/)).toBeInTheDocument();
  });

  it("source communautaire → « · proposée »", () => {
    renderDeadline("c", new Map([["c", comp("c", { effectiveEntryDeadline: "2026-10-06", deadlineSource: "community" })]]), "2026-10-03");
    expect(screen.getByText(/proposée/)).toBeInTheDocument();
  });

  it("amical (competitionId null) → AUCUNE échéance affichée", () => {
    renderDeadline(null, new Map(), "2026-10-03");
    expect(screen.queryByText(/avant le/)).not.toBeInTheDocument();
  });
});
