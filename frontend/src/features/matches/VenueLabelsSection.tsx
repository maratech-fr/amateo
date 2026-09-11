import { X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";

import type { Venue } from "./api";
import { useDetachVenueLabel } from "./queries";

interface PendingRemoval {
  venueId: string;
  venueName: string;
  label: string;
}

/**
 * P4-196 — voir / retirer les alias FBI/FFBB confirmés d'un gymnase (7ᵉ section de
 * `/matchs/configuration`). Le retrait ne touche AUCUNE rencontre déjà rattachée (le
 * `venueId` posé reste, seul l'alias qui l'a produit part) ; seuls les PROCHAINS
 * imports ne rattacheront plus par ce libellé — ce que dit exactement le refus du
 * geste « Rattacher » (« Retirez-le d'abord »).
 *
 * `venues === undefined` (chargement) ⇒ rendu NUL — jamais un `?? []` qui
 * fabriquerait un « aucun libellé » crédible sur une liste pas encore chargée
 * (`readState`). Les libellés sont affichés VERBATIM (la forme normalisée stockée),
 * et ce même libellé est renvoyé au DELETE — jamais re-normalisé/re-cassé côté client.
 *
 * Le repli de la section DÉMONTE ce composant, donc le `ConfirmDialog` avec lui, SANS
 * muter : le démontage passe par l'état, `pendingRemoval` disparaît sans confirmer —
 * direction fail-safe (annuler par défaut).
 */
export function VenueLabelsSection({ venues }: { venues: Venue[] | undefined }) {
  const detach = useDetachVenueLabel();
  const [pendingRemoval, setPendingRemoval] = useState<PendingRemoval | null>(null);

  if (undefined === venues) {
    return null;
  }

  const labelled = venues.filter((v) => v.externalLabels.length > 0);

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Les libellés sont enregistrés sous une forme simplifiée (minuscules, sans accents) : c'est la clé
        qui reconnaît la salle à l'import.
      </p>
      {0 === labelled.length ? (
        <EmptyHint>Aucun gymnase ne porte de libellé FFBB. Ils se rattachent depuis l'onglet Importer, sur un domicile sans gymnase.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-3">
          {labelled.map((venue) => (
            <li key={venue.id} className="flex flex-col gap-1.5">
              <span className="text-sm font-medium">{venue.name}</span>
              <div className="flex flex-wrap gap-1.5">
                {venue.externalLabels.map((label) => (
                  <span key={label} className="inline-flex items-center gap-1 rounded-md border border-border bg-muted px-2 py-0.5 text-xs">
                    {label}
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={`Retirer le libellé « ${label} » du gymnase ${venue.name}`}
                      onClick={() => setPendingRemoval({ venueId: venue.id, venueName: venue.name, label })}
                    >
                      <X className="size-3.5" />
                    </Button>
                  </span>
                ))}
              </div>
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        open={null !== pendingRemoval}
        title={null !== pendingRemoval ? `Retirer le libellé « ${pendingRemoval.label} » ?` : ""}
        description="Les matchs déjà rattachés gardent ce gymnase ; seuls les prochains imports ne le seront plus."
        confirmLabel="Retirer"
        destructive
        onConfirm={() => {
          if (null !== pendingRemoval) {
            detach.mutate({ venueId: pendingRemoval.venueId, label: pendingRemoval.label });
          }
          setPendingRemoval(null);
        }}
        onCancel={() => setPendingRemoval(null)}
      />
    </div>
  );
}
