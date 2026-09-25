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
