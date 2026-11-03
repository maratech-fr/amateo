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
// L'étape Équipes est isolée : seul le rendu des erreurs d'étape par `WizardLayout` est en jeu.
vi.mock("./steps/TeamsStep", () => ({ TeamsStep: () => <div /> }));

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

  /**
   * Un Membre ne reçoit jamais un ORDRE qu'il ne peut exécuter : sur une étape de saisie incomplète
   * (« Ajoutez au moins une équipe. »), il lit `WIZARD_TEXTS.readOnlyIncomplete`, en neutre — ni
   * alerte (`role="alert"`), ni rouge. Témoin : le gestionnaire garde l'erreur actionnable, en alerte.
   */
  it("Membre, étape incomplète : « un gestionnaire doit la compléter » en neutre, jamais l'ordre rouge", async () => {
    h.canManage = false;
    h.blockers = ["Ajoutez au moins une équipe."];
    useWizardStore.setState({ stepId: "teams", maxIndex: 0, mode: "season", calendarEntryId: null });
    renderWithProviders(<WizardPage />, { route: "/wizard?step=teams" });

    const notice = await screen.findByText(WIZARD_TEXTS.readOnlyIncomplete);
    expect(notice.closest("[role='alert']")).toBeNull();
    expect(notice.closest(".text-destructive")).toBeNull();
    expect(screen.queryByText("Ajoutez au moins une équipe.")).not.toBeInTheDocument();
    expect(screen.queryAllByRole("alert")).toHaveLength(0);
  });

  it("témoin gestionnaire, étape incomplète : l'erreur actionnable reste, en alerte", async () => {
    h.blockers = ["Ajoutez au moins une équipe."];
    useWizardStore.setState({ stepId: "teams", maxIndex: 0, mode: "season", calendarEntryId: null });
    renderWithProviders(<WizardPage />, { route: "/wizard?step=teams" });

    expect(await screen.findByRole("alert")).toHaveTextContent("Ajoutez au moins une équipe.");
    expect(screen.queryByText(WIZARD_TEXTS.readOnlyIncomplete)).not.toBeInTheDocument();
  });

  it("Membre, configuration incomplète : il lit « un gestionnaire doit la compléter », jamais la raison du gestionnaire", async () => {
    h.canManage = false;
    h.blockers = ["Groupe U15, U13 : aucun créneau."];
    renderWithProviders(<WizardPage />, { route: "/wizard?step=recap" });
    expect(await screen.findByText(WIZARD_TEXTS.readOnlyIncomplete)).toBeInTheDocument();
    expect(screen.queryByText(WIZARD_TEXTS.generationBlocked)).not.toBeInTheDocument();
  });
});
