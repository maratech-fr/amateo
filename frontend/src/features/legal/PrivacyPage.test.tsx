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
});
