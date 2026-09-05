import { AlertTriangle, MapPin, MapPinCheck } from "lucide-react";
import { useState } from "react";

import { apiErrorMessage } from "@/shared/api/errors";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Spinner } from "@/shared/components/ui/spinner";

import type { GeocodeCandidate, Venue } from "../api";
import { useGeocode } from "../queries";

/**
 * P2-53 RMM-8 — le geste géo d'une fiche de gymnase : saisir une adresse, la « Localiser »
 * (proxy `GET /api/geocode` → BAN, jamais un appel tiers direct), choisir un candidat, ce qui
 * POSE address + lat/long sur le gymnase.
 *
 * ⚠ On n'ÉCRASE JAMAIS en silence une géo existante : un gymnase déjà localisé (import FFBB
 * P2-20, ou géocodage antérieur) s'affiche « Localisé » et ses coordonnées ne bougent que si
 * l'utilisateur choisit EXPLICITEMENT un nouveau candidat (« Modifier l'adresse »). Proposer des
 * candidats ne touche à rien tant qu'aucun n'est cliqué.
 *
 * Le score BAN (0..1) n'est PAS montré en nombre (bruit pour un gestionnaire) : le premier
 * candidat porte « Recommandé », un score faible porte « correspondance approximative ». Passe de
 * design 2026-08-26.
 */

/** En deçà de ce score BAN, le candidat est marqué « correspondance approximative ». */
const LOW_SCORE = 0.4;
/** La BAN refuse (422) sous 3 caractères — on garde le bouton inerte avant, pas d'appel pour rien. */
const MIN_QUERY = 3;

export function VenueGeocodeField({
  venue,
  onLocate,
}: {
  venue: Pick<Venue, "id" | "address" | "latitude" | "longitude">;
  /** Pose address + lat/long (chaînes) sur le gymnase. Appelé au clic d'un candidat, jamais avant. */
  onLocate: (geo: { address: string; latitude: string; longitude: string }) => void;
}) {
  const located = null != venue.latitude && null != venue.longitude;
  const [editing, setEditing] = useState(!located);
  const [query, setQuery] = useState(venue.address ?? "");
  const [candidates, setCandidates] = useState<GeocodeCandidate[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [searched, setSearched] = useState("");
  const geocode = useGeocode();

  const runSearch = () => {
    const q = query.trim();
    if (q.length < MIN_QUERY) {
      return;
    }
    setError(null);
    setCandidates(null);
    setSearched(q);
    geocode.mutate(q, {
      onSuccess: (list) => setCandidates(list),
      onError: async (e) => setError(await apiErrorMessage(e)),
    });
  };

  const pick = (candidate: GeocodeCandidate) => {
    onLocate({ address: candidate.label, latitude: String(candidate.latitude), longitude: String(candidate.longitude) });
    setCandidates(null);
    setError(null);
    setEditing(false);
  };

  // Vue REPLIÉE : le gymnase est localisé et on n'édite pas. On montre l'état, jamais un champ
  // qui inviterait à réécrire par mégarde.
  if (located && !editing) {
    return (
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="inline-flex items-center gap-1 text-muted-foreground">
          <MapPinCheck className="size-4 text-accent" aria-hidden="true" />
          Localisé
        </span>
        {null != venue.address && "" !== venue.address ? <span className="truncate text-xs text-muted-foreground">{venue.address}</span> : null}
        <Button
          size="sm"
          variant="ghost"
          className="h-8"
          onClick={() => {
            setEditing(true);
            setQuery(venue.address ?? "");
          }}
        >
          Modifier l'adresse
        </Button>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-end gap-2">
        <Input
          aria-label="Adresse"
          autoComplete="street-address"
          placeholder="Adresse du gymnase"
          className="h-9 w-72"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={(e) => {
            if ("Enter" === e.key) {
              e.preventDefault();
              runSearch();
            }
          }}
        />
        <Button variant="outline" className="h-9" disabled={query.trim().length < MIN_QUERY || geocode.isPending} onClick={runSearch}>
          {geocode.isPending ? <Spinner className="size-4" /> : <MapPin className="size-4" />}
          Localiser
        </Button>
      </div>

      {null !== error ? (
        <p role="alert" className="flex items-center gap-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
          <AlertTriangle className="size-4 shrink-0 text-warning" />
          {error}
        </p>
      ) : null}

      {null !== candidates && 0 === candidates.length ? (
        <EmptyHint>Aucune adresse trouvée pour « {searched} ». Vérifiez l'orthographe ou ajoutez la ville.</EmptyHint>
      ) : null}

      {null !== candidates && candidates.length > 0 ? (
        <ul aria-label="Adresses proposées" className="flex flex-col gap-1 rounded-md border border-border bg-background py-1">
          {candidates.map((candidate, index) => (
            <li key={`${candidate.label}-${candidate.latitude}-${candidate.longitude}`}>
              <button type="button" className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted" onClick={() => pick(candidate)}>
                <MapPin className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span className="min-w-0 flex-1 truncate">{candidate.label}</span>
                {0 === index ? (
                  <StatusPill variant="accent" className="shrink-0">
                    Recommandé
                  </StatusPill>
                ) : null}
                {candidate.score < LOW_SCORE ? (
                  <span className="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                    <AlertTriangle className="size-3.5" aria-hidden="true" />
                    correspondance approximative
                  </span>
                ) : null}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
