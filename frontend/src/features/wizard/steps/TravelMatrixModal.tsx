import { AlertTriangle, Bike, Car, MapPinOff, RefreshCw, Search, Wand2 } from "lucide-react";
import { useState } from "react";

import { errorMessage } from "@/shared/lib/errorMessage";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Spinner } from "@/shared/components/ui/spinner";
import { Modal } from "@/shared/components/ui/modal";
import { cn } from "@/shared/lib/utils";
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
 *  - MATRICE N×N (gymnases en lignes ET en colonnes, même ordre, diagonale « — », symétrique) →
 *    chaque case est en LECTURE SEULE et TRÈS DÉPOUILLÉE : « 🚗 3′ · 🚶 12′ ». L'origine se lit à
 *    la COULEUR + la GRAISSE (calculé = neutre ; saisi à la main = accent + gras — le gras est
 *    l'indice NON chromatique, jamais la couleur seule). La case entière est un bouton qui ouvre
 *    une modale d'édition (deux champs minutes). Éditer une valeur la passe MANUEL (côté serveur) ;
 *    re-calculer préserve les MANUEL.
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

/** L'origine d'une valeur, en toutes lettres (pour l'aria-label de la case et la modale). */
function originWord(source: "AUTO" | "MANUAL" | null): string | null {
  if ("MANUAL" === source) {
    return "saisi à la main";
  }
  if ("AUTO" === source) {
    return "calculé";
  }
  return null;
}

const MODE_TEXT: Record<TravelMode, string> = { driving: "en voiture", walking: "à vélo" };

/** Le fragment accessible d'un temps pour l'aria-label de la case : « en voiture 3 min (calculé) ». */
function timeAria(mode: TravelMode, minutes: number | null, source: "AUTO" | "MANUAL" | null, reason: AutofillUnresolvedReason | null): string {
  if (null !== minutes) {
    const origin = originWord(source);
    return `${MODE_TEXT[mode]} ${minutes} min${null !== origin ? ` (${origin})` : ""}`;
  }
  if (null !== reason) {
    return `${MODE_TEXT[mode]} non calculé (${reasonLabel(reason)})`;
  }
  return `${MODE_TEXT[mode]} à saisir`;
}

/** Un temps affiché dans une case : pictogramme + minutes, teinté selon l'origine (gras si MANUEL). */
function TimeText({ mode, minutes, source }: { mode: TravelMode; minutes: number | null; source: "AUTO" | "MANUAL" | null }) {
  const Icon = "driving" === mode ? Car : Bike;
  // Code couleur : calculé = neutre ; saisi à la main = accent + GRAS (indice non chromatique).
  const tone = "MANUAL" === source ? "font-semibold text-accent" : "text-muted-foreground";
  return (
    <span className={cn("inline-flex items-center gap-0.5 tabular-nums", tone)}>
      <Icon className="size-3.5 shrink-0" aria-hidden="true" />
      {null !== minutes ? <span>{minutes}′</span> : <span aria-hidden="true">—</span>}
    </span>
  );
}

/**
 * Une case de la matrice : bouton en LECTURE SEULE « 🚗 3′ · 🚶 12′ » ouvrant la modale d'édition.
 * Tout le sens accessible vit dans l'aria-label (les temps visibles sont décoratifs pour l'AT).
 */
function MatrixCell({ from, dest, row, reason, onEdit }: { from: Venue; dest: Venue; row: VenueTravelTime | undefined; reason: AutofillUnresolvedReason | null; onEdit: () => void }) {
  const driving = row?.drivingMinutes ?? null;
  const walking = row?.walkingMinutes ?? null;
  const drivingSource = row?.drivingSource ?? null;
  const walkingSource = row?.walkingSource ?? null;
  const label = `Modifier le trajet ${from.name} ↔ ${dest.name} — ${timeAria("driving", driving, drivingSource, reason)}, ${timeAria("walking", walking, walkingSource, reason)}`;

  return (
    <button
      type="button"
      onClick={onEdit}
      aria-label={label}
      className="flex w-full items-center justify-center gap-1.5 rounded px-1 py-1 text-sm hover:bg-accent/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <TimeText mode="driving" minutes={driving} source={drivingSource} />
      <span aria-hidden="true" className="text-muted-foreground">
        ·
      </span>
      <TimeText mode="walking" minutes={walking} source={walkingSource} />
    </button>
  );
}

/**
 * La modale d'édition d'un couple : deux champs minutes (voiture, à vélo), l'origine de chaque
 * valeur, la raison `unresolved` le cas échéant. Enregistrer commit UNIQUEMENT les valeurs
 * MODIFIÉES (même geste que la matrice : `onCommit` bascule MANUEL côté serveur). Pas de retour
 * « en auto », pas de nouvelle API.
 */
function TravelEditModal({
  from,
  dest,
  row,
  reason,
  onCommit,
  onClose,
}: {
  from: Venue;
  dest: Venue;
  row: VenueTravelTime | undefined;
  reason: AutofillUnresolvedReason | null;
  onCommit: (mode: TravelMode, minutes: number) => void;
  onClose: () => void;
}) {
  const drivingServed = null != row?.drivingMinutes ? String(row.drivingMinutes) : "";
  const walkingServed = null != row?.walkingMinutes ? String(row.walkingMinutes) : "";
  const [driving, setDriving] = useState(drivingServed);
  const [walking, setWalking] = useState(walkingServed);

  // Valide et commit UNE valeur si elle a changé. Renvoie false si la saisie est hors bornes
  // (le signal part, rien n'est écrit). Une valeur inchangée ou vidée n'écrit rien.
  const commitIfChanged = (mode: TravelMode, value: string, served: string): boolean => {
    const trimmed = value.trim();
    if (trimmed === served.trim()) {
      return true; // inchangé → rien à faire.
    }
    if ("" === trimmed) {
      return true; // on n'efface pas une valeur via la modale (le serveur traite null = inchangé).
    }
    const n = Number(trimmed);
    if (!Number.isInteger(n) || n < MIN_MINUTES || n > MAX_MINUTES) {
      toast.error(`Un temps de trajet doit être un nombre entier de minutes entre ${MIN_MINUTES} et ${MAX_MINUTES}.`);
      return false;
    }
    onCommit(mode, n);
    return true;
  };

  const save = () => {
    // On évalue les DEUX avant de fermer : une saisie hors bornes retient la modale ouverte.
    const okDriving = commitIfChanged("driving", driving, drivingServed);
    const okWalking = commitIfChanged("walking", walking, walkingServed);
    if (okDriving && okWalking) {
      onClose();
    }
  };

  const field = (mode: TravelMode, Icon: typeof Car, value: string, setValue: (v: string) => void, source: "AUTO" | "MANUAL" | null) => {
    const origin = originWord(source);
    return (
      <label className="flex flex-col gap-1 text-sm">
        <span className="inline-flex items-center gap-1.5 font-medium text-foreground">
          <Icon className="size-4 text-muted-foreground" aria-hidden="true" />
          {"driving" === mode ? "En voiture" : "À vélo"}
        </span>
        <div className="flex items-center gap-1">
          <Input
            aria-label={`${"driving" === mode ? "En voiture" : "À vélo"} — ${from.name} ↔ ${dest.name} (minutes)`}
            inputMode="numeric"
            className="h-9 w-20 tabular-nums"
            placeholder="—"
            value={value}
            onChange={(e) => setValue(e.target.value)}
          />
          <span className="text-xs text-muted-foreground">min</span>
        </div>
        <span className="text-xs text-muted-foreground">
          {null !== origin ? `Origine : ${origin}.` : null !== reason ? `Non calculé — ${reasonLabel(reason)}.` : "Aucune valeur — à saisir."}
        </span>
      </label>
    );
  };

  const footer = (
    <>
      <Button variant="ghost" onClick={onClose}>
        Annuler
      </Button>
      <Button onClick={save}>Enregistrer</Button>
    </>
  );

  return (
    <Modal label={`Trajet ${from.name} ↔ ${dest.name}`} title={`${from.name} ↔ ${dest.name}`} onClose={onClose} size="sm" footer={footer}>
      <div className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">Le temps de trajet dans les deux sens. Ce que vous saisissez est conservé lors d'un recalcul automatique.</p>
        <div className="flex flex-wrap gap-6">
          {field("driving", Car, driving, setDriving, row?.drivingSource ?? null)}
          {field("walking", Bike, walking, setWalking, row?.walkingSource ?? null)}
        </div>
      </div>
    </Modal>
  );
}

/** L'empty-state de première ouverture : le consentement à l'autofill (jamais lancé sans clic). */
function AutofillConsent({ onRun, onClose, running, error }: { onRun: () => void; onClose: () => void; running: boolean; error: string | null }) {
  return (
    <div className="flex flex-col items-center gap-4 py-6 text-center">
      <Wand2 className="size-8 text-accent" aria-hidden="true" />
      <h3 className="text-base font-semibold text-foreground">Calculer les trajets entre vos gymnases ?</h3>
      <p className="max-w-md text-sm text-muted-foreground">
        L'application peut estimer les temps de trajet entre chaque gymnase, en voiture et à vélo. Vous pourrez corriger n'importe quelle valeur à la main.
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
  // Le couple en cours d'édition (modale) — null = aucune.
  const [editing, setEditing] = useState<{ from: Venue; dest: Venue } | null>(null);

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

  const editRow = null !== editing ? rowByPair.get(pairKey(editing.from.id, editing.dest.id)) : undefined;
  const editReason = null !== editing ? (reasonByPair.get(pairKey(editing.from.id, editing.dest.id)) ?? null) : null;

  return (
    <Modal label="Trajets entre gymnases" title="Trajets entre gymnases" onClose={onClose} size="xl" footer={footer}>
      {"failed" === matrixState ? (
        <LoadErrorHint onRetry={() => void matrixQuery.refetch()}>Impossible de lire la matrice de trajet.</LoadErrorHint>
      ) : venues.length < 2 ? (
        <p className="py-6 text-center text-sm text-muted-foreground">Ajoutez au moins deux gymnases pour définir des temps de trajet entre eux.</p>
      ) : showConsent ? (
        <AutofillConsent onRun={runAutofill} onClose={onClose} running={busy} error={autofillError} />
      ) : (
        // `mt-3` : un espace entre le titre de la modale et le filtre (l'en-tête partagé n'en pose pas).
        <div className="mt-3 flex flex-col gap-3">
          {/* LOT C — l'explication de ce à quoi servent ces temps, et où se règle le battement toléré. */}
          <p className="text-xs text-muted-foreground">
            Ces temps servent à la génération du planning. Quand une même personne enchaîne deux séances le même jour dans deux gymnases différents, le planning vérifie qu'elle a le temps
            d'y aller : en voiture si le coach est déclaré véhiculé, à vélo ou en trottinette sinon — à vélo aussi pour les joueurs qui s'entraînent dans deux équipes reliées par une
            passerelle. Votre club peut accepter qu'on parte un peu avant la fin ou qu'on commence un peu après l'heure : ce battement se règle, avec le niveau de la règle (inactive,
            préférée ou obligatoire), à l'étape Contraintes, onglet Bien-être, encart «&nbsp;Trajet entre gymnases&nbsp;».
          </p>
          {/* Zone d'en-tête non défilante : filtre + légende + gymnases sans adresse. */}
          <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
              <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
              <Input aria-label="Filtrer par gymnase" placeholder="Filtrer par gymnase…" className="h-9" value={filter} onChange={(e) => setFilter(e.target.value)} />
            </div>
            {/* Légende — mêmes styles que les cases : pictogrammes du mode + code d'origine (couleur + gras). */}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
              <span className="inline-flex items-center gap-1 text-muted-foreground">
                <Car className="size-3.5" aria-hidden="true" /> En voiture
              </span>
              <span className="inline-flex items-center gap-1 text-muted-foreground">
                <Bike className="size-3.5" aria-hidden="true" /> À vélo
              </span>
              <span className="text-muted-foreground">Calculé automatiquement</span>
              <span className="font-semibold text-accent">Saisi à la main</span>
              <span className="inline-flex items-center gap-1 text-muted-foreground">
                <span aria-hidden="true">—</span> non calculé
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
                <caption className="sr-only">Temps de trajet entre gymnases, en voiture et à vélo, du gymnase de la ligne vers celui de la colonne. Cliquez une case pour la modifier.</caption>
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
                        return (
                          <td key={dest.id} className="border-b border-l border-border/50 p-0.5 align-middle">
                            <MatrixCell
                              from={from}
                              dest={dest}
                              row={rowByPair.get(pairKey(from.id, dest.id))}
                              reason={reasonByPair.get(pairKey(from.id, dest.id)) ?? null}
                              onEdit={() => setEditing({ from, dest })}
                            />
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

      {null !== editing ? (
        <TravelEditModal
          key={pairKey(editing.from.id, editing.dest.id)}
          from={editing.from}
          dest={editing.dest}
          row={editRow}
          reason={editReason}
          onCommit={(mode, minutes) => commitCell(editing.from, editing.dest, mode, minutes)}
          onClose={() => setEditing(null)}
        />
      ) : null}
    </Modal>
  );
}
