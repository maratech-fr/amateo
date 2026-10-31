import { Button } from "@/shared/components/ui/button";

import type { FfbbSalle, Venue } from "../api";
import { WIZARD_TEXTS } from "../texts";

/**
 * Une ligne de résultat de salle FFBB, partagée par l'accordéon d'ajout et la modale d'association :
 * nom, adresse et ville (ce qui distingue deux homonymes), et l'action. Une salle déjà portée par un
 * gymnase du club (reconnue à son NUMÉRO fédéral) reste lisible mais fermée au geste.
 *
 * `accessibleName` nomme la salle pour un lecteur d'écran quand le libellé visible est répété en liste.
 */
export function SalleResultRow({
  salle,
  venues,
  action,
}: {
  salle: FfbbSalle;
  venues: Venue[];
  action: { label: string; onPick: (salle: FfbbSalle) => void; disabled?: boolean; accessibleName?: string };
}) {
  const linked = null !== salle.externalRef && venues.some((venue) => venue.externalRef === salle.externalRef);
  const location = WIZARD_TEXTS.associateVenueAddress(salle);

  return (
    <li className="flex items-center gap-2 text-sm">
      <span className="flex min-w-0 flex-1 flex-col">
        <span className={linked ? "text-muted-foreground" : "font-medium"}>{salle.name}</span>
        {"" === location ? null : <span className="truncate text-xs text-muted-foreground">{location}</span>}
      </span>
      {linked ? <span className="shrink-0 text-xs text-muted-foreground">{WIZARD_TEXTS.alreadyLinkedTag}</span> : null}
      <Button
        type="button"
        size="sm"
        variant="outline"
        className="shrink-0"
        aria-label={action.accessibleName}
        disabled={linked || action.disabled}
        disabledReason={linked ? WIZARD_TEXTS.salleAlreadyLinked : undefined}
        onClick={() => action.onPick(salle)}
      >
        {action.label}
      </Button>
    </li>
  );
}
