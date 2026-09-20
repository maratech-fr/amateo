import { Car, HelpCircle, MapPin, MapPinned } from "lucide-react";

import { StatusPill } from "@/shared/components/ui/badge";

import type { AwayTravel } from "./api";

/**
 * P2-54 — le trajet + le lieu d'un match AWAY, en CHIP de queue de ligne. Amendement
 * 2026-09-20 : lit `fixture.awayTravel` (le trajet DÉRIVÉ de la rencontre, tout serveur —
 * le front n'en dérive RIEN, il choisit l'icône/le mot depuis `basis`). Trois mots :
 * `most_frequent` → « gymnase supposé » (repli), `city` → « ville seule », et un trajet de
 * repli → « approché » (le tout en `StatusPill neutral`, jamais une teinte seule). Un trajet
 * manquant est un état muted silencieux, jamais un role=alert.
 */
export function AwayTravelChip({ travel }: { travel: AwayTravel | null | undefined }) {
  if (null === travel || undefined === travel) {
    return <span className="ml-1 text-xs text-muted-foreground">lieu inconnu</span>;
  }

  const place = travel.venueLabel ?? travel.city ?? "";
  const LocationIcon = "city" === travel.basis ? MapPin : "most_frequent" === travel.basis ? HelpCircle : MapPinned;

  return (
    <span className="ml-1 inline-flex items-center gap-1 text-xs text-muted-foreground">
      <LocationIcon className="size-3.5 shrink-0" aria-hidden="true" />
      <span>{place}</span>
      {"most_frequent" === travel.basis ? (
        <StatusPill variant="warning" className="text-[10px]">
          gymnase supposé
        </StatusPill>
      ) : null}
      {"city" === travel.basis ? (
        <StatusPill variant="warning" className="text-[10px]">
          ville seule
        </StatusPill>
      ) : null}
      {null === travel.oneWayMinutes ? (
        <span>· trajet indisponible</span>
      ) : (
        <>
          <span aria-hidden="true">·</span>
          <TravelMinutes minutes={travel.oneWayMinutes} approximated={travel.approximated} place={place} />
        </>
      )}
    </span>
  );
}

/**
 * Le FRAGMENT « voiture + minutes (+ approché) » d'un trajet estimé — réutilisé tel quel par la
 * colonne « Trajet » du tableau des adversaires. `tabular-nums` sur le nombre ; l'approximation
 * par `~` + le mot « approché » en `StatusPill neutral` (jamais une teinte seule, dette soldée
 * 2026-09-20). L'`aria-label` porte la phrase complète (le `·` et l'icône restent décoratifs).
 */
export function TravelMinutes({ minutes, approximated, place }: { minutes: number; approximated: boolean; place: string }) {
  return (
    <span className="inline-flex items-center gap-1" aria-label={`En voiture — trajet estimé à ${minutes} minutes jusqu'à ${place || "l'adversaire"}`}>
      <Car className="size-3.5 shrink-0" aria-hidden="true" />
      <span className="tabular-nums">
        {approximated ? "~" : ""}
        {minutes} min
      </span>
      {approximated ? (
        <StatusPill variant="neutral" className="text-[10px]">
          approché
        </StatusPill>
      ) : null}
    </span>
  );
}
