import { screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PlacedAbsence } from "./api";
import { PlacedAbsencesNotice } from "./PlacedAbsencesNotice";
import { PLANNING_TEXTS } from "./texts";

const absence = (over: Partial<PlacedAbsence> = {}): PlacedAbsence => ({
  scheduleId: "s1",
  dayOfWeek: 1,
  startTime: "18:00",
  venueName: "Nord",
  teamNames: ["U15"],
  coachNames: ["Anna Martin"],
  ...over,
});

/** Promesse : une séance dont aucun coach n'est disponible à son début est dite au gestionnaire, une ligne par case. */
describe("PlacedAbsencesNotice", () => {
  it("les libellés sont ceux validés, mot pour mot (singulier, pluriel, groupe)", () => {
    expect(PLANNING_TEXTS.absencesHeading(1)).toBe("Une séance sans coach");
    expect(PLANNING_TEXTS.absencesHeading(3)).toBe("3 séances sans coach");
    expect(PLANNING_TEXTS.absenceLine(absence())).toBe("Anna Martin est indisponible le lundi à 18:00 : personne pour tenir U15 (Nord).");
    expect(PLANNING_TEXTS.absenceLine(absence({ coachNames: ["Anna Martin", "Ben Durand"] }))).toBe("Anna Martin et Ben Durand sont indisponibles le lundi à 18:00 : personne pour tenir U15 (Nord).");
    expect(PLANNING_TEXTS.absenceLine(absence({ teamNames: ["U15", "U13"] }))).toBe("Anna Martin est indisponible le lundi à 18:00 : personne pour tenir U15 et U13 (Nord).");
  });

  it("ne rend rien sans absence", () => {
    const { container } = renderWithProviders(<PlacedAbsencesNotice absences={[]} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("titre et une ligne par séance, en alerte", () => {
    renderWithProviders(<PlacedAbsencesNotice absences={[absence(), absence({ dayOfWeek: 2, venueName: "Sud" })]} />);

    expect(screen.getByText(PLANNING_TEXTS.absencesHeading(2))).toBeInTheDocument();
    expect(within(screen.getByRole("list")).getAllByRole("listitem")).toHaveLength(2);
    expect(screen.getByText(PLANNING_TEXTS.absenceLine(absence()))).toBeInTheDocument();
  });
});
