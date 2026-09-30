import { fireEvent, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { renderWithProviders } from "@/test/utils";

import { HelpButton } from "./help-button";

describe("HelpButton — l'aide contextuelle (i)", () => {
  it("n'affiche rien tant qu'on ne clique pas, puis ouvre la modale titrée avec son contenu", () => {
    renderWithProviders(
      <HelpButton label="À quoi sert cette étape ?" triggerLabel="Comprendre cette étape">
        <p>Le contenu d'aide.</p>
      </HelpButton>,
    );
    // Fermée au départ.
    expect(screen.queryByText("À quoi sert cette étape ?")).toBeNull();
    expect(screen.queryByText("Le contenu d'aide.")).toBeNull();

    fireEvent.click(screen.getByLabelText("Comprendre cette étape"));
    expect(screen.getByText("À quoi sert cette étape ?")).toBeInTheDocument();
    expect(screen.getByText("Le contenu d'aide.")).toBeInTheDocument();
  });

  it("se ferme avec le bouton Fermer", () => {
    renderWithProviders(
      <HelpButton label="À quoi sert cet écran ?" triggerLabel="Comprendre cet écran">
        <p>Autre contenu.</p>
      </HelpButton>,
    );
    fireEvent.click(screen.getByLabelText("Comprendre cet écran"));
    expect(screen.getByText("Autre contenu.")).toBeInTheDocument();
    // Le bouton « Fermer » du pied (getByText le distingue de la croix, dont « Fermer » est un aria-label).
    fireEvent.click(screen.getByText("Fermer"));
    expect(screen.queryByText("Autre contenu.")).toBeNull();
  });
});
