import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Venue } from "../api";
import { ReservationPanel } from "./ReservationPanel";

// Les hooks de données sont mockés : ce test ne porte que sur le TEXTE d'intro du panneau
// (la survie des verrous à la régénération), pas sur la grille elle-même.
vi.mock("../queries", () => ({
  useGridSlots: () => ({ data: [] }),
  useReservations: () => ({ data: [] }),
  useSharedTrainingBlocks: () => ({ data: [] }),
  useWizardTeamCoaches: () => ({ data: new Map(), isPending: false, isError: false, refetch: vi.fn() }),
}));

vi.mock("@/features/cockpit/queries", () => ({
  useEntryConflicts: () => ({ data: { closures: [] }, isError: false, refetch: vi.fn() }),
}));

const venue: Venue = { id: "v1", name: "Gymnase A", color: "#00aa00", canSplit: false, isActive: true } as Venue;

describe("ReservationPanel — l'intro énonce la survie des verrous", () => {
  it("dit que les réservations survivent aux régénérations", () => {
    render(<ReservationPanel teams={[]} tiers={[]} venues={[venue]} schedulePlanId={null} />);

    // Le verrou HARD était déjà annoncé « honoré à la génération » ; il l'est AUSSI aux
    // régénérations — c'est ce que ce lot ajoute à l'écran.
    expect(screen.getByText(/régénér/i)).toBeInTheDocument();
  });
});
