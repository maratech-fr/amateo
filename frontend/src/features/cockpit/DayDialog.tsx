import { CalendarOff, CalendarRange, Minus, MoveRight, Plus, Sun, Trash2 } from "lucide-react";
import { type ReactNode, useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router";

import { useSchedules, useVenues } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Modal } from "@/shared/components/ui/modal";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry, PublicHoliday, RedateEffect, RedateEffectKind, SchedulePlan, SchoolHoliday } from "./api";
import { PreviewTokenStaleError, WindowAlreadyPlannedError } from "./api";
import { errorMessage } from "@/shared/lib/errorMessage";
import { useWorkingSeason } from "@/shared/session/queries";

import { clampRangeToSeason, frDateShort, groupCoverageSlots, periodWeeksToAdjust, todayISO, weeksCovering } from "./lib/date";
import { seasonLockTitle, useSocleValidated } from "./lib/socle";
import { useWeekAdapt } from "./lib/useWeekAdapt";
import { WarningPanel } from "@/shared/components/ui/warning-panel";
import { WindowAlreadyPlannedNotice } from "./WindowAlreadyPlannedNotice";
import { entryIcon, entryLabel, holidayIcon, isHolidayAnchor, isHolidayWeekChild } from "./lib/markers";
import { useCalendarEntries, useCreateCutoff, useCreateEvent, useCreateVenueClosure, useDeleteEntry, useRedateEntry, useRedatePreview, useSchedulePlanForEntry, useSchedulePlans } from "./queries";
import { WeekPickerDialog } from "./WeekPickerDialog";
import { StalenessPill } from "./StalenessPill";

type Mode = "list" | "event" | "closure" | "cutoff" | "redate";

interface DayDialogProps {
  iso: string;
  entries: CalendarEntry[];
  /** School-holiday window covering this day (amber), if any — enables the "Adapter" entry point. */
  holiday?: SchoolHoliday;
  /** Public holiday (jour férié) on this day, if any — shown as read-only info. */
  publicHoliday?: PublicHoliday;
  onClose: () => void;
}

/** Lightweight day dialog (annotation = modal, spec §5bis): lists the day's entries and creates an event / venue closure. */
export function DayDialog({ iso, entries, holiday, publicHoliday, onClose }: DayDialogProps) {
  const [mode, setMode] = useState<Mode>("list");
  // D3 v1 PR-2 — le geste « Modifier les dates » ouvre un MODE du même dialogue (pas une modale
  // par-dessus) : l'entrée visée est retenue le temps du re-datage.
  const [redateEntry, setRedateEntry] = useState<CalendarEntry | null>(null);
  const startRedate = (entry: CalendarEntry) => {
    setRedateEntry(entry);
    setMode("redate");
  };

  return (
    <Modal label={`Jour ${iso}`} title={formatFrDate(iso)} onClose={onClose}>
      <div className="mt-4">
        {mode === "list" ? <DayList entries={entries} holiday={holiday} publicHoliday={publicHoliday} onCreate={setMode} onRedate={startRedate} onClose={onClose} /> : null}
        {mode === "event" ? <EventForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "closure" ? <ClosureForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "cutoff" ? <CutoffForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "redate" && null !== redateEntry ? (
          redateEntry.redateNeedsPreview ? (
            <RedateWithPreviewForm entry={redateEntry} onBack={() => setMode("list")} onDone={onClose} />
          ) : (
            <RedateForm entry={redateEntry} onBack={() => setMode("list")} onDone={onClose} />
          )
        ) : null}
      </div>
    </Modal>
  );
}

