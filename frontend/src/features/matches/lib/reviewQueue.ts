import type { Fixture } from "../api";

/**
 * PR-3b — la file de traitement de l'onglet Importer, dérivée CÔTÉ CLIENT des
 * rencontres (pas d'endpoint agrégé, décision fondateur PR-3a). Présentation
 * pure : rien ici ne décide d'un comportement métier, on compte et on trie ce que
 * le backend a déjà calculé (`reviewState`).
 *
 * Une équipe SANS rencontre est absente de la file (rien à traiter). `open` = les
 * rencontres NEW|OUT_OF_SYNC (à traiter), triées par date de match ASC ; `treated`
 * = les REVIEWED. `toValidate` = combien de NEW ; `deviationCount` = combien de
 * rencontres OUT_OF_SYNC (une rencontre = une unité, jamais le nombre de champs).
 */
export interface TeamQueue {
  teamId: string;
  toValidate: number;
  deviationCount: number;
  open: Fixture[];
  treated: Fixture[];
}

function byMatchDateAsc(a: Fixture, b: Fixture): number {
  return a.matchDate < b.matchDate ? -1 : a.matchDate > b.matchDate ? 1 : 0;
}

/** Ordre des équipes = `teamOrder` (l'appelant le dérive de `compareTeamsByRank`,
 * même tri que `TeamSelect`). Une équipe hors de cet ordre (dérive de données)
 * échoue en fin de liste plutôt que de disparaître. */
export function buildReviewQueue(fixtures: Fixture[], teamOrder: string[]): TeamQueue[] {
  const byTeam = new Map<string, Fixture[]>();
  for (const fixture of fixtures) {
    const bucket = byTeam.get(fixture.teamId);
    if (undefined === bucket) {
      byTeam.set(fixture.teamId, [fixture]);
    } else {
      bucket.push(fixture);
    }
  }

  const rank = (teamId: string): number => {
    const i = teamOrder.indexOf(teamId);
    return -1 === i ? Number.MAX_SAFE_INTEGER : i;
  };

  return [...byTeam.entries()]
    .map(([teamId, teamFixtures]): TeamQueue => {
      const open = teamFixtures.filter((f) => "NEW" === f.reviewState || "OUT_OF_SYNC" === f.reviewState).sort(byMatchDateAsc);
      const treated = teamFixtures.filter((f) => "REVIEWED" === f.reviewState).sort(byMatchDateAsc);
      return {
        teamId,
        toValidate: teamFixtures.filter((f) => "NEW" === f.reviewState).length,
        deviationCount: teamFixtures.filter((f) => "OUT_OF_SYNC" === f.reviewState).length,
        open,
        treated,
      };
    })
    .sort((a, b) => rank(a.teamId) - rank(b.teamId));
}

/** Combien de rencontres restent à traiter, tous équipes confondues (NEW +
 * OUT_OF_SYNC) — nourrit le badge de l'onglet Importer. */
export function pendingReviewCount(fixtures: Fixture[]): number {
  return fixtures.filter((f) => "NEW" === f.reviewState || "OUT_OF_SYNC" === f.reviewState).length;
}
