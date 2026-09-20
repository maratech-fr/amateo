import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { AwayTravel } from "./api";
import { AwayTravelChip } from "./AwayTravelChip";
import { awayTravelTitle } from "./lib/awayTravelTitle";

const travel = (over: Partial<AwayTravel>): AwayTravel => ({
  venueLabel: "Gymnase du Clar",
  city: null,
  precision: "VENUE",
  oneWayMinutes: 22,
  approximated: false,
  basis: "linked",
  ...over,
});

describe("AwayTravelChip — le trajet + le lieu d'une rencontre AWAY (dérivé de la rencontre)", () => {
  it("linked : le gymnase exact + les minutes exactes, sans aucun repli", () => {
    render(<AwayTravelChip travel={travel({})} />);
    expect(screen.getByText("Gymnase du Clar")).toBeInTheDocument();
    expect(screen.getByText("22 min")).toBeInTheDocument();
    expect(screen.queryByText("approché")).not.toBeInTheDocument();
    expect(screen.queryByText("gymnase supposé")).not.toBeInTheDocument();
    expect(screen.getByLabelText(/En voiture — trajet estimé à 22 minutes jusqu'à Gymnase du Clar/)).toBeInTheDocument();
  });

  it("most_frequent : « gymnase supposé » + minutes préfixées de ~ et le mot « approché »", () => {
    render(<AwayTravelChip travel={travel({ basis: "most_frequent", venueLabel: "Gymnase Principal", oneWayMinutes: 30, approximated: true })} />);
    expect(screen.getByText("Gymnase Principal")).toBeInTheDocument();
    expect(screen.getByText("gymnase supposé")).toBeInTheDocument();
    expect(screen.getByText("~30 min")).toBeInTheDocument();
    expect(screen.getByText("approché")).toBeInTheDocument();
  });

  it("city : « ville seule » sur la commune, minutes approchées", () => {
    render(<AwayTravelChip travel={travel({ basis: "city", venueLabel: null, city: "Meyzieu", precision: "CITY", oneWayMinutes: 35, approximated: true })} />);
    expect(screen.getByText("Meyzieu")).toBeInTheDocument();
    expect(screen.getByText("ville seule")).toBeInTheDocument();
    expect(screen.getByText("~35 min")).toBeInTheDocument();
    expect(screen.getByText("approché")).toBeInTheDocument();
  });

  it("rencontre sans trajet (null) : « lieu inconnu », aucun trajet", () => {
    render(<AwayTravelChip travel={null} />);
    expect(screen.getByText("lieu inconnu")).toBeInTheDocument();
    expect(screen.queryByText(/min/)).not.toBeInTheDocument();
  });

  it("lieu connu mais trajet non calculé : « trajet indisponible » muted, jamais role=alert", () => {
    render(<AwayTravelChip travel={travel({ oneWayMinutes: null })} />);
    expect(screen.getByText("· trajet indisponible")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("le titre de secours épelle le trajet en toutes lettres", () => {
    expect(awayTravelTitle(travel({}))).toBe("Gymnase du Clar · 22 min");
    expect(awayTravelTitle(travel({ basis: "city", venueLabel: null, city: "Meyzieu", precision: "CITY", oneWayMinutes: 35, approximated: true }))).toBe(
      "ville de Meyzieu · ~35 min (approché)",
    );
    expect(awayTravelTitle(null)).toBe("lieu inconnu");
  });
});
