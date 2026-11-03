import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { StalePlanBanner } from "./StalePlanBanner";
import { PLANNING_TEXTS } from "./texts";

/**
 * Promesse (chantier « Fermetures de gymnase », PR 1, C5) : sur l'écran d'un plan de période qu'une
 * fermeture DÉCLARÉE rend périmé (il garde des séances au gymnase fermé ces jours-là), le gestionnaire
 * lit un bandeau qui nomme le gymnase, les jours et les équipes, et la consigne (« Rouvrez-le, puis
 * régénérez ») — armé depuis l'entrée du plan AFFICHÉ, jamais pour un autre plan, jamais pour un plan
 * sans version. Le bandeau AFFICHE `governingPlans[].sessions` servi par `/impact` (le serveur seul
 * calcule ; parité gardée par `ClosureImpactSaysTheSameEverywhereTest`) ; il est DISTINCT de
 * `stalenessMessage` (`lib/staleness.ts`, muet sur un plan validé — `staleness.test.ts`).
 *
 * Le texte passe par la clé `PLANNING_TEXTS.staleByClosure` (signature à figer par NEO) : ce test
 * vérifie les DONNÉES qu'elle doit porter (gymnase, jour j/n, équipe), pas la phrase.
 */

const governingPlan = (over: Record<string, unknown> = {}) => ({
  entryId: "e-mars",
  title: "Vacances de mars",
  startDate: "2999-03-02",
  endDate: "2999-03-22",
  chosenScheduleId: "s-mars",
  loadedScheduleId: "s-mars",
  hasVersions: true,
  sessions: [{ teamId: "t-sm1", teamName: "SM1", date: "2999-03-10", venueId: "v-mistral" }],
  toRemove: { locks: 0, reservations: [] },
  regeneratedWithout: false,
  ...over,
});

const impact = (plans: ReturnType<typeof governingPlan>[]) => ({
  venueIds: ["v-mistral"],
  startDate: "2999-03-10",
  endDate: "2999-03-10",
  seasonPlanChosen: true,
  teams: [],
  trainingOccurrences: 0,
  trainingSlotCount: 0,
  matchesToMove: [],
  governingPlans: plans,
  fullyGoverned: true,
  closedSessions: [{ constraintId: "k1", venueId: "v-mistral", venueName: "Mistral", closureTitle: "Mistral indisponible", date: "2999-03-10", teamId: "t-sm1", teamName: "SM1" }],
});

describe("StalePlanBanner — un plan périmé par une fermeture le dit sur son écran (C5)", () => {
  it("la clé de texte existe", () => {
    expect(typeof PLANNING_TEXTS.staleByClosure).toBe("function");
  });

  it("plan affiché touché : bandeau qui nomme gymnase, jour et équipe", () => {
    render(<StalePlanBanner impact={impact([governingPlan()])} planEntryId="e-mars" />);
    const banner = screen.getByRole("status");
    expect(banner).toHaveTextContent("Mistral");
    expect(banner).toHaveTextContent("10/3");
    expect(banner).toHaveTextContent("SM1");
  });

  it("plan affiché NON touché (aucune séance au gymnase fermé) : aucun bandeau", () => {
    const { container } = render(<StalePlanBanner impact={impact([governingPlan({ sessions: [] })])} planEntryId="e-mars" />);
    expect(container).toBeEmptyDOMElement();
  });

  it("un AUTRE plan est touché : rien sur l'écran de celui-ci", () => {
    const { container } = render(<StalePlanBanner impact={impact([governingPlan({ entryId: "e-noel" })])} planEntryId="e-mars" />);
    expect(container).toBeEmptyDOMElement();
  });

  it("plan sans version : rien (aucune séance à périmer)", () => {
    const { container } = render(
      <StalePlanBanner impact={impact([governingPlan({ chosenScheduleId: null, loadedScheduleId: null, hasVersions: false, sessions: [] })])} planEntryId="e-mars" />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it("impact pas encore lu : rien (jamais un faux « périmé »)", () => {
    const { container } = render(<StalePlanBanner impact={null} planEntryId="e-mars" />);
    expect(container).toBeEmptyDOMElement();
  });
});
