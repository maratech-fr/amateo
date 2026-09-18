/**
 * RMM-6 — la formulation PARTAGÉE du « delta de visite ». Les segments NON NULS du
 * résumé « depuis la dernière visite », dans l'ordre du geste du gestionnaire :
 * d'abord ce qui est ARRIVÉ (matchs à traiter), puis les CONFLITS neufs, puis le
 * signal « le planning de saison a changé ». Fonction PURE, sans le voile
 * `firstVisit` (propre au bandeau du gardien). Extraite de `moduleVisitSummary`
 * (RMM-3) pour être consommée aux DEUX endroits — le bandeau du module ET la tuile
 * cockpit (RMM-6 PR-3) — sans copier-coller de composant : une seule maison pour la
 * tournure française et les singuliers/pluriels.
 */

/** Les trois compteurs d'un delta de visite (le sous-ensemble partagé du gardien). */
export interface VisitDelta {
  newFixturesCount: number;
  newConflictFingerprints: string[];
  planningChanged: boolean;
}

export function visitDeltaSegments(delta: VisitDelta): string[] {
  // FRT-37 — garde de forme (maison unique). Un delta MALFORMÉ (payload API partiel, `{}`) lisait
  // `.newConflictFingerprints.length` sur `undefined` et abattait la route — cette fonction alimente
  // le bandeau du module ET la tuile cockpit. Compteur non-number / fingerprints non-array / booléen
  // absent → aucun segment → bandeau et tuile absents, page indemne (aucune error boundary requise).
  if (
    "number" !== typeof delta.newFixturesCount ||
    !Array.isArray(delta.newConflictFingerprints) ||
    "boolean" !== typeof delta.planningChanged
  ) {
    return [];
  }
  const segments: string[] = [];
  if (delta.newFixturesCount > 0) {
    segments.push(`${delta.newFixturesCount} match${delta.newFixturesCount > 1 ? "s" : ""} arrivé${delta.newFixturesCount > 1 ? "s" : ""}`);
  }
  const conflicts = delta.newConflictFingerprints.length;
  if (conflicts > 0) {
    segments.push(`${conflicts} nouveau${conflicts > 1 ? "x" : ""} conflit${conflicts > 1 ? "s" : ""}`);
  }
  if (delta.planningChanged) {
    segments.push("le planning de saison a changé");
  }
  return segments;
}
