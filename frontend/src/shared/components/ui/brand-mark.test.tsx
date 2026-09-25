import { readFileSync } from "node:fs";
import { join } from "node:path";

import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PRODUCT_NAME } from "@/shared/lib/product";
import { BrandMark } from "./brand-mark";

describe("BrandMark", () => {
  it("rend l'icône produit + le mot en deux tons, le teal sur les 2 derniers caractères", () => {
    const { container } = render(<BrandMark />);
    // L'icône produit (le SEUL svg au viewBox de la marque).
    expect(container.querySelector('svg[viewBox="0 0 1000 1000"]')).not.toBeNull();

    // Le mot vit en DEUX spans : la tête (currentColor) + la queue teal.
    const tail = container.querySelector('span[style*="color"]');
    expect(tail).not.toBeNull();
    expect(tail?.textContent).toBe(PRODUCT_NAME.toLowerCase().slice(-2)); // « eo »
    // jsdom normalise la couleur inline en rgb — #46AFAC = rgb(70, 175, 172), le teal du mark.
    expect(tail?.getAttribute("style") ?? "").toContain("rgb(70, 175, 172)");

    // Le mot complet visible (tête + queue) = la marque en minuscules.
    const word = container.querySelector('[aria-hidden="true"].font-semibold');
    expect(word?.textContent).toBe(PRODUCT_NAME.toLowerCase());
  });

  it("est un LOGOTYPE : nom accessible = PRODUCT_NAME, visuel décoratif (un seul énoncé)", () => {
    const { getByRole, container } = render(<BrandMark />);
    const mark = getByRole("img", { name: PRODUCT_NAME });
    expect(mark).toBeInTheDocument();
    // Le mot est aria-hidden (pas de double énonciation) ; l'icône aussi.
    expect(container.querySelector('span[aria-hidden="true"]')).not.toBeNull();
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
