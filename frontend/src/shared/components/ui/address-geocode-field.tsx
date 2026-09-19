import { AlertTriangle, MapPin, MapPinCheck } from "lucide-react";
import { type ReactNode, useState } from "react";

import { apiErrorMessage } from "@/shared/api/errors";
import type { GeocodeCandidate } from "@/shared/api/geocode";
import { useGeocode } from "@/shared/hooks/useGeocode";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Spinner } from "@/shared/components/ui/spinner";

/**
 * Le geste géo PARTAGÉ : saisir une adresse, la « Localiser » (proxy `GET /api/geocode` → BAN,
 * jamais un appel tiers direct — frontière §2), choisir un candidat, ce qui remonte le candidat
 * FÉDÉRAL au caller (`onPick`). Extrait du champ gymnase (P2-53 RMM-8) pour servir AUSSI le siège
 * du club — un seul foyer du géocodage à la saisie.
 *
 * ⚠ On n'ÉCRASE JAMAIS en silence une géo existante : `located` affiche l'état « localisé » et
 * ses coordonnées ne bougent que si l'utilisateur choisit EXPLICITEMENT un nouveau candidat
 * (« Modifier l'adresse »). Proposer des candidats ne touche à rien tant qu'aucun n'est cliqué.
 *
 * Le score BAN (0..1) n'est PAS montré en nombre : le premier candidat porte « Recommandé », un
 * score faible « correspondance approximative ».
 */

/** En deçà de ce score BAN, le candidat est marqué « correspondance approximative ». */
const LOW_SCORE = 0.4;
/** La BAN refuse (422) sous 3 caractères — bouton inerte avant, aucun appel pour rien. */
const MIN_QUERY = 3;

interface AddressGeocodeFieldProps {
  /** L'adresse actuelle — pré-remplit le champ ET s'affiche en état « localisé ». */
  address?: string | null;
  /** Une localisation existe déjà (coordonnées posées). */
  located: boolean;
  /** Le candidat FÉDÉRAL choisi — appelé au clic, jamais avant. */
  onPick: (candidate: GeocodeCandidate) => void;
  placeholder: string;
  /** Nom accessible du champ de saisie. */
  label: string;
  /** Le texte d'état quand c'est localisé (« Localisé », « Siège localisé »…). */
  statusWord: string;
  /** L'état affiché quand ce n'est PAS localisé (statut permanent) ; omis → rien. */
  unlocatedStatus?: ReactNode;
}

export function AddressGeocodeField({ address, located, onPick, placeholder, label, statusWord, unlocatedStatus }: AddressGeocodeFieldProps) {
  const [editing, setEditing] = useState(!located);
  const [query, setQuery] = useState(address ?? "");
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
    onPick(candidate);
    setCandidates(null);
    setError(null);
    setEditing(false);
  };

  // Vue REPLIÉE : localisé et on n'édite pas. On montre l'état, jamais un champ qui inviterait
  // à réécrire par mégarde.
  if (located && !editing) {
    return (
      <p role="status" className="flex flex-wrap items-center gap-2 text-sm">
        <span className="inline-flex items-center gap-1 text-muted-foreground">
          <MapPinCheck className="size-4 text-accent" aria-hidden="true" />
          {statusWord}
        </span>
        {null != address && "" !== address ? <span className="truncate text-xs text-muted-foreground">{address}</span> : null}
        <Button
          size="sm"
          variant="ghost"
          className="h-8"
          onClick={() => {
            setEditing(true);
            setQuery(address ?? "");
          }}
        >
          Modifier l'adresse
        </Button>
      </p>
    );
  }

  return (
    <div className="flex flex-col gap-2">
      {undefined !== unlocatedStatus ? (
        <p role="status" className="text-sm text-muted-foreground">
          {unlocatedStatus}
        </p>
      ) : null}
      <div className="flex flex-wrap items-end gap-2">
        <Input
          aria-label={label}
          autoComplete="street-address"
          placeholder={placeholder}
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
