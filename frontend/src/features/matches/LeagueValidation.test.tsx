import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { LeagueValidationOutlook } from "./api";
import { LeagueValidationBanner } from "./LeagueValidation";
import { MATCHES_TEXTS } from "./texts";

// On pilote les hooks PROD, jamais le réseau (patron EntryDeadlinesEditor).
const { outlookState, confirmMutate } = vi.hoisted(() => ({
  outlookState: { current: undefined as LeagueValidationOutlook | undefined },
  confirmMutate: vi.fn(),
}));

vi.mock("./queries", () => ({
  useLeagueValidationOutlook: () => ({ data: outlookState.current }),
  useConfirmLeagueValidatedFixtures: () => ({ mutate: confirmMutate, isPending: false }),
}));
// Chantier Membre (PR 2) : `useCanManage` piloté par cas (gestionnaire par défaut) ; le reste de la session reste réel.
const session = vi.hoisted(() => ({ canManage: true }));
vi.mock("@/shared/session/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/session/queries")>()),
  useCanManage: () => session.canManage,
}));

const EMPTY: LeagueValidationOutlook = { matured: [], toTreat: [], missingDeadline: [], totalValidatable: 0, swept: 0 };

beforeEach(() => {
  outlookState.current = undefined;
  confirmMutate.mockReset();
});

describe("LeagueValidationBanner — ce qui reste à traiter ou à renseigner, jamais à confirmer", () => {
  it("lecture absente (chargement/échec) ⇒ aucun bandeau", () => {
    outlookState.current = undefined;
    const { container } = renderWithProviders(<LeagueValidationBanner />);
    expect(container).toBeEmptyDOMElement();
  });

  it("rien à signaler ⇒ aucun bandeau", () => {
    outlookState.current = EMPTY;
    const { container } = renderWithProviders(<LeagueValidationBanner />);
    expect(container).toBeEmptyDOMElement();
  });

  // Un championnat commencé passe SEUL « Déjà dans FBI » à la lecture du gestionnaire : le bandeau
  // ne propose plus aucune confirmation. Des validables encore servis (lecture dont le balayage
  // n'a pas pu écrire) ne font naître ni bandeau ni geste — la lecture suivante les bascule.
  it("des validables servis sans rien à traiter ⇒ aucun bandeau, aucun geste de confirmation", () => {
    outlookState.current = {
      ...EMPTY,
      totalValidatable: 12,
      matured: [{ competitionId: "c1", name: "PNM", deadline: "2026-11-10", deadlineSource: "club", maturedBy: "deadline", firstMatchDate: null, validatableCount: 12 }],
    };
    const { container } = renderWithProviders(<LeagueValidationBanner />);

    expect(container).toBeEmptyDOMElement();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("des validables servis À CÔTÉ de rencontres à traiter ⇒ seul le bloc « à traiter » s'affiche", () => {
    outlookState.current = {
      ...EMPTY,
      totalValidatable: 12,
      matured: [{ competitionId: "c1", name: "PNM", deadline: "2026-11-10", deadlineSource: "club", maturedBy: "deadline", firstMatchDate: null, validatableCount: 12 }],
      toTreat: [{ fixtureId: "f1", teamId: "t1", competitionName: "U13 Départemental", matchDate: "2026-10-04", opponentLabel: "ASVEL", reason: "NO_KICKOFF" }],
    };
    renderWithProviders(<LeagueValidationBanner />);

    // Un seul bandeau (à traiter), un seul geste : « Traiter dans la file ».
    expect(screen.getAllByRole("status")).toHaveLength(1);
    expect(screen.getAllByRole("button")).toHaveLength(1);
    expect(screen.getByRole("button", { name: MATCHES_TEXTS.leagueTreatInQueue })).toBeInTheDocument();
  });

  it("un championnat échu dont TOUT reste à traiter ⇒ pas de bouton de validation, rencontres NOMMÉES + renvoi vers la file", () => {
    outlookState.current = {
      ...EMPTY,
      toTreat: [{ fixtureId: "f1", teamId: "t1", competitionName: "U13 Départemental", matchDate: "2026-10-04", opponentLabel: "ASVEL", reason: "NO_VENUE" }],
    };
    renderWithProviders(<LeagueValidationBanner />);

    // Rien à valider : aucun geste de validation ne s'offre.
    expect(screen.queryByRole("button", { name: /Marquer « déjà dans FBI »/ })).not.toBeInTheDocument();
    // Mais la rencontre est NOMMÉE (plus écartée en silence) et atteignable dans la file.
    expect(screen.getByText(/reste à traiter/)).toBeInTheDocument();
    expect(screen.getByText(/U13 Départemental vs ASVEL/)).toBeInTheDocument();
    expect(screen.getByText(/sans gymnase/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Traiter dans la file/ })).toBeInTheDocument();
  });

  it("un championnat SANS échéance avec des rencontres prêtes ⇒ signalé nommément + renvoi vers les échéances", () => {
    outlookState.current = {
      ...EMPTY,
      missingDeadline: [{ competitionId: "c9", name: "Régional U15", validatableCount: 3 }],
    };
    renderWithProviders(<LeagueValidationBanner />);

    expect(screen.getByText(/sans échéance/)).toBeInTheDocument();
    expect(screen.getByText(/Régional U15/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Échéances de saisie/ })).toBeInTheDocument();
  });

  /**
   * Chantier Membre (PR 2) : la vue « Déjà dans FBI » reste RÉSERVÉE côté serveur (sa lecture
   * ÉCRIT : amicaux passés et championnats commencés) — pour un Membre le bandeau n'existe pas,
   * même avec des rencontres à traiter (témoin : le cas « à traiter » ci-dessus, rendu au gestionnaire).
   */
  it("Membre : aucun bandeau, même avec des rencontres à traiter", () => {
    session.canManage = false;
    try {
      outlookState.current = {
        ...EMPTY,
        toTreat: [{ fixtureId: "f1", teamId: "t1", competitionName: "U13 Départemental", matchDate: "2026-10-04", opponentLabel: "ASVEL", reason: "NO_VENUE" }],
      };
      const { container } = renderWithProviders(<LeagueValidationBanner />);
      expect(container).toBeEmptyDOMElement();
    } finally {
      session.canManage = true;
    }
  });
});
