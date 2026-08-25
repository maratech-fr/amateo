import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Competition, Fixture, Team, Venue } from "./api";
import { FbiEntryList } from "./FbiEntryList";

const teams = new Map<string, Team>([
  { id: "tA", name: "U13" },
  { id: "tB", name: "Seniors" },
].map((t) => [t.id, t as Team]));
const venues = new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: null }]]);

const base = { seasonId: "s", competitionId: null, homeAway: "HOME" as const, fbiVenueLabel: null, placementSource: "MANUAL" as const };
function build(): Fixture[] {
  return [
    // U13 : un domicile PLACÉ (à saisir) + un domicile déjà SAISI (rangé, corrigeable).
    { ...base, id: "fxA1", teamId: "tA", matchDate: "2026-10-03", opponentLabel: "Voisins", status: "PLACED", venueId: "v1", kickoffTime: "16:00", externalRef: "12" },
    { ...base, id: "fxA2", teamId: "tA", matchDate: "2026-10-04", opponentLabel: "Lyon", status: "SUBMITTED", venueId: "v1", kickoffTime: "10:30", externalRef: "13" },
    // Seniors : un domicile PLACÉ.
    { ...base, id: "fxB1", teamId: "tB", matchDate: "2026-10-03", opponentLabel: "Rivaux", status: "PLACED", venueId: "v1", kickoffTime: "20:30", externalRef: "26" },
    // Bruit : un domicile UNPLACED (rien à recopier) et un extérieur.
    { ...base, id: "fxU", teamId: "tA", matchDate: "2026-10-03", opponentLabel: "Absents", status: "UNPLACED", venueId: null, kickoffTime: null, externalRef: null },
    { ...base, id: "fxAway", teamId: "tB", matchDate: "2026-10-04", homeAway: "AWAY", opponentLabel: "Grenoble", status: "PLACED", venueId: null, kickoffTime: "16:00", externalRef: "99" },
  ];
}

function renderList(overrides: Partial<Parameters<typeof FbiEntryList>[0]> = {}) {
  const onSubmit = vi.fn();
  const onReopen = vi.fn();
  render(<FbiEntryList fixtures={build()} teams={teams} venues={venues} busy={false} onSubmit={onSubmit} onReopen={onReopen} {...overrides} />);
  return { onSubmit, onReopen };
}

describe("FbiEntryList — la vue de saisie FBI (RMM-1 PR4, L9)", () => {
  it("groupe par équipe et n'affiche QUE les domiciles PLACED/SUBMITTED/VALIDATED (UNPLACED et extérieur absents)", () => {
    renderList();
    // Les deux équipes qui ont des domiciles à recopier (en-têtes de groupe —
    // pas les options du filtre, qui portent le même texte).
    expect(screen.getByRole("heading", { name: "U13" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Seniors" })).toBeInTheDocument();
    // Les lignes recopiables : leurs adversaires domicile.
    expect(screen.getByText(/Voisins/)).toBeInTheDocument();
    expect(screen.getByText(/Rivaux/)).toBeInTheDocument();
    expect(screen.getByText(/Lyon/)).toBeInTheDocument();
    // Un UNPLACED n'a rien à recopier ; un extérieur non plus.
    expect(screen.queryByText(/Absents/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Grenoble/)).not.toBeInTheDocument();
  });

  it("filtre par équipe DANS LES DEUX SENS : filtrer masque, retirer le filtre ramène", async () => {
    const user = userEvent.setup();
    renderList();
    const teamFilter = screen.getByLabelText("Filtrer par équipe");
    await user.selectOptions(teamFilter, "Seniors");
    expect(screen.getByText(/Rivaux/)).toBeInTheDocument();
    expect(screen.queryByText(/Voisins/)).not.toBeInTheDocument();
    // Le GROUPE U13 disparaît (l'en-tête ; l'option de filtre garde son texte).
    expect(screen.queryByRole("heading", { name: "U13" })).not.toBeInTheDocument();
    // Retirer le filtre ramène U13.
    await user.selectOptions(teamFilter, "Toutes les équipes");
    expect(screen.getByText(/Voisins/)).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "U13" })).toBeInTheDocument();
  });

  it("filtre par date DANS LES DEUX SENS", async () => {
    const user = userEvent.setup();
    renderList();
    const dateFilter = screen.getByLabelText("Filtrer par date");
    await user.selectOptions(dateFilter, "2026-10-04");
    // Seul le 4 octobre (fxA2 Lyon) reste.
    expect(screen.getByText(/Lyon/)).toBeInTheDocument();
    expect(screen.queryByText(/Voisins/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Rivaux/)).not.toBeInTheDocument();
    await user.selectOptions(dateFilter, "Toutes les dates");
    expect(screen.getByText(/Voisins/)).toBeInTheDocument();
  });

  it("cocher une ligne PLACÉE = le geste « saisi » (onSubmit) ; une ligne SAISIE offre « Corriger » (onReopen)", async () => {
    const user = userEvent.setup();
    const { onSubmit, onReopen } = renderList();
    await user.click(screen.getByRole("button", { name: /Marquer saisi.*Voisins/ }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ id: "fxA1" }));
    // La ligne déjà saisie (Lyon) porte « Corriger ».
    await user.click(screen.getByRole("button", { name: /Corriger.*Lyon/ }));
    expect(onReopen).toHaveBeenCalledWith(expect.objectContaining({ id: "fxA2" }));
  });

  it("« Tout marquer saisi » : confirmation AVANT le lot, puis ne soumet QUE les lignes affichées PLACÉES", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderList();
    await user.click(screen.getByRole("button", { name: "Tout marquer saisi" }));
    // Rien soumis tant que la confirmation n'est pas donnée.
    expect(onSubmit).not.toHaveBeenCalled();
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Confirmer" }));
    // Les deux PLACÉES affichées, pas la SUBMITTED (déjà saisie).
    expect(onSubmit).toHaveBeenCalledTimes(2);
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ id: "fxA1" }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ id: "fxB1" }));
    expect(onSubmit).not.toHaveBeenCalledWith(expect.objectContaining({ id: "fxA2" }));
  });

  it("FALSIFICATION — « Tout marquer saisi » borne au FILTRE : une ligne filtrée hors écran n'est PAS soumise", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderList();
    // Filtrer sur Seniors → seule fxB1 est à l'écran.
    await user.selectOptions(screen.getByLabelText("Filtrer par équipe"), "Seniors");
    await user.click(screen.getByRole("button", { name: "Tout marquer saisi" }));
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Confirmer" }));
    expect(onSubmit).toHaveBeenCalledTimes(1);
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ id: "fxB1" }));
    // fxA1 (U13, hors écran par le filtre) n'est JAMAIS soumise.
    expect(onSubmit).not.toHaveBeenCalledWith(expect.objectContaining({ id: "fxA1" }));
  });

  it("état vide : aucun domicile recopiable → message dédié", () => {
    render(<FbiEntryList fixtures={[]} teams={teams} venues={venues} busy={false} onSubmit={vi.fn()} onReopen={vi.fn()} />);
    expect(screen.getByText(/Aucun domicile à recopier/)).toBeInTheDocument();
  });
});

