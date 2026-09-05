import { MapPinOff, RotateCcw, RefreshCw } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { readState } from "@/shared/lib/readState";

import type { OpponentTravel } from "./api";
import { AwayTravelChip } from "./AwayTravelChip";
import { LocateOpponentModal } from "./LocateOpponentModal";
import { useOpponentTravel, useResolveOpponentTravel, useSetOpponentTravelAuto } from "./queries";
import { SourceBadge } from "./SourceBadge";

/**
 * P2-54 RMM-9 PR-3 — l'écran SET-UP du trajet adverse : où joue chaque adversaire,
 * le temps de trajet estimé depuis le siège du club, et la correction manuelle.
 * Acte de début de saison (stable per-adversaire), pas la boucle hebdo. Les
 * adversaires non localisés sont mis en avant (encart warning) ; tout le reste
 * s'affiche avec sa source AUTO/MANUEL et le geste de correction.
 */
export function OpponentTravelCard() {
  const travelQuery = useOpponentTravel();
  const resolve = useResolveOpponentTravel();
  const revert = useSetOpponentTravelAuto();
  const [locating, setLocating] = useState<OpponentTravel | null>(null);

  const state = readState(travelQuery);
  const opponents = travelQuery.data ?? [];
  const unlocated = opponents.filter((o) => !o.located);
  const located = opponents.filter((o) => o.located);

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Précisez le gymnase de ces adversaires pour estimer le trajet de vos coachs. La correction vaut pour
          tous les matchs contre cet adversaire.
        </p>
        <Button variant="ghost" size="sm" className="shrink-0" disabled={resolve.isPending} onClick={() => resolve.mutate()}>
          <RefreshCw className="size-4" aria-hidden="true" />
          {resolve.isPending ? "Recalcul…" : "Recalculer les trajets"}
        </Button>
      </div>

      {"failed" === state ? <LoadErrorHint onRetry={() => void travelQuery.refetch()} /> : null}
      {"loading" === state ? <EmptyHint>Chargement…</EmptyHint> : null}

      {"ready" === state ? (
        <>
          {0 === opponents.length ? (
            <EmptyHint>Aucun match à l'extérieur cette saison.</EmptyHint>
          ) : null}

          {unlocated.length > 0 ? (
            <div className="rounded-md border border-warning/40 bg-warning/10 p-3">
              <h4 className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                <MapPinOff className="size-4 shrink-0" aria-hidden="true" />
                Adversaires à localiser
              </h4>
              <ul className="flex flex-col gap-1.5">
                {unlocated.map((opponent) => (
                  <li key={opponent.opponentLabel} className="flex items-center justify-between gap-2 text-sm">
                    <span>
                      {opponent.opponentLabel}
                      {null === opponent.opponentOrganismeCode ? (
                        <span className="ml-1 text-xs text-muted-foreground">· code fédéral non résolu</span>
                      ) : null}
                    </span>
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={null === opponent.opponentOrganismeCode}
                      aria-label={`Localiser l'adversaire ${opponent.opponentLabel}`}
                      onClick={() => setLocating(opponent)}
                    >
                      Localiser
                    </Button>
                  </li>
                ))}
              </ul>
            </div>
          ) : opponents.length > 0 ? (
            <EmptyHint>Tous vos adversaires sont localisés — les temps de trajet sont estimés automatiquement.</EmptyHint>
          ) : null}

          {located.length > 0 ? (
            <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
              {located.map((opponent) => (
                <li key={opponent.opponentLabel} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                  <span className="flex min-w-0 flex-wrap items-center gap-x-1">
                    <span className="font-medium">{opponent.opponentLabel}</span>
                    <AwayTravelChip travel={opponent} />
                  </span>
                  <span className="flex shrink-0 items-center gap-1">
                    {null !== opponent.source ? <SourceBadge source={opponent.source} /> : null}
                    <Button variant="ghost" size="sm" disabled={null === opponent.opponentOrganismeCode} onClick={() => setLocating(opponent)}>
                      Localiser
                    </Button>
                    {"MANUAL" === opponent.source && null !== opponent.opponentOrganismeCode ? (
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`Rétablir la localisation automatique de ${opponent.opponentLabel}`}
                        disabled={revert.isPending}
                        onClick={() => revert.mutate(opponent.opponentOrganismeCode as string)}
                      >
                        <RotateCcw className="size-3.5" aria-hidden="true" />
                        Rétablir l'automatique
                      </Button>
                    ) : null}
                  </span>
                </li>
              ))}
            </ul>
          ) : null}
        </>
      ) : null}

      {null !== locating ? <LocateOpponentModal opponent={locating} onClose={() => setLocating(null)} /> : null}
    </div>
  );
}
