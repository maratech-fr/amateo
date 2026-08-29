import type { ModuleVisitDelta } from "../api";
import { visitDeltaSegments } from "./visitDeltaSegments";

/**
 * RMM-3 — les segments NON NULS du résumé de visite, dans l'ordre du geste du
 * gestionnaire : d'abord ce qui est ARRIVÉ (matchs à traiter), puis les CONFLITS à
 * régler, puis le signal « le planning de saison a changé » — l'invite à aller
 * vérifier ses placements (une régénération d'entraînement peut invalider des matchs
 * posés, §6quinquies). Fonction pure : une première visite ou un delta vide rend une
 * liste VIDE, et le bandeau ne s'affiche pas du tout.
 */
export function moduleVisitSummary(delta: ModuleVisitDelta): string[] {
  if (delta.firstVisit) {
    return [];
  }
  return visitDeltaSegments(delta);
}
