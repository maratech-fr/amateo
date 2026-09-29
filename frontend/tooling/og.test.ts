import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * P5-24 — garde statique : `index.html` porte la carte de partage Open Graph / Twitter
 * (l'aperçu — titre, texte, image — affiché quand un lien vers l'app est collé dans une
 * messagerie ou un réseau social). Sans ces balises, l'aperçu est vide ou aléatoire.
 *
 * Contraintes gardées ici, et pourquoi :
 *  - les balises REQUISES avec leurs valeurs (`og:type=website`, `og:url`, `og:title`,
 *    `og:description`, `og:image`, `og:image:width=1200`, `og:image:height=630`,
 *    `twitter:card=summary_large_image`) — un aperçu qui rate une de ces balises se
 *    dégrade en silence chez le destinataire ;
 *  - `og:image` et `og:url` en URL ABSOLUE : un aperçu se charge HORS du contexte de la
 *    page, un chemin relatif ne s'y résout pas ;
 *  - l'ASSET servi existe (`public/brand/og.png`) — une balise qui pointe un 404 ne
 *    montre rien ;
 *  - AUCUN `og:site_name` et AUCUN nom de MARQUE dans la COPIE affichée (titre +
 *    description) : même doctrine que `noscript.test.ts` — le nom produit est une
 *    variable, il ne se disperse pas en littéral hors du `<title>`. Les URL (`og:url`,
 *    `og:image`) portent le DOMAINE `amateo.app` : c'est une ADRESSE, pas la marque en
 *    clair, donc contrôlée à part (présence d'une URL absolue, pas absence du domaine).
 *
 * Lit le VRAI `frontend/index.html` (celui que Vite cuit dans le dist).
 */
const html = readFileSync(resolve(process.cwd(), "index.html"), "utf8");

// Les balises meta og:/twitter: (le « bloc og », posées côte à côte dans le <head>).
const metas = [...html.matchAll(/<meta\s+(?:property|name)="((?:og|twitter):[^"]+)"\s+content="([^"]*)"\s*\/?>/g)].map((m) => ({ key: m[1], content: m[2] }));
const get = (key: string): string | undefined => metas.find((m) => m.key === key)?.content;

describe("index.html — carte de partage Open Graph (P5-24)", () => {
  it("porte les balises requises avec les bonnes valeurs", () => {
    expect(get("og:type")).toBe("website");
    expect(get("og:title")).toBeTruthy();
    expect(get("og:description")).toBeTruthy();
    expect(get("og:image:width")).toBe("1200");
    expect(get("og:image:height")).toBe("630");
    expect(get("twitter:card")).toBe("summary_large_image");
  });

  it("og:url et og:image sont des URL ABSOLUES (l'aperçu se charge hors contexte)", () => {
    expect(get("og:url")).toMatch(/^https:\/\//);
    expect(get("og:image")).toMatch(/^https:\/\/.+\/brand\/og\.png$/);
  });

  it("l'asset servi existe — public/brand/og.png", () => {
    expect(existsSync(resolve(process.cwd(), "public/brand/og.png"))).toBe(true);
  });

  it("ne pose PAS og:site_name", () => {
    expect(get("og:site_name")).toBeUndefined();
  });

  it("aucun nom de marque dans la copie affichée (titre + description)", () => {
    expect(get("og:title")).not.toMatch(/amateo|maratech/i);
    expect(get("og:description")).not.toMatch(/amateo|maratech/i);
  });
});
