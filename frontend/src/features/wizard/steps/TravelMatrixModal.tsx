import { AlertTriangle, Car, Footprints, MapPinOff, Pencil, RefreshCw, Search, Wand2 } from "lucide-react";
import { useState } from "react";

import { SourceBadge } from "@/features/matches/SourceBadge";
import { errorMessage } from "@/shared/lib/errorMessage";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Spinner } from "@/shared/components/ui/spinner";
import { Modal } from "@/shared/components/ui/modal";
import { readState } from "@/shared/lib/readState";
import { useTravelStream } from "@/shared/lib/travelStream";
import { toast } from "@/shared/stores/toastStore";

import type { AutofillUnresolvedReason, Venue, VenueTravelTime, VenueTravelTimeAutofillResult, VenueTravelTimePayload } from "../api";
import { useAutofillVenueTravelTimes, useCreateVenueTravelTime, useUpdateVenueTravelTime, useVenueTravelTimes, useWizardVenues } from "../queries";

/**
 * P2-53 RMM-8 — la matrice de temps de trajet entre gymnases (patron TeamLinksModal : « ça
 * définit des relations entre eux »). Ouverte depuis un bouton du pied de page de l'étape
 * Gymnases.
 *
 * DEUX vues, une bascule :
 *  - PREMIÈRE OUVERTURE (aucune ligne de matrice, aucun autofill lancé) → un consentement passif :
 *    l'app PROPOSE de calculer les trajets. Le clic EST l'activation de la règle de trajet dans le
 *    solveur (opt-in au premier geste). JAMAIS lancé sans clic.
 *  - MATRICE → la liste des couples, groupée « Depuis {gymnase} » pour rester lisible jusqu'à ~120
 *    couples. Deux colonnes (voiture / à pied). L'origine AUTO (calculée) vs MANUEL (saisie, jamais
 *    écrasée) se distingue d'un coup d'œil (icône + texte, jamais couleur seule). Éditer une valeur
 *    la passe MANUEL (côté serveur). Re-calculer préserve les MANUEL.
 *
 * ⚠ Le front N'INVENTE aucune règle : l'activation de la règle est DÉRIVÉE serveur-side de la
 * présence de matrice (ScheduleConstraintBuilder). Ici on ne fait qu'écrire la matrice et proposer
 * l'autofill ; les raisons `unresolved` viennent du VERDICT servi par l'autofill, jamais devinées.
 */

const MIN_MINUTES = 1;
const MAX_MINUTES = 240;

type TravelMode = "driving" | "walking";

/** Clé d'un couple, indépendante de l'ordre (le serveur normalise venueAId < venueBId). */
function pairKey(a: string, b: string): string {
  return [a, b].sort().join("|");
}

/** Le libellé d'une raison servie. Table EXHAUSTIVE, pas de repli : le ternaire précédent rendait
 *  « calcul impossible » pour TOUT code inconnu — un `budget_exceeded` (lot interrompu, à relancer)
 *  se lisait donc comme un échec définitif. Un nouveau code servi rougit ici tant qu'il n'a pas
 *  son libellé. */
const REASON_LABELS: Record<AutofillUnresolvedReason, string> = {
  missing_geo: "gymnase sans adresse",
  routing_failed: "calcul impossible",
  budget_exceeded: "calcul interrompu, relancez",
};

function reasonLabel(reason: AutofillUnresolvedReason): string {
  return REASON_LABELS[reason];
}

