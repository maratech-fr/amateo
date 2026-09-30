import { AlertTriangle, Map as MapIcon, MapPin, MapPinCheck } from "lucide-react";
import { type ReactNode, useState } from "react";

import { errorMessage } from "@/shared/lib/errorMessage";
import type { GeocodeCandidate } from "@/shared/api/geocode";
import { parseCoordinates } from "@/shared/lib/parseCoordinates";
import { useGeocode, useReverseGeocode } from "@/shared/hooks/useGeocode";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
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
/** La précision d'un point exact : le NUMÉRO de rue. Toute autre valeur BAN (rue/quartier/commune)
 *  = position approximative. */
const PRECISE_TYPE = "housenumber";
const APPROX_WARNING = "Position approximative (rue entière) — ajoutez le numéro.";

function isApproximate(type: string | null | undefined): boolean {
  return null != type && PRECISE_TYPE !== type;
}

interface AddressGeocodeFieldProps {
  /** L'adresse actuelle — pré-remplit le champ ET s'affiche en état « localisé ». */
  address?: string | null;
  /** Une localisation existe déjà (coordonnées posées). */
  located: boolean;
  /** Coordonnées posées — servent le lien « Voir sur la carte » et le reverse-geocoding. */
  latitude?: number | null;
  longitude?: number | null;
  /** Le candidat FÉDÉRAL choisi — appelé au clic, jamais avant. */
  onPick: (candidate: GeocodeCandidate) => void;
  /** Coordonnées SAISIES à la main (lot E) — même écriture que `onPick` côté caller, sans adresse.
   *  Omis → l'affordance « Saisir les coordonnées » n'apparaît pas. */
  onManualCoords?: (coords: { latitude: number; longitude: number }) => void;
  placeholder: string;
  /** Nom accessible du champ de saisie. */
  label: string;
  /** Le texte d'état quand c'est localisé (« Localisé », « Siège localisé »…). */
  statusWord: string;
  /** L'état affiché quand ce n'est PAS localisé (statut permanent) ; omis → rien. */
  unlocatedStatus?: ReactNode;
}

