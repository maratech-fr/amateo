import { Trash2 } from "lucide-react";
import { useState } from "react";

import { useEntryConflicts, usePeriodAnchor } from "@/features/cockpit/queries";
import { frDateShort } from "@/features/cockpit/lib/date";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { formatDuration } from "@/shared/lib/duration";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { Closure } from "@/features/cockpit/api";

import type { Venue, VenuePeriodOverride, VenueTrainingSlot } from "../api";
import { DAYS, DURATIONS, durationOptions, hhmm } from "../lib/days";
import { closuresByVenue, closurePeriodLabel } from "../lib/venueClosures";
import { computeDayMaskToggle, manualClosedWeekdays } from "../lib/venueDays";
import type { DayMask } from "../lib/venueDays";
import { slotPlacementError } from "../lib/slotOverlap";
import {
  useClearVenuePeriodGrid,
  useClearVenuePeriodMode,
  useCreatePeriodSlot,
  useDeleteReservation,
  useDeletePeriodSlot,
  useDeletionImpact,
  usePeriodSlots,
  useReservations,
  useResetVenuePeriodGrid,
  useSetVenuePeriodMode,
  useUpdatePeriodSlot,
  useVenuePeriodOverrides,
  useWizardVenues,
} from "../queries";
import { WEEK } from "../lib/weekGrid";
import { CapacitySelect, GroupLabelField, SharedSlotHint } from "./slotFields";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";
import { PeriodAnchorGate } from "./PeriodAnchorGate";

/**
 * Period-editable venues (#8, PR-B) — même forme que l'éditeur de gymnases de la SAISON
 * (`VenuesEditor`), volontairement : un SÉLECTEUR de gymnase (UNE grille à la fois) et le
 * même geste d'édition de créneau. Réinventer ce panneau en « une carte par gymnase,
 * toutes montées » avait fait ressurgir tout ce que la saison avait déjà résolu — durée
 * figée, pas d'éditeur, mur de milliers de boutons, clavier cassé (revue #8 PR-B). Le
 * gestionnaire voit sa grille — une à la fois, exactement comme en saison.
 *
 * DEUX contrôles, pas trois positions (arbitrage fondateur) :
 *  - un ÉTAT persisté, actif / désactivé, qui ne touche JAMAIS la grille ;
 *  - deux ACTIONS destructives — reprendre la grille du planning principal, ou la vider —
 *    chacune confirmée en annonçant les réservations emportées.
 * « Hériter » n'est pas un état : c'est le défaut, l'absence de ligne. Réactiver ne coûte
 * donc jamais la saisie déjà faite.
 *
 * La grille d'un gymnase DÉSACTIVÉ est gelée dans un <fieldset disabled> — inerte à la
 * souris ET au clavier, contrairement à un pointer-events-none (revue #8 PR-B).
 */
export function PeriodVenues({ calendarEntryId }: { calendarEntryId: string }) {
  const anchor = usePeriodAnchor(calendarEntryId);

  return (
    <PeriodAnchorGate
      anchor={anchor}
      loadingLabel="Chargement des créneaux de la période…"
      errorLabel="Impossible de charger les créneaux de la période."
    >
      {(schedulePlanId) => <PeriodVenuesPanel calendarEntryId={calendarEntryId} schedulePlanId={schedulePlanId} />}
    </PeriodAnchorGate>
  );
}

