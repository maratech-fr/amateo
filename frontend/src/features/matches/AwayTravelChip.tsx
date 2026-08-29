import { Car, MapPin, MapPinned } from "lucide-react";

import type { OpponentTravel } from "./api";

/**
 * P2-54 RMM-9 PR-3 — le trajet + la précision du lieu d'un match AWAY, en CHIP de
 * queue de ligne (patron du badge « heure estimée »). La précision est portée par
 * l'ICÔNE + le MOT, jamais la couleur seule ; l'approximation par le `~` et le mot
 * « approché », jamais une teinte. Tout (précision, minutes, `approximated`) vient
 * du BACKEND — on choisit seulement l'icône/le mot depuis l'enum (présentation).
 * Un trajet manquant est un état muted silencieux, JAMAIS un role=alert.
 */
export function AwayTravelChip({ travel }: { travel: OpponentTravel | undefined }) {
  if (undefined === travel || !travel.located || null === travel.precision) {
    return <span className="ml-1 text-xs text-muted-foreground">lieu inconnu</span>;
  }

  const isCity = "CITY" === travel.precision;
  const LocationIcon = isCity ? MapPin : MapPinned;
  const place = travel.locationName ?? "";
  const locationText = isCity ? `ville de ${place}` : place;

  return (
    <span className="ml-1 inline-flex items-center gap-1 text-xs text-muted-foreground">
      <LocationIcon className="size-3.5 shrink-0" aria-hidden="true" />
      <span>{locationText}</span>
      {null === travel.travelMinutes ? (
        <span>· trajet indisponible</span>
      ) : (
        <span
          className="inline-flex items-center gap-1"
          aria-label={`En voiture — trajet estimé à ${travel.travelMinutes} minutes jusqu'à ${place || "l'adversaire"}`}
        >
          ·<Car className="size-3.5 shrink-0" aria-hidden="true" />
          <span className="tabular-nums">
            {travel.approximated ? "~" : ""}
            {travel.travelMinutes} min
          </span>
          {travel.approximated ? <span className="rounded bg-muted px-1 uppercase tracking-wide">approché</span> : null}
        </span>
      )}
    </span>
  );
}
