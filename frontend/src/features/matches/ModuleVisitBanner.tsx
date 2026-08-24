import { Sparkles } from "lucide-react";

import type { ModuleVisitDelta } from "./api";

interface ModuleVisitBannerProps {
  /** Le delta du « gardien » ; `undefined` tant que la requête n'est pas résolue. */
  delta: ModuleVisitDelta | undefined;
}

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

/**
 * Le bandeau du « gardien » à l'ouverture — un HEADS-UP amical (« voici ce qui a
 * bougé depuis la dernière fois »), PAS une alarme : ton accent, distinct du bandeau
 * des conflits sans date (warning) et du bandeau d'erreur socle (destructive). Non
 * dismissible (décision passe design) : le delta est par-visite et ne revient pas de
 * la session (grâce serveur + `staleTime: Infinity`) — un contrôle de fermeture
 * ajouterait de l'état pour rien, et le bandeau ne paraît QUE quand il y a du neuf.
 * `role="status"` : un résumé lisible par le lecteur d'écran, jamais un `alert`.
 */
export function ModuleVisitBanner({ delta }: ModuleVisitBannerProps) {
  if (undefined === delta) {
    return null;
  }
  const segments = moduleVisitSummary(delta);
  if (0 === segments.length) {
    return null;
  }

  return (
    <div role="status" className="flex items-start gap-2 rounded-md border border-accent/40 bg-accent/5 px-3 py-2 text-sm text-foreground">
      <Sparkles className="mt-0.5 size-4 shrink-0 text-accent" aria-hidden="true" />
      <p>
        <span className="font-medium">Depuis votre dernière visite :</span> {segments.join(" · ")}
      </p>
    </div>
  );
}
