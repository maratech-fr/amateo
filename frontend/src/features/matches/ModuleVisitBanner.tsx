import { Sparkles } from "lucide-react";
import { Fragment } from "react";
import { Link } from "react-router";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import type { ModuleVisitDelta } from "./api";
import { moduleVisitSummary } from "./lib/moduleVisitSummary";
import { visitDeltaLinkedSegments } from "./lib/visitDeltaSegments";
import { MATCHES_TEXTS } from "./texts";

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
  if (0 === moduleVisitSummary(delta).length) {
    return null;
  }
  // B1 — chaque morceau mène là où agir (Importer, conflits nouveaux) ; sans écran unique, un texte.
  const segments = visitDeltaLinkedSegments(delta);

  return (
    <NoticeBanner
      tone="accent"
      role="status"
      icon={<Sparkles className="size-4 text-accent" />}
      message={
        <>
          <span className="font-medium">{MATCHES_TEXTS.sinceLastVisit}</span>{" "}
          {segments.map((segment, index) => (
            <Fragment key={segment.label}>
              {0 === index ? null : " · "}
              {null === segment.to ? (
                segment.label
              ) : (
                <Link to={segment.to} className="underline underline-offset-2 hover:no-underline">
                  {segment.label}
                </Link>
              )}
            </Fragment>
          ))}
        </>
      }
    />
  );
}
