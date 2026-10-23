import { describe, expect, it, vi } from "vitest";

import { PRODUCT_NAME } from "@/shared/lib/product";

import { buildParcPrompt } from "./promptTemplate";

/**
 * Chantier G (import du parc par IA), PR3 — PROMESSES du générateur de prompt.
 *
 * `buildParcPrompt` est une fonction PURE qui fabrique le texte à coller dans le chatbot du
 * gestionnaire : à partir du périmètre DÉJÀ saisi du club (noms d'équipes / gymnases / coachs),
 * elle produit un prompt qui demande au chatbot de rendre le planning du club au format `parc-v1`.
 *
 * Signature proposée (NEO l'implémente) :
 *   buildParcPrompt(input: {
 *     teams: readonly string[];    // noms des équipes déjà saisies (vide = club neuf)
 *     venues: readonly string[];   // noms des gymnases déjà saisis
 *     coaches: readonly string[];  // noms d'affichage des coachs déjà saisis
 *   }): string
 *
 * Ce que ces tests UNITAIRES gardent : les PROMESSES du prompt (noms embarqués, sections du
 * format, champs EXACTS, « rends UNIQUEMENT le JSON », marque sourcée de `PRODUCT_NAME`,
 * réutilisation d'un nom existant, règle des deux équipes sur une même case). Les champs et
 * énumérations asservis ici sont ceux du validateur réel de la PR1
 * (`App\Service\ParcImport\ParcPayloadValidator`, chantier G #1252) — la VÉRITÉ. Le verrouillage
 * mécanique prompt⇄validateur (toute dérive de l'un ou l'autre) est tenu par le test de parité
 * CrossStack `ParcPromptValidatorParityTest` (groupe `contract`) ; ces tests-ci n'en dupliquent
 * pas la mécanique, ils prouvent que la fonction REND bien ces champs.
 *
 * La marque est mockée à « TestBrand » : si NEO écrit « Amateo » en littéral au lieu de lire
 * `PRODUCT_NAME`, la sortie contiendrait « Amateo » (et pas « TestBrand ») → le test rougit.
 */

vi.mock("@/shared/lib/product", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/shared/lib/product")>();
  return { ...actual, PRODUCT_NAME: "TestBrand" };
});

const SAMPLE = {
  teams: ["Les Aigles Zêta 42", "U13 Masculins"],
  venues: ["Gymnase Léon-Blum", "Salle Mont Blanc"],
  coaches: ["Camille Durand-Évêque"],
} as const;

const SECTIONS = ["equipes", "gymnases", "creneaux", "mutualisations", "coachs", "reservations"] as const;

describe("buildParcPrompt (générateur de prompt chatbot, chantier G PR3)", () => {
  it("embarque les noms EXACTS des équipes, gymnases et coachs existants", () => {
    const prompt = buildParcPrompt(SAMPLE);
    for (const name of [...SAMPLE.teams, ...SAMPLE.venues, ...SAMPLE.coaches]) {
      expect(prompt).toContain(name);
    }
  });

  it("décrit le format parc-v1 et ses six sections", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toContain("parc-v1");
    for (const section of SECTIONS) {
      expect(prompt).toContain(section);
    }
  });

  it("nomme les champs EXACTS de parc-v1 tels que le validateur les lit", () => {
    const prompt = buildParcPrompt(SAMPLE);
    // Clés JSON que le chatbot DOIT émettre, telles que ParcPayloadValidator/ParcImportAnalyzer
    // (chantier G #1252) les lisent. La vérité est le validateur ; le verrouillage mécanique est
    // dans ParcPromptValidatorParityTest.
    for (const field of ["nom", "genre", "seances", "gymnase", "jour", "debut", "duree", "equipes_en_parallele", "seances_communes"]) {
      expect(prompt).toContain(field);
    }
    // Énumération du genre : M / F / MIXTE (MIXTE est la valeur distinctive à asserter).
    expect(prompt).toContain("MIXTE");
  });

  it("demande de ne rendre QUE le JSON (aucun autre texte)", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toMatch(/json/i);
    expect(prompt).toMatch(/uniquement|seulement|rien d['’]autre|aucun (autre )?texte|que le json/i);
  });

  it("n'écrit JAMAIS la marque en littéral — elle vient de PRODUCT_NAME", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(PRODUCT_NAME).toBe("TestBrand");
    expect(prompt).toContain(PRODUCT_NAME);
    expect(prompt).not.toContain("Amateo");
  });

  it("demande de réutiliser EXACTEMENT un nom existant quand le planning le mentionne", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toMatch(/r[ée]utilis\w*/i);
    expect(prompt).toMatch(/exact/i);
  });

  it("donne la règle : deux équipes sur une même case = mutualisation ou terrain divisé", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toMatch(/deux équipes|2 équipes/i);
    expect(prompt).toMatch(/même (case|créneau|cellule)/i);
    expect(prompt).toMatch(/mutualisation/i);
    expect(prompt).toMatch(/terrain divis[ée]/i);
  });

  it("reste un prompt valide pour un club SANS périmètre existant (listes vides)", () => {
    const prompt = buildParcPrompt({ teams: [], venues: [], coaches: [] });
    expect(prompt).toContain("parc-v1");
    for (const section of SECTIONS) {
      expect(prompt).toContain(section);
    }
    expect(prompt).toMatch(/json/i);
  });
});
