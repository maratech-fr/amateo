import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CtcMark } from "./ctc-mark";

/**
 * Chantier D (CTC) PR1 — le pictogramme PARTAGÉ d'une équipe en entente (CTC).
 *
 * Promesse (retour Rillieux #6, décision fondateur 2026-10-24) : « un simple repère visuel » posé
 * DEVANT le nom de l'équipe, « pour l'identifier d'un coup d'œil », partout où le nom s'affiche. Ce
 * test garde le CONTRAT de la primitive — c'est lui que toutes les surfaces (TeamSelect, WeekGrid,
 * ClubViewTable, matchs…) consomment, un changement silencieux casserait leur a11y sans qu'un test
 * de surface le voie. Les surfaces qui DOIVENT le rendre sont gardées par `src/test/ctcMarkGuard`.
 *
 * Le pictogramme porte un NOM ACCESSIBLE (patron `BrandMark` : `role="img"` + `aria-label`) pour le
 * lecteur d'écran, et un `title` pour le survol souris — le partenaire se découvre des deux côtés,
 * jamais au seul survol (A11Y). Sans partenaire, l'énoncé reste « Entente (CTC) ».
 *
 * ⚠ jsdom n'a aucun moteur de rendu : ce test garde la STRUCTURE (rôle, nom, title, texte littéral),
 * pas l'apparence (le vrai contraste/placement vivent en Playwright).
 *
 * Signature attendue de NEO (option A — ce fichier ERREUR à l'import tant que la primitive n'existe
 * pas) : `shared/components/ui/ctc-mark.tsx` exportant `CtcMark({ partner?: string | null })`, icône
 * lucide Handshake aria-hidden dans un conteneur `role="img"` dont l'`aria-label` ET le `title` =
 * « Entente (CTC) avec {partner} » (ou « Entente (CTC) » sans partenaire).
 */
describe("CtcMark — pictogramme partagé d'une équipe en entente", () => {
  it("expose un nom accessible nommant le partenaire + un title pour le survol", () => {
    render(<CtcMark partner="AS Voisine" />);
    const mark = screen.getByRole("img", { name: "Entente (CTC) avec AS Voisine" });
    expect(mark).toHaveAttribute("title", "Entente (CTC) avec AS Voisine");
  });

  it("sans partenaire, l'énoncé reste « Entente (CTC) »", () => {
    render(<CtcMark />);
    const mark = screen.getByRole("img", { name: "Entente (CTC)" });
    expect(mark).toHaveAttribute("title", "Entente (CTC)");
  });

  it("un partenaire vide (ou que des espaces) retombe sur « Entente (CTC) » — pas « avec  »", () => {
    render(<CtcMark partner="   " />);
    expect(screen.getByRole("img", { name: "Entente (CTC)" })).toBeInTheDocument();
  });

  it("abus A3 — un partenaire porteur de HTML est rendu en TEXTE littéral, jamais interprété", () => {
    const hostile = '<b onmouseover="alert(1)">AS Pirate</b>';
    const { container } = render(<CtcMark partner={hostile} />);
    const mark = screen.getByRole("img");
    // Le nom accessible ET le title portent la chaîne EXACTE, balises comprises.
    expect(mark.getAttribute("aria-label")).toBe(`Entente (CTC) avec ${hostile}`);
    expect(mark.getAttribute("title")).toBe(`Entente (CTC) avec ${hostile}`);
    // …et aucune balise n'a été injectée dans le DOM (React échappe le texte).
    expect(container.querySelector("b")).toBeNull();
  });
});
