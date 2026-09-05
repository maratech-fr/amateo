import { AlertTriangle } from "lucide-react";

import { stalenessBadge } from "@/features/planning/lib/staleness";
import { StatusPill } from "@/shared/components/ui/badge";

import type { SchedulePlanStaleness } from "./api";

/**
 * P4-173 — la pastille « à régénérer » du cockpit, sur les surfaces qui portent un plan (carte
 * Saison, radar, modale « Tous les plannings », ligne du jour). Elle AFFICHE la péremption SERVIE
 * par le backend (`SchedulePlan.staleness`) — aucune règle re-dérivée ici.
 *
 * `null`/absent → rien (pas de plan pointé, ou fenêtre révolue : le backend a déjà tranché). La
 * cause complète est le texte de la pastille (jamais un `title`) ; le texte visible EST l'annonce
 * (pas d'`aria-label`, pas de `role="alert"` — état stable, non cliquable : chaque surface a son CTA).
 */
export function StalenessPill({ staleness }: { staleness: SchedulePlanStaleness | null | undefined }) {
  const label = staleness ? stalenessBadge(staleness) : null;
  if (null === label) {
    return null;
  }

  return (
    <StatusPill variant="warning" icon={<AlertTriangle className="size-3.5 shrink-0 text-warning" aria-hidden="true" />}>
      {label}
    </StatusPill>
  );
}
