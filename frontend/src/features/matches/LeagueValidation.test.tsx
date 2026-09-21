import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { LeagueValidationOutlook } from "./api";
import { LeagueValidationBanner } from "./LeagueValidation";

// On pilote les hooks PROD, jamais le réseau (patron EntryDeadlinesEditor).
const { outlookState, confirmMutate } = vi.hoisted(() => ({
  outlookState: { current: undefined as LeagueValidationOutlook | undefined },
  confirmMutate: vi.fn(),
}));

vi.mock("./queries", () => ({
  useLeagueValidationOutlook: () => ({ data: outlookState.current }),
  useConfirmLeagueValidatedFixtures: () => ({ mutate: confirmMutate, isPending: false }),
}));

const EMPTY: LeagueValidationOutlook = { matured: [], toTreat: [], missingDeadline: [], totalValidatable: 0 };

beforeEach(() => {
  outlookState.current = undefined;
  confirmMutate.mockReset();
});

describe("LeagueValidationBanner — trois états servis par l'échéance (lot O)", () => {
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

  it("championnats échus avec validables ⇒ la confirmation liste chaque championnat + un seul oui", async () => {
    outlookState.current = {
      ...EMPTY,
      totalValidatable: 12,
      matured: [{ competitionId: "c1", name: "PNM", deadline: "2026-11-10", deadlineSource: "club", validatableCount: 12 }],
    };
    const user = userEvent.setup();
    renderWithProviders(<LeagueValidationBanner />);

    expect(screen.getByText(/à confirmer « validé ligue »/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Marquer « validé ligue »/ }));
    const dialog = await screen.findByRole("dialog");
    // Le DÉTAIL par championnat (nom + échéance + compte).
    expect(within(dialog).getByText(/PNM — échéance .* — 12 à valider/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: /Marquer « validé ligue »/ }));
    expect(confirmMutate).toHaveBeenCalledOnce();
  });

  it("un championnat échu dont TOUT reste à traiter ⇒ pas de bouton de validation, rencontres NOMMÉES + renvoi vers la file", () => {
    outlookState.current = {
      ...EMPTY,
      toTreat: [{ fixtureId: "f1", teamId: "t1", competitionName: "U13 Départemental", matchDate: "2026-10-04", opponentLabel: "ASVEL", reason: "NO_VENUE" }],
    };
    renderWithProviders(<LeagueValidationBanner />);

    // Rien à valider : aucun geste de validation ne s'offre.
    expect(screen.queryByRole("button", { name: /Marquer « validé ligue »/ })).not.toBeInTheDocument();
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
});