function PeriodVenuesPanel({ calendarEntryId, schedulePlanId }: { calendarEntryId: string; schedulePlanId: string }) {
  const { data: venues = [] } = useWizardVenues();
  const periodSlotsQuery = usePeriodSlots(schedulePlanId);
  const overridesQuery = useVenuePeriodOverrides(schedulePlanId);
  const conflictsQuery = useEntryConflicts(calendarEntryId);
  const conflicts = conflictsQuery.data;

  const [selectedId, setSelectedId] = useState("");
  const [editingSlot, setEditingSlot] = useState<VenueTrainingSlot | null>(null);
  // Durée du PROCHAIN créneau posé au clic (P4-43). L'état vit ICI et non dans
  // `PeriodVenuePanel` : ce dernier est remonté par `key={selected.id}`, si bien qu'y
  // loger la durée la remettrait à 90 min à chaque changement de gymnase. La saison la
  // conserve (état dans `VenuesEditor`) — la période fait pareil.
  const [posingDuration, setPosingDuration] = useState(90);

  // P4-1 — la grille EST ces deux requêtes : un échec coercé en `[]` afficherait
  // « 0 créneau », indistinguable d'une période délibérément vidée. Le gestionnaire
  // re-dessine sa semaine → doublons de créneaux, ou valide une période qu'il croit
  // sans entraînement. L'échec passe donc avant tout rendu de grille.
  // `readState` plutôt que les drapeaux bruts : un refetch d'arrière-plan raté
  // laisse la donnée en cache intacte — céder la place à une erreur détruirait un
  // écran qui fonctionne. Seul un échec SANS rien à montrer s'affiche.
  if (readLoading(periodSlotsQuery) || readLoading(overridesQuery) || readLoading(conflictsQuery)) {
    // Les conflits font partie du chargement : sans eux, la grille se rend avec ZÉRO
    // fermeture affichée — tout paraît permis, et le gestionnaire pose des créneaux dans un
    // gymnase fermé (le vide crédible, version fermetures).
    return <p className="text-xs text-muted-foreground">Chargement de la grille de la période…</p>;
  }
  // Les conflits portent l'interdit « ce gymnase est fermé cette période » : les
  // avaler en `[]` rendrait une grille où tout paraît permis.
  if (readFailed(conflictsQuery)) {
    return (
      <LoadErrorHint onRetry={() => void conflictsQuery.refetch()}>
        Impossible de vérifier les fermetures de gymnase sur cette période.
      </LoadErrorHint>
    );
  }
  if (readFailed(periodSlotsQuery) || readFailed(overridesQuery)) {
    return (
      <LoadErrorHint
        onRetry={() => {
          void periodSlotsQuery.refetch();
          void overridesQuery.refetch();
        }}
      >
        Impossible de charger la grille de la période.
      </LoadErrorHint>
    );
  }
  if (0 === venues.length) {
    return <EmptyHint>Aucun gymnase.</EmptyHint>;
  }

  const periodSlots = periodSlotsQuery.data ?? [];
  const overrides = overridesQuery.data ?? [];
  const selected = (selectedId ? venues.find((v) => v.id === selectedId) : null) ?? venues[0];
  const override = overrides.find((o) => o.venueId === selected.id) ?? null;
  // D3 (P2-22) — `venueIds` reste l'INDEX des gymnases concernés ; le DÉTAIL (jours fermés,
  // dates, titre) vient de `closures`, au grain JOUR. Le front ne dérive rien : `weekdays` est
  // calculé serveur.
  const closed = new Set(conflicts?.venueIds ?? []);
  // P2-37 D6 — les gymnases ENTIÈREMENT fermés sur la fenêtre, tels que le SERVEUR les
  // calcule (`fullyClosedVenueIds`). Indisponibilité TOTALE : le front la LIT, il ne
  // redérive pas « toutes les dates fermées » (règle d'or). Sur un tel gymnase, l'interrupteur
  // Désactiver/Réactiver laisse place à la RAISON — le serveur refuserait le geste (D2).
  const fullyClosed = new Set(conflicts?.fullyClosedVenueIds ?? []);
  const closuresByV = closuresByVenue(conflicts?.closures ?? []);
  const closureLabelsFor = (venueId: string): string[] => (closuresByV.get(venueId) ?? []).map(closurePeriodLabel);
  // Indispo INFORMATIVE (2026-08-18) — l'état effectif jour par jour, servi AVEC provenance
  // (`effectiveClosedWeekdays`). Le front le LIT ; il ne recompose jamais incident × masque.
  const effectiveClosedWeekdays = conflicts?.effectiveClosedWeekdays;
  const manualDaysFor = (venueId: string): number[] => manualClosedWeekdays(effectiveClosedWeekdays, venueId);
  // P2-43 volet (ii) — les gymnases DÉSACTIVÉS (mode DISABLED), tels que le SERVEUR les calcule.
  const disabledSet = new Set(conflicts?.disabledVenueIds ?? []);
  // L'état effectif que le SÉLECTEUR doit annoncer, par priorité : désactivé (mode) > indisponible
  // toute la période (fermé total) > fermé {jours} (partiel, masque OU indispo) > rien (ouvert).
  // Lu de l'état SERVI, jamais recomposé — un gymnase désactivé cessait sinon d'exister à l'écran.
  const pickerStateLabel = (venueId: string): string => {
    if (disabledSet.has(venueId)) {
      return "désactivé";
    }
    if (fullyClosed.has(venueId)) {
      return "indisponible toute la période";
    }
    const days = Object.keys(effectiveClosedWeekdays?.[venueId] ?? {}).map(Number).sort((a, b) => a - b);
    return days.length > 0 ? `fermé ${days.map((d) => DAY_LABELS_LONG[d].toLowerCase()).join(", ")}` : "";
  };
  const venueSlots = periodSlots.filter((s) => s.venueId === selected.id);
  // Le bandeau rappelle TOUT ce qui ne sert pas : indisponibilités déclarées ET jours décochés
  // à la main (décision C) — sans quoi un jour fermé d'un clic passait inaperçu hors du gymnase
  // sélectionné.
  const venuesToRemind = venues.filter((v) => closed.has(v.id) || manualDaysFor(v.id).length > 0);

  return (
    <div>
      <p className="mb-3 text-sm text-muted-foreground">
        Ces créneaux sont repris de votre planning principal à l’ouverture de la période. Les modifier ici ne change que cette période — cliquez dans la grille pour poser un créneau, un créneau pour l’ajuster.
      </p>

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <label className="text-sm font-medium" htmlFor="period-venue-picker">
          Gymnase :
        </label>
        <VenueSelect
          id="period-venue-picker"
          aria-label="Gymnase"
          className="h-9"
          wrapperClassName="w-60"
          // P2-43 volet (ii) — chaque option porte son état effectif en SOUS-LIGNE (désactivé /
          // indisponible toute la période / fermé {jours} / rien), le nom reste intact.
          venues={venues.map((v) => {
            const state = pickerStateLabel(v.id);
            return { id: v.id, name: v.name, color: v.color, sub: "" === state ? undefined : state };
          })}
          value={selected.id}
          onValueChange={(next) => {
            setSelectedId(next);
            setEditingSlot(null); // ne jamais laisser l'éditeur ouvert sur un créneau d'un autre gymnase
          }}
        />
      </div>

      {/* Indicateur d'ensemble : le badge par gymnase ne montre que le gymnase choisi, or
          un gestionnaire doit voir d'un coup TOUT ce qui ne sert pas (revue #8 PR-B round 2 ;
          décision C 2026-08-18) — les indisponibilités déclarées ET les jours décochés à la
          main — sans quoi il croit un jour/gymnase utilisable et ne comprend pas le résultat. */}
      {venuesToRemind.length > 0 ? (
        <div role="alert" className="mb-3 space-y-1 text-sm text-destructive">
          {venuesToRemind.map((v) => {
            const parts: string[] = [];
            if (closed.has(v.id)) {
              parts.push(closureLabelsFor(v.id).join(" · ") || "Indispo cette période");
            }
            const manual = manualDaysFor(v.id);
            if (manual.length > 0) {
              parts.push(`jours décochés à la main : ${manual.map((d) => DAY_LABELS_LONG[d].toLowerCase()).join(", ")}`);
            }
            return (
              <p key={v.id}>
                <span className="font-medium">{v.name}</span> : {parts.join(" — ")}
              </p>
            );
          })}
        </div>
      ) : null}

      <PeriodVenuePanel
        key={selected.id}
        venue={selected}
        schedulePlanId={schedulePlanId}
        slots={venueSlots}
        override={override}
        closures={closuresByV.get(selected.id) ?? []}
        fullyClosed={fullyClosed.has(selected.id)}
        effectiveClosed={effectiveClosedWeekdays?.[selected.id] ?? {}}
        syncing={overridesQuery.isFetching}
        editingSlot={editingSlot}
        onEditSlot={setEditingSlot}
        posingDuration={posingDuration}
        onPosingDuration={setPosingDuration}
      />
    </div>
  );
}

