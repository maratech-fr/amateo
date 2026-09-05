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
// Le bandeau lit le NOM du plan sur me.seasonPlan (retour fondateur 2026-07-18).
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { seasonPlan: { name: "Planning de la saison 2026-2027" } } }) }));

const navigate = vi.fn();
vi.mock("react-router", async (orig) => ({ ...(await orig<typeof import("react-router")>()), useNavigate: () => navigate }));

const chosen: Schedule = { id: "b1", name: "Socle", status: "COMPLETED", score: 9011, createdAt: "", updatedAt: "", planType: "SEASON", schedulePlanId: "season-plan", isChosen: true };

import { SeasonPlanBanner } from "./SeasonPlanBanner";

function renderBanner() {
  return render(
    <MemoryRouter>
      <SeasonPlanBanner schedules={[chosen]} socleValidated />
    </MemoryRouter>,
  );
}

const seasonPlan = (staleness: unknown) => ({ id: "season-plan", type: "SEASON", name: "Saison", startDate: "2026-07-15", calendarEntryId: null, chosenScheduleId: "b1", teamSelectionInitialized: false, staleness });

describe("SeasonPlanBanner", () => {
  it("offers only « Ouvrir » (no « Modifier… » — modification happens on the planning page)", () => {
    renderBanner();
    expect(screen.getByRole("button", { name: "Ouvrir" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Modifier/ })).not.toBeInTheDocument();
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
    expect(screen.queryByText("Planning principal")).not.toBeInTheDocument();
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
});
