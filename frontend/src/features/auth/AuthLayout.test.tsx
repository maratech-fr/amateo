import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PRODUCT_NAME } from "@/shared/lib/product";
import { AuthLayout } from "./AuthLayout";

describe("AuthLayout — en-tête logo", () => {
  it("porte le logo produit (logotype nommé), plus le nom en texte nu ni l'icône calendrier", () => {
    const { container } = render(
      <AuthLayout title="Connexion" description="Accédez à votre espace">
        <p>corps</p>
      </AuthLayout>,
    );
    // Le logo complet = un logotype role="img" nommé PRODUCT_NAME.
    expect(screen.getByRole("img", { name: PRODUCT_NAME })).toBeInTheDocument();
    // Plus de mark en texte nu ni d'icône calendrier de repli.
    expect(screen.queryByText(PRODUCT_NAME)).toBeNull();
    expect(container.querySelector('[class*="calendar-check"]')).toBeNull();
  });
});

describe("AuthLayout — largeur du conteneur", () => {
  it("par défaut, le conteneur reste en max-w-md (login/inscription inchangés)", () => {
    const { container } = render(
      <AuthLayout title="Connexion">
        <p>corps</p>
      </AuthLayout>,
    );
    expect(container.querySelector(".max-w-md")).not.toBeNull();
    expect(container.querySelector(".max-w-2xl")).toBeNull();
  });

  it('width="2xl" élargit le conteneur (page doléance uniquement)', () => {
    const { container } = render(
      <AuthLayout title="Doléances" width="2xl">
        <p>corps</p>
      </AuthLayout>,
    );
    expect(container.querySelector(".max-w-2xl")).not.toBeNull();
    expect(container.querySelector(".max-w-md")).toBeNull();
  });
});
