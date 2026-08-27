import { AlertTriangle, ArrowRight, Car, Footprints, MapPinOff, Pencil, RefreshCw, Search, Wand2 } from "lucide-react";
import { useState } from "react";

import { apiErrorMessage } from "@/shared/api/errors";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { Modal } from "@/shared/components/ui/modal";
import { readState } from "@/shared/lib/readState";
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

function reasonLabel(reason: AutofillUnresolvedReason): string {
  return "missing_geo" === reason ? "gymnase sans adresse" : "calcul impossible";
}

/** Le badge d'origine d'une valeur : AUTO (calculée) ou MANUEL (saisie). Icône + texte. */
function SourceBadge({ source }: { source: "AUTO" | "MANUAL" }) {
  return "AUTO" === source ? (
    <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-muted-foreground">
      <Wand2 className="size-3.5" aria-hidden="true" />
      Auto
    </span>
  ) : (
    <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-accent">
      <Pencil className="size-3.5" aria-hidden="true" />
      Manuel
    </span>
  );
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
      {null !== error ? (
        <p role="alert" className="flex items-center gap-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
          <AlertTriangle className="size-4 shrink-0 text-warning" />
          {error}
        </p>
      ) : null}
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

  const [autofillResult, setAutofillResult] = useState<VenueTravelTimeAutofillResult | null>(null);
  const [autofillError, setAutofillError] = useState<string | null>(null);
  const [filter, setFilter] = useState("");

  const venues: Venue[] = venuesQuery.data ?? [];
  const matrix: VenueTravelTime[] = matrixQuery.data ?? [];
  const matrixState = readState(matrixQuery);

  const rowByPair = new Map<string, VenueTravelTime>(matrix.map((r) => [pairKey(r.venueAId, r.venueBId), r]));
  const reasonByPair = new Map<string, AutofillUnresolvedReason>((autofillResult?.unresolved ?? []).map((u) => [pairKey(u.venueAId, u.venueBId), u.reason]));
  const venuesWithoutGeo = venues.filter((v) => null == v.latitude || null == v.longitude);

  const runAutofill = () => {
    setAutofillError(null);
    autofill.mutate(undefined, {
      onSuccess: (result) => setAutofillResult(result),
      onError: async (e) => setAutofillError(await apiErrorMessage(e)),
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

  const showConsent = 0 === matrix.length && null === autofillResult;

  const needle = filter.trim().toLowerCase();
  const sorted = [...venues].sort((a, b) => a.name.localeCompare(b.name, "fr"));
  const sections = sorted
    .map((from, i) => ({ from, dests: sorted.slice(i + 1) }))
    .filter((s) => s.dests.length > 0)
    .map((s) => ({
      from: s.from,
      dests: "" === needle ? s.dests : s.dests.filter((d) => d.name.toLowerCase().includes(needle) || s.from.name.toLowerCase().includes(needle)),
    }))
    .filter((s) => s.dests.length > 0);

  const footer = showConsent ? undefined : (
    <>
      <div className="mr-auto flex flex-wrap items-center gap-2">
        <Button variant="outline" onClick={runAutofill} disabled={autofill.isPending}>
          {autofill.isPending ? <Spinner className="size-4" /> : <RefreshCw className="size-4" />}
          Recalculer les trajets
        </Button>
        <span className="text-xs text-muted-foreground">Vos valeurs saisies à la main sont conservées.</span>
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
        <AutofillConsent onRun={runAutofill} onClose={onClose} running={autofill.isPending} error={autofillError} />
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
              <p role="alert" className="flex items-center gap-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
                <AlertTriangle className="size-4 shrink-0 text-warning" />
                {autofillError}
              </p>
            ) : null}
            {venuesWithoutGeo.length > 0 ? (
              <div className="flex flex-col gap-1 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
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

          {/* Corps défilant : couples groupés par gymnase de départ. */}
          {0 === sections.length ? (
            <p className="py-4 text-center text-sm text-muted-foreground">Aucun gymnase ne correspond à « {filter.trim()} ».</p>
          ) : (
            <div className="flex flex-col gap-4">
              {sections.map(({ from, dests }) => (
                <section key={from.id}>
                  <h3 className="sticky top-0 border-b border-border bg-card py-1.5 text-sm font-medium text-foreground">Depuis {from.name}</h3>
                  <ul>
                    {dests.map((dest) => {
                      const row = rowByPair.get(pairKey(from.id, dest.id));
                      const reason = reasonByPair.get(pairKey(from.id, dest.id)) ?? null;
                      const label = `${from.name} → ${dest.name}`;
                      return (
                        <li key={dest.id} className="grid grid-cols-[1fr_auto_auto] items-center gap-3 border-b border-border/50 py-2">
                          <span className="flex min-w-0 items-center gap-1 truncate text-sm">
                            <ArrowRight className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span className="truncate">{dest.name}</span>
                          </span>
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
                        </li>
                      );
                    })}
                  </ul>
                </section>
              ))}
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
