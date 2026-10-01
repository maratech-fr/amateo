import { dayLabelLong } from "@/shared/lib/days";

import type { PlacedConflict } from "../api";

/**
 * P4-269 — le libellé d'un conflit « personne à deux endroits », au gabarit fondateur :
 * « Anna Dupont est à deux endroits le mardi à 18:00 (U13F · Gymnase B / U11M1 · Gymnase A) ».
 *
 * PRÉSENTATION pure : le backend a déjà décidé QU'IL Y A conflit et QUI/QUAND/OÙ ; ici on ne
 * fait que composer la phrase à partir des champs servis (jamais une règle métier re-dérivée).
 */
export function placedConflictLabel(conflict: PlacedConflict): string {
  const day = dayLabelLong(conflict.dayOfWeek) || "ce jour";
  const first = `${conflict.first.teamName} · ${conflict.first.venueName}`;
  const second = `${conflict.second.teamName} · ${conflict.second.venueName}`;
  return `${conflict.personName} est à deux endroits le ${day} à ${conflict.first.startTime} (${first} / ${second})`;
}
