import { Building2, MapPin } from "lucide-react";
import { useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { readState } from "@/shared/lib/readState";
import { toast } from "@/shared/stores/toastStore";

import type { FfbbSalle, OpponentTravel, OpponentTravelScope, VenueSuggestion } from "./api";
import { useFfbbSalles, useSetOpponentTravelManual, useVenueSuggestions } from "./queries";

/**
 * P2-54 « adversaire multi-gymnases » PR-3 — la correction MANUELLE du lieu d'un adversaire.
 * Deux façons de choisir un gymnase, un SEUL geste de validation (le clic sur un gymnase) :
 *  1. « Gymnases connus » — les suggestions PARTAGÉES du club (`useVenueSuggestions`, données
 *     fédérales, « un compte jamais un qui ») ;
 *  2. « Ajouter un gymnase » — la recherche FFBB par code postal (préremplie), patron VenuesStep.
 * La PORTÉE (cette équipe / tout le club) est choisie en tête ; depuis la ligne club elle est
 * verrouillée sur « tout le club ». L'échec d'écriture remonte en toast (onError du hook, la
 * modale reste ouverte) ; le succès ferme et confirme.
 */
export function LocateOpponentModal({
  opponent,
  clubLabel,
  lockedToClub,
  onClose,
}: {
  opponent: OpponentTravel;
  clubLabel: string;
  lockedToClub: boolean;
  onClose: () => void;
}) {
  const code = opponent.opponentOrganismeCode ?? "";
  const [scope, setScope] = useState<OpponentTravelScope>(lockedToClub ? "CLUB" : "TEAM");
  const [cp, setCp] = useState(opponent.postalCode ?? "");
  const [pendingKey, setPendingKey] = useState<string | null>(null);

  const suggestionsQuery = useVenueSuggestions(code);
  const sallesQuery = useFfbbSalles(cp);
  const setManual = useSetOpponentTravelManual();

  const displayName = lockedToClub ? `${clubLabel}, toutes les équipes` : opponent.opponentLabel;

  const suggestions = suggestionsQuery.data ?? [];
  const suggestionsState = readState(suggestionsQuery);

  const salles = sallesQuery.data?.salles ?? [];
  const cpReady = /^\d{5}$/.test(cp);
  const sallesState = readState({ data: cpReady ? (sallesQuery.data ?? undefined) : {}, isError: sallesQuery.isError });

  /** Un seul geste : un clic pose la surcharge MANUELLE (portée courante) et ferme au succès. */
  const submitManual = (venueLabel: string, venueExternalRef: string | null, latitude: number, longitude: number, key: string): void => {
    if ("" === code) {
      return;
    }
    setPendingKey(key);
    setManual.mutate(
      {
        opponentOrganismeCode: code,
        venueLabel,
        venueExternalRef,
        latitude,
        longitude,
        // TEAM → on épingle CETTE équipe ; CLUB → le défaut du club (jamais un teamKey).
        ...("TEAM" === scope ? { opponentTeamKey: opponent.opponentTeamKey, scope: "TEAM" as const } : { scope: "CLUB" as const }),
      },
      {
        onSuccess: () => {
          toast.success(`Gymnase enregistré pour ${displayName}.`);
          onClose();
        },
        onSettled: () => setPendingKey(null),
      },
    );
  };

  return (
    <Modal
      label="Localiser un adversaire"
      title={`Localiser ${displayName}`}
      onClose={onClose}
      size="lg"
      footer={
        <Button variant="outline" size="sm" onClick={onClose}>
          Fermer
        </Button>
      }
    >
      <div className="flex flex-col gap-4">
        {/* Portée de la correction — verrouillée « tout le club » depuis la ligne club. */}
        <fieldset className="flex flex-col gap-1.5" aria-describedby="locate-scope-help">
          <legend className="text-xs font-medium">Portée de la correction</legend>
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
            {lockedToClub ? null : (
              <label className="flex items-center gap-1.5">
                <input type="radio" name="locate-scope" value="TEAM" checked={"TEAM" === scope} onChange={() => setScope("TEAM")} />
                Pour cette équipe
              </label>
            )}
            <label className="flex items-center gap-1.5">
              <input type="radio" name="locate-scope" value="CLUB" checked={"CLUB" === scope} onChange={() => setScope("CLUB")} disabled={lockedToClub} />
              Pour tout le club
            </label>
          </div>
          <p id="locate-scope-help" className="text-xs text-muted-foreground">
            Tout le club = défaut, n'écrase pas une équipe déjà choisie.
          </p>
        </fieldset>

        {/* Section 1 — les gymnases DÉJÀ connus de cet adversaire (suggestions partagées). */}
        <div className="flex flex-col gap-2">
          <h5 className="text-sm font-medium">Gymnases connus</h5>
          {"loading" === suggestionsState ? <EmptyHint>Recherche des gymnases connus…</EmptyHint> : null}
          {"failed" === suggestionsState ? (
            <LoadErrorHint onRetry={() => void suggestionsQuery.refetch()}>Suggestions indisponibles — cherchez par code postal ci-dessous.</LoadErrorHint>
          ) : null}
          {"ready" === suggestionsState && 0 === suggestions.length ? (
            <EmptyHint>Aucun gymnase connu pour ce club — cherchez-le par code postal.</EmptyHint>
          ) : null}
          {suggestions.length > 0 ? (
            <ul aria-label={`Gymnases connus de ${clubLabel}`} className="flex max-h-64 flex-col gap-1 overflow-y-auto">
              {suggestions.map((suggestion) => (
                <SuggestionButton
                  key={`sugg-${suggestion.externalRef ?? suggestion.label}`}
                  suggestion={suggestion}
                  outline={1 === suggestions.length}
                  pending={pendingKey === `sugg-${suggestion.externalRef ?? suggestion.label}`}
                  disabled={setManual.isPending}
                  onPick={() =>
                    submitManual(
                      suggestion.label,
                      suggestion.externalRef,
                      suggestion.latitude as number,
                      suggestion.longitude as number,
                      `sugg-${suggestion.externalRef ?? suggestion.label}`,
                    )
                  }
                />
              ))}
            </ul>
          ) : null}
        </div>

        {/* Section 2 — ajouter un gymnase par recherche FFBB (code postal préremplie). */}
        <div className="flex flex-col gap-2">
          <h5 className="text-sm font-medium">Ajouter un gymnase</h5>
          <Input
            aria-label="Commune (code postal)"
            placeholder="Code postal du gymnase"
            inputMode="numeric"
            maxLength={5}
            className="h-8 w-full sm:w-40"
            value={cp}
            onChange={(e) => setCp(e.target.value.replace(/\D/g, ""))}
          />
          {"failed" === sallesState ? (
            <LoadErrorHint onRetry={() => void sallesQuery.refetch()}>FFBB indisponible, réessayez plus tard.</LoadErrorHint>
          ) : null}
          {cpReady && "ready" === sallesState && 0 === salles.length ? <EmptyHint>Aucune salle trouvée pour ce code postal.</EmptyHint> : null}
          {salles.length > 0 ? (
            <ul aria-label={`Salles FFBB à ${cp}`} className="max-h-64 overflow-y-auto rounded-md border border-border bg-background py-1 text-sm">
              {salles.map((salle) => {
                const key = `salle-${salle.externalRef ?? salle.name}-${salle.address ?? ""}`;
                return (
                  <SalleButton
                    key={key}
                    salle={salle}
                    pending={pendingKey === key}
                    disabled={setManual.isPending}
                    onPick={() => submitManual(salle.name, salle.externalRef, Number(salle.latitude), Number(salle.longitude), key)}
                  />
                );
              })}
            </ul>
          ) : null}
        </div>
      </div>
    </Modal>
  );
}

function SuggestionButton({
  suggestion,
  outline,
  pending,
  disabled,
  onPick,
}: {
  suggestion: VenueSuggestion;
  outline: boolean;
  pending: boolean;
  disabled: boolean;
  onPick: () => void;
}) {
  const noGeo = null === suggestion.latitude || null === suggestion.longitude;
  const noRef = null === suggestion.externalRef;
  return (
    <li>
      <button
        type="button"
        disabled={noGeo || disabled}
        className={
          "flex w-full flex-col gap-0.5 rounded-md px-2.5 py-1.5 text-left text-sm hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
          + (outline ? " border border-border" : "")
        }
        onClick={onPick}
      >
        <span className="flex flex-wrap items-center gap-1.5">
          {pending ? <Spinner className="size-3.5" /> : <Building2 className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />}
          <span className="font-medium">{suggestion.label}</span>
          {null !== suggestion.city ? <span className="text-muted-foreground">{suggestion.city}</span> : null}
          {"FFBB_API" === suggestion.source ? (
            <StatusPill>vu sur FFBB</StatusPill>
          ) : (
            <StatusPill>choisi {suggestion.chosenByCount} fois</StatusPill>
          )}
        </span>
        {noRef && !noGeo ? <span className="text-xs text-muted-foreground">sans n° de salle — votre choix restera propre à votre club</span> : null}
        {noGeo ? <span className="text-xs text-muted-foreground">sans coordonnées</span> : null}
      </button>
    </li>
  );
}

function SalleButton({ salle, pending, disabled, onPick }: { salle: FfbbSalle; pending: boolean; disabled: boolean; onPick: () => void }) {
  const noGeo = null === salle.latitude || null === salle.longitude;
  return (
    <li>
      <button
        type="button"
        disabled={noGeo || disabled}
        className="flex w-full items-baseline gap-2 px-2.5 py-1.5 text-left hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
        onClick={onPick}
      >
        {pending ? <Spinner className="size-3.5 shrink-0 translate-y-0.5" /> : <MapPin className="size-3.5 shrink-0 translate-y-0.5 text-muted-foreground" aria-hidden="true" />}
        <span>
          <span className="font-medium">{salle.name}</span>
          {null !== salle.address ? <span className="text-muted-foreground"> · {salle.address}</span> : null}
          {noGeo ? <span className="text-muted-foreground"> · sans coordonnées</span> : null}
        </span>
      </button>
    </li>
  );
}
