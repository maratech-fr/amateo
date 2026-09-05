import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { StatusPill } from "./badge";

/**
 * StatusPill — la pastille d'état PARTAGÉE (P4-173, étendue P4-177). On garde ici le CONTRAT des
 * variantes (jetons de couleur) et des passthrough a11y, parce que c'est ce contrat que les
 * pastilles migrées consomment — un changement silencieux d'un jeton casserait leur apparence sans
 * qu'aucun test de composant ne le voie. Les VRAIS ratios de contraste vivent dans
 * `tests/e2e/a11y-contrast.spec.ts` (jsdom n'a pas de moteur de rendu).
 */
describe("StatusPill", () => {
  it("rend l'icône et le texte", () => {
    render(
      <StatusPill icon={<svg data-testid="icon" />}>Ceci est un état</StatusPill>,
    );
    expect(screen.getByTestId("icon")).toBeInTheDocument();
    expect(screen.getByText("Ceci est un état")).toBeInTheDocument();
  });

  it("variante neutral par défaut : bordure + fond muted, texte muted-foreground", () => {
    render(<StatusPill>Info</StatusPill>);
    const pill = screen.getByText("Info");
    expect(pill.className).toContain("border-border");
    expect(pill.className).toContain("bg-muted");
    expect(pill.className).toContain("text-muted-foreground");
  });

  it("variante warning : bordure + fond warning, TEXTE text-foreground (AA), pas text-warning", () => {
    render(<StatusPill variant="warning">Attention</StatusPill>);
    const pill = screen.getByText("Attention");
    expect(pill.className).toContain("border-warning/50");
    expect(pill.className).toContain("bg-warning/10");
    expect(pill.className).toContain("text-foreground");
    expect(pill.className).not.toContain("text-warning");
  });

  it("variante accent : bordure + fond accent, TEXTE text-foreground (AA), pas text-accent", () => {
    render(<StatusPill variant="accent">Gain</StatusPill>);
    const pill = screen.getByText("Gain");
    expect(pill.className).toContain("border-accent/50");
    expect(pill.className).toContain("bg-accent/10");
    expect(pill.className).toContain("text-foreground");
    expect(pill.className).not.toContain("text-accent");
  });

  it("transmet title et aria-label au span (le texte visible peut différer de l'annonce)", () => {
    render(
      <StatusPill title="une infobulle" aria-label="annonce complète">
        Crédits : 5/10
      </StatusPill>,
    );
    const pill = screen.getByLabelText("annonce complète");
    expect(pill).toHaveAttribute("title", "une infobulle");
    expect(pill.textContent).toBe("Crédits : 5/10");
  });
});
