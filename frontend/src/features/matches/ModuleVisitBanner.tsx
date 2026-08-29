import { Sparkles } from "lucide-react";

import type { ModuleVisitDelta } from "./api";
import { moduleVisitSummary } from "./lib/moduleVisitSummary";

interface ModuleVisitBannerProps {
  /** Le delta du « gardien » ; `undefined` tant que la requête n'est pas résolue. */
  delta: ModuleVisitDelta | undefined;
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