export function AddressGeocodeField({ address, located, latitude, longitude, onPick, onManualCoords, placeholder, label, statusWord, unlocatedStatus }: AddressGeocodeFieldProps) {
  const [editing, setEditing] = useState(!located);
  const [query, setQuery] = useState(address ?? "");
  const [candidates, setCandidates] = useState<GeocodeCandidate[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [searched, setSearched] = useState("");
  // Saisie MANUELLE des coordonnées (lot E) : le sous-formulaire ouvert, sa valeur, son erreur.
  const [coordsOpen, setCoordsOpen] = useState(false);
  const [coordsInput, setCoordsInput] = useState("");
  const [coordsError, setCoordsError] = useState<string | null>(null);
  // Ce qui a produit la localisation courante : un candidat approximatif (rue) ou une saisie
  // manuelle — pour l'afficher en vue repliée (le props `venue` ne porte pas la précision).
  const [lastAction, setLastAction] = useState<"picked-approx" | "manual" | null>(null);
  const geocode = useGeocode();

  const hasAddress = null != address && "" !== address;
  const hasCoords = null != latitude && null != longitude;
  const collapsed = located && !editing;
  // Aucune adresse saisie mais des coordonnées posées (seed/FFBB) → on RETROUVE une adresse
  // pour l'afficher (jamais stockée). `enabled` gouverne le tir : le hook est toujours appelé.
  const reverse = useReverseGeocode(latitude, longitude, collapsed && !hasAddress && hasCoords);
  const mapHref = hasCoords ? `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=18/${latitude}/${longitude}` : null;

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
      onError: async (e) => setError(await errorMessage(e)),
    });
  };

  const pick = (candidate: GeocodeCandidate) => {
    onPick(candidate);
    setCandidates(null);
    setError(null);
    setEditing(false);
    setLastAction(isApproximate(candidate.type) ? "picked-approx" : null);
  };

  const submitManualCoords = () => {
    const parsed = parseCoordinates(coordsInput);
    if (null === parsed) {
      setCoordsError("Coordonnées non reconnues. Attendu : « 45.76799, 4.88853 », ou un lien OpenStreetMap / Google Maps.");
      return;
    }
    onManualCoords?.(parsed);
    setCoordsError(null);
    setCoordsOpen(false);
    setCoordsInput("");
    setCandidates(null);
    setError(null);
    setEditing(false);
    setLastAction("manual");
  };

  // Vue REPLIÉE : localisé et on n'édite pas. On montre l'état, jamais un champ qui inviterait
  // à réécrire par mégarde.
  if (collapsed) {
    return (
      <div className="flex flex-col gap-1">
        <p role="status" className="flex flex-wrap items-center gap-2 text-sm">
          <span className="inline-flex items-center gap-1 text-muted-foreground">
            <MapPinCheck className="size-4 text-accent" aria-hidden="true" />
            {"manual" === lastAction ? "Position saisie à la main" : statusWord}
          </span>
          {hasAddress ? (
            <span className="truncate text-xs text-muted-foreground">{address}</span>
          ) : hasCoords ? (
            // Pas d'adresse saisie : on affiche celle RETROUVÉE (libellé distinct d'une adresse saisie).
            <span className="truncate text-xs text-muted-foreground">
              {reverse.isPending ? "Adresse en cours de recherche…" : `Adresse retrouvée : ${reverse.data ?? "Adresse inconnue"}`}
            </span>
          ) : null}
          {null !== mapHref ? (
            <a href={mapHref} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-accent underline">
              <MapIcon className="size-3.5" aria-hidden="true" />
              Voir sur la carte
            </a>
          ) : null}
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
        {/* Un point posé sur une RUE (pas un numéro) est approximatif : on invite à préciser. */}
        {"picked-approx" === lastAction ? (
          <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
            <AlertTriangle className="size-3.5 text-warning" aria-hidden="true" />
            {APPROX_WARNING}
          </span>
        ) : null}
      </div>
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
        {undefined !== onManualCoords ? (
          <Button variant="ghost" className="h-9" onClick={() => setCoordsOpen((o) => !o)} aria-expanded={coordsOpen}>
            Saisir les coordonnées
          </Button>
        ) : null}
      </div>

      {/* Saisie MANUELLE (lot E) : un couple « lat, lon » ou un lien OSM / Google Maps. Le point
          est EXACT (l'utilisateur l'a choisi) ; il passe par la MÊME écriture que « Localiser ». */}
      {undefined !== onManualCoords && coordsOpen ? (
        <div className="flex flex-col gap-1 rounded-md border border-border bg-background p-2">
          <div className="flex flex-wrap items-end gap-2">
            <Input
              aria-label="Coordonnées (latitude, longitude) ou lien de carte"
              placeholder="45.76799, 4.88853"
              className="h-9 w-72"
              value={coordsInput}
              onChange={(e) => setCoordsInput(e.target.value)}
              onKeyDown={(e) => {
                if ("Enter" === e.key) {
                  e.preventDefault();
                  submitManualCoords();
                }
              }}
            />
            <Button variant="outline" className="h-9" disabled={"" === coordsInput.trim()} onClick={submitManualCoords}>
              <MapPin className="size-4" />
              Placer
            </Button>
          </div>
          <span className="text-xs text-muted-foreground">Collez un couple « latitude, longitude », ou un lien OpenStreetMap ou Google Maps.</span>
          {null !== coordsError ? (
            <NoticeBanner tone="warning" role="alert" icon={<AlertTriangle className="size-4 text-warning" />} message={coordsError} />
          ) : null}
        </div>
      ) : null}

      {null !== error ? <NoticeBanner tone="warning" role="alert" icon={<AlertTriangle className="size-4 text-warning" />} message={error} /> : null}

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
                ) : isApproximate(candidate.type) ? (
                  // Précision BAN : une rue/quartier (pas un numéro) = point approximatif.
                  <span className="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                    <AlertTriangle className="size-3.5" aria-hidden="true" />
                    rue entière — ajoutez le numéro
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
