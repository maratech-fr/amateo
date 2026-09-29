import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { CreditsView } from "./useCredits";

// On mocke la COUCHE DE DONNÉES (le hook credits) — la règle testée ici (libellé
// « Découverte · N crédits », ambre PERMANENT, lien vers /club) vit dans le composant.
const credits = vi.hoisted(() => ({ current: null as CreditsView | null }));
vi.mock("./useCredits", () => ({ useCredits: () => credits.current }));

import { CreditBadge } from "./CreditBadge";

const view = (remaining: number): CreditsView => ({ max: 10, used: 10 - remaining, remaining, canGenerate: remaining > 0, canPlaceMatches: remaining > 0, canExportPdf: remaining > 0 });

// Le badge s'enveloppe d'un `Link` (react-router) → il exige un routeur.
const renderBadge = () =>
  render(
    <MemoryRouter>
      <CreditBadge />
    </MemoryRouter>,
  );

afterEach(() => {
  credits.current = null;
});

describe("CreditBadge", () => {
  it("n'affiche RIEN hors Découverte bridée (payant/bêta/démo → useCredits null)", () => {
    credits.current = null;
    const { container } = renderBadge();
    expect(container).toBeEmptyDOMElement();
  });

  // Le badge reflète l'OFFRE Découverte : libellé « Découverte · N crédits », ambre PERMANENT
  // (variante `warning` de la pastille partagée, quel que soit le solde), enveloppé d'un lien /club.
  it("affiche « Découverte · N crédits » en AMBRE permanent, même au-dessus de 5", () => {
    credits.current = view(8);
    renderBadge();
    const pill = screen.getByText("Découverte · 8 crédits");
    expect(pill.className).toContain("bg-warning/10");
    expect(pill.querySelector(".text-warning")).not.toBeNull();
  });

  it("accorde le singulier à 1 crédit restant", () => {
    credits.current = view(1);
    renderBadge();
    expect(screen.getByText("Découverte · 1 crédit")).toBeInTheDocument();
  });

  it("s'enveloppe d'un lien vers /club, dont l'aria-label conserve l'explication crédits", () => {
    credits.current = view(3);
    renderBadge();
    const link = screen.getByRole("link");
    expect(link).toHaveAttribute("href", "/club");
    expect(link.getAttribute("aria-label")).toMatch(/3 sur 10/);
    expect(link.getAttribute("aria-label")).toMatch(/crédit/i);
  });
});
