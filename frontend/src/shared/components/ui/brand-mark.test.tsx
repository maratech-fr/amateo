import { readFileSync } from "node:fs";
import { join } from "node:path";

import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PRODUCT_NAME } from "@/shared/lib/product";
import { BrandMark } from "./brand-mark";

describe("BrandMark", () => {
  it("rend l'icône produit + le mot (minuscule) SANS aucune couleur en dur — le mot hérite", () => {
    const { container } = render(<BrandMark />);
    // L'icône produit (le SEUL svg au viewBox de la marque) porte seule les couleurs du mark.
    expect(container.querySelector('svg[viewBox="0 0 1000 1000"]')).not.toBeNull();

    // Le mot vit en UN span, la marque en minuscules, sans style couleur inline (il hérite).
    const word = container.querySelector('[aria-hidden="true"].font-semibold');
    expect(word?.textContent).toBe(PRODUCT_NAME.toLowerCase());
    // Aucune couleur codée en dur nulle part (ni sur le mot, ni ailleurs) : un seul ton, hérité.
    expect(container.querySelector('[style*="color"]')).toBeNull();
  });

  it("est un LOGOTYPE : nom accessible = PRODUCT_NAME (un seul énoncé)", () => {
    const { getByRole } = render(<BrandMark />);
    expect(getByRole("img", { name: PRODUCT_NAME })).toBeInTheDocument();
  });

  it("décline trois tailles (icône + échelle du mot)", () => {
    const px = (node: HTMLElement) => node.querySelector('svg[viewBox="0 0 1000 1000"]')?.getAttribute("width");
    const sm = render(<BrandMark size="sm" />);
    const lg = render(<BrandMark size="lg" />);
    expect(px(sm.container)).toBe("16");
    expect(px(lg.container)).toBe("32");
    expect(lg.container.querySelector(".text-2xl")).not.toBeNull();
  });

  it("ne code AUCUN littéral de marque : le mot est dérivé de PRODUCT_NAME", () => {
    const src = readFileSync(join(import.meta.dirname, "brand-mark.tsx"), "utf8");
    expect(/amateo/i.test(src), "le mot « amateo » ne doit pas être écrit en dur — dérive-le de PRODUCT_NAME").toBe(false);
  });
});
