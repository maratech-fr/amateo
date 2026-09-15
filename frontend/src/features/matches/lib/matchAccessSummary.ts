import { DAYS } from "@/shared/lib/days";

import type { VenueMatchWindow } from "../api";

/**
 * PR 2a « Configuration & navigation » — le libellé des plages d'accès match d'UN gymnase,
 * pour la liste des accès de la section « Accès match ». Helper PUR (présentation seule) : il
 * MET EN FORME les fenêtres servies par le backend (`venue_match_windows`), il n'en dérive
 * aucune règle métier (🔴 `.claude/rules/frontend.md`).
 *
 * Forme : « sam. 12:00–23:00 · dim. 09:00–20:00 » — jour ascendant, début ascendant ; deux
 * plages le MÊME jour se rejoignent par une virgule (« sam. 12:00–14:00, 16:00–23:00 »). Les
 * jours viennent du foyer unique `days.ts` (« sam. » minuscule, abrégé, avec point) ; les heures
 * telles que servies (HH:MM), séparées d'un tiret demi-cadratin. Sans fenêtre : « aucun accès match ».
 */

/** Abréviation minuscule d'un jour ISO depuis le foyer unique (« Sam » → « sam. »). */
const DAY_ABBR = new Map(DAYS.map((d) => [d.n, `${d.label.toLowerCase()}.`]));

export function formatMatchAccessWindows(windows: VenueMatchWindow[]): string {
  if (0 === windows.length) {
    return "aucun accès match";
  }
  const byDay = new Map<number, VenueMatchWindow[]>();
  for (const window of windows) {
    const bucket = byDay.get(window.dayOfWeek) ?? [];
    bucket.push(window);
    byDay.set(window.dayOfWeek, bucket);
  }
  return [...byDay.keys()]
    .sort((a, b) => a - b)
    .map((day) => {
      const ranges = (byDay.get(day) ?? [])
        .slice()
        .sort((a, b) => a.startTime.localeCompare(b.startTime))
        .map((w) => `${w.startTime}–${w.endTime}`)
        .join(", ");
      return `${DAY_ABBR.get(day) ?? "?"} ${ranges}`;
    })
    .join(" · ");
}
