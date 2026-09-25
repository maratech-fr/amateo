import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { BrandIcon } from "./brand-icon";

// Nombres DÉFINITIFS du handoff marque (business/7-marque/design_handoff_logo_loaders) —
// magenta / orange / teal, dans l'ordre de dessin. Toute dérive ici est une régression de marque.
const ARCS = [
  { stroke: "#B51C8A", r: "313", width: "83", dash: "133 400", transform: "rotate(98 500 500)" },
  { stroke: "#D47800", r: "325", width: "66", dash: "69 400", transform: "rotate(252 500 500)" },
  { stroke: "#46AFAC", r: "332", width: "50", dash: "89 400", transform: "rotate(334.5 500 500)" },
];

describe("BrandIcon", () => {
  it("rend les trois arcs aux rayons / épaisseurs / angles / couleurs exacts du handoff", () => {
    const { container } = render(<BrandIcon />);
    const circles = container.querySelectorAll("circle");
    expect(circles).toHaveLength(3);

    ARCS.forEach((arc, i) => {
      const c = circles[i];
      expect(c.getAttribute("cx")).toBe("500");
      expect(c.getAttribute("cy")).toBe("500");
      expect(c.getAttribute("r")).toBe(arc.r);
      expect(c.getAttribute("stroke")).toBe(arc.stroke);
      expect(c.getAttribute("stroke-width")).toBe(arc.width);
      expect(c.getAttribute("stroke-linecap")).toBe("round");
      expect(c.getAttribute("pathLength")).toBe("360");
      expect(c.getAttribute("stroke-dasharray")).toBe(arc.dash);
      expect(c.getAttribute("transform")).toBe(arc.transform);
      expect(c.getAttribute("fill")).toBe("none");
    });
  });

  it("est décoratif par défaut (aria-hidden) et sans disque blanc", () => {
    const { container } = render(<BrandIcon />);
    const svg = container.querySelector("svg");
    expect(svg?.getAttribute("aria-hidden")).toBe("true");
    expect(svg?.getAttribute("viewBox")).toBe("0 0 1000 1000");
    // Le disque blanc appartient au favicon (vignette d'onglet), jamais à l'icône d'en-tête.
    expect(container.querySelector('circle[fill="#ffffff"]')).toBeNull();
    expect(container.querySelector("title")).toBeNull();
  });

  it("devient une image nommée quand `title` est fourni", () => {
    const { container } = render(<BrandIcon title="Amateo" />);
    const svg = container.querySelector("svg");
    expect(svg?.getAttribute("aria-hidden")).toBeNull();
    expect(svg?.getAttribute("role")).toBe("img");
    expect(container.querySelector("title")?.textContent).toBe("Amateo");
  });

  it("respecte la taille demandée", () => {
    const { container } = render(<BrandIcon size={40} className="text-accent" />);
    const svg = container.querySelector("svg");
    expect(svg?.getAttribute("width")).toBe("40");
    expect(svg?.getAttribute("height")).toBe("40");
    expect(svg?.getAttribute("class")).toBe("text-accent");
  });
});
