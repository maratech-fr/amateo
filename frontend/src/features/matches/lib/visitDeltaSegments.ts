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

/** Un segment du delta et l'écran où agir (`null` : aucun écran unique, le segment reste un texte). */
export interface VisitDeltaLinkedSegment {
  label: string;
  to: string | null;
}

export function visitDeltaSegments(delta: VisitDelta): string[] {
  return visitDeltaLinkedSegments(delta).map((segment) => segment.label);
}

/**
 * B1 — les mêmes segments, chacun avec sa destination : les matchs arrivés mènent à l'Importer, les
 * conflits neufs aux seuls conflits nouveaux (`?nouveaux=1`), « le planning a changé » à aucun écran.
 */
export function visitDeltaLinkedSegments(delta: VisitDelta): VisitDeltaLinkedSegment[] {
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
  const segments: VisitDeltaLinkedSegment[] = [];
  if (delta.newFixturesCount > 0) {
    segments.push({ label: `${delta.newFixturesCount} match${delta.newFixturesCount > 1 ? "s" : ""} arrivé${delta.newFixturesCount > 1 ? "s" : ""}`, to: "/matchs/importer" });
  }
  const conflicts = delta.newConflictFingerprints.length;
  if (conflicts > 0) {
    segments.push({ label: `${conflicts} nouveau${conflicts > 1 ? "x" : ""} conflit${conflicts > 1 ? "s" : ""}`, to: "/matchs/conflits?nouveaux=1" });
  }
  if (delta.planningChanged) {
    segments.push({ label: "le planning de saison a changé", to: null });
  }
  return segments;
}
