import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

/**
 * Promesse : au Récap, « Continuer vers la génération » grisé dit pourquoi. Jumeau voulu :
 * `steps/GenerateStep.test.tsx` (le bouton de génération lui-même), `GenerationRefusesARecapBlockerTest` (le serveur refuse).
 */
const h = vi.hoisted(() => ({ blockers: [] as string[] }));

vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: null, club: { id: "c", name: "Club" } } }),
  useWorkingSeason: () => null,
}));
vi.mock("./lib/useStepValidation", () => ({
  useStepValidation: () => ({ errors: h.blockers, warnings: [], pending: false }),
}));
vi.mock("./steps/RecapStep", () => ({ RecapStep: () => <div /> }));

import { useWizardStore } from "./store";
import { WIZARD_TEXTS } from "./texts";
import { WizardPage } from "./WizardLayout";

beforeEach(() => {
  useWizardStore.setState({ stepId: "recap", mode: "season", calendarEntryId: null });
});

describe("WizardLayout — la porte de la génération dit pourquoi elle est fermée", () => {
  it("bloqué : bouton grisé et raison lisible", async () => {
    h.blockers = ["Groupe U15, U13 : aucun créneau."];
    renderWithProviders(<WizardPage />, { route: "/wizard?step=recap" });

    await waitFor(() => expect(screen.getByRole("button", { name: /Continuer vers la génération/ })).toBeDisabled());
    expect(screen.getByText(WIZARD_TEXTS.generationBlocked)).toBeInTheDocument();
  });

  it("témoin : aucun blocage, aucune raison", async () => {
    h.blockers = [];
    renderWithProviders(<WizardPage />, { route: "/wizard?step=recap" });

    await waitFor(() => expect(screen.getByRole("button", { name: /Continuer vers la génération/ })).toBeEnabled());
    expect(screen.queryByText("Des conditions bloquent la génération")).not.toBeInTheDocument();
  });
});
