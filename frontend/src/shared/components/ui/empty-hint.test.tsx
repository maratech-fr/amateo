import { render, screen } from "@testing-library/react";
import { Landmark } from "lucide-react";
import { describe, expect, it } from "vitest";

import { EmptyBlock, EmptyHint, EmptyState } from "./empty-hint";

/**
 * UXC-17 (P4-117) — les TROIS étages du vide vivent dans UNE maison. `EmptyState`
 * (la Card « vue entière vide ») vivait en local dans `PlanningPage` avec ses deux
 * petits frères déjà partagés : un écran qui naissait vide re-inventait la Card au
 * lieu de la consommer. La promotion est mécanique — le rendu de PlanningPage ne
 * change pas d'un pixel (icône par défaut incluse).
 */
describe("EmptyState — la Card « vue entière vide », promue en primitive", () => {
  it("rend le titre, la description et une icône par défaut", () => {
    render(<EmptyState title="Aucun planning" description="Passez par l'assistant." />);

    expect(screen.getByText("Aucun planning")).toBeInTheDocument();
    expect(screen.getByText("Passez par l'assistant.")).toBeInTheDocument();
    // L'icône par défaut (CalendarX2) est décorative : présente, hors arbre accessible.
    expect(document.querySelector("svg")).not.toBeNull();
  });

  it("accepte une icône propre à l'écran — le défaut calendrier ne s'impose pas partout", () => {
    const { container } = render(<EmptyState icon={Landmark} title="Aucun gymnase" description="Ajoutez un gymnase." />);

    expect(container.querySelector("svg.lucide-landmark")).not.toBeNull();
  });
});

describe("EmptyHint / EmptyBlock — les deux étages existants restent intacts", () => {
  it("EmptyHint reste le paragraphe discret en ligne", () => {
    render(<EmptyHint>Aucune équipe.</EmptyHint>);
    expect(screen.getByText("Aucune équipe.")).toBeInTheDocument();
  });

  it("EmptyBlock reste le bloc pointillé de grille", () => {
    render(<EmptyBlock>Rien à afficher.</EmptyBlock>);
    expect(screen.getByText("Rien à afficher.")).toBeInTheDocument();
  });
});

/**
 * P4-149 — les deux étages inline/bloc portent désormais une PEAU (`SurfaceSkin`), comme les
 * onglets (`tabs.tsx`). `app` (défaut, jetons du thème) et `console` (jetons `--console-*`)
 * doivent rendre des couleurs DISTINCTES : sans ce filet, un `variant="console"` oublié sur un
 * site de la console retomberait sur les jetons clairs de l'app SANS que rien ne rougisse
 * (le garde de palette n'attrape que les nuances Tailwind BRUTES, pas un jeton de thème).
 */
describe("EmptyHint / EmptyBlock — la peau (SurfaceSkin) app vs console", () => {
  it("EmptyHint app (défaut) porte le jeton du thème, pas celui de la console", () => {
    render(<EmptyHint>Aucune équipe.</EmptyHint>);
    const el = screen.getByText("Aucune équipe.");
    expect(el.className).toContain("text-muted-foreground");
    expect(el.className).not.toContain("text-console-muted");
  });

  it("EmptyHint console porte le jeton --console-*, pas celui du thème", () => {
    render(<EmptyHint variant="console">Aucun plan.</EmptyHint>);
    const el = screen.getByText("Aucun plan.");
    expect(el.className).toContain("text-console-muted");
    expect(el.className).not.toContain("text-muted-foreground");
  });

  it("EmptyBlock app (défaut) garde fond de carte et bordure du thème", () => {
    render(<EmptyBlock>Rien à afficher.</EmptyBlock>);
    const el = screen.getByText("Rien à afficher.");
    expect(el.className).toContain("bg-card");
    expect(el.className).toContain("border-border");
    expect(el.className).toContain("text-muted-foreground");
  });

  it("EmptyBlock console porte les jetons de la console, sans fond de carte", () => {
    render(<EmptyBlock variant="console">Aucun conteneur à monitorer.</EmptyBlock>);
    const el = screen.getByText("Aucun conteneur à monitorer.");
    expect(el.className).toContain("text-console-muted");
    expect(el.className).toContain("border-white/15");
    expect(el.className).not.toContain("bg-card");
    expect(el.className).not.toContain("text-muted-foreground");
  });
});
