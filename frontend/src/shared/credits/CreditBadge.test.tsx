import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { CreditsView } from "./useCredits";

// On mocke la COUCHE DE DONNÉES (le hook credits) — la règle testée ici (seuil
// ambre, format « reste/max ») vit dans le composant, pas dans le mock.
const credits = vi.hoisted(() => ({ current: null as CreditsView | null }));
vi.mock("./useCredits", () => ({ useCredits: () => credits.current }));

import { CreditBadge } from "./CreditBadge";

const view = (remaining: number): CreditsView => ({ max: 10, used: 10 - remaining, remaining, canGenerate: remaining > 0, canPlaceMatches: remaining > 0, canExportPdf: remaining > 0 });

afterEach(() => {
  credits.current = null;
});

describe("CreditBadge", () => {
  it("n'affiche RIEN hors Découverte bridée (payant/bêta/démo → useCredits null)", () => {
    credits.current = null;
    const { container } = render(<CreditBadge />);
    expect(container).toBeEmptyDOMElement();
  });

  // La pastille est désormais un `StatusPill` partagé (P4-177) : l'ambre est porté par la VARIANTE
  // `warning` (bordure + fond) et l'ICÔNE `text-warning`, plus par le texte (repli AA `text-foreground`).
  // On vérifie donc le jeton sur la pastille et son icône, pas la couleur du texte.
  it("affiche « Crédits : reste/max » et reste NEUTRE au-dessus de 5", () => {
    credits.current = view(8);
    render(<CreditBadge />);
    const badge = screen.getByLabelText(/Crédits gratuits restants : 8 sur 10/);
    expect(badge.textContent).toContain("Crédits : 8/10");
    expect(badge.className).toContain("bg-muted");
    expect(badge.className).not.toContain("bg-warning");
    expect(badge.querySelector(".text-warning")).toBeNull();
  });

  it("passe en AMBRE à ≤ 5 crédits (variante warning + icône text-warning)", () => {
    credits.current = view(5);
    render(<CreditBadge />);
    const badge = screen.getByLabelText(/Crédits gratuits restants : 5 sur 10/);
    expect(badge.textContent).toContain("Crédits : 5/10");
    expect(badge.className).toContain("bg-warning/10");
    expect(badge.querySelector(".text-warning")).not.toBeNull();
  });
});
