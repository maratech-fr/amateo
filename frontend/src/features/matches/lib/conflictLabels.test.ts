import { describe, expect, it } from "vitest";

import type { ConflictType } from "../api";
import { CONFLICT_FAMILY_LABEL } from "./conflictLabels";

// PR-2a — les 10 familles de conflits couvertes exhaustivement. La table est un
// `Record<ConflictType, string>` : TypeScript exige déjà les 10 clés, ce test
// verrouille les LIBELLÉS et interdit une clé fantôme.
const ALL_FAMILIES: ConflictType[] = [
  "VENUE_OVERLAP",
  "LEAGUE_WINDOW_VIOLATION",
  "MATCH_MATCH",
  "MATCH_TRAINING",
  "VENUE_UNAVAILABLE",
  "ACCESS_WINDOW_LOST",
  "TEAM_LINK_OVERLAP",
  "COMPETITION_INCOMPLETE",
  "AWAY_NO_FOOTPRINT",
  "FRIENDLY_ON_MATCH_SLOT",
];

describe("CONFLICT_FAMILY_LABEL", () => {
  it("porte un libellé non vide pour les 10 familles, et exactement celles-ci", () => {
    expect(Object.keys(CONFLICT_FAMILY_LABEL).sort()).toEqual([...ALL_FAMILIES].sort());
    for (const family of ALL_FAMILIES) {
      expect(CONFLICT_FAMILY_LABEL[family]).toBeTruthy();
    }
  });

  it("mappe chaque famille sur son libellé humain (PR-2a)", () => {
    expect(CONFLICT_FAMILY_LABEL.VENUE_OVERLAP).toBe("Collision de gymnase");
    expect(CONFLICT_FAMILY_LABEL.LEAGUE_WINDOW_VIOLATION).toBe("Hors fenêtre ligue");
    expect(CONFLICT_FAMILY_LABEL.MATCH_MATCH).toBe("Coach en double");
    expect(CONFLICT_FAMILY_LABEL.MATCH_TRAINING).toBe("Match × entraînement");
    expect(CONFLICT_FAMILY_LABEL.TEAM_LINK_OVERLAP).toBe("Passerelle");
    expect(CONFLICT_FAMILY_LABEL.ACCESS_WINDOW_LOST).toBe("Placement fragilisé");
    expect(CONFLICT_FAMILY_LABEL.COMPETITION_INCOMPLETE).toBe("Calendrier incomplet");
    expect(CONFLICT_FAMILY_LABEL.VENUE_UNAVAILABLE).toBe("Gymnase indisponible");
    expect(CONFLICT_FAMILY_LABEL.AWAY_NO_FOOTPRINT).toBe("Extérieur sans heure");
    expect(CONFLICT_FAMILY_LABEL.FRIENDLY_ON_MATCH_SLOT).toBe("Amical sur créneau match");
  });
});
