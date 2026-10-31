import { useState } from "react";

import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { readState } from "@/shared/lib/readState";

import type { FfbbSalle, Venue } from "../api";
import { useFfbbSallesByName, useFfbbSallesProches } from "../queries";
import { WIZARD_TEXTS } from "../texts";
import { SalleResultRow } from "./SalleResultRow";

/** Seuil de la recherche par nom dans la FFBB : même valeur que le serveur. */
const MIN_NAME_SEARCH_LENGTH = 3;

const norm = (value: string): string =>
  value
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");

/** Sans numéro fédéral, une salle n'est pas associable ; présente deux fois, elle n'a qu'une ligne. */
function associableRows(salles: FfbbSalle[]): FfbbSalle[] {
  const seen = new Set<string>();
  return salles.filter((salle) => {
    if (null === salle.externalRef || seen.has(salle.externalRef)) {
      return false;
    }
    seen.add(salle.externalRef);
    return true;
  });
}

function AssociateSalleSearch({ venue, venues, onPick, onClose }: { venue: Venue; venues: Venue[]; onPick: (salle: FfbbSalle) => void; onClose: () => void }) {
  const [query, setQuery] = useState("");
  const typed = query.trim();
  const searching = typed.length >= MIN_NAME_SEARCH_LENGTH;
  const nearby = useFfbbSallesProches(null);
  const national = useFfbbSallesByName(typed);
  const nationalState = readState(national);
  const needle = norm(typed);

  const nearbyMatches = (nearby.data?.salles ?? []).filter((salle) => "" === needle || norm(`${salle.name} ${salle.address ?? ""} ${salle.city ?? ""}`).includes(needle));
  const rows = associableRows([...nearbyMatches, ...(searching ? (national.data?.salles ?? []) : [])]);
  const failed = searching ? "failed" === nationalState : "failed" === readState(nearby);

  return (
    <Modal label={WIZARD_TEXTS.associateModalTitle(venue.name)} title={WIZARD_TEXTS.associateModalTitle(venue.name)} onClose={onClose}>
      <p className="mb-3 text-sm text-muted-foreground">{WIZARD_TEXTS.associateModalHint}</p>
      <Input aria-label={WIZARD_TEXTS.associateModalSearchLabel} placeholder={WIZARD_TEXTS.associateModalSearchLabel} className="mb-3" value={query} onChange={(event) => setQuery(event.target.value)} />
      {failed ? <LoadErrorHint className="mb-2">{WIZARD_TEXTS.nameSearchError}</LoadErrorHint> : null}
      {searching && "loading" === nationalState ? <Spinner className="mb-2" /> : null}
      {searching && "ready" === nationalState && 0 === rows.length ? <EmptyHint>{WIZARD_TEXTS.associateModalEmpty}</EmptyHint> : null}
      {rows.length > 0 ? (
        <ul aria-label={WIZARD_TEXTS.nameSearchResultsLabel} className="flex max-h-72 flex-col gap-2 overflow-y-auto">
          {rows.map((salle) => (
            <SalleResultRow
              key={salle.externalRef}
              salle={salle}
              venues={venues}
              action={{ label: WIZARD_TEXTS.associateTag, accessibleName: WIZARD_TEXTS.salleActionName(WIZARD_TEXTS.associateTag, salle.name), onPick }}
            />
          ))}
        </ul>
      ) : null}
    </Modal>
  );
}

/**
 * Associer un gymnase à une salle FFBB : recherche dans toute la FFBB, plus les salles d'à côté
 * filtrées par ce qui est tapé. Démontée à la fermeture : chaque ouverture repart d'un champ vide.
 */
export function AssociateSalleModal({ venue, venues, onPick, onClose }: { venue: Venue | null; venues: Venue[]; onPick: (salle: FfbbSalle) => void; onClose: () => void }) {
  return null === venue ? null : <AssociateSalleSearch venue={venue} venues={venues} onPick={onPick} onClose={onClose} />;
}
