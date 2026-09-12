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
  /** P4-187b — domiciles importés avec un libellé de salle mais SANS gymnase rattaché
   * (ouvertes ET traitées confondues) : le geste « Rattacher » vit sur la ligne. */
  unattachedCount: number;
  open: Fixture[];
  treated: Fixture[];
}

/**
 * P4-187b — un domicile importé qui porte un libellé de salle FBI/FFBB mais aucun
 * gymnase rattaché : invisible de la collision de gymnase et de la fermeture tant que
 * son `venueId` n'est pas posé. Présentation pure (le backend a déjà tout calculé) —
 * on lit trois champs, on ne décide d'aucune règle métier.
 */
export function isUnattachedHome(fixture: Fixture): boolean {
  return "HOME" === fixture.homeAway && null === fixture.venueId && null !== fixture.fbiVenueLabel;
}

/**
 * P4-199 — une rencontre TRAITÉE (REVIEWED) peut porter un écart AUTO-APPLIQUÉ : la
 * source a imposé une valeur d'office (extérieur, ou domicile déphasé dans la semaine
 * en cours — « FBI fait foi ») pendant qu'elle était traitée. Elle reste traitée mais
 * mérite un « Pris en compte » (bandeau) — elle doit donc rester VISIBLE dans la file,
 * pas rangée avec les traitées sans alerte. Présentation pure (le backend a tranché).
 */
export function hasAutoAppliedDeviation(fixture: Fixture): boolean {
  return fixture.pendingDeviations.some((d) => d.autoApplied);
}

/** À traiter d'un geste ou à arbitrer : NEW, OUT_OF_SYNC, ou REVIEWED encore porteuse
 * d'une alerte auto-appliquée (à acquitter). Ce prédicat gouverne `open` ET le badge. */
function isOpenReview(fixture: Fixture): boolean {
  return "NEW" === fixture.reviewState || "OUT_OF_SYNC" === fixture.reviewState || ("REVIEWED" === fixture.reviewState && hasAutoAppliedDeviation(fixture));
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
      const open = teamFixtures.filter(isOpenReview).sort(byMatchDateAsc);
      // Traitées SANS alerte : une REVIEWED à écart auto-appliqué reste dans `open`.
      const treated = teamFixtures.filter((f) => "REVIEWED" === f.reviewState && !hasAutoAppliedDeviation(f)).sort(byMatchDateAsc);
      return {
        teamId,
        // « à valider » = les gestes en un clic : NEW + REVIEWED à acquitter (« Pris en compte »).
        toValidate: teamFixtures.filter((f) => "NEW" === f.reviewState || ("REVIEWED" === f.reviewState && hasAutoAppliedDeviation(f))).length,
        deviationCount: teamFixtures.filter((f) => "OUT_OF_SYNC" === f.reviewState).length,
        unattachedCount: teamFixtures.filter(isUnattachedHome).length,
        open,
        treated,
      };
    })
    .sort((a, b) => rank(a.teamId) - rank(b.teamId));
}

/** Combien de rencontres restent à traiter, tous équipes confondues (NEW +
 * OUT_OF_SYNC + REVIEWED à alerte auto-appliquée) — nourrit le badge de l'onglet
 * Importer : masquer ≠ traiter, mais une alerte non acquittée compte comme « à traiter ». */
export function pendingReviewCount(fixtures: Fixture[]): number {
  return fixtures.filter(isOpenReview).length;
}
