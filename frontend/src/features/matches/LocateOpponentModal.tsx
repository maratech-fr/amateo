import { MapPin } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Modal } from "@/shared/components/ui/modal";
import { readState } from "@/shared/lib/readState";

import type { FfbbSalle, OpponentTravel } from "./api";
import { useFfbbSalles, useSetOpponentTravelManual } from "./queries";

/**
 * P2-54 RMM-9 PR-3 — la correction MANUELLE du lieu d'un adversaire : le
 * gestionnaire choisit un gymnase FFBB (proxy `/api/ffbb/salles`, patron combobox
 * de VenuesStep : champ CP + liste `<ul>`). La correction vaut pour TOUS les matchs
 * contre cet adversaire (donnée per-club/per-adversaire, jamais per-match, jamais
 * partagée). L'échec d'écriture remonte en toast (onError du hook, finding FRT-27).
 */
export function LocateOpponentModal({ opponent, onClose }: { opponent: OpponentTravel; onClose: () => void }) {
  const [cp, setCp] = useState("");
  const sallesQuery = useFfbbSalles(cp);
  const setManual = useSetOpponentTravelManual();

  const salles = sallesQuery.data?.salles ?? [];
  const cpReady = /^\d{5}$/.test(cp);
  const state = readState({ data: cpReady ? (sallesQuery.data ?? undefined) : {}, isError: sallesQuery.isError });

  const pick = (salle: FfbbSalle): void => {
    if (null === opponent.opponentOrganismeCode || null === salle.latitude || null === salle.longitude) {
      return;
    }
    setManual.mutate(
      {
        opponentOrganismeCode: opponent.opponentOrganismeCode,
        venueLabel: salle.name,
        venueExternalRef: salle.externalRef,
        latitude: Number(salle.latitude),
        longitude: Number(salle.longitude),
      },
      { onSuccess: () => onClose() },
    );
  };

  return (
    <Modal
      label="Localiser un adversaire"
      title={`Localiser ${opponent.opponentLabel}`}
      onClose={onClose}
      size="lg"
      footer={
        <Button variant="outline" size="sm" onClick={onClose}>
          Fermer
        </Button>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-xs text-muted-foreground">
          Choisissez le gymnase de cet adversaire — la correction vaut pour tous les matchs contre lui.
        </p>
        <Input
          aria-label="Commune (code postal)"
          placeholder="Code postal du gymnase"
          inputMode="numeric"
          maxLength={5}
          className="h-8 w-40"
          value={cp}
          onChange={(e) => setCp(e.target.value.replace(/\D/g, ""))}
        />
        {"failed" === state ? (
          <LoadErrorHint onRetry={() => void sallesQuery.refetch()}>FFBB indisponible, réessayez plus tard.</LoadErrorHint>
        ) : null}
        {cpReady && "ready" === state && 0 === salles.length ? (
          <EmptyHint>Aucune salle trouvée pour ce code postal.</EmptyHint>
        ) : null}
        {salles.length > 0 ? (
          <ul aria-label={`Salles FFBB à ${cp}`} className="max-h-64 overflow-y-auto rounded-md border border-border bg-background py-1 text-sm">
            {salles.map((salle) => {
              const noGeo = null === salle.latitude || null === salle.longitude;
              return (
                <li key={`${salle.externalRef ?? salle.name}-${salle.address ?? ""}`}>
                  <button
                    type="button"
                    disabled={noGeo || setManual.isPending}
                    className="flex w-full items-baseline gap-2 px-2.5 py-1.5 text-left hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
                    onClick={() => pick(salle)}
                  >
                    <MapPin className="size-3.5 shrink-0 translate-y-0.5 text-muted-foreground" aria-hidden="true" />
                    <span>
                      <span className="font-medium">{salle.name}</span>
                      {null !== salle.address ? <span className="text-muted-foreground"> · {salle.address}</span> : null}
                      {noGeo ? <span className="text-muted-foreground"> · sans coordonnées</span> : null}
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
        ) : null}
      </div>
    </Modal>
  );
}
