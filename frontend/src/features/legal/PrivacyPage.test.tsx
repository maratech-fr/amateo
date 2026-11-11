import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { describe, expect, it } from "vitest";

import { LEGAL_NOTICE_URL } from "@/shared/lib/product";

import { PrivacyPage } from "./PrivacyPage";

/**
 * P4-275 — la page de confidentialité de l'app renvoie vers les mentions légales
 * de la VITRINE (éditeur, responsable de publication, hébergeur). La vitrine et
 * l'app sont deux hôtes distincts (`amateo.app` vs `app.amateo.app`), donc le lien
 * est une URL ABSOLUE ouverte dans un nouvel onglet, avec un `rel` sûr.
 */
describe("PrivacyPage", () => {
  it("renvoie vers les mentions légales de la vitrine (lien externe sûr)", () => {
    render(
      <MemoryRouter>
        <PrivacyPage />
      </MemoryRouter>,
    );

    const link = screen.getByRole("link", { name: /mentions légales/i });
    expect(link).toHaveAttribute("href", LEGAL_NOTICE_URL);
    expect(link).toHaveAttribute("target", "_blank");
    expect(link.getAttribute("rel") ?? "").toMatch(/noopener/);
  });

  it("garde l'URL des mentions légales ABSOLUE (la vitrine n'est pas une route de l'app)", () => {
    expect(LEGAL_NOTICE_URL).toMatch(/^https:\/\//);
  });

  /**
   * P4-522 (F5, D12) — promesse : le gestionnaire lit dans l'application le MÊME délai
   * d'effacement de l'espace du club que sur la page « Vos données » de la vitrine (rédaction
   * validée le 2026-11-11), et plus l'ancienne « rotation (30 jours) ». Angle jumeau côté
   * fichiers : `backend/tests/Unit/Landing/LandingClaimsTest.php`
   * (`testErasureDelayIsTheSameOnTheSiteAndInTheApp`, parité vitrine ⇄ app) — doublon assumé,
   * domaine RGPD/effacement ; celui-ci prouve le RENDU. Phrase recopiée : page juridique
   * provisoire sans `texts.ts` (exemption F20, docblock de `PrivacyPage.tsx`) ; aucun rôle ne
   * discrimine un paragraphe, d'où la lecture du texte rendu.
   */
  it("annonce l'effacement de l'espace du club avec la phrase de la vitrine", () => {
    const erasure =
      "Quand le dernier gestionnaire supprime son compte, l'espace du club est effacé 30 jours plus tard ; " +
      "pendant ces 30 jours, un gestionnaire peut reprendre la main et tout annuler. Ses données sortent " +
      "ensuite des sauvegardes à mesure qu'elles sont remplacées par de plus récentes ; la copie conservée " +
      "aux Pays-Bas est effacée au bout de 30 jours. Nous ne restaurons jamais une sauvegarde pour " +
      "récupérer des données supprimées.";
    const backups =
      "Sauvegardes : renouvelées en continu (copie hors site effacée au bout de 30 jours) — aucune " +
      "restauration sélective de données effacées.";
    const { container } = render(
      <MemoryRouter>
        <PrivacyPage />
      </MemoryRouter>,
    );

    const rendered = (container.textContent ?? "").replace(/\s+/g, " ");
    expect(rendered).toContain(erasure);
    expect(rendered).toContain(backups);
    expect(rendered).not.toContain("rotation (30 jours)");
    expect(rendered).not.toContain("60 jours");
  });
});
