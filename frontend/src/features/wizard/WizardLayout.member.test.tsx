import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SESSION_TEXTS } from "@/shared/session/texts";
import { renderWithProviders } from "@/test/utils";

/**
 * Chantier Membre (PR 2) — l'assistant d'un Membre est une CONSULTATION :
 *  - la bannière `SESSION_TEXTS.readOnlyNotice` est portée UNE fois par `WizardLayout` (toutes étapes) ;
 *  - jamais « guidé » : un club sans planning ne verrouille pas les étapes suivantes d'un Membre
 *    (il ne peut pas compléter la saisie, l'enfermer à l'étape 1 le bloquerait) — `guided = canManage && …` ;
 *  - jamais « bloqué » par le Récap : au lieu de la raison de gestionnaire
 *    (`WIZARD_TEXTS.generationBlocked`), il lit `WIZARD_TEXTS.readOnlyIncomplete` — `blocked = canManage && …`.
 * Témoin gestionnaire dans chaque cas (même décor), et jumeau voulu :
 * `WizardLayout.generationBlocked.test.tsx` (la raison du gestionnaire).
 */
const h = vi.hoisted(() => ({ blockers: [] as string[], canManage: true }));

vi.mock("@/shared/session/queries", () => ({
  // Club SANS planning (seasonPlan null) : l'assistant serait « guidé » pour un gestionnaire.
  useMe: () => ({ data: { role: h.canManage ? "admin" : "member", membershipStatus: "active", seasonPlan: null, club: { id: "c", name: "Club" } } }),
  useWorkingSeason: () => null,
  useCanManage: () => h.canManage,
}));
vi.mock("./lib/useStepValidation", () => ({
  useStepValidation: () => ({ errors: h.blockers, warnings: [], pending: false }),
}));
vi.mock("./steps/RecapStep", () => ({ RecapStep: () => <div /> }));

import { useWizardStore } from "./store";
import { WIZARD_TEXTS } from "./texts";
import { WizardPage } from "./WizardLayout";

beforeEach(() => {
  h.blockers = [];
  h.canManage = true;
  useWizardStore.setState({ stepId: "recap", maxIndex: 0, mode: "season", calendarEntryId: null });
});

describe("WizardLayout — Membre (consultation)", () => {
  it("témoin gestionnaire : club sans planning → étapes suivantes verrouillées, aucune bannière", async () => {
    renderWithProviders(<WizardPage />, { route: "/wizard?step=recap" });
    await waitFor(() => expect(screen.getByRole("button", { name: /Coachs/ })).toBeDisabled());
    expect(screen.queryByText(SESSION_TEXTS.readOnlyNotice)).not.toBeInTheDocument();
  });

  it("Membre : bannière de consultation, et navigation libre dans toutes les étapes", async () => {
    h.canManage = false;
    renderWithProviders(<WizardPage />, { route: "/wizard?step=recap" });
    expect(await screen.findByText(SESSION_TEXTS.readOnlyNotice)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Gymnases/ })).toBeEnabled();
    expect(screen.getByRole("button", { name: /Coachs/ })).toBeEnabled();
    expect(screen.getByRole("button", { name: /Contraintes/ })).toBeEnabled();
  });

  it("Membre, configuration incomplète : il lit « un gestionnaire doit la compléter », jamais la raison du gestionnaire", async () => {
    h.canManage = false;
    h.blockers = ["Groupe U15, U13 : aucun créneau."];
    renderWithProviders(<WizardPage />, { route: "/wizard?step=recap" });
    expect(await screen.findByText(WIZARD_TEXTS.readOnlyIncomplete)).toBeInTheDocument();
    expect(screen.queryByText(WIZARD_TEXTS.generationBlocked)).not.toBeInTheDocument();
  });
});
