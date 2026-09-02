import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import { SocleDeviationPanel } from "./SocleDeviationPanel";
import type { SocleDeviationMoved, SocleDeviationUnplaced } from "./api";

const teamName = (id: string) => ({ t1: "U13F1", t2: "Séniors F", t3: "U15M2" })[id] ?? id;
const venueName = (id: string) => ({ vX: "Matéo", vY: "JDR", vZ: "Salle Bleue", vW: "Gymnase Nord" })[id] ?? id;

const moved: SocleDeviationMoved[] = [
  { teamId: "t1", from: { dayOfWeek: 2, startTime: "18:30", venueId: "vX" }, to: { dayOfWeek: 4, startTime: "19:00", venueId: "vY", slotId: "slot-A" } },
];
const unplaced: SocleDeviationUnplaced[] = [
  { teamId: "t2", dayOfWeek: 5, startTime: "20:00", venueId: "vZ", reason: "venue_closed" },
  { teamId: "t3", dayOfWeek: 1, startTime: "17:00", venueId: "vW", reason: null },
];

describe("SocleDeviationPanel", () => {
  it("NOMME l'agrégat puis chaque écart — déplacée « U13F1 · Mar 18h30 Matéo → Jeu 19h00 JDR »", () => {
    render(<SocleDeviationPanel moved={moved} unplaced={unplaced} teamName={teamName} venueName={venueName} />);

    const region = screen.getByRole("region", { name: /écarts avec le planning de saison/i });

    // Agrégat en tête : N déplacées, M à replacer (longueur de liste, présentation pure).
    expect(within(region).getByText(/1 séance déplacée/i)).toBeInTheDocument();
    expect(within(region).getByText(/2 à replacer/i)).toBeInTheDocument();

    // La ligne déplacée, du socle vers la période — format exact du fondateur.
    const movedItem = within(region).getAllByRole("listitem").find((li) => li.textContent?.includes("→"));
    expect(movedItem).toBeDefined();
    expect(movedItem).toHaveTextContent("U13F1 · Mar 18h30 Matéo → Jeu 19h00 JDR");
  });

  it("les non replacées portent la RAISON en clair, une raison nulle NE porte aucune étiquette", () => {
    render(<SocleDeviationPanel moved={moved} unplaced={unplaced} teamName={teamName} venueName={venueName} />);
    const region = screen.getByRole("region", { name: /écarts avec le planning de saison/i });

    // Raison servie → libellé en clair, jamais le code brut.
    expect(within(region).getByText("Fermeture du gymnase")).toBeInTheDocument();
    expect(within(region).queryByText("venue_closed")).not.toBeInTheDocument();

    // La ligne à raison NULLE (U15M2) n'a AUCUNE étiquette de raison.
    const nullItem = within(region).getAllByRole("listitem").find((li) => li.textContent?.includes("U15M2"));
    expect(nullItem).toBeDefined();
    expect(nullItem).toHaveTextContent(/U15M2/);
    expect(nullItem?.textContent).not.toMatch(/réduites|Fermeture|désactivé|Non reprise/i);
  });

  it("D6-d — cliquer une ligne déplacée vise SA carte dans la grille (onSelectSlot avec to.slotId)", () => {
    const onSelectSlot = vi.fn();
    render(<SocleDeviationPanel moved={moved} unplaced={unplaced} teamName={teamName} venueName={venueName} onSelectSlot={onSelectSlot} />);
    const region = screen.getByRole("region", { name: /écarts avec le planning de saison/i });

    const movedButton = within(region).getByRole("button", { name: /U13F1/ });
    movedButton.click();
    expect(onSelectSlot).toHaveBeenCalledWith("slot-A");

    // Les lignes « à replacer » restent du texte : aucune n'est un bouton.
    expect(within(region).queryByRole("button", { name: /Séniors F/ })).not.toBeInTheDocument();
  });

  it("sans onSelectSlot, les lignes déplacées restent du texte (pas de bouton)", () => {
    render(<SocleDeviationPanel moved={moved} unplaced={unplaced} teamName={teamName} venueName={venueName} />);
    const region = screen.getByRole("region", { name: /écarts avec le planning de saison/i });
    expect(within(region).queryByRole("button")).not.toBeInTheDocument();
  });

  it("aucun écart (deux listes vides) → ne rend RIEN (pas de région fantôme)", () => {
    const { container } = render(<SocleDeviationPanel moved={[]} unplaced={[]} teamName={teamName} venueName={venueName} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("est accessible (axe)", async () => {
    const { container } = render(<SocleDeviationPanel moved={moved} unplaced={unplaced} teamName={teamName} venueName={venueName} />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
