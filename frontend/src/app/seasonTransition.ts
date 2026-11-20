/** Season year of an ISO date, using the July-15 pivot. */
export function seasonYearOf(iso: string): number {
  const year = Number(iso.slice(0, 4));

  return iso.slice(5, 10) >= "07-15" ? year : year - 1;
}

/**
 * D-29 — `localIso` était une COPIE de `toISODate` (même corps, même commentaire sur le
 * piège de `toISOString`). Le foyer est `shared/lib/clock`, qui porte en plus l'« aujourd'hui »
 * SERVEUR d'un club démo : sans lui, le cockpit vivait à la date simulée pendant que la
 * bannière de bascule et le sélecteur lisaient l'horloge réelle — le nudge apparaissait au
 * mauvais moment, sans rien pour l'expliquer.
 */
export { toISODate as localIso } from "@/shared/lib/clock";

interface SeasonBounds {
  startDate: string;
  endDate: string;
  isCurrent: boolean;
}

/**
 * Fenêtre UNIQUE « préparer la saison suivante » — source partagée par la bannière
 * d'anticipation ET l'item du sélecteur (revue D : les deux divergeaient). ANCRÉE
 * SUR AUJOURD'HUI (nudge même un club dormant avant CHAQUE pivot du 15 juillet).
 *
 * Ouvre à `opensAt`, la date SERVIE par `/api/me` (`seasonPreparationOpensOn`) : le serveur
 * seul tient la borne basse et refuse la bascule avant. Ferme, à l'écran seulement, à la FIN
 * RÉELLE de la saison courante (retour fondateur 2026-07-19) — ou au pivot du 15 juillet si la
 * saison courante n'est pas celle qui précède ce pivot (club dormant). Masquée si un
 * successeur existe déjà. Clamp anti-fenêtre-inversée : une saison qui finit AVANT
 * l'ouverture retombe sur le pivot (sinon aucune fenêtre → aucun nudge).
 *
 * `inWindow` = dans la plage de dates ; `successorExists` = un N+1 est déjà préparé.
 * Le MENU d'action reste cliquable sur `inWindow` seul (préparer 2× réutilise
 * gracieusement le brouillon existant via un 409 serveur — flux conçu, e2e) ; la
 * BANNIÈRE (nag) se masque en plus quand `successorExists`.
 *
 * @param opensAt borne basse ISO « AAAA-MM-JJ » (menu = la date servie, bannière = le 15 du même mois).
 */
export function seasonPrepWindow(
  todayIso: string,
  seasons: SeasonBounds[],
  opensAt: string,
): { inWindow: boolean; successorExists: boolean; deadline: string } {
  const anchorYear = seasonYearOf(todayIso);
  const pivotYear = anchorYear + 1;
  const pivotEnd = `${pivotYear}-07-15`;
  const successorExists = seasons.some((s) => seasonYearOf(s.startDate) > anchorYear);
  const current = seasons.find((s) => s.isCurrent) ?? null;
  const seasonEnd = null !== current && seasonYearOf(current.startDate) === anchorYear ? current.endDate : pivotEnd;
  const deadline = seasonEnd < opensAt ? pivotEnd : seasonEnd;
  const inWindow = todayIso >= opensAt && todayIso <= deadline;
  return { inWindow, successorExists, deadline };
}

/** « 15 juillet » — jour + mois FR d'une date ISO, pour le libellé de deadline. */
export function frDayMonth(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString("fr-FR", { day: "numeric", month: "long" });
}
