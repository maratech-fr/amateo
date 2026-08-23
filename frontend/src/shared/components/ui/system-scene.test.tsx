import { render, screen } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { SystemScene } from "./system-scene";
import { SystemScreen } from "./system-screen";

// Le TEXTE du CSS de la scène (lu du disque) : jsdom n'a aucun moteur de mise en page, on garde
// donc le piège reduced-motion sur la SOURCE, pas sur le rendu (`.claude/rules/frontend.md`).
// Chemin depuis la racine du projet vitest (`/app/frontend`).
const css = readFileSync(resolve(process.cwd(), "src/shared/components/ui/system-scene.css"), "utf8");
// Sans les commentaires : les assertions « à l'arrêt » portent sur les DÉCLARATIONS, pas sur la
// prose du docblock (qui, elle, cite ces mots pour les EXPLIQUER — « aucun stroke-dashoffset »).
const cssCode = css.replace(/\/\*[\s\S]*?\*\//g, "");

describe("SystemScene — la scène commune des écrans système (P5-22)", () => {
  it("est décorative : aria-hidden, sans texte lisible (le sens vit dans le titre/corps)", () => {
    render(<SystemScene />);
    const scene = screen.getByTestId("system-scene");
    expect(scene.getAttribute("aria-hidden")).toBe("true");
    // Aucun texte : ni nom produit, ni email — c'est un décor SVG pur.
    expect(scene.textContent?.trim()).toBe("");
    expect(scene.querySelector("svg")).not.toBeNull();
  });

  it("rend SANS aucun provider (contrainte ErrorBoundary : aucun hook applicatif)", () => {
    // Aucun QueryClient, aucun Router : un hook applicatif ferait jeter ce render.
    expect(() => render(<SystemScene />)).not.toThrow();
  });

  it("lit l'accent du club (identité) — var(--accent) est présent dans la scène", () => {
    const { container } = render(<SystemScene />);
    expect(container.innerHTML).toContain("var(--accent)");
  });
});

describe("SystemScreen — porte la scène PAR DÉFAUT, sans prop", () => {
  const noop = () => {};

  it("rend la scène commune sans qu'aucun consommateur ait à la demander", () => {
    render(
      <SystemScreen title="Ce créneau n'existe pas." primaryAction={{ label: "Retour à l'accueil", onClick: noop }}>
        corps
      </SystemScreen>,
    );
    // La scène est là, décorative — c'est de la FORME commune, pas de la copie par écran.
    const scene = screen.getByTestId("system-scene");
    expect(scene.getAttribute("aria-hidden")).toBe("true");
  });
});

/**
 * jsdom n'a AUCUN moteur de mise en page (`.claude/rules/frontend.md`) : le comportement
 * `prefers-reduced-motion` ne s'atteste pas au rendu. On garde donc le PIÈGE directement sur le
 * TEXTE du CSS (patron : `features/planning/GenerationServiceDown.test.tsx`).
 *
 * ⚠ LE PIÈGE : un fichier frère (`GenerationWaiting.css`) force `.gw-anim { opacity: 1 !important }`
 * sous reduced-motion — l'état PLEIN d'un écran d'ATTENTE. Transposé naïvement à un écran d'ERREUR,
 * un utilisateur reduced-motion verrait une scène « pleine » sous un titre de panne : un faux état
 * de SUCCÈS. La règle de CETTE scène : sous reduced-motion, cartes POSÉES, visibles, immobiles —
 * obtenues en coupant les animations (les cartes ont une opacité de base 1 et flottent par
 * TRANSFORM), donc AUCUN `opacity: 1 !important`.
 */
describe("system-scene.css — reduced-motion coupe les animations SANS peindre un faux succès", () => {
  const reducedBlock = css.match(/@media[^{]*prefers-reduced-motion[^{]*\{[\s\S]*?\}\s*\}/)?.[0] ?? "";

  it("le bloc reduced-motion existe et neutralise les animations", () => {
    expect(reducedBlock).not.toBe("");
    expect(reducedBlock).toMatch(/\.ss-anim\s*\{[^}]*animation:\s*none\s*!important/);
  });

  it("le bloc reduced-motion ne touche QUE l'animation — aucun override d'opacité (donc aucun `opacity: 1 !important`)", () => {
    expect(reducedBlock).not.toMatch(/opacity/);
  });

  // Assertion « à l'arrêt veut dire à l'arrêt » : aucune keyframe ne porte de remplissage
  // progressif, de balayage, ni de révélation par l'opacité (qui EXIGERAIT le !important qu'on
  // bannit). La scène ne fait que flotter.
  it("aucune keyframe de balayage/remplissage/progression (pas de sweep/scan/drop/fill/rise)", () => {
    expect(cssCode).not.toMatch(/@keyframes\s+ss-(sweep|scan|scanline|drop|fill|rise|riseIn|blink|spin)/i);
  });

  it("aucun stroke-dashoffset animé (le vecteur d'un balayage)", () => {
    expect(cssCode).not.toMatch(/stroke-dashoffset/);
  });

  it("aucune keyframe ne révèle un élément depuis l'opacité 0 (pas de fade-in)", () => {
    // 0.16 / 0.09 ne matchent pas `0` suivi de `;` ou `}` : on interdit la seule révélation « 0 → visible ».
    expect(cssCode).not.toMatch(/opacity:\s*0\s*[;}]/);
  });
});
