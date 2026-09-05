import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { StalenessPill } from "./StalenessPill";

describe("StalenessPill (P4-173)", () => {
  it("renders nothing when staleness is null (backend already filtered past / unpointed)", () => {
    const { container } = render(<StalenessPill staleness={null} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when staleness is undefined (loosely-typed data)", () => {
    const { container } = render(<StalenessPill staleness={undefined} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("renders nothing when every flag is false (block present, nothing stale)", () => {
    const { container } = render(<StalenessPill staleness={{ manuallyEdited: false, constraintsChanged: false, resourcesChanged: false }} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows the full cause as visible text (not a title/tooltip) when a flag is set", () => {
    render(<StalenessPill staleness={{ manuallyEdited: false, constraintsChanged: true, resourcesChanged: false }} />);
    expect(screen.getByText("À régénérer — une contrainte a changé")).toBeInTheDocument();
  });

  it("is a stable state, not an event: no role=alert / aria-live, icon is aria-hidden", () => {
    const { container } = render(<StalenessPill staleness={{ manuallyEdited: true, constraintsChanged: true, resourcesChanged: true }} />);
    // Le texte visible EST l'annonce : pas de région live, pas d'alerte volée au lecteur d'écran.
    expect(container.querySelector('[role="alert"]')).toBeNull();
    expect(container.querySelector("[aria-live]")).toBeNull();
    // L'icône est décorative — le texte porte le sens.
    const icon = container.querySelector("svg");
    expect(icon).not.toBeNull();
    expect(icon?.getAttribute("aria-hidden")).toBe("true");
    // Enveloppe, ne tronque pas : jamais un nœud `truncate`.
    expect(container.querySelector(".truncate")).toBeNull();
  });
});
