import type { AwayTravel } from "../api";

/**
 * The travel, in plain words, for the row's fallback `title` (the narrow column
 * truncates; the title spells everything out). Empty when nothing to say. Amendement
 * 2026-09-20 : lit `fixture.awayTravel` (dérivé de la rencontre, tout serveur).
 */
export function awayTravelTitle(travel: AwayTravel | null | undefined): string {
  if (null === travel || undefined === travel) {
    return "lieu inconnu";
  }
  const place = travel.venueLabel ?? travel.city ?? "";
  const location = "city" === travel.basis ? `ville de ${place}` : "most_frequent" === travel.basis ? `${place} (gymnase supposé)` : place;
  if (null === travel.oneWayMinutes) {
    return `${location} · trajet indisponible`;
  }
  const approx = travel.approximated ? "~" : "";
  return `${location} · ${approx}${travel.oneWayMinutes} min${travel.approximated ? " (approché)" : ""}`;
}
