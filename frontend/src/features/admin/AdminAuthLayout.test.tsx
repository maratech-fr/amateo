import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PRODUCT_NAME } from "@/shared/lib/product";
import { AdminAuthLayout } from "./AdminAuthLayout";

describe("AdminAuthLayout — logo console", () => {
  it("porte le logotype produit (nommé PRODUCT_NAME), pas le nom en texte nu", () => {
    render(
      <AdminAuthLayout title="Connexion superadmin" description="Accès restreint">
        <p>corps</p>
      </AdminAuthLayout>,
    );
    expect(screen.getByRole("img", { name: PRODUCT_NAME })).toBeInTheDocument();
    expect(screen.queryByText(PRODUCT_NAME)).toBeNull();
    // La console garde son sous-titre.
    expect(screen.getByText("Console sécurisée")).toBeInTheDocument();
  });
});
