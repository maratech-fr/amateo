import { describe, expect, it, vi } from "vitest";

import { PRODUCT_NAME } from "@/shared/lib/product";

import { buildParcPrompt } from "./promptTemplate";

/**
 * Lot correctif import IA (« G2 »), PR1 — PROMESSES du générateur de prompt, RÉÉCRIT sur le format
 * `parc-v2`. parc-v2 remplace parc-v1 (jamais déployé) : le chatbot ne rend plus des sections
 * structurées mais UNE ENTRÉE PAR CASE (`{gymnase, jour, debut, duree, equipes[], groupe?}`), et
 * l'appli déduit équipes / séances / genre / catégorie / mutualisations / réservations. Les coachs
 * NE SONT PLUS extraits (cadrage G2 D11, saisis à la main).
 *
 * `buildParcPrompt` est une fonction PURE qui fabrique le texte à coller dans le chatbot
 * (GPT / Gemini / Claude / Copilot). Signature proposée (NEO l'implémente — coachs RETIRÉS, contexte
 * club AJOUTÉ) :
 *   buildParcPrompt(input: {
 *     teams: readonly string[];                               // noms des équipes déjà saisies (vide = club neuf)
 *     venues: readonly string[];                              // noms des gymnases déjà saisis
 *     club?: { name?: string; city?: string; sport?: string }; // contexte (terrain vierge)
 *   }): string
 *
 * Promesses gardées :
 *  - le format demandé est `parc-v2` (plus jamais `parc-v1`) ;
 *  - la structure du test 7 est enseignée : une entrée par CASE avec gymnase, jour (1=lundi…7),
 *    debut HH:MM, duree en minutes, equipes ;
 *  - D8 le champ `groupe` (label d'un bloc mutualisé, ex. « CEC ») est enseigné ;
 *  - D2 « une entrée par équipe » : toutes les équipes d'une case sont listées, chacune par son nom ;
 *  - D3 contexte CORRECTEUR, jamais filtre : les noms déjà saisis sont embarqués pour CORRIGER le
 *    nommage, mais le chatbot garde AUSSI ce qu'il détecte au-delà de cette liste ;
 *  - D3 terrain vierge : sans périmètre saisi, le contexte club (nom / ville / sport) aide le chatbot ;
 *  - la phrase de source : l'Excel est le meilleur support, une image ou un PDF conviennent aussi ;
 *  - D11 les coachs ne sont PLUS demandés ;
 *  - la marque vient de `PRODUCT_NAME`, jamais un littéral « Amateo ».
 *
 * La marque est mockée à « TestBrand » : si NEO écrit « Amateo » en littéral, le test rougit.
 *
 * A4 (injection de prompt via un nom saisi) — PAS de test, justifié : les noms embarqués sont les
 * données DÉJÀ saisies du club lui-même, et le prompt est COPIÉ PAR LE GESTIONNAIRE dans SON PROPRE
 * chatbot — notre serveur n'appelle aucun LLM. Un nom hostile ne peut donc « injecter » que la
 * session que le gestionnaire ouvre avec ses propres données ; aucune surface d'attaque nouvelle
 * côté app. Le rendu LITTÉRAL d'un nom hostile jusqu'aux exports (A3) est, lui, gardé côté import
 * (neutralisation des déclencheurs de formule, cf. ParcImportExecuteTest historique).
 */

vi.mock("@/shared/lib/product", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/shared/lib/product")>();
  return { ...actual, PRODUCT_NAME: "TestBrand" };
});

const SAMPLE = {
  teams: ["U15-1", "SM1", "U18F Fays"],
  venues: ["Armand", "Jean Vilar"],
  club: { name: "Basket Club Réaliste", city: "Rillieux", sport: "basket" },
} as const;

/** Les champs de case que parc-v2 demande, tels que le validateur/dériveur les lisent. */
const CASE_FIELDS = ["gymnase", "jour", "debut", "duree", "equipes", "groupe"] as const;

describe("buildParcPrompt (générateur de prompt chatbot, lot G2 PR1, format parc-v2)", () => {
  it("demande le format parc-v2 et jamais parc-v1", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toContain("parc-v2");
    expect(prompt).not.toContain("parc-v1");
  });

  it("enseigne la structure du test 7 : une entrée par case avec ses champs", () => {
    const prompt = buildParcPrompt(SAMPLE);
    for (const field of CASE_FIELDS) {
      expect(prompt).toContain(field);
    }
    // Numérotation des jours (1 = lundi) : le repère distinctif du format.
    expect(prompt).toMatch(/lundi\s*=\s*1|1\s*=\s*lundi/i);
  });

  it("liste toutes les équipes d'une case, chacune par son nom (une entrée par équipe)", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toMatch(/toutes les équipes|chaque équipe|une entrée par équipe/i);
    expect(prompt).toContain("equipes");
  });

  it("enseigne le champ groupe pour un bloc mutualisé", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toContain("groupe");
  });

  it("embarque les noms existants comme CORRECTEUR sans jamais filtrer ce qui est détecté", () => {
    const prompt = buildParcPrompt(SAMPLE);
    for (const name of [...SAMPLE.teams, ...SAMPLE.venues]) {
      expect(prompt).toContain(name);
    }
    // Corriger le nommage…
    expect(prompt).toMatch(/r[ée]utilis\w*|corrig\w*/i);
    // …mais garder AUSSI ce qui est détecté au-delà de la liste (jamais filtrer).
    expect(prompt).toMatch(/au-delà|en plus|même (si|absent)|aussi|détect\w*/i);
  });

  it("pour un club neuf (listes vides), s'appuie sur le contexte club nom / ville", () => {
    const prompt = buildParcPrompt({ teams: [], venues: [], club: { name: "Étoile de Lyon", city: "Lyon", sport: "basket" } });
    expect(prompt).toContain("parc-v2");
    expect(prompt).toContain("Étoile de Lyon");
    expect(prompt).toContain("Lyon");
  });

  it("dit que l'Excel est le meilleur support, image ou PDF acceptés", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toMatch(/excel/i);
    expect(prompt).toMatch(/image/i);
    expect(prompt).toMatch(/pdf/i);
  });

  it("ne demande plus d'extraire les coachs (retirés du format)", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).not.toMatch(/coach|entra[îi]neur/i);
  });

  it("n'écrit JAMAIS la marque en littéral — elle vient de PRODUCT_NAME", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(PRODUCT_NAME).toBe("TestBrand");
    expect(prompt).toContain(PRODUCT_NAME);
    expect(prompt).not.toContain("Amateo");
  });

  it("demande de ne rendre QUE le JSON", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toMatch(/json/i);
    expect(prompt).toMatch(/uniquement|seulement|rien d['’]autre|aucun (autre )?texte|que le json/i);
  });
});