function TravelCell({
  mode,
  minutes,
  source,
  reason,
  label,
  onCommit,
}: {
  mode: TravelMode;
  minutes: number | null;
  source: "AUTO" | "MANUAL" | null;
  reason: AutofillUnresolvedReason | null;
  /** Nom lisible pour le lecteur d'écran (« En voiture — A → B »). */
  label: string;
  onCommit: (minutes: number) => void;
}) {
  // Champ NON contrôlé, re-semé par `key` sur la valeur servie : un refetch (autofill ou autre
  // saisie) remonte le champ avec la nouvelle valeur, sans setState dans un effet (règle
  // react-hooks/set-state-in-effect). Le commit lit le DOM et restaure la valeur servie sur une
  // entrée hors bornes.
  const served = null !== minutes ? String(minutes) : "";

  const ModeIcon = "driving" === mode ? Car : Footprints;
  const modeText = "driving" === mode ? "en voiture" : "à pied";

  const commit = (input: HTMLInputElement) => {
    const trimmed = input.value.trim();
    if ("" === trimmed) {
      return; // pas d'effacement via la matrice (le serveur traite null = inchangé).
    }
    const n = Number(trimmed);
    if (!Number.isInteger(n) || n < MIN_MINUTES || n > MAX_MINUTES) {
      // FRT-27 — la restauration silencieuse laissait le gestionnaire croire que sa saisie
      // avait pris. On DIT pourquoi elle est rejetée (le SIGNAL ; la restauration ne change pas).
      toast.error(`Un temps de trajet doit être un nombre entier de minutes entre ${MIN_MINUTES} et ${MAX_MINUTES}.`);
      input.value = served; // hors bornes → on rend la valeur servie.
      return;
    }
    if (n === minutes) {
      return;
    }
    onCommit(n);
  };

  return (
    <div className="flex flex-col items-start gap-0.5">
      <div className="flex items-center gap-1">
        {/* Pictogramme du mode (voiture / piéton) — le texte accessible vit dans l'aria-label du champ. */}
        <ModeIcon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
        <span className="sr-only">{modeText}</span>
        <Input
          key={served}
          aria-label={`${"driving" === mode ? "En voiture" : "À pied"} — ${label}`}
          inputMode="numeric"
          className="h-8 w-14 tabular-nums"
          placeholder="—"
          defaultValue={served}
          onBlur={(e) => commit(e.currentTarget)}
          onKeyDown={(e) => {
            if ("Enter" === e.key) {
              e.currentTarget.blur();
            }
          }}
        />
        <span className="text-xs text-muted-foreground">min</span>
      </div>
      {null !== source ? (
        <SourceBadge source={source} />
      ) : null !== reason ? (
        <span className="inline-flex items-center gap-1 text-xs text-warning">
          <AlertTriangle className="size-3.5" aria-hidden="true" />À saisir · {reasonLabel(reason)}
        </span>
      ) : (
        <span className="px-1.5 text-xs text-muted-foreground">à saisir</span>
      )}
    </div>
  );
}

/** L'empty-state de première ouverture : le consentement à l'autofill (jamais lancé sans clic). */
function AutofillConsent({ onRun, onClose, running, error }: { onRun: () => void; onClose: () => void; running: boolean; error: string | null }) {
  return (
    <div className="flex flex-col items-center gap-4 py-6 text-center">
      <Wand2 className="size-8 text-accent" aria-hidden="true" />
      <h3 className="text-base font-semibold text-foreground">Calculer les trajets entre vos gymnases ?</h3>
      <p className="max-w-md text-sm text-muted-foreground">
        L'application peut estimer les temps de trajet entre chaque gymnase, en voiture et à pied. Vous pourrez corriger n'importe quelle valeur à la main.
      </p>
      <p className="max-w-md text-sm text-muted-foreground">
        En les calculant, la règle « Trajet entre gymnases » s'active : le planning cherchera à enchaîner des gymnases proches. Elle démarre en « Préféré » (une préférence souple),
        et vous pourrez la passer en « Obligatoire » depuis l'étape Contraintes.
      </p>
      {null !== error ? <NoticeBanner tone="warning" role="alert" icon={<AlertTriangle className="size-4 text-warning" />} message={error} /> : null}
      <div className="flex items-center gap-2">
        <Button onClick={onRun} disabled={running}>
          {running ? <Spinner className="size-4" /> : <Wand2 className="size-4" />}
          Calculer les trajets
        </Button>
        <Button variant="ghost" onClick={onClose} disabled={running}>
          Plus tard
        </Button>
      </div>
    </div>
  );
}

