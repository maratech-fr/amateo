import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { OpponentTravel } from "./api";
import { AwayTravelChip } from "./AwayTravelChip";
import { awayTravelTitle } from "./lib/awayTravelTitle";

const travel = (over: Partial<OpponentTravel>): OpponentTravel => ({
  opponentOrganismeCode: "ORG",
  opponentLabel: "Adversaire",
  located: true,
  precision: "VENUE",
  locationName: "Gymnase du Clar",
  travelMinutes: 22,
  approximated: false,
  source: "AUTO",
  overrideVenueLabel: null,
  ...over,
});

describe("AwayTravelChip — le trajet + la précision en queue de ligne (P2-54 PR-3)", () => {
  it("VENUE : le gymnase exact + les minutes exactes, sans « approché »", () => {
    render(<AwayTravelChip travel={travel({})} />);
    expect(screen.getByText("Gymnase du Clar")).toBeInTheDocument();
    expect(screen.getByText("22 min")).toBeInTheDocument();
    expect(screen.queryByText("approché")).not.toBeInTheDocument();
    // a11y : le trajet voiture porte un aria-label nommant le lieu (patron TravelCell).
    expect(screen.getByLabelText(/En voiture — trajet estimé à 22 minutes jusqu'à Gymnase du Clar/)).toBeInTheDocument();
  });

  it("CITY : « ville de … », minutes préfixées de ~ et le mot « approché » (calculé serveur)", () => {
    render(<AwayTravelChip travel={travel({ precision: "CITY", locationName: "Meyzieu", travelMinutes: 35, approximated: true })} />);
    expect(screen.getByText("ville de Meyzieu")).toBeInTheDocument();
    expect(screen.getByText("~35 min")).toBeInTheDocument();
    expect(screen.getByText("approché")).toBeInTheDocument();
  });

  it("adversaire non localisé : « lieu inconnu », aucun trajet", () => {
    render(<AwayTravelChip travel={travel({ located: false, precision: null, locationName: null, travelMinutes: null, source: null })} />);
    expect(screen.getByText("lieu inconnu")).toBeInTheDocument();
    expect(screen.queryByText(/min/)).not.toBeInTheDocument();
  });

  it("lieu connu mais trajet non calculé : « trajet indisponible » muted, jamais role=alert", () => {
    render(<AwayTravelChip travel={travel({ travelMinutes: null })} />);
    expect(screen.getByText("· trajet indisponible")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("le titre de secours épelle le trajet en toutes lettres", () => {
    expect(awayTravelTitle(travel({}))).toBe("Gymnase du Clar · 22 min");
    expect(awayTravelTitle(travel({ precision: "CITY", locationName: "Meyzieu", travelMinutes: 35, approximated: true }))).toBe(
      "ville de Meyzieu · ~35 min (approché)",
    );
    expect(awayTravelTitle(undefined)).toBe("lieu inconnu");
  });
});
