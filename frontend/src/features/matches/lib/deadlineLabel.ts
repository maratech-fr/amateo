/**
 * RMM-6 PR-2 — la PRÉSENTATION d'une échéance de saisie (le « J-N » sous une ligne
 * de match, la provenance dans l'éditeur). PURE présentation : on formate une date
 * DÉJÀ servie par le backend (`effectiveEntryDeadline`), on ne redérive AUCUNE règle
 * métier (🔴 le front n'invente pas la règle — la règle « club gagne » est calculée
 * côté serveur, cf. `.claude/rules/frontend.md`). Le « aujourd'hui » vient TOUJOURS du
 * front (`todayISO`, ancrage démo compris) — passé en argument, cette lib reste pure.
 */

/**
 * Jours calendaires SIGNÉS d'aujourd'hui à l'échéance : > 0 = à venir, 0 = ce jour,
 * < 0 = dépassée. Compte des jours (part DATE seule), jamais des heures.
 */
export function daysUntilDeadline(deadlineIso: string, todayIso: string): number {
  const [dy, dm, dd] = deadlineIso.slice(0, 10).split("-").map(Number);
  const [ty, tm, td] = todayIso.slice(0, 10).split("-").map(Number);
  return Math.round((Date.UTC(dy, dm - 1, dd) - Date.UTC(ty, tm - 1, td)) / 86_400_000);
}

export interface DeadlineDisplay {
  /** Phrase lisible : « avant le 10 sept. » à venir, « échéance dépassée » passée. */
  label: string;
  /** Le compte à rebours : « J-3 » · « aujourd'hui » · « J+2 » (dépassée). */
  countdown: string;
  /** Vraie quand l'échéance est PASSÉE — ton warning, jamais bloquant. */
  overdue: boolean;
}

/** « 10 sept. » — le jour + mois court, locale FR (même formatage que la liste FBI). */
export function frShortDate(deadlineIso: string): string {
  return new Date(`${deadlineIso.slice(0, 10)}T00:00:00`).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}

/**
 * Le trio {phrase, compte à rebours, dépassée ?} pour UNE échéance effective.
 * À venir → « avant le 10 sept. » + « J-3 » ; le jour même → « aujourd'hui » ;
 * dépassée → « échéance dépassée » + « J+2 », marquée `overdue`.
 */
export function deadlineDisplay(deadlineIso: string, todayIso: string): DeadlineDisplay {
  const days = daysUntilDeadline(deadlineIso, todayIso);
  if (days < 0) {
    return { label: "échéance dépassée", countdown: `J+${-days}`, overdue: true };
  }
  return { label: `avant le ${frShortDate(deadlineIso)}`, countdown: 0 === days ? "aujourd'hui" : `J-${days}`, overdue: false };
}
