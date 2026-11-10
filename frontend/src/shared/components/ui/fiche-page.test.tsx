import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { FichePage } from "./fiche-page";

/**
 * NR — P4-107 (3ᵉ tranche) : **une page « fiche » a UNE largeur, et ses paragraphes restent
 * lisibles dedans.**
 *
 * Le shell est pleine largeur depuis la 1ʳᵉ tranche (PR #613) ; Club (672 px) et Profil
 * (512 px) étaient restés dessous, si bien que sur 1920×1080 la marge dépassait l'utile. La
 * largeur retenue (52 rem = 832 px) est celle testée à la main par le fondateur, entre `3xl`
 * (768) et `4xl` (896) : plus grande, en gardant le visuel des panneaux.
 *
 * ⚠ **La borne de lisibilité n'est pas un ornement.** La seule mesure chiffrée du corpus de
 * design (`ui-ux-pro-max`) est 65-75 caractères par ligne, et elle vaut À L'INTÉRIEUR d'un
 * conteneur plus large : élargir le cadre sans borner les paragraphes déplacerait le défaut
 * au lieu de le corriger — on gagnerait de la largeur pour perdre de la lisibilité.
 *
 * ⚑ **Pourquoi ici et pas dans `AccordionSection`** (la maison qui semblait évidente) : cet
 * accordéon a cinq consommateurs, dont **quatre écrans du wizard** qui sont pleine largeur
 * par conception. Y poser la borne aurait reflowé en silence des écrans hors de ce lot. La
 * maison unique des trois fiches est un composant de PAGE, pas un composant de section.
 *
 * ⚠ Ce fichier garde le CONTRAT de classes — jsdom n'a aucun moteur de mise en page et ne
 * mesure aucune largeur. Que `max-w-fiche` existe VRAIMENT (token `--container-fiche` posé
 * dans `index.css`) et vaille 832 px se prouve en Playwright : `tests/e2e/width-calibration.spec.ts`.
 */
describe("FichePage — le cadre des pages fiche", () => {
  function classesOf(node: ReturnType<typeof render>): string[] {
    return (node.container.firstChild as HTMLElement).className.split(/\s+/).filter(Boolean);
  }

  it("centre la page sur la largeur fiche, et sur elle seule", () => {
    const classes = classesOf(render(<FichePage>contenu</FichePage>));

    expect(classes).toContain("mx-auto");
    expect(classes).toContain("max-w-fiche");
    // Deuxième sens : aucune autre largeur ne traîne. Un `max-w-2xl` résiduel de l'ancien
    // code gagnerait sur `max-w-fiche` ou pas selon l'ordre des règles — indécidable à l'œil.
    expect(classes.filter((token) => token.startsWith("max-w-"))).toEqual(["max-w-fiche"]);
  });

  it("borne les paragraphes qu'elle contient à la longueur de ligne lisible", () => {
    expect(classesOf(render(<FichePage>contenu</FichePage>))).toContain("[&_p]:max-w-prose");
  });

  /**
   * ⚑ DÉPEND D'A4 (capture SWITCH de `/nouveautes` attendue avant le code).
   *
   * Promesse : **une page dont les paragraphes sont le contenu lui-même (Nouveautés) peut renoncer
   * à la borne — et la règle n'est alors PAS émise du tout**. Pas de `max-w-none` en surcharge :
   * Tailwind 4 trie `[&_p]:max-w-none` AVANT `[&_p]:max-w-prose`, la borne gagnerait à spécificité
   * égale. Le défaut (test ci-dessus) reste la borne : Club et Profil n'y touchent pas.
   * Jumeau page : `ReleaseNotesPage.test.tsx` (« n'émet pas la borne… ») ; effet calculé :
   * `tests/e2e/release-notes.spec.ts`.
   */
  it("n'émet pas la borne de lisibilité quand la page y renonce (prose={false}), sans perdre le cadre", () => {
    const classes = classesOf(render(<FichePage prose={false}>contenu</FichePage>));

    expect(classes).not.toContain("[&_p]:max-w-prose");
    expect(classes.filter((token) => token.includes("max-w-"))).toEqual(["max-w-fiche"]);
    expect(classes).toContain("mx-auto");
  });

  it("laisse la page ajouter son espacement sans perdre le cadre", () => {
    // Les trois fiches ont leur propre rythme vertical (`space-y-*`) : le wrapper doit
    // l'accepter, sinon chacune se remettrait à bricoler son conteneur — la dérive d'origine.
    const classes = classesOf(render(<FichePage className="space-y-4">contenu</FichePage>));

    expect(classes).toContain("space-y-4");
    expect(classes).toContain("max-w-fiche");
  });
});