// ── RMM-6 PR-2 — l'échéance de saisie par ligne ──────────────────────────────
const comp = (id: string, over: Partial<Competition>): Competition => ({
  id,
  teamId: "tA",
  name: "Départemental",
  competitionType: "CHAMPIONSHIP",
  effectiveEntryDeadline: null,
  deadlineSource: null,
  ...over,
});

/** Un domicile PLACÉ rattaché à une compétition donnée. */
function fixtureWith(competitionId: string | null): Fixture {
  return { ...base, id: "fxD", teamId: "tA", competitionId, matchDate: "2026-10-31", opponentLabel: "Voisins", status: "PLACED", venueId: "v1", kickoffTime: "16:00", externalRef: "40" };
}

function renderDeadline(competitionId: string | null, competitions: Map<string, Competition>, today: string) {
  const onSubmit = vi.fn();
  render(
    <FbiEntryList fixtures={[fixtureWith(competitionId)]} teams={teams} venues={venues} competitions={competitions} today={today} busy={false} onSubmit={onSubmit} onReopen={vi.fn()} />,
  );
  return { onSubmit };
}

describe("FbiEntryList — l'échéance de saisie par ligne (RMM-6 PR-2)", () => {
  it("à venir : « avant le … (J-3) » via effectiveEntryDeadline SERVIE", () => {
    const competitions = new Map([["c", comp("c", { effectiveEntryDeadline: "2026-10-06", deadlineSource: "club" })]]);
    renderDeadline("c", competitions, "2026-10-03");
    expect(screen.getByText(/avant le .*\(J-3\)/)).toBeInTheDocument();
  });

  it("le jour même : « (aujourd'hui) » (J0)", () => {
    const competitions = new Map([["c", comp("c", { effectiveEntryDeadline: "2026-10-03", deadlineSource: "club" })]]);
    renderDeadline("c", competitions, "2026-10-03");
    expect(screen.getByText(/\(aujourd'hui\)/)).toBeInTheDocument();
  });

  it("dépassée : « échéance dépassée (J+2) » — ET la case reste COCHABLE (jamais bloquant)", async () => {
    const user = userEvent.setup();
    const competitions = new Map([["c", comp("c", { effectiveEntryDeadline: "2026-10-01", deadlineSource: "club" })]]);
    const { onSubmit } = renderDeadline("c", competitions, "2026-10-03");
    expect(screen.getByText(/échéance dépassée \(J\+2\)/)).toBeInTheDocument();
    // FALSIFICATION : le geste « Marquer saisi » marche SOUS échéance dépassée.
    await user.click(screen.getByRole("button", { name: /Marquer saisi.*Voisins/ }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ id: "fxD" }));
  });

  it("source communautaire → suffixe « · proposée »", () => {
    const competitions = new Map([["c", comp("c", { effectiveEntryDeadline: "2026-10-06", deadlineSource: "community" })]]);
    renderDeadline("c", competitions, "2026-10-03");
    expect(screen.getByText(/proposée/)).toBeInTheDocument();
  });

  it("amical (competitionId null) → AUCUNE échéance affichée", () => {
    renderDeadline(null, new Map(), "2026-10-03");
    expect(screen.queryByText(/avant le/)).not.toBeInTheDocument();
    expect(screen.queryByText(/échéance dépassée/)).not.toBeInTheDocument();
    expect(screen.queryByText(/J-|J\+/)).not.toBeInTheDocument();
  });

  it("compétition sans échéance servie → rien affiché", () => {
    const competitions = new Map([["c", comp("c", { effectiveEntryDeadline: null, deadlineSource: null })]]);
    renderDeadline("c", competitions, "2026-10-03");
    expect(screen.queryByText(/avant le/)).not.toBeInTheDocument();
  });
});
