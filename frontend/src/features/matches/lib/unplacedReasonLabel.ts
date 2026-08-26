import type { Fixture } from "../api";

/**
 * P2-52 (RMM-10) — PRÉSENTATION pure : le libellé d'une raison de dépointage PERSISTANTE servie
 * par le backend (`Fixture.unplacedReason`). Un mapping enum→libellé est de la présentation, pas
 * une règle métier (patron `matches/lib/diagnostic.ts`) : le front NOMME ce que le backend a
 * décidé, il ne re-dérive rien.
 *
 * Distinct de la raison VOLATILE d'auto-placement (« demandez votre dérogation tôt »), qui vit
 * dans l'UI et reste en ton d'alerte. Celle-ci est une INFO calme : le match est récupérable.
 */
const UNPLACED_REASON_LABEL: Record<NonNullable<Fixture["unplacedReason"]>, string> = {
  venue_lost: "Le gymnase n'est plus affilié au club",
};

export function unplacedReasonLabel(reason: Fixture["unplacedReason"]): string | null {
  return null === reason ? null : UNPLACED_REASON_LABEL[reason];
}
