import type { OpponentTravel } from "../api";

/**
 * The travel, in plain words, for the row's fallback `title` (the narrow column
 * truncates; the title spells everything out). Empty when nothing to say.
 */
export function awayTravelTitle(travel: OpponentTravel | undefined): string {
  if (undefined === travel || !travel.located || null === travel.precision) {
    return "lieu inconnu";
  }
  const place = travel.locationName ?? "";
  const location = "CITY" === travel.precision ? `ville de ${place}` : place;
  if (null === travel.travelMinutes) {
    return `${location} · trajet indisponible`;
  }
  const approx = travel.approximated ? "~" : "";
  return `${location} · ${approx}${travel.travelMinutes} min${travel.approximated ? " (approché)" : ""}`;
}
