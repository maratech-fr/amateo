import { frDateWeekdayNoYear } from "@/shared/lib/date";

import type { PendingDeviation } from "../api";
import { FIELD_LABEL } from "./deviationConsequence";

/**
 * Retours de tests — les valeurs auto-appliquées d'office par la source (FBI / API FFBB)
 * pendant qu'un match était traité, en UNE seule phrase lisible (au lieu d'une ligne par
 * champ, avec des dates ISO brutes). PRÉSENTATION pure : rien ici ne décide, on ÉPELLE ce
 * que la source a imposé.
 *
 * Le cas courant « le match est reprogrammé » (date ET heure) fusionne en « … → {nouvelle
 * date} à {nouvelle heure} ». Un seul champ garde sa forme « (champ) : ancien → nouveau ».
 * Une combinaison rare (salle + date/heure) liste chaque champ, toujours en UN bloc.
 *
 * `source` = le nom humain de la source (`sourceLabel(channel)` du composant appelant, maison
 * unique) : la phrase n'en re-dérive pas le libellé.
 */
export function autoAppliedPhrase(deviations: PendingDeviation[], source: string): string | null {
  if (0 === deviations.length) {
    return null;
  }

  const date = deviations.find((d) => "date" === d.field);
  const kickoff = deviations.find((d) => "kickoff" === d.field);
  const venue = deviations.find((d) => "venue" === d.field);

  const fmtDate = (v: string | null): string => (null !== v ? frDateWeekdayNoYear(v) : "—");
  const raw = (v: string | null): string => v ?? "—";

  // Le match est reprogrammé : jour ET heure changent → une phrase qui se lit d'un trait.
  if (undefined !== date && undefined !== kickoff && undefined === venue) {
    return `${source} a déplacé ce match : ${fmtDate(date.appValue)} → ${fmtDate(date.sourceValue)} à ${raw(kickoff.sourceValue)}.`;
  }

  // Un seul champ.
  if (undefined !== date && undefined === kickoff && undefined === venue) {
    return `${source} a déplacé ce match (date) : ${fmtDate(date.appValue)} → ${fmtDate(date.sourceValue)}`;
  }
  if (undefined !== kickoff && undefined === date && undefined === venue) {
    return `${source} a déplacé ce match (heure) : ${raw(kickoff.appValue)} → ${raw(kickoff.sourceValue)}`;
  }
  if (undefined !== venue && undefined === date && undefined === kickoff) {
    return `${source} a déplacé ce match (salle) : ${raw(venue.appValue)} → ${raw(venue.sourceValue)}`;
  }

  // Combinaison rare (la salle avec une date/heure, ou les trois) : on liste chaque champ,
  // toujours en UN bloc — jamais laisser tomber une information imposée.
  const parts = deviations.map((d) => {
    const fmt = "date" === d.field ? fmtDate : raw;
    return `${FIELD_LABEL[d.field].toLowerCase()} : ${fmt(d.appValue)} → ${fmt(d.sourceValue)}`;
  });

  return `${source} a déplacé ce match — ${parts.join(" ; ")}.`;
}
