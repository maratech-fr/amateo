import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { describe, expect, it, vi } from "vitest";

import type { Schedule } from "@/features/planning/api";

// Stub the modal (its own deps — ExportMenu/useVenues/store — are tested apart).
vi.mock("./SeasonSchedulesModal", () => ({
  SeasonSchedulesModal: ({ onClose }: { onClose: () => void }) => (
    <div role="dialog">
      Plannings de la saison
      <button onClick={onClose}>Fermer</button>
    </div>
  ),
}));
vi.mock("./seasonPlannings", () => ({ seasonPlanCounts: () => ({ total: 2, overlays: 1, openOverlays: 0 }) }));
let plansData: unknown[] = [];
vi.mock("./queries", () => ({ useSchedulePlans: () => ({ data: plansData }) }));
// P4-269 — le bandeau résume le radar « personne à deux endroits » du planning en vigueur.
let placedConflictsData: { conflicts: { personId: string }[] } | undefined = { conflicts: [] };
vi.mock("@/features/planning/queries", () => ({ usePlacedConflicts: () => ({ data: placedConflictsData }) }));
// Le bandeau lit le NOM du plan sur me.seasonPlan (retour fondateur 2026-07-18).
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { seasonPlan: { name: "Planning de la saison 2026-2027" } } }) }));

const navigate = vi.fn();
vi.mock("react-router", async (orig) => ({ ...(await orig<typeof import("react-router")>()), useNavigate: () => navigate }));

const chosen: Schedule = { id: "b1", name: "Socle", status: "COMPLETED", score: 9011, createdAt: "", updatedAt: "", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true };

import { SeasonPlanBanner } from "./SeasonPlanBanner";
import { useWizardStore } from "@/features/wizard/store";

function renderBanner(socleValidated = true) {
  return render(
    <MemoryRouter>
      <SeasonPlanBanner schedules={[chosen]} socleValidated={socleValidated} />
    </MemoryRouter>,
  );
}

const seasonPlan = (staleness: unknown) => ({ id: "season-plan", type: "SEASON", name: "Saison", startDate: "2026-07-15", calendarEntryId: null, chosenScheduleId: "b1", teamSelectionInitialized: false, staleness });

describe("SeasonPlanBanner", () => {
  // Décision fondateur 2026-09-28 : le bandeau offre « Modifier les données du club » DÈS QUE le
  // socle est validé — compléter le modèle (coachs tardifs, équipes, gymnases) sans « Rouvrir ».
  it("offers « Modifier les données du club » when the socle is validated", () => {
    renderBanner();
    expect(screen.getByRole("button", { name: "Ouvrir" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Modifier les données du club" })).toBeInTheDocument();
  });

  it("hides « Modifier les données du club » while the socle is NOT validated (still edited in the wizard)", () => {
    renderBanner(false);
    expect(screen.getByRole("button", { name: "Ouvrir" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Modifier les données/ })).not.toBeInTheDocument();
  });

  it("« Modifier les données du club » opens the wizard on the Équipes step, out of any period mode", async () => {
    // Un mode période résiduel ferait éditer la PÉRIODE, pas la saison : le geste sort du mode
    // période et vise l'étape Équipes (décision fondateur), puis ouvre le wizard.
    useWizardStore.setState({ mode: "period", calendarEntryId: "entry-x", stepId: "constraints" });
    renderBanner();
    await userEvent.click(screen.getByRole("button", { name: "Modifier les données du club" }));
    expect(navigate).toHaveBeenCalledWith("/wizard");
    expect(useWizardStore.getState().mode).toBe("season");
    expect(useWizardStore.getState().stepId).toBe("teams");
  });

  it("n'affiche PAS le score du solveur (P4-39, décision fermée)", () => {
    // La fixture porte `score: 9011` : si le bandeau le remettait, ce test le verrait.
    // Sans lui, le retrait n'était gardé nulle part — une décision fermée que rien
    // n'empêchait de défaire par accident (revue #350).
    renderBanner();

    expect(screen.queryByText(/score/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/9011/)).not.toBeInTheDocument();
    // …et le statut, lui, reste : on retire le score, pas la ligne qui le portait.
    expect(screen.getByText(/Terminé/)).toBeInTheDocument();
  });

  it("titles the strip with the plan's REAL name, not a generic label", () => {
    renderBanner();
    expect(screen.getByText("Planning de la saison 2026-2027")).toBeInTheDocument();
    expect(screen.queryByText("Planning de saison")).not.toBeInTheDocument();
  });

  it("« Ouvrir » navigates to the planning (validated socle)", async () => {
    renderBanner();
    await userEvent.click(screen.getByRole("button", { name: "Ouvrir" }));
    expect(navigate).toHaveBeenCalledWith("/planning");
  });

  it("« Tous les plannings (N) » opens the plannings modal, counting distinct plannings", async () => {
    plansData = [];
    renderBanner();
    await userEvent.click(screen.getByRole("button", { name: /Tous les plannings \(2\)/ }));
    expect(screen.getByRole("dialog")).toHaveTextContent("Plannings de la saison");
  });

  it("P4-173 — shows the « à régénérer » pill in the subtitle when the SEASON plan is stale", () => {
    plansData = [seasonPlan({ manuallyEdited: false, constraintsChanged: true, resourcesChanged: false })];
    renderBanner();
    expect(screen.getByText("À régénérer — une contrainte a changé")).toBeInTheDocument();
  });

  it("P4-173 — no pill when the SEASON plan carries no staleness (null: unpointed / past)", () => {
    plansData = [seasonPlan(null)];
    renderBanner();
    expect(screen.queryByText(/À régénérer/)).not.toBeInTheDocument();
  });

  it("P4-269 — shows a pill counting the DISTINCT people caught in a live conflict", () => {
    // Deux conflits mais UNE seule personne (elle est prise dans les deux) → « 1 personne ».
    placedConflictsData = { conflicts: [{ personId: "anna" }, { personId: "anna" }, { personId: "bob" }] };
    renderBanner();
    expect(screen.getByText("2 personnes à deux endroits")).toBeInTheDocument();
    placedConflictsData = { conflicts: [] };
  });

  it("P4-269 — no pill when nobody is double-booked", () => {
    placedConflictsData = { conflicts: [] };
    renderBanner();
    expect(screen.queryByText(/à deux endroits/)).not.toBeInTheDocument();
  });
});
