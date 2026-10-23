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
 * format, « rends UNIQUEMENT le JSON », marque sourcée de `PRODUCT_NAME`, réutilisation d'un nom
 * existant, règle des deux équipes sur une même case). L'EXACTITUDE des noms de champs et des
 * énumérations de `parc-v1` ⇄ le validateur backend est, elle, du ressort du test de parité
 * CrossStack prompt⇄validateur (à écrire quand le validateur de la PR1 existe) — ces tests-ci ne
 * la dupliquent pas.
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

  it("décrit les champs structurants d'un créneau : jour, durée, séances, terrain divisé", () => {
    const prompt = buildParcPrompt(SAMPLE);
    expect(prompt).toMatch(/jour/i);
    expect(prompt).toMatch(/dur[ée]e/i);
    expect(prompt).toMatch(/s[ée]ances?/i);
    expect(prompt).toMatch(/terrain divis[ée]/i);
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