function PeriodVenuePanel({
  venue,
  schedulePlanId,
  slots,
  override,
  closures,
  fullyClosed,
  effectiveClosed,
  syncing,
  editingSlot,
  onEditSlot,
  posingDuration,
  onPosingDuration,
}: {
  venue: Venue;
  schedulePlanId: string;
  slots: VenueTrainingSlot[];
  override: VenuePeriodOverride | null;
  closures: Closure[];
  /** Indispo INFORMATIVE (2026-08-18) — gymnase entièrement fermé sur la fenêtre (donnée serveur).
   *  Le geste est désormais ACCEPTÉ ; la raison reste affichée en information, jamais à sa place. */
  fullyClosed: boolean;
  /** L'état effectif jour par jour du gymnase, servi AVEC provenance : `{ jour ISO → provenance }`.
   *  Seuls les jours EFFECTIVEMENT fermés y figurent. Composé SERVEUR — jamais recomposé ici. */
  effectiveClosed: Record<string, "manual" | "default-incident">;
  syncing: boolean;
  editingSlot: VenueTrainingSlot | null;
  onEditSlot: (slot: VenueTrainingSlot | null) => void;
  posingDuration: number;
  onPosingDuration: (minutes: number) => void;
}) {
  const [pending, setPending] = useState<"reset" | "clear" | null>(null);
  const { data: reservations = [] } = useReservations(schedulePlanId);
  const createSlot = useCreatePeriodSlot(schedulePlanId);
  const deleteSlot = useDeletePeriodSlot(schedulePlanId);
  const setMode = useSetVenuePeriodMode(schedulePlanId);
  const clearMode = useClearVenuePeriodMode(schedulePlanId);
  const resetGrid = useResetVenuePeriodGrid(schedulePlanId);
  const clearGrid = useClearVenuePeriodGrid(schedulePlanId);

  const isDisabled = "DISABLED" === override?.mode;
  // `syncing` ferme la fenêtre entre le succès d'une mutation et le refetch des overrides :
  // sans lui, un double-clic « Désactiver » repartait en POST create (override encore null
  // dans le cache) → 422 (revue #8 PR-B). Le bouton reste inerte tant que l'état n'est pas
  // à jour.
  const modeBusy = setMode.isPending || clearMode.isPending || syncing;
  const gridBusy = resetGrid.isPending || clearGrid.isPending;
  const reservationCount = reservations.filter((r) => r.venueId === venue.id).length;
  // #8 round 2 finding #6 — un créneau sur un jour que la grille ne montre pas resterait
  // INVISIBLE tout en étant SERVI au solveur. On le rend visible et supprimable plutôt que
  // de le laisser agir en silence.
  //
  // ⚠ Le cas d'origine était le DIMANCHE, que la grille s'arrêtant au samedi ne pouvait pas
  // afficher. P4-37 a traité la cause : les sept jours sont désormais rendus, et ce filet
  // ne rattrape plus qu'un jour ABERRANT (donnée importée, dérive). On le garde pour ça —
  // il coûte une ligne et son absence rendrait le créneau muet.
  const offGridSlots = slots.filter((sl) => !WEEK.some((d) => d.n === sl.dayOfWeek));

  // Le masque manuel STOCKÉ (la ressource que l'écran édite). Le front l'écrit, mais ne
  // recompose jamais l'état effectif — celui-ci vient du serveur (`effectiveClosed`).
  const dayMask: DayMask = override?.dayOverrides ?? {};
  const hasIncident = closures.length > 0;
  const hasOverride = null !== override;
  const maskHasEntries = Object.keys(dayMask).length > 0;

  // Basculer la coche d'UN jour : écrire l'intention (OPEN/CLOSED) dans le masque SPARSE, et
  // supprimer la ligne dès que mode ET masque redeviennent vides (retour au défaut « hériter »).
  const toggleDay = (weekday: number, currentlyClosed: boolean) => {
    const nextMask = computeDayMaskToggle(dayMask, weekday, currentlyClosed);
    const mode = override?.mode ?? null;
    const maskEmpty = 0 === Object.keys(nextMask).length;
    if (null === mode && maskEmpty) {
      if (null !== override) {
        clearMode.mutate(override.id);
      }
      return;
    }
    setMode.mutate({ venueId: venue.id, mode, dayOverrides: maskEmpty ? null : nextMask, existingId: override?.id });
  };

  // Geste gymnase entier — rouvrir les 7 jours malgré l'indisponibilité (masque OPEN×7), mode
  // courant préservé (le serveur REMPLACE mode + masque au PUT, on envoie donc l'état complet).
  const reactivateDespite = () => {
    const allOpen: DayMask = { 1: "OPEN", 2: "OPEN", 3: "OPEN", 4: "OPEN", 5: "OPEN", 6: "OPEN", 7: "OPEN" };
    setMode.mutate({ venueId: venue.id, mode: override?.mode ?? null, dayOverrides: allOpen, existingId: override?.id });
  };

  // « Revenir au défaut » : supprimer la ligne (mode ET masque effacés) — sans ligne = hériter.
  const backToDefault = () => {
    if (null !== override) {
      clearMode.mutate(override.id);
    }
  };

  const toggleActive = () => {
    if (isDisabled && null !== override) {
      // Réactiver : garder le masque DORMANT (coches conservées). Masque vide → retirer la ligne
      // (le backend ne touche pas la grille depuis DISABLED) ; masque présent → PUT mode nul +
      // masque, jamais un DELETE qui effacerait les coches conservées.
      if (maskHasEntries) {
        setMode.mutate({ venueId: venue.id, mode: null, dayOverrides: dayMask, existingId: override.id });
      } else {
        clearMode.mutate(override.id);
      }
      return;
    }
    // Désactiver : poser le mode en PRÉSERVANT le masque (le PUT remplace tout côté serveur).
    setMode.mutate({ venueId: venue.id, mode: "DISABLED", dayOverrides: maskHasEntries ? dayMask : null, existingId: override?.id });
  };

  return (
    <section aria-label={`Gymnase ${venue.name}`} className="rounded-lg border border-border bg-card p-3">
      <header className="mb-2 flex flex-wrap items-center gap-2">
        <span className={cn("font-medium", isDisabled && "text-muted-foreground line-through")}>{venue.name}</span>
        {isDisabled ? <span className="rounded bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground">Désactivé cette période</span> : null}
        {/* Indispo INFORMATIVE (2026-08-18) — la raison reste affichée en INFORMATION (badge par
            fermeture, grain jour), qu'elle soit partielle ou totale. Elle ne remplace plus
            l'interrupteur : le serveur accepte désormais le geste. */}
        {closures.map((c) => (
          <span key={c.constraintId} className="rounded bg-destructive/10 px-1.5 py-0.5 text-xs font-semibold text-destructive">
            {closurePeriodLabel(c)}
          </span>
        ))}
        <span className="text-xs text-muted-foreground">
          {slots.length} créneau{slots.length > 1 ? "x" : ""}
        </span>
        <Button type="button" size="sm" variant="outline" className="ml-auto" disabled={modeBusy} onClick={toggleActive}>
          {isDisabled ? "Réactiver" : "Désactiver"}
        </Button>
      </header>

      {fullyClosed && !isDisabled ? (
        <p className="mb-2 text-xs text-muted-foreground">
          Ce gymnase est indisponible sur toute la fenêtre (indisponibilité déclarée) — aucune séance n'y sera placée. Vous pouvez rouvrir des jours ci-dessous, ou le préparer pour la suite.
        </p>
      ) : isDisabled ? (
        <p className="mb-2 text-xs text-muted-foreground">Ce gymnase ne sera pas utilisé pour cette période. Sa grille est conservée telle quelle — réactivez-le pour la modifier.</p>
      ) : 0 === slots.length ? (
        <p role="alert" className="mb-2 text-sm text-destructive">Aucun créneau : aucune équipe ne pourra s’entraîner dans ce gymnase tant que vous n’en aurez pas posé.</p>
      ) : null}

      {/* Rangée de coches JOUR (indispo INFORMATIVE, 2026-08-18) : la coche du plan fait foi, jour
          par jour. L'état est LU de `effectiveClosed` (composé SERVEUR, avec provenance) ; le clic
          écrit OPEN/CLOSED dans le masque manuel, ou RETIRE l'entrée au retour au défaut. Sous
          DISABLED la rangée est GELÉE (fieldset) et montre le masque DORMANT — coches conservées. */}
      <fieldset disabled={isDisabled} className={cn("mb-3 min-w-0 border-0 p-0", isDisabled && "opacity-50")}>
        <p className="mb-1 text-xs font-medium text-muted-foreground">Jours ouverts cette période</p>
        <div className="flex flex-wrap gap-x-3 gap-y-1">
          {WEEK.map((d) => {
            // Sous DISABLED, l'état effectif servi exclut le gymnase : on rend alors le masque
            // dormant. Sinon, `effectiveClosed` (serveur) fait foi.
            const closedDay = isDisabled ? "CLOSED" === dayMask[d.n] : undefined !== effectiveClosed[d.n];
            const provenance = effectiveClosed[d.n];
            const reopened = "OPEN" === dayMask[d.n];
            const firstClosure = closures[0];
            const datesSuffix = firstClosure ? ` (du ${frDateShort(firstClosure.startDate)} au ${frDateShort(firstClosure.endDate)})` : "";
            const title = closedDay
              ? "manual" === provenance
                ? "fermé — décoché manuellement"
                : `fermé — indisponibilité déclarée${datesSuffix}`
              : reopened
                ? "ouvert — réactivé malgré l'indisponibilité"
                : "ouvert";
            return (
              <label key={d.n} className="flex items-center gap-1 text-xs text-muted-foreground" title={title}>
                <input
                  type="checkbox"
                  checked={!closedDay}
                  disabled={modeBusy}
                  onChange={() => toggleDay(d.n, closedDay)}
                  aria-label={`${DAY_LABELS_LONG[d.n]} — ${venue.name}`}
                  title={title}
                />
                <span>{d.label}</span>
              </label>
            );
          })}
        </div>
        {isDisabled ? (
          <p className="mt-1 text-xs italic text-muted-foreground">Grille et coches conservées — réactivez le gymnase pour les retrouver.</p>
        ) : (
          <p className="mt-1 text-xs text-muted-foreground">Décocher un jour le ferme sur toutes les semaines de la période ; le recocher le rouvre partout.</p>
        )}
        {!isDisabled && (hasIncident || hasOverride) ? (
          <div className="mt-2 flex flex-wrap items-center gap-2">
            {hasIncident ? (
              <Button type="button" size="sm" variant="outline" disabled={modeBusy} onClick={reactivateDespite}>
                Réactiver malgré l&apos;indisponibilité
              </Button>
            ) : null}
            {hasOverride ? (
              <Button type="button" size="sm" variant="ghost" disabled={modeBusy} onClick={backToDefault}>
                Revenir au défaut
              </Button>
            ) : null}
          </div>
        ) : null}
      </fieldset>

      {/* fieldset disabled : gèle la grille à la souris ET au clavier quand le gymnase est
          désactivé — ses boutons sortent de l'ordre de tabulation et ne s'activent pas.
          ⚠ La barre « À poser » est DEDANS, pas au-dessus : le geste qu'elle règle et la
          grille où il s'exerce ne font qu'un. Laissée dehors, son libellé et son indice
          « cliquez la grille » restaient en pleine opacité au-dessus d'une grille gelée —
          une instruction de cliquer là où le clic est impossible (revue #351). */}
      <fieldset disabled={isDisabled} className={cn("min-w-0 border-0 p-0", isDisabled && "opacity-50")}>
        {/* Barre « À poser » — même forme et même rôle qu'en saison (`VenuesStep`) : la durée
            du PROCHAIN créneau posé au clic. Elle manquait ici, et la pose était figée à
            90 min : un créneau de 2h se posait en deux gestes (poser, puis rouvrir
            l'éditeur), et une pose tardive était refusée pour une durée que le gestionnaire
            n'avait pas choisie (P4-43). La capacité, elle, reste réglée par créneau dans
            l'éditeur — un créneau neuf vaut toujours 1, comme en saison. */}
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <span className="text-xs text-muted-foreground">À poser :</span>
          <Select aria-label="Durée à poser" className="h-9 w-24" value={posingDuration} onChange={(e) => onPosingDuration(Number(e.target.value))}>
            {DURATIONS.map((d) => (
              <option key={d} value={d}>
                {formatDuration(d)}
              </option>
            ))}
          </Select>
          <span className="text-xs text-muted-foreground">— cliquez la grille pour ajouter un créneau</span>
        </div>

        <VenueAvailabilityGrid
          venue={venue}
          slots={slots}
          closures={closures}
          selectedSlotId={editingSlot?.id ?? null}
          onAdd={(dayOfWeek, startTime) => {
            // Un créneau peut finir APRÈS la dernière ligne de la grille — un créneau du
            // soir (22:00–23:30) est légitime, et l'y interdire rendait la période plus
            // stricte que la saison (revue #8 PR-B round 2). La seule borne est MINUIT
            // (P4-37), et son message par défaut nomme désormais la durée : le gestionnaire
            // a la barre « À poser » sous les yeux pour la corriger.
            const invalid = slotPlacementError(slots, dayOfWeek, startTime, posingDuration);
            if (null !== invalid) {
              toast.error(invalid);
              return;
            }
            createSlot.mutate({ venueId: venue.id, dayOfWeek, startTime, durationMinutes: posingDuration, capacity: 1 });
          }}
          onSelect={(slot) => onEditSlot(slot)}
        />
      </fieldset>

      {offGridSlots.length > 0 && !isDisabled ? (
        <div className="mt-2 rounded-md border border-destructive/40 bg-destructive/5 p-2">
          <p role="alert" className="mb-1 text-xs font-medium text-destructive">
            Créneau(x) sur un jour non affichable — servi au système mais invisible sur la grille. Supprimez-le pour ne pas planifier ce jour-là.
          </p>
          <ul className="flex flex-col gap-1">
            {offGridSlots.map((sl) => (
              <li key={sl.id} className="flex items-center justify-between gap-2 text-xs">
                <span>
                  {DAY_LABELS_LONG[sl.dayOfWeek] ?? `jour ${sl.dayOfWeek}`} {hhmm(sl.startTime)} ({sl.durationMinutes} min)
                </span>
                <Button type="button" size="sm" variant="destructive" disabled={deleteSlot.isPending} onClick={() => deleteSlot.mutate(sl.id)}>
                  <Trash2 className="size-4" />
                  Supprimer
                </Button>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      <div className="mt-2 flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" variant="outline" disabled={isDisabled || gridBusy} onClick={() => setPending("reset")}>
          Reprendre la grille du planning principal
        </Button>
        <Button type="button" size="sm" variant="outline" disabled={isDisabled || gridBusy || 0 === slots.length} onClick={() => setPending("clear")}>
          Vider la grille
        </Button>
      </div>

      {null !== editingSlot && editingSlot.venueId === venue.id && !isDisabled ? (
        <PeriodSlotEditor
          key={editingSlot.id}
          slot={editingSlot}
          schedulePlanId={schedulePlanId}
          canSplit={venue.canSplit}
          otherSlots={slots.filter((s) => s.id !== editingSlot.id)}
          reservationsHere={reservations.filter((r) => r.venueId === venue.id && r.dayOfWeek === editingSlot.dayOfWeek && hhmm(r.startTime) === hhmm(editingSlot.startTime))}
          // P2-43 volet (i) — l'état effectif du gymnase (jour → provenance) + ses fermetures :
          // l'éditeur DIT qu'un créneau tombe un jour fermé (poser reste permis).
          effectiveClosed={effectiveClosed}
          closures={closures}
          onClose={() => onEditSlot(null)}
        />
      ) : null}

      <ConfirmDialog
        open={null !== pending}
        destructive
        title={"reset" === pending ? `Reprendre la grille de ${venue.name} ?` : `Vider la grille de ${venue.name} ?`}
        description={
          "reset" === pending
            ? `Les créneaux de ${venue.name} pour cette période seront remplacés par ceux de votre planning principal.${reservationCount > 0 ? ` ${reservationCount} réservation${reservationCount > 1 ? "s" : ""} sur ce gymnase ser${reservationCount > 1 ? "ont" : "a"} supprimée${reservationCount > 1 ? "s" : ""}.` : ""}`
            : `Tous les créneaux de ${venue.name} pour cette période seront supprimés, à ressaisir.${reservationCount > 0 ? ` ${reservationCount} réservation${reservationCount > 1 ? "s" : ""} sur ce gymnase part${reservationCount > 1 ? "iront" : "ira"} avec eux.` : ""}`
        }
        confirmLabel={"reset" === pending ? "Reprendre" : "Vider"}
        onConfirm={() => {
          // Actions atomiques et idempotentes des deux côtés : jamais un PUT de mode, dont
          // l'idempotence rendrait « vider » un no-op quand le mode ne change pas.
          if ("reset" === pending) {
            resetGrid.mutate(venue.id, { onSuccess: () => toast.success("Grille reprise du planning principal") });
          } else {
            clearGrid.mutate(venue.id, { onSuccess: () => toast.success("Grille vidée") });
          }
          onEditSlot(null); // l'éditeur pouvait viser un créneau qu'on vient de détruire
          setPending(null);
        }}
        onCancel={() => setPending(null)}
      />
    </section>
  );
}

/**
 * Édite un créneau de la période — jour, heure, DURÉE, capacité (gymnase divisible) — ou
 * le supprime. Miroir de `SlotEditor` (saison), câblé sur les hooks de la COUCHE période :
 * mêmes gestes, écriture sur le plan et jamais sur le socle.
 *
 * DÉPLACER un créneau (changer jour/heure) laisse les réservations épinglées à l'ANCIENNE
 * position orphelines — et un épinglage orphelin BLOQUE la génération (OrphanPinGuard). On
 * ne le fait donc jamais en silence : si le créneau porte des réservations et que sa
 * position change, on confirme en annonçant qu'elles seront retirées, puis on déplace ET
 * on les supprime (pas d'orphelin laissé derrière — revue #8 PR-B round 2).
 */
function PeriodSlotEditor({
  slot,
  schedulePlanId,
  canSplit,
  otherSlots,
  reservationsHere,
  effectiveClosed,
  closures,
  onClose,
}: {
  slot: VenueTrainingSlot;
  schedulePlanId: string;
  canSplit: boolean;
  otherSlots: VenueTrainingSlot[];
  reservationsHere: { id: string }[];
  /** P2-43 volet (i) — l'état effectif jour par jour du gymnase (jour ISO → provenance), SERVI. */
  effectiveClosed: Record<string, "manual" | "default-incident">;
  /** Les fermetures déclarées du gymnase (pour dater la cause « indisponibilité déclarée »). */
  closures: Closure[];
  onClose: () => void;
}) {
  const update = useUpdatePeriodSlot(schedulePlanId);
  const del = useDeletePeriodSlot(schedulePlanId);
  const delReservation = useDeleteReservation();
  const [day, setDay] = useState(slot.dayOfWeek);
  const [time, setTime] = useState(hhmm(slot.startTime));
  const [duration, setDuration] = useState(slot.durationMinutes);
  const [capacity, setCapacity] = useState(slot.capacity);
  const [groupLabel, setGroupLabel] = useState(slot.groupLabel ?? "");
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [confirmMove, setConfirmMove] = useState(false);

  const moved = day !== slot.dayOfWeek || time !== hhmm(slot.startTime);
  const slotImpact = useDeletionImpact("slot", confirmDelete ? slot.id : null);
  // Toujours dérivé du cache, et c'est légitime : il sert à la confirmation de DÉPLACEMENT
  // (un autre geste, qui ne détruit pas en cascade), pas à l'annonce de suppression.
  const reservationCount = reservationsHere.length;

  // P2-43 volet (i) — le JOUR édité est-il fermé (état effectif SERVI) ? On ne bloque pas (décision
  // fondateur : poser reste permis) — on le DIT. La provenance choisit la cause (présentation) ; la
  // décision « ce jour est fermé » vient du serveur (présence de la clé).
  const closedProvenance = effectiveClosed[day];
  const firstClosure = closures[0];
  const closedCause =
    "manual" === closedProvenance
      ? "décoché manuellement"
      : `indisponibilité déclarée${firstClosure ? ` du ${frDateShort(firstClosure.startDate)} au ${frDateShort(firstClosure.endDate)}` : ""}`;

  const doSave = () => {
    const effCapacity = canSplit ? capacity : 1;
    // La période n'est pas plus stricte que la saison : un créneau du soir (22:00–23:30)
    // reste légitime, la seule borne est MINUIT — posée en amont par `slotPlacementError`
    // dans `save`, pas ici. Ne referme qu'au succès : une écriture rejetée garde l'éditeur
    // ouvert plutôt que de disparaître en donnant l'illusion d'être enregistrée.
    // Libellé de groupe : envoyé seulement sur un créneau partageable (≥ 2), sinon null (P2-17).
    update.mutate(
      { id: slot.id, body: { venueId: slot.venueId, dayOfWeek: day, startTime: time, durationMinutes: duration, capacity: effCapacity, groupLabel: effCapacity >= 2 ? groupLabel : null } },
      {
        onSuccess: () => {
          // Le déplacement a orpheliné les réservations de l'ancienne position : on les
          // retire pour ne pas bloquer la génération (elles ont été annoncées).
          for (const r of reservationsHere) {
            delReservation.mutate(r.id);
          }
          onClose();
        },
      },
    );
  };

  const save = () => {
    const invalid = slotPlacementError(otherSlots, day, time, duration);
    if (null !== invalid) {
      setError(invalid);
      return;
    }
    // Déplacer un créneau réservé retire ses réservations : on le confirme d'abord.
    if (moved && reservationCount > 0) {
      setConfirmMove(true);
      return;
    }
    doSave();
  };

  return (
    <Modal
      label="Modifier le créneau"
      title="Modifier le créneau"
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>
            <Trash2 className="size-4" />
            Supprimer
          </Button>
          <Button onClick={save} disabled={update.isPending}>
            Enregistrer
          </Button>
        </>
      }
    >
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="text-xs text-muted-foreground">
          Jour
          <Select aria-label="Jour" className="mt-0.5 h-9 w-28" value={day} onChange={(e) => (setDay(Number(e.target.value)), setError(null))}>
            {/* Idem `VenuesStep` : ce select filtrait le dimanche pour son compte, alors
                que la grille le rend désormais (P4-37). Un créneau du dimanche s'y ouvrait
                sur un champ vide. */}
            {WEEK.map((d) => (
              <option key={d.n} value={d.n}>
                {d.label}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          Début
          <Input aria-label="Début" type="time" className="mt-0.5 h-9 w-28" value={time} onChange={(e) => (setTime(e.target.value), setError(null))} />
        </label>
        <label className="text-xs text-muted-foreground">
          Durée
          <Select aria-label="Durée" className="mt-0.5 h-9 w-28" value={duration} onChange={(e) => (setDuration(Number(e.target.value)), setError(null))}>
            {durationOptions(duration, slot.durationMinutes).map((d) => (
              <option key={d} value={d}>
                {formatDuration(d)}
              </option>
            ))}
          </Select>
        </label>
        {canSplit ? (
          <div className="text-xs text-muted-foreground">
            {/* CapacitySelect porte son propre aria-label="Capacité". */}
            <span>Capacité</span>
            <CapacitySelect value={capacity} onChange={setCapacity} canSplit={canSplit} className="mt-0.5 block h-9 w-52" />
          </div>
        ) : null}
      </div>

      {/* P2-43 volet (i) — le jour édité est fermé : on le DIT, sans rien bloquer (poser reste permis). */}
      {undefined !== closedProvenance ? (
        <p role="note" className="mt-3 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
          Créneau inactif — le {DAY_LABELS_LONG[day]?.toLowerCase() ?? `jour ${day}`} est fermé ({closedCause}). Le poser reste possible ; il ne servira pas tant que ce jour reste fermé.
        </p>
      ) : null}

      {canSplit ? <SharedSlotHint capacity={capacity} /> : null}
      {canSplit ? <GroupLabelField capacity={capacity} value={groupLabel} onChange={setGroupLabel} /> : null}

      {null !== error ? (
        <p role="alert" className="mt-3 text-sm text-destructive">
          {error}
        </p>
      ) : null}

      {/* affectsPeriodPlans NON posé : supprimer un créneau de PÉRIODE ne touche QUE cette
          période, pas « les plannings de période » — l'exagérer trompe (invariant n°4,
          revue #8 PR-B round 2). */}
      <DeleteConfirm
        open={confirmDelete}
        entityName={`créneau ${DAYS.find((d) => d.n === slot.dayOfWeek)?.label ?? ""} ${hhmm(slot.startTime)}`.trim()}
        // P4-108 : compté par le serveur, borné à la COUCHE de ce créneau — un créneau de
        // période n'emporte jamais l'épinglage du planning principal.
        impact={slotImpact.data ?? undefined}
        impactLoading={slotImpact.isPending && confirmDelete}
        impactFailed={slotImpact.isError}
        onConfirm={() => {
          del.mutate(slot.id, { onSuccess: onClose });
          setConfirmDelete(false);
        }}
        onCancel={() => setConfirmDelete(false)}
      />

      <ConfirmDialog
        open={confirmMove}
        destructive
        title="Déplacer ce créneau ?"
        description={`Ce créneau porte ${reservationCount} réservation${reservationCount > 1 ? "s" : ""} d'équipe. Le déplacer ${reservationCount > 1 ? "les retirera" : "la retirera"} — il faudra ${reservationCount > 1 ? "les reposer" : "la reposer"} sur le nouveau créneau.`}
        confirmLabel="Déplacer"
        onConfirm={() => {
          setConfirmMove(false);
          doSave();
        }}
        onCancel={() => setConfirmMove(false)}
      />
    </Modal>
  );
}

const DAY_LABELS_LONG: Record<number, string> = { 1: "Lundi", 2: "Mardi", 3: "Mercredi", 4: "Jeudi", 5: "Vendredi", 6: "Samedi", 7: "Dimanche" };
