import { describe, expect, it } from "vitest";

import { humanDistance, venueGeoAlertMessage } from "./venueGeoAlert";

describe("venueGeoAlert — le message du bandeau « Position à vérifier » (verdict serveur affiché)", () => {
  it("formate une distance lisible : km au-delà du kilomètre, m en deçà", () => {
    expect(humanDistance(1931)).toBe("1,9 km");
    expect(humanDistance(1000)).toBe("1,0 km");
    expect(humanDistance(450)).toBe("450 m");
    expect(humanDistance(12)).toBe("12 m");
  });

  it("OTHER_STREET : dit dans quelle autre rue le point tombe et rappelle l'adresse FFBB", () => {
    const message = venueGeoAlertMessage({
      venueId: "v1",
      reason: "OTHER_STREET",
      ffbbAddress: "253 cours Emile Zola",
      pointStreet: "Rue Léon Blum",
      distanceM: null,
    });

    expect(message).toContain("tombe Rue Léon Blum");
    expect(message).toContain("« 253 cours Emile Zola »");
    expect(message).toContain("collez les coordonnées exactes");
  });

  it("FAR_FROM_ADDRESS : dit à quelle distance lisible de l'adresse FFBB est le point", () => {
    const message = venueGeoAlertMessage({
      venueId: "v1",
      reason: "FAR_FROM_ADDRESS",
      ffbbAddress: "253 cours Emile Zola",
      pointStreet: null,
      distanceM: 1931,
    });

    expect(message).toContain("à 1,9 km de l'adresse");
    expect(message).toContain("« 253 cours Emile Zola »");
    expect(message).toContain("collez les coordonnées exactes");
  });
});
