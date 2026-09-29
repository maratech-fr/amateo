/**
 * P4-272 ③ — le LIBELLÉ humain d'une règle de match du club, dérivé de ses bornes.
 *
 * PRÉSENTATION pure (🔴 `.claude/rules/frontend.md` : choisir un libellé est de la
 * présentation, autorisé côté front ; ce qui reste interdit, c'est de REDÉRIVER une
 * règle métier — ici la COLLISION est calculée côté serveur, cette maison ne fait que
 * NOMMER une règle). MAISON UNIQUE partagée par la section Club de l'écran des
 * contraintes ET l'écran Semaine type.
 */

/** « 21:00 » → « 21h », « 21:30 » → « 21h30 », « 09:05 » → « 9h05 ». */
export function frClock(hhmm: string): string {
  const [h, m] = hhmm.split(":");
  const hour = String(Number(h));
  return "00" === m ? `${hour}h` : `${hour}h${m}`;
}

/** Le libellé d'une règle depuis ses bornes (chacune facultative). */
export function clubRuleLabel(rule: { kickoffMin: string | null; kickoffMax: string | null }): string {
  const { kickoffMin, kickoffMax } = rule;
  if (null !== kickoffMin && null !== kickoffMax) {
    return `entre ${frClock(kickoffMin)} et ${frClock(kickoffMax)}`;
  }
  if (null !== kickoffMax) {
    return `pas après ${frClock(kickoffMax)}`;
  }
  if (null !== kickoffMin) {
    return `pas avant ${frClock(kickoffMin)}`;
  }
  // Défensif : le serveur refuse une règle sans borne, mais ne jamais rendre vide.
  return "règle horaire";
}