function DayList({ entries, holiday, publicHoliday, onCreate, onRedate, onClose }: { entries: CalendarEntry[]; holiday?: SchoolHoliday; publicHoliday?: PublicHoliday; onCreate: (m: Mode) => void; onRedate: (entry: CalendarEntry) => void; onClose: () => void }) {
  const deleteEntry = useDeleteEntry();
  const schedulesQuery = useSchedules();
  const [toDelete, setToDelete] = useState<CalendarEntry | null>(null);
  // Plannings couvrant ce jour (retour fondateur 2026-07-19) : une entrée qui PORTE
  // un plan (fermeture / semaine de vacances) devient accessible en AJUSTER (plan
  // pas encore validé) / Consulter (validé) — plus seulement supprimable. Le plan
  // se dérive de allPlans par calendarEntryId ; « en cours » = pas de chosenScheduleId.
  const navigate = useNavigate();
  const startPeriodMode = useWizardStore((s) => s.startPeriodMode);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const { data: allPlans } = useSchedulePlans();
  const socleValidated = useSocleValidated();
  const lockTitle = seasonLockTitle(socleValidated);
  // Index construits UNE fois (revue B1 F5) : plan par entrée + plans portant une
  // version — plutôt qu'un .find/.some par ligne rendue. PREMIER-gagne sur un
  // calendarEntryId dupliqué (revue dette F1 : même sémantique que l'ancien .find ;
  // l'invariant ADR-0002 « 1 entrée = 1 plan » rend le cas théorique).
  const planByEntry = new Map<string, SchedulePlan>();
  for (const p of allPlans ?? []) {
    if (null !== p.calendarEntryId && !planByEntry.has(p.calendarEntryId)) {
      planByEntry.set(p.calendarEntryId, p);
    }
  }
  const plansWithVersions = new Set((schedulesQuery.data ?? []).map((s) => s.schedulePlanId));
  const adjust = (entryId: string) => {
    // Ceinture (bug fondateur 2026-08-19) : purge une sélection planning d'un autre écran
    // avant d'entrer en mode période (la vraie correction = la portée de PlanningPage).
    setSelectedScheduleId(null);
    startPeriodMode(entryId);
    onClose();
    navigate("/wizard");
  };
  // P2-36 tranche 2 — la liste du jour passe elle aussi par la maison unique
  // (useWeekAdapt) : « Adapter » une fermeture RACINE (ADR-0002 amendé 2026-07-24 : elle
  // n'a plus de plan tant que personne n'a cliqué) et « Ajuster » une fermeture qui a déjà
  // un plan ouvrent d'abord le sélecteur de semaines si la période en couvre plusieurs, au
  // lieu de filer droit au plan de bloc. Le geste direct (une seule semaine / déjà découpée)
  // crée le plan idempotent puis ouvre le wizard — même comportement qu'au radar. Le refus
  // de chevauchement (P2-38) et la découpe destructive vivent dans le hook. P2-40 : l'offre de
  // semaines (dont l'exclusion des semaines sous vacances) vient du hook — la liste ne la calcule
  // plus elle-même.
  const workingSeason = useWorkingSeason();
  const {
    requestAdapt: requestWeekAdapt,
    pickerFor,
    setPickerFor,
    pickerState,
    pickerOffer,
    blockInfo,
    blockDeleting,
    blockDeleteFailed,
    deleteBlockVersionsAndSplit,
    pickWeeks,
    adaptBlock,
    createWeekChildren,
    createPeriodPlan,
    windowConflict,
    resetWindowConflict,
  } = useWeekAdapt(adjust);
  // « Déjà découpée » = une semaine-enfant de cette entrée tombe dans le jour affiché (la seule
  // connaissance des enfants dont dispose la liste du jour) : la carte de couverture gouverne
  // alors, le geste part droit au bloc.
  const requestAdapt = (target: CalendarEntry): void => requestWeekAdapt(target, { alreadySplit: entries.some((e) => e.parentEntryId === target.id) });
  const consult = (scheduleId: string) => {
    setSelectedScheduleId(scheduleId);
    onClose();
    navigate("/planning");
  };
  // Décision fondateur (2026-07-18) : supprimer une période supprime son PLAN, donc TOUTES
  // ses versions liées — le gestionnaire doit en valider la PORTÉE. On avertit fort dès que
  // le plan porte ≥ 1 version (brouillon inclus : la cascade les emporte), pas seulement une
  // version validée. « Porte des versions » se dérive du plan de la période (schedulePlanId),
  // plus d'un pointeur sur l'entrée (lot D-b). Un plan vide (aucune version) ne perd rien → bénin.
  const planQuery = useSchedulePlanForEntry(toDelete?.id ?? null);
  const toDeletePlanId = planQuery.data?.id ?? null;
  // Restreint aux types OVERLAYABLES : seuls closure/holiday portent un plan (inv. 9) —
  // cutoff/mutualisation/custom et les événements n'en ont jamais, donc aucune cascade à
  // annoncer (les avertir serait un faux positif alarmant).
  const overlayCapable = "closure" === toDelete?.periodType || "holiday" === toDelete?.periodType;
  // Fail-closed sur l'absence de DONNÉE (pas le statut) : le dialogue s'ouvre avant que le
  // plan/les versions répondent. On n'affiche le message bénin que si on A la donnée des deux
  // requêtes — sinon un delete confirmé pendant le chargement/1er échec afficherait « rien à
  // perdre » puis emporterait le plan et ses versions. Clé sur `data`, PAS sur isSuccess :
  // TanStack bascule en error sur un refetch d'arrière-plan tout en gardant la donnée périmée
  // — s'y fier sur-avertirait un plan vide après un simple blip. (usePeriodAnchor n'expose pas
  // cet état → lecture directe des deux requêtes ici.)
  const versionsResolved = undefined !== planQuery.data && undefined !== schedulesQuery.data;
  const toDeleteHasVersions = overlayCapable && (!versionsResolved || (null !== toDeletePlanId && (schedulesQuery.data ?? []).some((s) => s.schedulePlanId === toDeletePlanId)));

  const confirmDelete = () => {
    if (!toDelete) return;
    deleteEntry.mutate(toDelete.id, { onSuccess: () => toast.success("Entrée supprimée") });
    setToDelete(null);
  };

  // La mère vacances est un ancrage invisible (la vacance scolaire EST l'événement,
  // portée par HolidayBlock) : jamais listée comme entrée supprimable. Ses
  // SEMAINES non plus quand l'encart vacances est là — leurs actions (ajuster,
  // voir, supprimer) vivent DANS l'encart (fondateur 2026-07-24 : « le plan est
  // attaché aux vacances, plus simple en UX »). Un jour où la semaine déborde la
  // vacance (pas d'encart), la ligne reste — sinon elle serait inaccessible.
  const deletable = entries.filter((e) => !isHolidayAnchor(e) && !(undefined !== holiday && isHolidayWeekChild(e)));

  return (
    <div className="space-y-4">
      {publicHoliday ? (
        <p className="flex items-center gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm">
          <CalendarOff className="size-4 shrink-0 text-destructive" />
          <span>
            <span className="font-medium">Jour férié</span> — {publicHoliday.label}
          </span>
        </p>
      ) : null}

      {holiday ? <HolidayBlock holiday={holiday} entries={entries} onClose={onClose} /> : null}

      {null !== windowConflict && null === pickerFor ? <WindowAlreadyPlannedNotice message={windowConflict.message} onOpen={() => adjust(windowConflict.entryId)} /> : null}

      {deletable.length > 0 ? (
        <ul className="space-y-2">
          {deletable.map((entry) => {
            // Entrée porteuse d'un plan (fermeture / semaine de vacances) → AJUSTER
            // (pas de version validée) ou Consulter (validé). Une closure/holiday
            // RACINE sans plan (jamais adaptée — ADR-0002 amendé) → ADAPTER : le
            // geste crée le plan puis ouvre le wizard. Événement/coupure : rien.
            const plan = planByEntry.get(entry.id) ?? null;
            const chosen = plan?.chosenScheduleId ?? null;
            const planHasVersions = null !== plan && plansWithVersions.has(plan.id);
            const adaptable = null === plan && null === entry.parentEntryId && ("closure" === entry.periodType || "holiday" === entry.periodType);
            // Gating (#5/F3) : AJUSTER une fermeture PAS ENCORE commencée (0 version)
            // reste bloqué tant que la saison n'est pas validée ; reprendre un travail
            // déjà commencé (versions) ne l'est pas.
            const adjustLocked = !socleValidated && !planHasVersions;
            return (
              <li key={entry.id} className="flex items-center justify-between gap-2 rounded-md border border-border px-3 py-2 text-sm">
                <span className="flex min-w-0 flex-wrap items-center gap-2">
                  {/* Same emoji marker as the month calendar (decorative → aria-hidden;
                      the title/fallback text carries the meaning). */}
                  <span aria-hidden className="text-base leading-none">{entryIcon(entry)}</span>
                  <span className="truncate">{entry.title || entryLabel(entry)}</span>
                  {/* P4-173 — « à régénérer » à côté du titre (frère du nœud tronqué, jamais dedans). */}
                  <StalenessPill staleness={plan?.staleness ?? null} />
                </span>
                <span className="flex shrink-0 items-center gap-1">
                  {null !== plan ? (
                    null !== chosen ? (
                      <Button variant="ghost" size="sm" onClick={() => consult(chosen)}>
                        Consulter
                      </Button>
                    ) : (
                      <Button variant="outline" size="sm" disabled={adjustLocked || createPeriodPlan.isPending} title={adjustLocked ? lockTitle : undefined} onClick={() => requestAdapt(entry)}>
                        Ajuster
                      </Button>
                    )
                  ) : adaptable ? (
                    <Button variant="outline" size="sm" disabled={!socleValidated || createPeriodPlan.isPending} title={!socleValidated ? lockTitle : undefined} onClick={() => requestAdapt(entry)}>
                      Adapter
                    </Button>
                  ) : null}
                  {/* « Modifier les dates » : rendu SEULEMENT si le serveur dit la période
                      re-datable — « d'un bloc » (`redatable`, D3 v1) OU découpée (`redateNeedsPreview`,
                      D3 v2 : le mode affichera alors l'aperçu des effets avant confirmation). Absent
                      sinon (jamais désactivé — aucun levier n'existe, Supprimer est à côté). */}
                  {entry.redatable || entry.redateNeedsPreview ? (
                    <button
                      type="button"
                      aria-label={`Modifier les dates de ${entry.title}`}
                      className="rounded p-1 text-muted-foreground hover:text-foreground"
                      onClick={() => onRedate(entry)}
                    >
                      <CalendarRange className="size-4" />
                    </button>
                  ) : null}
                  <button
                    type="button"
                    aria-label={`Supprimer ${entry.title}`}
                    className="rounded p-1 text-muted-foreground hover:text-destructive"
                    disabled={deleteEntry.isPending}
                    onClick={() => setToDelete(entry)}
                  >
                    <Trash2 className="size-4" />
                  </button>
                </span>
              </li>
            );
          })}
        </ul>
      ) : null}

      {/* « Rien ce jour-là » NE s'affiche PAS sous un jour de vacances : la mère
          holiday est masquée de la liste supprimable (ancrage invisible), donc
          `deletable` peut être vide alors que le bloc vacances ci-dessus tient la
          journée — dire « la semaine type tourne normalement » se contredirait. */}
      {deletable.length === 0 && !holiday ? (
        <EmptyHint>Rien ce jour-là — la semaine type tourne normalement.</EmptyHint>
      ) : null}

      <ConfirmDialog
        open={toDelete !== null}
        title={`Supprimer « ${toDelete?.title ?? ""} » ?`}
        description={
          toDeleteHasVersions
            ? "Supprimer cette période supprime aussi son plan et toutes ses versions générées. À refaire si besoin."
            : "Cette entrée sera retirée du calendrier."
        }
        confirmLabel="Supprimer"
        destructive={toDeleteHasVersions}
        onConfirm={confirmDelete}
        onCancel={() => setToDelete(null)}
      />

      <div className="grid grid-cols-1 gap-2">
        <Button variant="outline" onClick={() => onCreate("event")}>
          Événement
        </Button>
        <Button variant="outline" onClick={() => onCreate("closure")}>
          Signaler une indisponibilité
        </Button>
        <Button variant="outline" onClick={() => onCreate("cutoff")}>
          Coupure (pas d'entraînement)
        </Button>
        <Button variant="ghost" disabled title="Créer une période libre : à venir. En attendant, utilisez « Signaler une indisponibilité » ou le radar vacances.">
          Créer une période…
        </Button>
      </div>

      <div className="flex justify-end">
        <Button variant="ghost" onClick={onClose}>
          Fermer
        </Button>
      </div>

      {/* P2-36 tranche 2 — le sélecteur de semaines de la liste du jour : mêmes états nommés
          (weeks / loading / block) et même bouton qu'au radar. L'entrée existe déjà ici (pas de
          chemin « pending »). */}
      {null !== pickerFor && null !== workingSeason ? (
        <WeekPickerDialog
          title={pickerFor.title}
          startDate={pickerFor.startDate}
          endDate={pickerFor.endDate}
          periodType={pickerFor.periodType}
          weeks={pickerOffer.offered}
          season={workingSeason}
          excludedRanges={pickerOffer.excludedRanges}
          plannedRanges={pickerOffer.plannedRanges}
          busy={createWeekChildren.isPending}
          state={pickerState}
          block={{ ...blockInfo, deleting: blockDeleting, deleteFailed: blockDeleteFailed, onDeleteVersions: deleteBlockVersionsAndSplit }}
          onPickSegments={(segments) => pickWeeks(pickerFor, segments)}
          onAdaptWhole={() => {
            setPickerFor(null);
            void adaptBlock(pickerFor.id);
          }}
          onClose={() => { resetWindowConflict(); setPickerFor(null); }}
          conflict={windowConflict}
          onOpenConflict={adjust}
        />
      ) : null}
    </div>
  );
}

/**
 * School-holiday info + "Adapter" entry point (same action as the radar, but from
 * the day the manager clicked). The holiday is materialised as a period entry
 * (matched by schoolHolidayId): none yet → create it then open the wizard in
 * period mode; already there → "Adapter" (wizard) or "Voir le planning" if its
 * overlay is generated.
 */
function HolidayBlock({ holiday, entries, onClose }: { holiday: SchoolHoliday; entries: CalendarEntry[]; onClose: () => void }) {
  // P3-13 : les semaines OFFERTES ici suivent la même règle qu'au radar — une semaine
  // révolue ne s'offre plus (revue #344 round 2 : le picker la cochait, et la semaine
  // créée devenait un artefact que le radar filtrait ensuite partout).
  const today = todayISO();
  const navigate = useNavigate();
  const startPeriodMode = useWizardStore((s) => s.startPeriodMode);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  // Gating (#5) : tant que le plan de la SAISON n'est pas validé (chosenScheduleId),
  // on ne peut pas créer de planning secondaire — les ajustements sont désactivés.
  const socleValidated = useSocleValidated();
  const lockTitle = seasonLockTitle(socleValidated);
  // Clamp saison (même règle que le radar) : une période vit dans sa saison ;
  // les vacances à cheval (été) ne créent que leur part en-saison. null = vacance
  // entièrement hors saison, ou saison pas encore chargée → pas de création.
  const workingSeason = useWorkingSeason();
  const clamped = null === workingSeason ? null : clampRangeToSeason(holiday.startDate, holiday.endDate, workingSeason);

  // L'entrée qui ANCRE cette vacance (`isHolidayAnchor` — racine + schoolHolidayId, maison unique
  // du prédicat dans lib/markers). La garde d'ancrage empêche d'apparier une entrée-enfant qui
  // porterait (hypothétiquement) un schoolHolidayId ; le rendu courant est inchangé (les enfants
  // n'en portent pas).
  const entry = entries.find((e) => isHolidayAnchor(e) && e.schoolHolidayId === holiday.id) ?? null;
  // ADR-0002 lot D-b : « overlay généré » = plan validé (chosenScheduleId), dérivé du plan.
  const plan = useSchedulePlanForEntry(entry?.id ?? null);
  const activeId = plan.data?.chosenScheduleId ?? null;

  // P2-5 E1 : les SEMAINES enfants de cette période (fenêtrées sur la mère —
  // elles y vivent par construction). Le DayDialog ne reçoit que les entrées du
  // JOUR : requête ciblée, cache partagé avec le cockpit.
  const childrenQuery = useCalendarEntries(entry?.startDate ?? "", entry?.endDate ?? "", null !== entry);
  // Résolu ? — gate du picker : « 0 enfant » pendant le chargement n'est PAS
  // « pas découpée » (fail-open → 422 en série, revue #262 round 2).
  const childrenResolved = null === entry || undefined !== childrenQuery.data;
  const weekChildren = (childrenQuery.data ?? []).filter((e) => e.parentEntryId === (entry?.id ?? "")).sort((a, b) => a.startDate.localeCompare(b.startDate));
  const { data: allPlans } = useSchedulePlans();
  // Index une fois (revue dette F2, même règle que DayList) : plan (id + version
  // validée) par entrée-enfant — plutôt qu'un .find par chip rendu. Premier-gagne.
  const planByChildEntry = new Map<string, { planId: string; chosen: string | null }>();
  for (const p of allPlans ?? []) {
    if (null !== p.calendarEntryId && !planByChildEntry.has(p.calendarEntryId)) {
      planByChildEntry.set(p.calendarEntryId, { planId: p.id, chosen: p.chosenScheduleId });
    }
  }
  const chosenOfChild = (childId: string): string | null => planByChildEntry.get(childId)?.chosen ?? null;
  // Générée « d'un bloc » ? (versions sur le plan de la mère → pas de découpage.)
  const schedulesQuery = useSchedules();
  const schedulesResolved = undefined !== schedulesQuery.data;
  // « en cours » par semaine (parité radar startedEntryIds) : plan avec ≥1 version,
  // non validé. Sert au libellé 3 états ET au gating (reprendre un travail commencé
  // n'est jamais bloqué par le socle — même règle que la carte radar).
  const plansWithVersionsSet = new Set((schedulesQuery.data ?? []).map((s) => s.schedulePlanId));
  const wipOfChild = (childId: string): boolean => {
    const p = planByChildEntry.get(childId);
    return undefined !== p && null === p.chosen && plansWithVersionsSet.has(p.planId);
  };
  // Suppression d'UNE semaine — intégrée à l'encart (fondateur 2026-07-24). Même
  // avertissement fort que DayList : fail-closed sur l'ABSENCE de donnée (plans ou
  // schedules pas résolus → on annonce la cascade, jamais « rien à perdre »).
  const deleteEntry = useDeleteEntry();
  const [weekToDelete, setWeekToDelete] = useState<CalendarEntry | null>(null);
  const weekToDeleteHasVersions =
    null !== weekToDelete
    && (undefined === allPlans || !schedulesResolved
      || (() => { const p = planByChildEntry.get(weekToDelete.id); return undefined !== p && plansWithVersionsSet.has(p.planId); })());
  const confirmWeekDelete = () => {
    if (null === weekToDelete) return;
    deleteEntry.mutate(weekToDelete.id, { onSuccess: () => toast.success("Semaine retirée du calendrier") });
    setWeekToDelete(null);
  };
  const adapt = (entryId: string) => {
    // Ceinture (bug fondateur 2026-08-19) : purge une sélection planning d'un autre écran
    // avant d'entrer en mode période (la vraie correction = la portée de PlanningPage).
    setSelectedScheduleId(null);
    startPeriodMode(entryId);
    onClose();
    navigate("/wizard");
  };
  const viewOverlay = (overlayScheduleId: string) => {
    setSelectedScheduleId(overlayScheduleId);
    onClose();
    navigate("/planning");
  };
  // Flux de découpage partagé avec le radar ; ici, plusieurs semaines créées →
  // referme le DayDialog (le radar reprend le relais via ses cartes). Le chemin
  // `pending` matérialise la mère vacances SEULEMENT à la confirmation du picker.
  const { pickerFor, setPickerFor, pendingMother, setPendingMother, openPendingPicker, createWeekChildren, createHoliday, adaptBlock, pickWeeks, pickWeeksPending, adaptWholePending, recordPendingOnly, createOneWeek, windowConflict, resetWindowConflict, requestAdapt: requestWeekAdapt, pickerState, pickerOffer, pendingOffer, pendingPickerState, blockInfo, blockDeleting, blockDeleteFailed, deleteBlockVersionsAndSplit } = useWeekAdapt(adapt, childrenResolved);
  // P2-36 — la décision « semaines / bloc / chargement » vit dans useWeekAdapt (maison unique,
  // partagée avec le radar) : on ne passe plus que l'entrée + le savoir « déjà découpée »
  // (weekChildren). Données pas résolues → le picker s'ouvre en « chargement » et le DIT, au
  // lieu de partir en bloc en silence (revue #262 gardait la raison — ne jamais cocher sans
  // savoir ; P2-36 en retire le seul silence).
  const requestAdapt = (target: CalendarEntry) => requestWeekAdapt(target, { alreadySplit: weekChildren.length > 0 });

  return (
    <WarningPanel
      /* Same season emoji as the calendar (🎄/🎃/…) — decorative, the text names it. */
      icon={<span className="text-base">{holidayIcon(holiday)}</span>}
      message={
        <>
          <span className="font-medium">Vacances</span> — {holiday.label}
        </>
      }
    >
      {/* Toutes les vacances sont adaptables, été inclus (planning de reprise —
          retour fondateur 2026-07-18, P2-5 E2 : l'exclusion `ete` est levée). */}
      {null !== entry && weekChildren.length > 0 ? (
        // P2-5 E1 — période DÉCOUPÉE : la couverture par semaine, même lecture
        // que la carte radar (validée → Voir, sinon → Reprendre, MANQUANTE → +
        // créer — une semaine décochée reste planifiable, revue #262).
        <div className="flex flex-col items-end gap-1">
          {/* P2-41 — groupé PAR ENFANT (parité radar) : un enfant-segment sur N semaines = UNE
              puce (« du X au Y »). Une semaine MANQUANTE (child null) reste individuelle → « + créer »
              ponctuel, à la semaine. */}
          {groupCoverageSlots(
            null === workingSeason
              ? weekChildren.map((c) => ({ week: { startDate: c.startDate, endDate: c.endDate, monday: c.startDate }, child: c as CalendarEntry | null }))
              : (() => {
                // Revue C F1 : toutes les semaines calendaires ; on garde celle qui porte
                // un enfant EXISTANT (toujours visible) OU qui est OFFERTE à la création
                // (periodAdjustWeeks écarte la semaine partielle d'une vacance Ven/Sam/Dim).
                const offeredMondays = new Set(periodWeeksToAdjust(entry.startDate, entry.endDate, workingSeason, "holiday", today).map((w) => w.monday));
                return weeksCovering(entry.startDate, entry.endDate, workingSeason)
                  .map((week) => ({ week, child: (weekChildren.find((c) => c.startDate <= week.endDate && c.endDate >= week.startDate) ?? null) as CalendarEntry | null }))
                  .filter(({ week, child }) => null !== child || offeredMondays.has(week.monday));
              })(),
          ).map((group) => {
            const child = group.child;
            if (null === child) {
              return (
                <Button
                  key={group.key}
                  variant="outline"
                  size="sm"
                  disabled={createWeekChildren.isPending || !socleValidated}
                  title={lockTitle}
                  onClick={() => createOneWeek(entry, group.weeks[0])}
                >
                  {`+ sem. du ${frDateShort(group.startDate)}`}
                </Button>
              );
            }
            const chosen = chosenOfChild(child.id);
            const wip = wipOfChild(child.id);
            // 3 états, mêmes libellés que la carte radar (fondateur 2026-07-24) :
            // ✅ validée · « en cours » (versions, non validée) · « à faire » (0 version).
            // Gating : seule une semaine À DÉMARRER est bloquée socle non validé —
            // « Voir » et « en cours » (reprise) restent actifs (parité radar).
            const chipLocked = null === chosen && !wip && !socleValidated;
            const span = group.weeks.length > 1 ? ` au ${frDateShort(child.endDate)}` : "";
            return (
              <span key={child.id} className="flex items-center gap-1">
                <Button
                  variant={null !== chosen ? "ghost" : "outline"}
                  size="sm"
                  disabled={chipLocked}
                  title={chipLocked ? lockTitle : undefined}
                  onClick={() => (null !== chosen ? viewOverlay(chosen) : adapt(child.id))}
                >
                  {`sem. du ${frDateShort(child.startDate)}${span} ${null !== chosen ? "✅ validée" : wip ? "· en cours" : "· à faire"}`}
                </Button>
                {/* Suppression de L'enfant (segment) — dans l'encart, le plan est attaché aux
                    vacances (fondateur 2026-07-24 ; la ligne séparée a disparu). */}
                <button
                  type="button"
                  aria-label={`Supprimer la semaine du ${frDateShort(child.startDate)}`}
                  className="rounded p-1 text-muted-foreground hover:text-destructive"
                  disabled={deleteEntry.isPending}
                  onClick={() => setWeekToDelete(child)}
                >
                  <Trash2 className="size-4" />
                </button>
              </span>
            );
          })}
        </div>
      ) : null !== activeId ? (
        <div className="flex justify-end">
          <Button variant="outline" size="sm" onClick={() => viewOverlay(activeId)}>
            Voir le planning
          </Button>
        </div>
      ) : entry ? (
        <div className="flex justify-end">
          <Button variant="outline" size="sm" disabled={!socleValidated} title={lockTitle} onClick={() => requestAdapt(entry)}>
            Adapter
          </Button>
        </div>
      ) : null !== workingSeason && null === clamped ? (
        // Fenêtre entièrement disjointe de la saison (fait VÉRIFIÉ — la saison est
        // chargée) : un bouton mort sans explication serait pire que l'ancien
        // message (revue #260 round 2). Saison encore en vol → bouton désactivé
        // bref, ci-dessous.
        <EmptyHint className="text-xs">Hors de la saison en cours — rien à adapter.</EmptyHint>
      ) : (
        <div className="flex justify-end">
          <Button
            variant="outline"
            size="sm"
            disabled={createHoliday.isPending || null === clamped || !socleValidated}
            title={lockTitle}
            onClick={async () => {
              if (null === clamped) {
                return;
              }
              const payload = { schoolHolidayId: holiday.id, label: holiday.label, startDate: clamped.startDate, endDate: clamped.endDate };
              // Vacances couvrant PLUSIEURS semaines → choix des semaines SANS rien
              // créer (la mère naît à la confirmation — retour fondateur : annuler
              // ne doit laisser aucun événement fantôme). Le créateur (createHoliday)
              // est confié au chemin pending unique (P2-36 tranche 2).
              const multiWeek = null !== workingSeason && periodWeeksToAdjust(clamped.startDate, clamped.endDate, workingSeason, "holiday", today).length > 1;
              if (multiWeek) {
                openPendingPicker({ label: holiday.label, startDate: clamped.startDate, endDate: clamped.endDate, periodType: "holiday", create: () => createHoliday.mutateAsync(payload) });
                return;
              }
              // 1 seule semaine (pas de picker, donc pas de fantôme possible) :
              // création + wizard direct. mutateAsync : la navigation part même si
              // la modale se referme pendant le POST. Erreur → filet global.
              try {
                const created = await createHoliday.mutateAsync(payload);
                await adaptBlock(created.id);
              } catch {
                /* surfaced by the global mutation-cache net */
              }
            }}
          >
            Adapter
          </Button>
        </div>
      )}

      {/* Refus de chevauchement (P2-38) hors picker (adapter un bloc / créer une semaine) : la
          proposition vit dans l'encart. Le picker ouvert, elle vit DANS le picker (ci-dessous). */}
      {null !== windowConflict && null === pickerFor && null === pendingMother ? (
        <WindowAlreadyPlannedNotice message={windowConflict.message} onOpen={() => adapt(windowConflict.entryId)} />
      ) : null}

      <ConfirmDialog
        open={null !== weekToDelete}
        title={`Supprimer « ${weekToDelete?.title ?? ""} » ?`}
        description={
          weekToDeleteHasVersions
            ? "Supprimer cette semaine supprime aussi son plan et toutes ses versions générées. À refaire si besoin."
            : "Cette semaine sera retirée du calendrier."
        }
        confirmLabel="Supprimer"
        destructive={weekToDeleteHasVersions}
        onConfirm={confirmWeekDelete}
        onCancel={() => setWeekToDelete(null)}
      />

      {/* Vacance PAS encore matérialisée : picker sur une mère synthétique (aucune
          création tant que non confirmé). */}
      {null !== pendingMother && null !== workingSeason ? (
        <WeekPickerDialog
          title={pendingMother.label}
          startDate={pendingMother.startDate}
          endDate={pendingMother.endDate}
          periodType={pendingMother.periodType}
          weeks={pendingOffer.offered}
          season={workingSeason}
          plannedRanges={pendingOffer.plannedRanges}
          state={pendingPickerState}
          busy={createHoliday.isPending || createWeekChildren.isPending}
          onPickSegments={(segments) => pickWeeksPending(pendingMother, segments)}
          onAdaptWhole={() => adaptWholePending(pendingMother)}
          onRecordOnly={() => recordPendingOnly(pendingMother)}
          onClose={() => { resetWindowConflict(); setPendingMother(null); }}
          conflict={windowConflict}
          onOpenConflict={adapt}
        />
      ) : null}

      {null !== pickerFor && null !== workingSeason ? (
        <WeekPickerDialog
          title={pickerFor.title}
          startDate={pickerFor.startDate}
          endDate={pickerFor.endDate}
          periodType={pickerFor.periodType}
          weeks={pickerOffer.offered}
          season={workingSeason}
          plannedRanges={pickerOffer.plannedRanges}
          busy={createWeekChildren.isPending}
          state={pickerState}
          block={{ ...blockInfo, deleting: blockDeleting, deleteFailed: blockDeleteFailed, onDeleteVersions: deleteBlockVersionsAndSplit }}
          onPickSegments={(segments) => pickWeeks(pickerFor, segments)}
          onAdaptWhole={() => {
            setPickerFor(null);
            void adaptBlock(pickerFor.id);
          }}
          onClose={() => { resetWindowConflict(); setPickerFor(null); }}
          conflict={windowConflict}
          onOpenConflict={adapt}
        />
      ) : null}
    </WarningPanel>
  );
}

function FormShell({ children, onBack }: { children: ReactNode; onBack: () => void }) {
  return (
    <div className="space-y-3">
      {children}
      <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={onBack}>
        ← Retour
      </button>
    </div>
  );
}

const fieldClass = "w-full rounded-md border border-input bg-background px-3 py-2 text-sm";

/**
 * Shared "Du … Jusqu'au …" range of the three creation forms (event / closure /
 * cutoff). The clicked day is only the DEFAULT start — both ends are editable
 * (start ≥ today, end ≥ start). Changing the start bumps a now-earlier end so the
 * range never inverts.
 */
function DateRangeFields({ startDate, endDate, onStart, onEnd, minStart, max }: { startDate: string; endDate: string; onStart: (value: string) => void; onEnd: (value: string) => void; minStart?: string; max?: string }) {
  // Plancher du début : `today` pour une CRÉATION (start ≥ aujourd'hui) ; paramétrable pour le
  // re-datage d'une fermeture DÉJÀ commencée (elle peut bouger sa fin sans bouger son début). Ces
  // bornes sont de la PRÉSENTATION — le 422 serveur reste le juge de la saison (règle d'or).
  const floor = minStart ?? todayISO();
  return (
    <div className="grid grid-cols-2 gap-2">
      <label className="block text-xs text-muted-foreground">
        Du
        <input type="date" className={`${fieldClass} mt-1`} value={startDate} min={floor} max={max} onChange={(e) => onStart(e.target.value)} />
      </label>
      <label className="block text-xs text-muted-foreground">
        Jusqu'au
        <input type="date" className={`${fieldClass} mt-1`} value={endDate} min={startDate} max={max} onChange={(e) => onEnd(e.target.value)} />
      </label>
    </div>
  );
}

/**
 * Editable [start, end] range shared by the three creation forms. `today` is
 * frozen at mount (not re-read each render → a dialog left open past midnight
 * stays submittable). Moving the start past the end bumps the end so the range
 * never inverts. `valid` = today ≤ start ≤ end.
 */
function useDateRange(initialStart: string, initialEnd: string = initialStart, floor?: string) {
  // `floor` gèle le plancher du début à la naissance (par défaut aujourd'hui, comme les trois
  // créations ; le re-datage passe min(aujourd'hui, début servi) pour une fermeture déjà commencée).
  const [minStart] = useState(() => floor ?? todayISO());
  const [startDate, setStartDate] = useState(initialStart);
  const [endDate, setEndDate] = useState(initialEnd);
  const setStart = (value: string) => {
    setStartDate(value);
    setEndDate((prev) => (prev < value ? value : prev));
  };
  return { startDate, endDate, minStart, setStart, setEnd: setEndDate, valid: startDate >= minStart && endDate >= startDate };
}

function EventForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const [title, setTitle] = useState("");
  const { startDate, endDate, setStart, setEnd, valid } = useDateRange(iso);
  const [isDisruptive, setDisruptive] = useState(false);
  const createEvent = useCreateEvent();

  const submit = () => {
    if (title.trim() === "" || !valid) return;
    createEvent.mutate(
      { title: title.trim(), startDate, endDate, isDisruptive },
      { onSuccess: () => { toast.success("Événement ajouté"); onDone(); } },
    );
  };

  return (
    <FormShell onBack={onBack}>
      {/* eslint-disable-next-line jsx-a11y/no-autofocus -- inside a Modal: focusing the first field on step change is intentional, better than the neutral panel */}
      <input className={fieldClass} aria-label="Titre de l'événement" placeholder="Titre (AG, tournoi…)" value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      <DateRangeFields startDate={startDate} endDate={endDate} onStart={setStart} onEnd={setEnd} />
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={isDisruptive} onChange={(e) => setDisruptive(e.target.checked)} />
        Perturbant (pas d'entraînement ce jour)
      </label>
      <Button className="w-full" onClick={submit} disabled={createEvent.isPending || title.trim() === "" || !valid}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

function ClosureForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const { data: venues } = useVenues();
  const [title, setTitle] = useState("");
  const { startDate, endDate, setStart, setEnd, valid } = useDateRange(iso);
  const [venueId, setVenueId] = useState("");
  const createClosure = useCreateVenueClosure();

  const submit = () => {
    if (venueId === "" || !valid) return;
    const venueName = venues?.find((v) => v.id === venueId)?.name ?? "Gymnase";
    // Structured "gymnase — raison" so the calendar tooltip names both the venue
    // and why it's closed. Don't prefix when the typed reason already mentions the
    // venue (avoids "Gymnase A — Gymnase A …"); default reason to "fermé" when
    // blank; cap to the Constraint.name column (180) so a long reason can't make
    // the paired FACILITY constraint fail to persist.
    const reason = title.trim();
    const base = reason === "" ? `${venueName} — fermé` : reason.includes(venueName) ? reason : `${venueName} — ${reason}`;
    createClosure.mutate(
      { title: base.slice(0, 180), startDate, endDate, venueId },
      // Errors are toasted by the hook itself (unmount-safe rollback message).
      { onSuccess: () => { toast.success("Indisponibilité enregistrée"); onDone(); } },
    );
  };

  return (
    <FormShell onBack={onBack}>
      {/* eslint-disable-next-line jsx-a11y/no-autofocus -- inside a Modal: focusing the first field on step change is intentional */}
      <select className={fieldClass} aria-label="Gymnase indisponible" value={venueId} onChange={(e) => setVenueId(e.target.value)} autoFocus>
        <option value="">Gymnase indisponible…</option>
        {(venues ?? []).map((v) => (
          <option key={v.id} value={v.id}>
            {v.name}
          </option>
        ))}
      </select>
      <input className={fieldClass} aria-label="Intitulé de l'indisponibilité (optionnel)" placeholder="Intitulé (optionnel)" maxLength={140} value={title} onChange={(e) => setTitle(e.target.value)} />
      <DateRangeFields startDate={startDate} endDate={endDate} onStart={setStart} onEnd={setEnd} />
      <Button className="w-full" onClick={submit} disabled={createClosure.isPending || venueId === "" || !valid}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

/** A cutoff is a bare period ("no training on the window") — no venue, no constraint, no overlay to generate. */
function CutoffForm({ iso, onBack, onDone }: { iso: string; onBack: () => void; onDone: () => void }) {
  const [title, setTitle] = useState("");
  const { startDate, endDate, setStart, setEnd, valid } = useDateRange(iso);
  const createCutoff = useCreateCutoff();

  const submit = () => {
    if (!valid) return;
    createCutoff.mutate(
      { title: title.trim() === "" ? "Coupure" : title.trim(), startDate, endDate },
      { onSuccess: () => { toast.success("Coupure enregistrée"); onDone(); } },
    );
  };

  return (
    <FormShell onBack={onBack}>
      {/* eslint-disable-next-line jsx-a11y/no-autofocus -- inside a Modal: focusing the first field on step change is intentional */}
      <input className={fieldClass} aria-label="Intitulé de la coupure (optionnel)" placeholder="Intitulé (optionnel, ex. Coupure de Noël)" value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      <DateRangeFields startDate={startDate} endDate={endDate} onStart={setStart} onEnd={setEnd} />
      <p className="text-xs text-muted-foreground">Rappel affiché au calendrier (🛑) et au radar — le planning de base reste inchangé, rien à générer.</p>
      <Button className="w-full" onClick={submit} disabled={createCutoff.isPending || !valid}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

/**
 * D3 v1 PR-2 — RE-DATER une fermeture (« Modifier les dates »). Le backend a déjà tranché que la
 * période est re-datable (`entry.redatable`) : ce formulaire ne fait que RE-SAISIR la fenêtre. Le
 * plancher du début est min(aujourd'hui, début servi) — une fermeture déjà commencée doit pouvoir
 * bouger sa fin sans bouger son début — et le plafond est la fin de saison (présentation ; le 422
 * serveur reste le juge). « Enregistrer » est désactivé, avec sa raison en title, tant qu'aucune
 * date n'a changé ou que la fin précède le début (patron `adjustLocked`). Le hook possède son
 * feedback : un 409 typé s'affiche ICI comme une proposition (`WindowAlreadyPlannedNotice`, focus
 * porté sur son action), les autres échecs partent au filet ; le refus est réinitialisé à chaque
 * tentative. Aucune règle métier recalculée (rien sur les enfants, le plan, la saison).
 */
function RedateForm({ entry, onBack, onDone }: { entry: CalendarEntry; onBack: () => void; onDone: () => void }) {
  const navigate = useNavigate();
  const startPeriodMode = useWizardStore((s) => s.startPeriodMode);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const workingSeason = useWorkingSeason();
  const redate = useRedateEntry();
  const floor = entry.startDate < todayISO() ? entry.startDate : todayISO();
  const { startDate, endDate, minStart, setStart, setEnd, valid } = useDateRange(entry.startDate, entry.endDate, floor);
  const [windowConflict, setWindowConflict] = useState<{ message: string; entryId: string } | null>(null);
  const conflictRef = useRef<HTMLDivElement>(null);
  // Refus reçu → le focus part sur l'action de la notice (« Ouvrir le planning en place »).
  useEffect(() => {
    if (null !== windowConflict) {
      conflictRef.current?.querySelector("button")?.focus();
    }
  }, [windowConflict]);

  const openConflict = (entryId: string) => {
    // Même ceinture que « Adapter » (bug fondateur 2026-08-19) : purge une sélection planning
    // d'un autre écran avant d'entrer en mode période.
    setSelectedScheduleId(null);
    startPeriodMode(entryId);
    onDone();
    navigate("/wizard");
  };

  const changed = startDate !== entry.startDate || endDate !== entry.endDate;
  const disabledReason = !changed ? "Aucune date n'a changé." : !valid ? "La fin doit suivre le début." : undefined;

  const submit = async () => {
    if (!changed || !valid) {
      return;
    }
    setWindowConflict(null); // réinitialisé à chaque tentative (jamais un refus périmé collé au geste)
    try {
      await redate.mutateAsync({ entry, startDate, endDate });
      toast.success(`Fermeture re-datée du ${frDateShort(startDate)} au ${frDateShort(endDate)} — planning à régénérer`);
      onDone();
    } catch (error) {
      if (error instanceof WindowAlreadyPlannedError) {
        setWindowConflict({ message: error.message, entryId: error.conflictingEntryId });
        return;
      }
      // Tout autre échec (422 hors saison / fin avant début, transport) → filet : le message serveur.
      toast.error(await errorMessage(error));
    }
  };

  return (
    <FormShell onBack={onBack}>
      <p className="text-xs text-muted-foreground">Déplacez la fenêtre de cette fermeture. Son planning devra être régénéré.</p>
      <DateRangeFields startDate={startDate} endDate={endDate} onStart={setStart} onEnd={setEnd} minStart={minStart} max={workingSeason?.endDate} />
      {null !== windowConflict ? (
        <div ref={conflictRef}>
          <WindowAlreadyPlannedNotice message={windowConflict.message} onOpen={() => openConflict(windowConflict.entryId)} />
        </div>
      ) : null}
      <Button className="w-full" onClick={() => void submit()} disabled={redate.isPending || !changed || !valid} title={disabledReason}>
        Enregistrer
      </Button>
    </FormShell>
  );
}

/** D3 v2 — icône DÉCORATIVE par verdict (le texte du label porte toujours le sens). Présentation
 *  pure : ce n'est pas une décision de comportement sur un enum métier (règle d'or côté front). */
function effectIcon(kind: RedateEffectKind): ReactNode {
  switch (kind) {
    case "shift":
      return <MoveRight className="size-4" />;
    case "birth":
      return <Plus className="size-4" />;
    case "holiday_takes_over":
      return <Sun className="size-4" />;
    case "absorb":
    case "vanish":
      return <Trash2 className="size-4 text-warning" />;
    case "keep":
    default:
      return <Minus className="size-4 text-muted-foreground" />;
  }
}

/**
 * La liste chronologique des effets, servie TELLE QUELLE (aucun compte ni identifiant recalculé
 * côté front). Quand un effet SUPPRIME un plan (absorb/vanish), toute la liste est encadrée par le
 * `WarningPanel` (ambre = « attention, des plans seront ajustés » — jamais la couleur destructive
 * pour des FAITS) ; sinon un cadre neutre.
 */
function RedateEffectsList({ effects }: { effects: RedateEffect[] }) {
  const hasDeletion = effects.some((e) => "absorb" === e.kind || "vanish" === e.kind);
  const list = (
    <ul className="space-y-1">
      {effects.map((effect) => (
        <li key={effect.label} className="flex items-start gap-2 text-sm text-foreground">
          <span aria-hidden className="mt-0.5 shrink-0 leading-none">
            {effectIcon(effect.kind)}
          </span>
          <span>{effect.label}</span>
        </li>
      ))}
    </ul>
  );
  return hasDeletion ? (
    <WarningPanel message={<span className="font-medium">Effets de ce re-datage</span>}>{list}</WarningPanel>
  ) : (
    <div className="rounded-md border border-border bg-muted/40 px-3 py-2">{list}</div>
  );
}

/**
 * D3 v2 (P4-174, décision fondateur 2026-09-05) — RE-DATER une indisponibilité DÉCOUPÉE
 * (`entry.redateNeedsPreview`) : on ANNONCE avant de détruire. Deux champs date, un bouton unique au
 * MÊME nœud DOM — « Voir les effets » tant qu'aucun aperçu valide n'est chargé, « Confirmer » ensuite
 * (déclenchement explicite au clic, jamais d'auto-fetch). Changer une date PÉRIME l'aperçu localement
 * (le jeton n'est jamais réutilisé après). La liste d'effets est SERVIE telle quelle (règle d'or : le
 * backend dit, le front affiche — aucun miroir de règle métier). Un 409 « jeton périmé »
 * ({@link PreviewTokenStaleError}) affiche le message serveur et redemande l'aperçu (confirmation
 * MANUELLE) ; un 409 de chevauchement s'affiche comme au v1 (`WindowAlreadyPlannedNotice`).
 */
function RedateWithPreviewForm({ entry, onBack, onDone }: { entry: CalendarEntry; onBack: () => void; onDone: () => void }) {
  const navigate = useNavigate();
  const startPeriodMode = useWizardStore((s) => s.startPeriodMode);
  const setSelectedScheduleId = usePlanningStore((s) => s.setSelectedScheduleId);
  const workingSeason = useWorkingSeason();
  const preview = useRedatePreview();
  const redate = useRedateEntry();
  const floor = entry.startDate < todayISO() ? entry.startDate : todayISO();
  const { startDate, endDate, minStart, setStart, setEnd, valid } = useDateRange(entry.startDate, entry.endDate, floor);
  // L'aperçu chargé (pour les dates courantes) ; null = pas d'aperçu valide → bouton « Voir les effets ».
  const [loaded, setLoaded] = useState<{ effects: RedateEffect[]; token: string } | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [staleWarning, setStaleWarning] = useState<string | null>(null);
  const [windowConflict, setWindowConflict] = useState<{ message: string; entryId: string } | null>(null);
  const conflictRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (null !== windowConflict) {
      conflictRef.current?.querySelector("button")?.focus();
    }
  }, [windowConflict]);

  const openConflict = (entryId: string) => {
    setSelectedScheduleId(null);
    startPeriodMode(entryId);
    onDone();
    navigate("/wizard");
  };

  // Toute retouche de date périme l'aperçu : la liste disparaît, le jeton n'est plus réutilisé.
  const invalidatePreview = () => {
    setLoaded(null);
    setPreviewError(null);
    setStaleWarning(null);
    setWindowConflict(null);
  };
  const changeStart = (v: string) => {
    setStart(v);
    invalidatePreview();
  };
  const changeEnd = (v: string) => {
    setEnd(v);
    invalidatePreview();
  };

  const changed = startDate !== entry.startDate || endDate !== entry.endDate;
  const previewValid = null !== loaded;
  const busy = preview.isPending || redate.isPending;

  const loadPreview = async () => {
    if (!changed || !valid) {
      return;
    }
    setPreviewError(null);
    setWindowConflict(null);
    try {
      const result = await preview.mutateAsync({ id: entry.id, startDate, endDate });
      setLoaded({ effects: result.effects, token: result.token });
    } catch (error) {
      setLoaded(null);
      setPreviewError(await errorMessage(error)); // 422 servi tel quel, dans le même panneau
    }
  };

  const confirm = async () => {
    if (null === loaded) {
      return;
    }
    const token = loaded.token;
    const hasDeletion = loaded.effects.some((e) => "absorb" === e.kind || "vanish" === e.kind);
    setWindowConflict(null);
    try {
      await redate.mutateAsync({ entry, startDate, endDate, previewToken: token });
      toast.success(hasDeletion ? "Dates modifiées — plans de période ajustés, planning à régénérer." : `Fermeture re-datée du ${frDateShort(startDate)} au ${frDateShort(endDate)} — planning à régénérer`);
      onDone();
    } catch (error) {
      if (error instanceof WindowAlreadyPlannedError) {
        setWindowConflict({ message: error.message, entryId: error.conflictingEntryId });
        return;
      }
      if (error instanceof PreviewTokenStaleError) {
        // La période a bougé depuis l'aperçu : on l'annonce et on REDEMANDE l'aperçu (mêmes dates) ;
        // la confirmation reste MANUELLE (le bouton redevient « Confirmer », l'utilisateur reclique).
        setStaleWarning(error.message);
        setLoaded(null);
        try {
          const fresh = await preview.mutateAsync({ id: entry.id, startDate, endDate });
          setLoaded({ effects: fresh.effects, token: fresh.token });
        } catch (refetchError) {
          setPreviewError(await errorMessage(refetchError));
        }
        return;
      }
      toast.error(await errorMessage(error));
    }
  };

  const deletionAhead = previewValid && loaded.effects.some((e) => "absorb" === e.kind || "vanish" === e.kind);
  const actionLabel = previewValid ? "Confirmer" : "Voir les effets";
  const actionDisabled = busy || (previewValid ? false : !changed || !valid);
  const actionTitle = busy ? "Chargement de l'aperçu…" : previewValid ? undefined : !changed ? "Aucune date n'a changé." : !valid ? "La fin doit suivre le début." : undefined;

  return (
    <FormShell onBack={onBack}>
      <p className="text-xs text-muted-foreground">Déplacez la fenêtre de cette indisponibilité découpée. Voyez d'abord les effets, puis confirmez.</p>
      <DateRangeFields startDate={startDate} endDate={endDate} onStart={changeStart} onEnd={changeEnd} minStart={minStart} max={workingSeason?.endDate} />
      {null !== staleWarning ? <WarningPanel message={staleWarning} /> : null}
      {/* Région live PRÉSENTE dès le montage (vide au départ), aria-busy pendant le chargement ; jamais role="alert". */}
      <div aria-live="polite" aria-busy={preview.isPending} className="space-y-2">
        {null !== previewError ? <p className="text-sm text-destructive">{previewError}</p> : null}
        {previewValid ? <RedateEffectsList effects={loaded.effects} /> : null}
      </div>
      {null !== windowConflict ? (
        <div ref={conflictRef}>
          <WindowAlreadyPlannedNotice message={windowConflict.message} onOpen={() => openConflict(windowConflict.entryId)} />
        </div>
      ) : null}
      <Button className="w-full" variant={deletionAhead ? "destructive" : undefined} onClick={() => void (previewValid ? confirm() : loadPreview())} disabled={actionDisabled} title={actionTitle}>
        {actionLabel}
      </Button>
    </FormShell>
  );
}

function formatFrDate(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  const date = new Date(y, m - 1, d);
  return date.toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
}
