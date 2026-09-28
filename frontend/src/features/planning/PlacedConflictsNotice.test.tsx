import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PlacedConflict } from "./api";
import { PlacedConflictsNotice } from "./PlacedConflictsNotice";

const conflict = (over: Partial<PlacedConflict> = {}): PlacedConflict => ({
  personId: "p1",
  personName: "Anna Dupont",
  dayOfWeek: 2,
  first: { teamId: "tA", teamName: "U13F", venueId: "vB", venueName: "Gymnase B", startTime: "18h00" },
  second: { teamId: "tB", teamName: "U11M1", venueId: "vA", venueName: "Gymnase A", startTime: "18h00" },
  ...over,
});

describe("PlacedConflictsNotice", () => {
  it("ne rend RIEN quand il n'y a aucun conflit", () => {
    const { container } = renderWithProviders(<PlacedConflictsNotice conflicts={[]} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("liste chaque conflit avec sa phrase, et titre au singulier pour un seul", () => {
    renderWithProviders(<PlacedConflictsNotice conflicts={[conflict()]} />);
    expect(screen.getByText("Une personne est à deux endroits en même temps")).toBeInTheDocument();
    expect(
      screen.getByText("Anna Dupont est à deux endroits le mardi à 18h00 (U13F · Gymnase B / U11M1 · Gymnase A)"),
    ).toBeInTheDocument();
  });

  it("titre au pluriel avec le compte quand plusieurs personnes sont concernées", () => {
    renderWithProviders(
      <PlacedConflictsNotice conflicts={[conflict(), conflict({ personId: "p2", personName: "Bob Martin" })]} />,
    );
    expect(screen.getByText("2 personnes sont à deux endroits en même temps")).toBeInTheDocument();
    expect(screen.getByText(/Bob Martin est à deux endroits/)).toBeInTheDocument();
  });
});
