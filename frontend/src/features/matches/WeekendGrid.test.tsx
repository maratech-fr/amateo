import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Team, Venue } from "./api";
import { buildWeekendGrid } from "./lib/weekendGrid";
import { WeekendGrid } from "./WeekendGrid";

const venues = new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00", externalLabels: [] }]]);
const teams = new Map<string, Team>([
  { id: "tA", name: "U13" },
  { id: "tB", name: "Seniors" },
].map((t) => [t.id, t as Team]));

// Deux domiciles placés le même week-end, même gymnase, deux heures.
const fixtureBase = { seasonId: "s", competitionId: null, homeAway: "HOME" as const, fbiVenueLabel: null, unplacedReason: null, reviewState: "NEW" as const, reviewedAt: null, pendingDeviations: [], ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null, fbiEcho: null, awayTravel: null };
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

describe("WeekendGrid — colonne extérieur (lot 3 PR-3a)", () => {
  const awayBase = { ...fixtureBase, homeAway: "AWAY" as const, venueId: null, kickoffTime: null, status: "UNPLACED" as const, placementSource: null as null };
  const habit = { id: "h", teamId: "tA", dayOfWeek: 6, kickoffTime: "15:30", venueId: null } as import("./api").TeamMatchHabit;
  // Extérieur SANS heure réelle mais habitude samedi 15:30 → heure estimée + trajet 45 min.
  const estimatedAway = { ...awayBase, id: "fxAway", teamId: "tA", matchDate: "2026-10-03", opponentLabel: "Épinouze", externalRef: null, opponentOrganismeCode: "C1", opponentTeamKey: "EPI-1", awayTravel: { venueLabel: "Halle Y", city: null, precision: "VENUE" as const, oneWayMinutes: 45, approximated: false, basis: "linked" as const } };

  it("l'en-tête « Extérieur » porte l'icône bus (title À l'extérieur), jamais de pastille de gymnase", () => {
    const model = buildWeekendGrid([estimatedAway], venues, teams, new Set(), [habit], "2026-10-03", 15, new Map());
    const { container } = render(<WeekendGrid model={model} onSelectFixture={() => {}} />);
    const header = screen.getByTitle("À l'extérieur");
    expect(header).toHaveTextContent("Extérieur");
    // Pas de VenueSwatch dans l'en-tête extérieur (la pastille porte un aria-hidden `title`… non :
    // VenueSwatch rend un <span> coloré ; ici seule l'icône bus svg est présente).
    expect(header.querySelector("svg")).not.toBeNull();
    expect(container.querySelector('[data-away="true"]')).not.toBeNull();
  });

  it("le bloc extérieur porte data-away + data-fixture-id et un aria-label complet", () => {
    const model = buildWeekendGrid([estimatedAway], venues, teams, new Set(), [habit], "2026-10-03", 15, new Map());
    const { container } = render(<WeekendGrid model={model} onSelectFixture={() => {}} />);
    const block = container.querySelector('[data-away="true"]') as HTMLElement;
    expect(block).toHaveAttribute("data-fixture-id", "fxAway");
    expect(block).toHaveAttribute("aria-label", "U13 à Épinouze, sam. 15:30, heure estimée, 45 min de trajet");
    // Heure estimée → l'icône horloge nommée.
    expect(within(block).getByLabelText("Heure estimée")).toBeInTheDocument();
  });

  it("un extérieur sans heure ni habitude : nom « heure inconnue » + icône nommée", () => {
    const unknown = { ...awayBase, id: "fxU", teamId: "tA", matchDate: "2026-10-03", opponentLabel: "Vienne", externalRef: null, opponentOrganismeCode: null, opponentTeamKey: null };
    const model = buildWeekendGrid([unknown], venues, teams, new Set(), [], "2026-10-03");
    render(<WeekendGrid model={model} onSelectFixture={() => {}} />);
    expect(screen.getByRole("button", { name: "U13 à Vienne, sam. heure inconnue" })).toBeInTheDocument();
    expect(screen.getByLabelText("Heure inconnue")).toBeInTheDocument();
  });

  it("en mode échange, le bloc extérieur est INERTE (pas de bouton, estompé)", async () => {
    const onSelect = vi.fn();
    const model = buildWeekendGrid([estimatedAway], venues, teams, new Set(), [habit], "2026-10-03", 15, new Map());
    const { container } = render(<WeekendGrid model={model} onSelectFixture={onSelect} swapCandidateIds={new Set()} />);
    const block = container.querySelector('[data-away="true"]') as HTMLElement;
    // Rendu en <div>, pas <button> : aucun handler en mode échange.
    expect(block.tagName).toBe("DIV");
    expect(block).toHaveClass("opacity-40");
    await userEvent.click(block);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it("hors échange, cliquer le bloc extérieur remonte son fixtureId (édition côté page)", async () => {
    const onSelect = vi.fn();
    const model = buildWeekendGrid([estimatedAway], venues, teams, new Set(), [habit], "2026-10-03", 15, new Map());
    render(<WeekendGrid model={model} onSelectFixture={onSelect} />);
    await userEvent.click(screen.getByRole("button", { name: /à Épinouze/ }));
    expect(onSelect).toHaveBeenCalledWith("fxAway");
  });
});

describe("WeekendGrid — case « À confirmer » (lot 1, 2026-09-17)", () => {
  // Un domicile porté sur la grille (gymnase + heure repris de l'import) mais UNPLACED.
  const toConfirm = {
    ...fixtureBase,
    id: "fxC",
    teamId: "tA",
    matchDate: "2026-10-03", // samedi
    opponentLabel: "Villeurbanne",
    status: "UNPLACED" as const,
    venueId: "v1",
    kickoffTime: "15:30",
    externalRef: "7",
    placementSource: null as null,
  };
  const habit = { id: "h", teamId: "tB", dayOfWeek: 6, kickoffTime: "14:00", venueId: "v1" } as import("./api").TeamMatchHabit;

  it("porte la pastille « À confirmer », un fond hachuré et un nom accessible verbatim — jamais de cadenas", () => {
    const model = buildWeekendGrid([toConfirm], venues, teams);
    const { container } = render(<WeekendGrid model={model} onSelectFixture={() => {}} />);
    const cell = container.querySelector('[data-to-confirm="true"]') as HTMLElement;
    expect(cell).not.toBeNull();
    // Pastille (le texte visible EST l'annonce d'état).
    expect(within(cell).getByText("À confirmer")).toBeInTheDocument();
    // Fond hachuré (jamais d'opacité, jamais de pointillé — ce n'est PAS un fantôme).
    expect(cell.getAttribute("style")).toContain("repeating-linear-gradient");
    expect(cell).not.toHaveClass("border-dashed");
    expect(cell.getAttribute("style") ?? "").not.toContain("opacity");
    // Un à-confirmer n'est jamais verrouillé.
    expect(within(cell).queryByLabelText("Ancre manuelle")).toBeNull();
    expect(cell).toHaveAttribute(
      "aria-label",
      "U13 – Villeurbanne, sam. 15:30, Gymnase Alpha. À confirmer : gymnase et heure repris de l'import, rencontre pas encore placée.",
    );
  });

  it("cliquer une case à confirmer remonte son fixtureId (sélection → panneau)", async () => {
    const onSelect = vi.fn();
    const model = buildWeekendGrid([toConfirm], venues, teams);
    const { container } = render(<WeekendGrid model={model} onSelectFixture={onSelect} />);
    await userEvent.click(container.querySelector('[data-to-confirm="true"]') as HTMLElement);
    expect(onSelect).toHaveBeenCalledWith("fxC");
  });

  it("un match PLACÉ manuel garde son cadenas ; un fantôme garde son pointillé", () => {
    // fxA est PLACED + MANUAL (cadenas) ; un fantôme d'habitude (pointillé/translucide).
    const model = buildWeekendGrid([fixtures[0]], venues, teams, new Set(), [habit], "2026-10-03");
    render(<WeekendGrid model={model} onSelectFixture={() => {}} />);
    expect(screen.getByLabelText("Ancre manuelle")).toBeInTheDocument();
    const ghost = screen.getByTitle(/fenêtre protégée/);
    expect(ghost).toHaveClass("border-dashed");
    // A11Y-22 — la dé-emphase du fantôme se porte par la GRAISSE, jamais par l'opacité sur du texte.
    expect(ghost).toHaveClass("font-normal");
    expect(ghost).not.toHaveClass("opacity-60");
  });
});

describe("WeekendGrid — a11y (A11Y-17)", () => {
  it("un match hors fenêtre ligue porte un nom accessible, pas l'icône + couleur seules", () => {
    // 4ᵉ argument = ids hors enveloppe : fxA sort de la fenêtre autorisée par la ligue.
    const model = buildWeekendGrid(fixtures, venues, teams, new Set(["fxA"]));
    render(<WeekendGrid model={model} onSelectFixture={() => {}} />);
    // L'alerte est annoncée au lecteur d'écran (patron du cadenas « Ancre manuelle » voisin) :
    // sans nom accessible, l'info « hors fenêtre ligue » ne reposerait que sur l'icône + la couleur.
    expect(screen.getByLabelText("Hors fenêtre ligue")).toBeInTheDocument();
  });
});
