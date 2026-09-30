/**
 * P4-272 ③ — le LIBELLÉ humain d'une règle de match du club, dérivé de ses bornes.
 *
 * PRÉSENTATION pure (🔴 `.claude/rules/frontend.md` : choisir un libellé est de la
 * présentation, autorisé côté front ; ce qui reste interdit, c'est de REDÉRIVER une
 * règle métier — ici la COLLISION est calculée côté serveur, cette maison ne fait que
 * NOMMER une règle). MAISON UNIQUE partagée par la section Club de l'écran des
 * contraintes ET l'écran Semaine type.
 */

import { formatMinutes, parseTime } from "@/shared/lib/time";

/**
 * Une borne « HH:MM(:SS) » servie par le backend → « 21:00 » (format horaire unique,
 * N3, uniformité des écrans 2026-09-30) : DÉLÈGUE au foyer unique `formatMinutes`,
 * ne fabrique aucune heure à la main. Repli défensif « 00:00 » si la valeur est illisible
 * (le serveur ne sert que des bornes valides).
 */
export function clockLabel(hhmm: string): string {
  return formatMinutes(parseTime(hhmm) ?? 0);
}

/** Le libellé d'une règle depuis ses bornes (chacune facultative). */
export function clubRuleLabel(rule: { kickoffMin: string | null; kickoffMax: string | null }): string {
  const { kickoffMin, kickoffMax } = rule;
  if (null !== kickoffMin && null !== kickoffMax) {
    return `entre ${clockLabel(kickoffMin)} et ${clockLabel(kickoffMax)}`;
  }
  if (null !== kickoffMax) {
    return `pas après ${clockLabel(kickoffMax)}`;
  }
  if (null !== kickoffMin) {
    return `pas avant ${clockLabel(kickoffMin)}`;
  }
  // Défensif : le serveur refuse une règle sans borne, mais ne jamais rendre vide.
  return "règle horaire";
}
