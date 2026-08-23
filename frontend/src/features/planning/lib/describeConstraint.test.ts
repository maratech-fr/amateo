import { describe, expect, it } from "vitest";

import type { Constraint } from "../api";
import { describeConstraint } from "./describeConstraint";

const constraint = (over: Partial<Constraint>): Constraint => ({
  id: "c1",
  name: "un nom libre",
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "PREFERRED",
  config: {},
  isActive: true,
  ...over,
});

const venueName = (id: string): string | undefined => ({ "v-mateo": "Matéo", "v-alpha": "Gymnase Alpha" })[id];
const teamName = (id: string): string | undefined => ({ "team-sm2": "SM2", "team-a": "U11 A" })[id];
const coachName = (id: string): string | undefined => ({ "co1": "Jean Dupont" })[id];

/** Lookups complets (venue/team/coach). Par défaut team/coach ne résolvent RIEN (cible seule
 *  → prédicat seul), pour laisser les anciens cas tester le prédicat sans préfixe parasite. */
const lookups = { venueName, teamName: () => undefined, coachName: () => undefined };
const fullLookups = { venueName, teamName, coachName };

describe("describeConstraint — ce que la règle FAIT, pas son nom", () => {
  it("DAY forbiddenDays:[6] se lit « samedi », quel que soit le nom libre", () => {
    // Le cas fondateur : une règle « samedi interdit » nommée n'importe comment.
    const desc = describeConstraint(constraint({ name: "SM2 au moins 1 seance a Mateo", family: "DAY", config: { forbiddenDays: [6] } }), lookups);
    expect(desc).not.toBeNull();
    expect(desc!.toLowerCase()).toContain("samedi");
    expect(desc!.toLowerCase()).toContain("interdit");
  });

  it("DAY forbiddenDays multiples s'accordent au pluriel", () => {
    const desc = describeConstraint(constraint({ family: "DAY", config: { forbiddenDays: [6, 7] } }), lookups);
    expect(desc!.toLowerCase()).toContain("samedi");
    expect(desc!.toLowerCase()).toContain("dimanche");
    expect(desc!.toLowerCase()).toContain("interdits");
  });

  it("DAY allowedDays se lit « uniquement »", () => {
    const desc = describeConstraint(constraint({ family: "DAY", config: { allowedDays: [3] } }), lookups);
    expect(desc!.toLowerCase()).toContain("uniquement");
    expect(desc!.toLowerCase()).toContain("mercredi");
  });

  it("DAY forcedDays se lit « au moins une séance » (ALIGN-09, distinct de « uniquement »)", () => {
    const desc = describeConstraint(constraint({ family: "DAY", config: { forcedDays: [7] } }), lookups);
    expect(desc!.toLowerCase()).toContain("au moins une séance");
    expect(desc!.toLowerCase()).toContain("dimanche");
    // Ce n'est PAS « uniquement » : les deux clés ont des sens différents côté moteur.
    expect(desc!.toLowerCase()).not.toContain("uniquement");
  });

  // CLUB nu → cible « Toutes les équipes · <prédicat> » (prédicat en clair, minuscule, comme le wizard).
  it("FACILITY minAtVenueId nomme le gymnase et le compte", () => {
    const desc = describeConstraint(constraint({ family: "FACILITY", config: { minAtVenueId: "v-mateo", minAtVenueCount: 1 } }), lookups);
    expect(desc).toBe("Toutes les équipes · au moins 1 séance à Matéo");
  });

  it("FACILITY minAtVenueId au pluriel", () => {
    const desc = describeConstraint(constraint({ family: "FACILITY", config: { minAtVenueId: "v-mateo", minAtVenueCount: 2 } }), lookups);
    expect(desc).toBe("Toutes les équipes · au moins 2 séances à Matéo");
  });

  it("FACILITY preferredVenueId se lit « préfère »", () => {
    const desc = describeConstraint(constraint({ family: "FACILITY", config: { preferredVenueId: "v-mateo" } }), lookups);
    expect(desc).toBe("Toutes les équipes · préfère Matéo");
  });

  it("FACILITY forbiddenVenueId / forcedVenueId se lisent « évite » / « impose »", () => {
    expect(describeConstraint(constraint({ family: "FACILITY", config: { forbiddenVenueId: "v-mateo" } }), lookups)).toBe("Toutes les équipes · évite Matéo");
    expect(describeConstraint(constraint({ family: "FACILITY", config: { forcedVenueId: "v-mateo" } }), lookups)).toBe("Toutes les équipes · impose Matéo");
  });

  it("TIME compose les bornes en clair", () => {
    const desc = describeConstraint(constraint({ family: "TIME", config: { maxStartTime: "21:00", minStartTime: "18:00" } }), lookups);
    expect(desc!.toLowerCase()).toContain("pas après 21:00");
    expect(desc!.toLowerCase()).toContain("pas avant 18:00");
  });

  it("COACH_AVAILABILITY se lit « indisponible » avec sa fenêtre horaire", () => {
    const desc = describeConstraint(constraint({ family: "COACH_AVAILABILITY", scope: "COACH", scopeTargetId: "co1", config: { unavailableDays: [5], fromTime: "20:00" } }), lookups);
    expect(desc!.toLowerCase()).toContain("indisponible");
    expect(desc!.toLowerCase()).toContain("vendredi");
    expect(desc!.toLowerCase()).toContain("20:00");
  });

  it("retombe sur `null` (→ le nom) pour un gymnase INTROUVABLE : jamais « préfère undefined »", () => {
    const desc = describeConstraint(constraint({ family: "FACILITY", config: { preferredVenueId: "inconnu" } }), lookups);
    expect(desc).toBeNull();
  });

  it("retombe sur `null` pour une combinaison NON couverte — pas d'invention", () => {
    // Config vide, ou clé qu'on ne sait pas décrire fidèlement.
    expect(describeConstraint(constraint({ family: "DAY", config: {} }), lookups)).toBeNull();
    expect(describeConstraint(constraint({ family: "FACILITY", config: {} }), lookups)).toBeNull();
    // Famille inconnue (donnée future) → null, on ne devine pas.
    expect(describeConstraint(constraint({ family: "MYSTERY", config: { foo: "bar" } }), lookups)).toBeNull();
  });
});

