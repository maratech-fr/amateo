import { describe, expect, it } from "vitest";

import type { ConflictSideRole, ConflictType } from "../api";
import { CONFLICT_FAMILY_LABEL, sideRoleWord } from "./conflictLabels";

// PR-2a — les familles de conflits couvertes exhaustivement. La table est un
// `Record<ConflictType, string>` : TypeScript exige déjà toutes les clés, ce test
// verrouille les LIBELLÉS et interdit une clé fantôme.
const ALL_FAMILIES: ConflictType[] = [
  "VENUE_OVERLAP",
  "LEAGUE_WINDOW_VIOLATION",
  "CLUB_RULE_VIOLATION",
  "TEAM_VENUE_FORBIDDEN",
  "MATCH_MATCH",
  "MATCH_TRAINING",
  "VENUE_UNAVAILABLE",
  "ACCESS_WINDOW_LOST",
  "COMPETITION_INCOMPLETE",
  "AWAY_NO_FOOTPRINT",
  "FRIENDLY_ON_MATCH_SLOT",
];

describe("CONFLICT_FAMILY_LABEL", () => {
  it("porte un libellé non vide pour toutes les familles, et exactement celles-ci", () => {
    expect(Object.keys(CONFLICT_FAMILY_LABEL).sort()).toEqual([...ALL_FAMILIES].sort());
    for (const family of ALL_FAMILIES) {
      expect(CONFLICT_FAMILY_LABEL[family]).toBeTruthy();
    }
  });

  it("mappe chaque famille sur son libellé humain (PR-2a)", () => {
    expect(CONFLICT_FAMILY_LABEL.VENUE_OVERLAP).toBe("Collision de gymnase");
    expect(CONFLICT_FAMILY_LABEL.LEAGUE_WINDOW_VIOLATION).toBe("Hors fenêtre ligue");
    expect(CONFLICT_FAMILY_LABEL.CLUB_RULE_VIOLATION).toBe("Hors règle du club");
    expect(CONFLICT_FAMILY_LABEL.TEAM_VENUE_FORBIDDEN).toBe("Gymnase interdit");
    expect(CONFLICT_FAMILY_LABEL.MATCH_MATCH).toBe("Personne en double");
    expect(CONFLICT_FAMILY_LABEL.MATCH_TRAINING).toBe("Match × entraînement");
    expect(CONFLICT_FAMILY_LABEL.ACCESS_WINDOW_LOST).toBe("Hors accès match");
    expect(CONFLICT_FAMILY_LABEL.COMPETITION_INCOMPLETE).toBe("Calendrier incomplet");
    expect(CONFLICT_FAMILY_LABEL.VENUE_UNAVAILABLE).toBe("Gymnase indisponible");
    expect(CONFLICT_FAMILY_LABEL.AWAY_NO_FOOTPRINT).toBe("Extérieur sans heure");
    expect(CONFLICT_FAMILY_LABEL.FRIENDLY_ON_MATCH_SLOT).toBe("Amical sur créneau match");
  });
});

describe("sideRoleWord (rôle par côté d'un conflit personne-en-double)", () => {
  const ALL_ROLES: ConflictSideRole[] = ["MAIN", "ASSISTANT", "PLAYER"];

  it("porte un mot non vide pour les 3 rôles", () => {
    for (const role of ALL_ROLES) {
      expect(sideRoleWord(role, "UNSPECIFIED")).toBeTruthy();
    }
  });

  it("mappe « coach » et « assistant » sans accord, « joueur » accordé au genre (P4-311)", () => {
    expect(sideRoleWord("MAIN", "FEMALE")).toBe("coach");
    expect(sideRoleWord("ASSISTANT", "MALE")).toBe("assistant");
    expect(sideRoleWord("PLAYER", "MALE")).toBe("joueur");
    expect(sideRoleWord("PLAYER", "FEMALE")).toBe("joueuse");
    expect(sideRoleWord("PLAYER", "UNSPECIFIED")).toBe("joueur·euse");
  });
});