export function TravelMatrixModal({ onClose, onLocateVenue }: { onClose: () => void; onLocateVenue?: (venueId: string) => void }) {
  const venuesQuery = useWizardVenues();
  const matrixQuery = useVenueTravelTimes();
  const create = useCreateVenueTravelTime();
  const update = useUpdateVenueTravelTime();
  const autofill = useAutofillVenueTravelTimes();

  const [autofillError, setAutofillError] = useState<string | null>(null);
  // « lancé » = le gestionnaire a demandé au moins un calcul cette session (event handler) ;
  // le reste se DÉRIVE du flux (useSyncExternalStore), jamais d'un setState dans un effet.
  const [launched, setLaunched] = useState(false);
  const [filter, setFilter] = useState("");

  // C6 — le calcul tourne dans le worker : on écoute la progression sur le flux des trajets tant
  // qu'un calcul a été lancé. Le terminal porte le verdict (`{filled, unresolved}`) ; la matrice,
  // elle, se rafraîchit toute seule (le flux invalide `["wizard","venue_travel_times"]`).
  const stream = useTravelStream(launched);
  const travel = "VENUE_MATRIX" === stream.latest?.scope ? stream.latest : null;
  const computing = launched && (null === travel || !travel.terminal);
  const autofillResult: VenueTravelTimeAutofillResult | null =
    launched && null !== travel && travel.terminal ? { filled: travel.verdict?.filled ?? 0, unresolved: (travel.verdict?.unresolved ?? []) as VenueTravelTimeAutofillResult["unresolved"] } : null;

  const venues: Venue[] = venuesQuery.data ?? [];
  const matrix: VenueTravelTime[] = matrixQuery.data ?? [];
  const matrixState = readState(matrixQuery);

  const rowByPair = new Map<string, VenueTravelTime>(matrix.map((r) => [pairKey(r.venueAId, r.venueBId), r]));
  const reasonByPair = new Map<string, AutofillUnresolvedReason>((autofillResult?.unresolved ?? []).map((u) => [pairKey(u.venueAId, u.venueBId), u.reason]));
  const venuesWithoutGeo = venues.filter((v) => null == v.latitude || null == v.longitude);

  const runAutofill = () => {
    setAutofillError(null);
    autofill.mutate(undefined, {
      // C6 — la réponse dit seulement que le calcul est EN FILE : on marque « lancé » et le flux
      // fait le reste (progression puis verdict au terminal). Sécurité H — si un calcul tourne
      // déjà (rien dispatché), on NE bascule PAS en « en cours… » (le hook émet le toast).
      onSuccess: (result) => {
        if (!result.alreadyRunning) {
          setLaunched(true);
        }
      },
      onError: async (e) => setAutofillError(await errorMessage(e)),
    });
  };

  const commitCell = (from: Venue, dest: Venue, mode: TravelMode, minutes: number) => {
    const existing = rowByPair.get(pairKey(from.id, dest.id));
    const body: VenueTravelTimePayload = {
      venueAId: from.id,
      venueBId: dest.id,
      ...("driving" === mode ? { drivingMinutes: minutes } : { walkingMinutes: minutes }),
    };
    if (existing) {
      update.mutate({ id: existing.id, body });
    } else {
      create.mutate(body);
    }
  };

  const showConsent = 0 === matrix.length && null === autofillResult && !computing;
  const busy = autofill.isPending || computing;
  // Ligne de progression stable pendant le calcul (jauge `done/total` si le flux la porte).
  const progressLabel = computing
    ? null !== travel && travel.total > 0
      ? `Calcul des trajets en cours… ${travel.done} / ${travel.total}`
      : "Calcul des trajets en cours…"
    : null;

  const needle = filter.trim().toLowerCase();
  // Ordre IDENTIQUE en lignes et en colonnes (la matrice est carrée et symétrique). Le filtre
  // restreint les LIGNES (« depuis quels gymnases »), les colonnes restent tous les gymnases.
  const cols = [...venues].sort((a, b) => a.name.localeCompare(b.name, "fr"));
  const rows = "" === needle ? cols : cols.filter((v) => v.name.toLowerCase().includes(needle));

  const footer = showConsent ? undefined : (
    <>
      <div className="mr-auto flex flex-wrap items-center gap-2">
        <Button variant="outline" onClick={runAutofill} disabled={busy}>
          {busy ? <Spinner className="size-4" /> : <RefreshCw className="size-4" />}
          Recalculer les trajets
        </Button>
        {null !== progressLabel ? (
          <span role="status" aria-live="polite" className="text-xs text-muted-foreground">
            {progressLabel}
          </span>
        ) : (
          <span className="text-xs text-muted-foreground">Vos valeurs saisies à la main sont conservées.</span>
        )}
      </div>
      <Button onClick={onClose}>Terminé</Button>
    </>
  );

  return (
    <Modal label="Trajets entre gymnases" title="Trajets entre gymnases" onClose={onClose} size="xl" footer={footer}>
      {"failed" === matrixState ? (
        <LoadErrorHint onRetry={() => void matrixQuery.refetch()}>Impossible de lire la matrice de trajet.</LoadErrorHint>
      ) : venues.length < 2 ? (
        <p className="py-6 text-center text-sm text-muted-foreground">Ajoutez au moins deux gymnases pour définir des temps de trajet entre eux.</p>
      ) : showConsent ? (
        <AutofillConsent onRun={runAutofill} onClose={onClose} running={busy} error={autofillError} />
      ) : (
        <div className="flex flex-col gap-3">
          {/* Zone d'en-tête non défilante : filtre + légende + gymnases sans adresse. */}
          <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
              <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
              <Input aria-label="Filtrer par gymnase" placeholder="Filtrer par gymnase…" className="h-9" value={filter} onChange={(e) => setFilter(e.target.value)} />
            </div>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
              <span className="inline-flex items-center gap-1">
                <Car className="size-3.5" aria-hidden="true" /> En voiture
              </span>
              <span className="inline-flex items-center gap-1">
                <Footprints className="size-3.5" aria-hidden="true" /> À pied
              </span>
              <span className="inline-flex items-center gap-1">
                <Wand2 className="size-3.5" aria-hidden="true" /> Auto (calculé)
              </span>
              <span className="inline-flex items-center gap-1 text-accent">
                <Pencil className="size-3.5" aria-hidden="true" /> Manuel (saisi)
              </span>
              <span className="inline-flex items-center gap-1 text-warning">
                <AlertTriangle className="size-3.5" aria-hidden="true" /> À saisir
              </span>
            </div>
            {autofillError && !showConsent ? (
              <NoticeBanner tone="warning" role="alert" icon={<AlertTriangle className="size-4 text-warning" />} message={autofillError} />
            ) : null}
            {venuesWithoutGeo.length > 0 ? (
              <div className="flex flex-col gap-1 rounded-md border border-warning/40 bg-surface-warning px-3 py-2 text-sm">
                <span className="inline-flex items-center gap-2 text-foreground">
                  <MapPinOff className="size-4 shrink-0 text-warning" aria-hidden="true" />
                  {venuesWithoutGeo.length > 1 ? "Ces gymnases n'ont pas d'adresse" : "Ce gymnase n'a pas d'adresse"} : renseignez-la sur leur fiche pour calculer les trajets automatiquement.
                </span>
                <div className="flex flex-wrap gap-1">
                  {venuesWithoutGeo.map((v) =>
                    onLocateVenue ? (
                      <Button key={v.id} size="sm" variant="ghost" className="h-7" onClick={() => onLocateVenue(v.id)}>
                        {v.name}
                      </Button>
                    ) : (
                      <span key={v.id} className="rounded-full bg-background px-2 py-0.5 text-xs text-foreground">
                        {v.name}
                      </span>
                    ),
                  )}
                </div>
              </div>
            ) : null}
          </div>

          {/* Corps défilant : la matrice N×N. Gymnases en LIGNES et en COLONNES (même ordre),
              diagonale « — », symétrique (A→B = B→A, même `pairKey`). En-têtes collants au scroll. */}
          {0 === rows.length ? (
            <p className="py-4 text-center text-sm text-muted-foreground">Aucun gymnase ne correspond à « {filter.trim()} ».</p>
          ) : (
            // Défilement horizontal accepté sous 360 px (garde-fou existant, bureau d'abord).
            <div className="max-h-[24rem] overflow-auto rounded-md border border-border">
              <table className="border-collapse text-sm">
                <caption className="sr-only">Temps de trajet entre gymnases, en voiture et à pied, du gymnase de la ligne vers celui de la colonne.</caption>
                <thead>
                  <tr>
                    <th scope="col" className="sticky left-0 top-0 z-20 border-b border-r border-border bg-card px-2 py-1.5 text-left text-xs font-medium text-muted-foreground">Depuis \ vers</th>
                    {cols.map((col) => (
                      <th key={col.id} scope="col" className="sticky top-0 z-10 whitespace-nowrap border-b border-border bg-card px-2 py-1.5 text-left font-medium text-foreground">
                        {col.name}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((from) => (
                    <tr key={from.id}>
                      <th scope="row" className="sticky left-0 z-10 whitespace-nowrap border-r border-border bg-card px-2 py-1.5 text-left font-medium text-foreground">
                        {from.name}
                      </th>
                      {cols.map((dest) => {
                        if (from.id === dest.id) {
                          return (
                            <td key={dest.id} className="border-b border-l border-border/50 bg-muted px-2 py-1.5 text-center text-muted-foreground" aria-label={`${from.name} — même gymnase`}>
                              —
                            </td>
                          );
                        }
                        const row = rowByPair.get(pairKey(from.id, dest.id));
                        const reason = reasonByPair.get(pairKey(from.id, dest.id)) ?? null;
                        const label = `${from.name} → ${dest.name}`;
                        return (
                          <td key={dest.id} className="border-b border-l border-border/50 px-2 py-1.5 align-top">
                            <div className="flex flex-col gap-1">
                              <TravelCell
                                mode="driving"
                                minutes={row?.drivingMinutes ?? null}
                                source={row?.drivingSource ?? null}
                                reason={reason}
                                label={label}
                                onCommit={(m) => commitCell(from, dest, "driving", m)}
                              />
                              <TravelCell
                                mode="walking"
                                minutes={row?.walkingMinutes ?? null}
                                source={row?.walkingSource ?? null}
                                reason={reason}
                                label={label}
                                onCommit={(m) => commitCell(from, dest, "walking", m)}
                              />
                            </div>
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
