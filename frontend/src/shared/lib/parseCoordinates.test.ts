import { describe, expect, it } from "vitest";

import { parseCoordinates } from "./parseCoordinates";

describe("parseCoordinates — saisie manuelle (lot E)", () => {
  it("un couple « latitude, longitude » nu", () => {
    expect(parseCoordinates("45.76799, 4.88853")).toEqual({ latitude: 45.76799, longitude: 4.88853 });
    // Séparateur point-virgule et espaces tolérés.
    expect(parseCoordinates(" 45.76799 ; 4.88853 ")).toEqual({ latitude: 45.76799, longitude: 4.88853 });
    // Coordonnées négatives (hémisphère sud / ouest).
    expect(parseCoordinates("-33.8688,151.2093")).toEqual({ latitude: -33.8688, longitude: 151.2093 });
  });

  it("un lien OpenStreetMap (mlat/mlon ou #map=z/lat/lon)", () => {
    expect(parseCoordinates("https://www.openstreetmap.org/?mlat=45.76799&mlon=4.88853#map=18/45.76799/4.88853")).toEqual({
      latitude: 45.76799,
      longitude: 4.88853,
    });
    expect(parseCoordinates("https://www.openstreetmap.org/#map=17/48.8584/2.2945")).toEqual({ latitude: 48.8584, longitude: 2.2945 });
  });

  it("un lien Google Maps (@lat,lon)", () => {
    expect(parseCoordinates("https://www.google.com/maps/@45.76799,4.88853,17z")).toEqual({ latitude: 45.76799, longitude: 4.88853 });
  });

  it("rejette une saisie non reconnue ou hors bornes", () => {
    expect(parseCoordinates("")).toBeNull();
    expect(parseCoordinates("pas des coordonnées")).toBeNull();
    expect(parseCoordinates("https://example.com/rien")).toBeNull();
    // Latitude hors [-90, 90].
    expect(parseCoordinates("120, 4.88")).toBeNull();
    // Longitude hors [-180, 180].
    expect(parseCoordinates("45.7, 999")).toBeNull();
  });
});
