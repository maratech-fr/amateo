import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { VenueTravelTime } from "../api";

const matrixState: { data: VenueTravelTime[] } = { data: [] };

vi.mock("../queries", () => ({
  useVenueTravelTimes: () => ({ data: matrixState.data }),
}));

import { TravelRuleNotice } from "./ImplicitRulesPanel";

const row: VenueTravelTime = { id: "r1", venueAId: "v1", venueBId: "v2", drivingMinutes: 15, walkingMinutes: null, drivingSource: "AUTO", walkingSource: null };

beforeEach(() => {
  matrixState.data = [];
});

describe("TravelRuleNotice — opt-in dérivé de la présence de matrice", () => {
  it("ABSENTE tant qu'aucune ligne de matrice n'existe", () => {
    matrixState.data = [];
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.queryByText("Trajet entre gymnases")).toBeNull();
  });

  it("PRÉSENTE dès qu'une ligne existe — informative, « Préféré · actif », sans réglage", () => {
    matrixState.data = [row];
    renderWithProviders(<TravelRuleNotice />);
    expect(screen.getByText("Trajet entre gymnases")).toBeInTheDocument();
    expect(screen.getByText("Préféré · actif")).toBeInTheDocument();
    // Lecture seule : aucun bouton d'intensité (pas de rail backend).
    expect(screen.queryByRole("button")).toBeNull();
  });
});
