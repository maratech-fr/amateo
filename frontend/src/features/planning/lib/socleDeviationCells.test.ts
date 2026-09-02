import { describe, expect, it } from "vitest";

import type { SocleDeviation } from "../api";
import { deviatedSlots, placementLabel } from "./socleDeviationCells";

const venueName = (id: string) => ({ vX: "Matéo", vY: "JDR", vZ: "Salle Bleue" })[id] ?? id;

const deviation: SocleDeviation = {
  socleScheduleId: "socle-1",
  moved: [
    { teamId: "t1", from: { dayOfWeek: 2, startTime: "18:30", venueId: "vX" }, to: { dayOfWeek: 4, startTime: "19:00", venueId: "vY", slotId: "slot-A" } },
    { teamId: "t2", from: { dayOfWeek: 1, startTime: "20:00", venueId: "vZ" }, to: { dayOfWeek: 3, startTime: "17:00", venueId: "vX", slotId: "slot-B" } },
  ],
  unplaced: [{ teamId: "t3", dayOfWeek: 5, startTime: "20:00", venueId: "vZ", reason: "venue_closed" }],
};

describe("deviatedSlots", () => {
  it("mappe chaque slotId de PÉRIODE (moved[].to.slotId) vers l'origine de saison lisible", () => {
    const map = deviatedSlots(deviation, venueName);

    expect([...map.keys()].sort()).toEqual(["slot-A", "slot-B"]);
    // La valeur = le placement du SOCLE (`from`), pas de la période — c'est « où c'était en saison ».
    expect(map.get("slot-A")).toBe("Mar 18h30 Matéo");
    expect(map.get("slot-B")).toBe("Lun 20h00 Salle Bleue");
  });

  it("les non replacées n'entrent PAS dans la table (pas de carte à viser)", () => {
    const map = deviatedSlots(deviation, venueName);
    // Aucune clé issue d'une `unplaced` — seules les `moved` portent un slotId de grille.
    expect(map.size).toBe(2);
  });

  it("déviation nulle (route non armée) → table vide, jamais une erreur", () => {
    expect(deviatedSlots(null, venueName).size).toBe(0);
  });
});

describe("placementLabel", () => {
  it("compose « Jour HhMM Gymnase » — le format partagé panneau/grille", () => {
    expect(placementLabel(4, "19:00", "JDR")).toBe("Jeu 19h00 JDR");
  });
});
