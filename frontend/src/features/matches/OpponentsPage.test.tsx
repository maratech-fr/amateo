import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

// La carte est testée à part ; ici on prouve seulement que la PAGE la porte, sous son titre.
vi.mock("./OpponentTravelCard", () => ({ OpponentTravelCard: () => <div>OPPONENT_TRAVEL_CARD</div> }));

import { OpponentsPage } from "./OpponentsPage";

describe("OpponentsPage (C8 — l'onglet Adversaires)", () => {
  it("porte le titre « Adversaires » et rend la carte des trajets adverses", () => {
    render(<OpponentsPage />);
    expect(screen.getByRole("heading", { name: "Adversaires" })).toBeInTheDocument();
    expect(screen.getByText("OPPONENT_TRAVEL_CARD")).toBeInTheDocument();
  });
});