describe("describeConstraint — la cible est NOMMÉE en tête (P4-94)", () => {
  it("TEAM → le nom de l'équipe · prédicat", () => {
    const desc = describeConstraint(
      constraint({ scope: "TEAM", scopeTargetId: "team-sm2", family: "FACILITY", config: { preferredVenueId: "v-mateo" } }),
      fullLookups,
    );
    expect(desc).toBe("SM2 · préfère Matéo");
  });

  it("COACH → le nom du coach · prédicat", () => {
    const desc = describeConstraint(
      constraint({ scope: "COACH", scopeTargetId: "co1", family: "COACH_AVAILABILITY", config: { unavailableDays: [5] } }),
      fullLookups,
    );
    expect(desc).toBe("Jean Dupont · indisponible vendredi");
  });

  it("CLUB + targetTag SANS résolveur → nom brut (dégradation douce)", () => {
    const desc = describeConstraint(
      constraint({ scope: "CLUB", family: "FACILITY", config: { targetTag: "FEMININE", preferredVenueId: "v-mateo" } }),
      fullLookups,
    );
    expect(desc).toBe("Groupe FEMININE · préfère Matéo");
  });

  it("CLUB + targetTags/excludeTags SANS résolveur → « Groupe A + B sauf C » en noms bruts (P2-29)", () => {
    const desc = describeConstraint(
      constraint({ scope: "CLUB", family: "FACILITY", config: { targetTags: ["ADULTE", "COMPETITION"], excludeTags: ["SENIOR"], preferredVenueId: "v-mateo" } }),
      fullLookups,
    );
    expect(desc).toBe("Groupe ADULTE + COMPETITION sauf SENIOR · préfère Matéo");
  });

  it("CLUB + excludeTags seul → « Groupe toutes les équipes sauf C » (jamais « ? »)", () => {
    const desc = describeConstraint(
      constraint({ scope: "CLUB", family: "FACILITY", config: { excludeTags: ["LOISIR"], preferredVenueId: "v-mateo" } }),
      fullLookups,
    );
    expect(desc).toBe("Groupe toutes les équipes sauf LOISIR · préfère Matéo");
  });

  it("CLUB nu → « Toutes les équipes · prédicat »", () => {
    const desc = describeConstraint(
      constraint({ scope: "CLUB", family: "FACILITY", config: { preferredVenueId: "v-mateo" } }),
      fullLookups,
    );
    expect(desc).toBe("Toutes les équipes · préfère Matéo");
  });

  it("cible INTROUVABLE (équipe supprimée) → prédicat SEUL, jamais « ? · … »", () => {
    const desc = describeConstraint(
      constraint({ scope: "TEAM", scopeTargetId: "team-disparue", family: "FACILITY", config: { preferredVenueId: "v-mateo" } }),
      fullLookups,
    );
    expect(desc).toBe("Préfère Matéo");
    expect(desc).not.toContain("?");
    expect(desc).not.toContain("·");
  });

  // Le panneau de créneau INJECTE `tagLabel` (comme il injecte `venueName`) : sans lui, l'écran
  // mentait à deux voix — description « Groupe ADULTE » au-dessus du nom « Groupe Adulte (+ de 18) ».
  it("avec le résolveur tagLabel → libellés AFFICHÉS, des deux côtés du « sauf »", () => {
    const desc = describeConstraint(
      constraint({ scope: "CLUB", family: "FACILITY", config: { targetTags: ["ADULTE", "COMPETITION"], excludeTags: ["U21"], preferredVenueId: "v-mateo" } }),
      { ...fullLookups, tagLabel: (n) => ({ ADULTE: "Adulte (+ de 18)", COMPETITION: "Compétition (hors loisir)", U21: "U21" })[n] ?? n },
    );
    expect(desc).toBe("Groupe Adulte (+ de 18) + Compétition (hors loisir) sauf U21 · préfère Matéo");
  });
});
