/**
 * Analyse une saisie MANUELLE de coordonnées (lot E) : le gestionnaire colle soit un couple
 * « latitude, longitude », soit un lien OpenStreetMap (`?mlat=…&mlon=…` ou `#map=z/lat/lon`), soit
 * un lien Google Maps (`@lat,lon`). Renvoie `{ latitude, longitude }` bornées au globe, ou `null`
 * si rien d'exploitable / hors bornes — le backend re-valide de toute façon (422).
 */
export interface ParsedCoordinates {
  latitude: number;
  longitude: number;
}

const NUM = "(-?\\d+(?:\\.\\d+)?)";

function bounded(latitude: number, longitude: number): ParsedCoordinates | null {
  if (Number.isNaN(latitude) || Number.isNaN(longitude)) {
    return null;
  }
  if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
    return null;
  }
  return { latitude, longitude };
}

export function parseCoordinates(raw: string): ParsedCoordinates | null {
  const s = raw.trim();
  if ("" === s) {
    return null;
  }

  // OpenStreetMap — paramètres `mlat`/`mlon` (marqueur) …
  const mlat = s.match(new RegExp(`[?&]mlat=${NUM}`, "i"));
  const mlon = s.match(new RegExp(`[?&]mlon=${NUM}`, "i"));
  if (null !== mlat && null !== mlon) {
    return bounded(Number(mlat[1]), Number(mlon[1]));
  }
  // … ou le fragment `#map=zoom/lat/lon`.
  const osmMap = s.match(new RegExp(`#map=\\d+(?:\\.\\d+)?/${NUM}/${NUM}`, "i"));
  if (null !== osmMap) {
    return bounded(Number(osmMap[1]), Number(osmMap[2]));
  }

  // Google Maps — `@lat,lon` (dans l'URL de la carte).
  const gmaps = s.match(new RegExp(`@${NUM},${NUM}`));
  if (null !== gmaps) {
    return bounded(Number(gmaps[1]), Number(gmaps[2]));
  }

  // Couple nu « lat, lon » (virgule, point-virgule ou espace) — jamais une URL restée non reconnue.
  if (!/^https?:\/\//i.test(s)) {
    const pair = s.match(new RegExp(`^${NUM}\\s*[,;]\\s*${NUM}$`));
    if (null !== pair) {
      return bounded(Number(pair[1]), Number(pair[2]));
    }
  }

  return null;
}
