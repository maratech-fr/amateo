import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import type { Team, TeamMatchHabit, Venue } from "./api";
import { TypicalWeekendGrid } from "./TypicalWeekendGrid";

const VENUES = new Map<string, Venue>([
  ["v1", { id: "v1", name: "Alpha", color: null, externalLabels: [] }],
  ["v9", { id: "v9", name: "Coubertin", color: null, externalLabels: [] }],
]);
const TEAMS = new Map<string, Team>([
  ["ta", { id: "ta", name: "SM1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["tb", { id: "tb", name: "SM2", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["tx", { id: "tx", name: "SF1", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);

// Toutes les équipes du fixture sont en catégorie « c », de durée de match SERVIE 75 min.
const DURATIONS = new Map<string, number>([["c", 75]]);

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({ id: "h", teamId: "tx", dayOfWeek: 6, kickoffTime: "15:30", venueId: "v1", week: "A", ...over });

describe("TypicalWeekendGrid — segmenté A/B (P4-271)", () => {
  it("club sans alternance (weekendAlternates=false) : AUCUN segmenté, la vue est unique", () => {
    render(<TypicalWeekendGrid habits={[habit()]} venues={VENUES} teams={TEAMS} durations={DURATIONS} weekendAlternates={false} />);
    expect(screen.queryByRole("tablist")).toBeNull();
    expect(screen.getByText("SF1")).toBeInTheDocument();
  });

  it("vue unique : les créneaux A ET B sont tous montrés (le club n'alterne pas)", () => {
    const habits = [
      habit({ id: "a", teamId: "ta", venueId: "v9", kickoffTime: "20:30", week: "A" }),
      habit({ id: "b", teamId: "tb", venueId: "v9", kickoffTime: "20:30", week: "B" }),
    ];
    render(<TypicalWeekendGrid habits={habits} venues={VENUES} teams={TEAMS} durations={DURATIONS} weekendAlternates={false} />);
    expect(screen.queryByRole("tablist")).toBeNull();
    expect(screen.getByText("SM1")).toBeInTheDocument();
    expect(screen.getByText("SM2")).toBeInTheDocument();
  });

  // P4-206 — un bloc s'étend sur la durée SERVIE de la catégorie (75 min = 5 pas de 15 min),
  // du coup d'envoi (aucun échauffement dessiné) : `gridRow` = « <début> / span 5 ».
  it("P4-206 — un bloc couvre la durée servie de la catégorie, sans échauffement", () => {
    render(<TypicalWeekendGrid habits={[habit()]} venues={VENUES} teams={TEAMS} durations={DURATIONS} weekendAlternates={false} />);
    const block = screen.getByTitle("SF1 · 15:30 · Alpha");
    expect(block.style.gridRow).toContain("span 5");
  });

  it("club alternant (weekendAlternates=true) : un segmenté « Semaine A / Semaine B », A montre l'équipe A, B l'équipe B", async () => {
    const user = userEvent.setup();
    const habits = [
      habit({ id: "a", teamId: "ta", venueId: "v9", kickoffTime: "20:30", week: "A" }),
      habit({ id: "b", teamId: "tb", venueId: "v9", kickoffTime: "20:30", week: "B" }),
    ];
    render(<TypicalWeekendGrid habits={habits} venues={VENUES} teams={TEAMS} durations={DURATIONS} weekendAlternates={true} />);
    expect(screen.getByRole("tablist", { name: "Semaine de l'alternance" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Semaine A" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Semaine B" })).toBeInTheDocument();
    // Semaine A → l'équipe taguée A (SM1) seulement.
    expect(screen.getByText("SM1")).toBeInTheDocument();
    expect(screen.queryByText("SM2")).toBeNull();
    // Bascule → Semaine B → l'équipe taguée B (SM2) seulement.
    await user.click(screen.getByRole("tab", { name: "Semaine B" }));
    expect(screen.getByText("SM2")).toBeInTheDocument();
    expect(screen.queryByText("SM1")).toBeNull();
  });

  // A11Y-23 — le segmenté A/B posait un `aria-controls` sur CHAQUE onglet, mais AUCUN TabPanel
  // n'existait : des références pendantes. La semaine active est désormais enveloppée dans un
  // TabPanel, et la primitive ne pose `aria-controls` que sur l'onglet actif → tout lien résout.
  it("A11Y-23 — chaque aria-controls d'onglet référence un panneau présent", () => {
    render(<TypicalWeekendGrid habits={[habit({ teamId: "ta", venueId: "v9", kickoffTime: "20:30", week: "A" })]} venues={VENUES} teams={TEAMS} durations={DURATIONS} weekendAlternates={true} />);
    const tabs = screen.getAllByRole("tab");
    expect(tabs.length).toBeGreaterThan(1);
    for (const tab of tabs) {
      const controls = tab.getAttribute("aria-controls");
      if (null !== controls) {
        expect(document.getElementById(controls), `aria-controls="${controls}" doit exister`).not.toBeNull();
      }
    }
    expect(screen.getByRole("tabpanel")).toBeInTheDocument();
  });

  // A11Y-24 — la grille défile ; une région défilante doit être focusable au clavier et nommée
  // (WCAG 2.1.1). Le div `overflow-auto` porte lui-même tabIndex/role="region"/aria-label.
  it("A11Y-24 — la grille défilante est une région focusable et nommée", () => {
    render(<TypicalWeekendGrid habits={[habit({ teamId: "ta", venueId: "v9", kickoffTime: "20:30", week: "A" })]} venues={VENUES} teams={TEAMS} durations={DURATIONS} weekendAlternates={false} />);
    const region = screen.getByRole("region", { name: "Grille de la semaine type" });
    expect(region).toHaveClass("overflow-auto");
    expect(region).toHaveAttribute("tabindex", "0");
  });
});
