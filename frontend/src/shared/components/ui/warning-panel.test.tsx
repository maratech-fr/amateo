import { render, screen } from "@testing-library/react";
import { CalendarClock } from "lucide-react";
import { describe, expect, it } from "vitest";

import { WarningPanel } from "./warning-panel";

/**
 * P4-127 (b) — une seule boîte d'avertissement pour tout le produit. Ce test garde ce qui doit
 * rester VRAI quel que soit l'appelant : la boîte est identique, l'icône est décorative, et le
 * texte porte seul le sens (contrainte a11y maison — jamais la couleur ni l'icône seules).
 */
describe("WarningPanel — la boîte d'avertissement partagée", () => {
  it("rend son contenu, et la même boîte quel que soit l'appelant", () => {
    const { container: withIcon } = render(<WarningPanel icon={<CalendarClock className="size-4 text-warning" />} message="Déjà planifié." />);
    const boxed = withIcon.firstElementChild;
    expect(screen.getByText("Déjà planifié.")).toBeInTheDocument();

    const { container: bare } = render(<WarningPanel message="Sous vacances." />);
    // La MÊME boîte : c'est tout l'objet du lot — deux bordures différentes cohabitaient.
    expect(bare.firstElementChild?.className).toBe(boxed?.className);
  });

  it("l'icône est DÉCORATIVE : hors de l'arbre accessible, le texte porte seul le sens", () => {
    render(<WarningPanel icon={<CalendarClock data-testid="ico" />} message="Le texte dit tout." />);

    expect(screen.getByTestId("ico").closest("[aria-hidden]")).not.toBeNull();
    expect(screen.getByText("Le texte dit tout.")).toBeInTheDocument();
  });

  it("une ACTION vit à côté du texte, jamais dedans : un <div> dans un <p> est invalide", () => {
    const { container } = render(
      <WarningPanel icon={<CalendarClock />} message="Déjà planifié.">
        <div data-testid="action">
          <button type="button">Ouvrir</button>
        </div>
      </WarningPanel>,
    );

    // L'action est un FRÈRE du paragraphe, pas son enfant — sinon le navigateur referme le <p>
    // tout seul et la mise en page casse (piège mordu au premier jet de cette primitive).
    expect(container.querySelector("p [data-testid='action']")).toBeNull();
    expect(screen.getByTestId("action")).toBeInTheDocument();
  });

  it("n'est PAS une alerte : ni role=alert ni région live (l'appelant décide de l'annonce)", () => {
    const { container } = render(<WarningPanel message="État stable, pas un événement." />);

    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    expect(container.querySelector("[aria-live]")).toBeNull();
  });
});
