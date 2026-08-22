import { CalendarOff, Trash2 } from "lucide-react";
import { type ReactNode, useState } from "react";
import { useNavigate } from "react-router";

import { useSchedules, useVenues } from "@/features/planning/queries";
import { usePlanningStore } from "@/features/planning/store";
import { useWizardStore } from "@/features/wizard/store";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Modal } from "@/shared/components/ui/modal";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry, PublicHoliday, SchedulePlan, SchoolHoliday } from "./api";
import { useWorkingSeason } from "@/shared/session/queries";

import { clampRangeToSeason, frDateShort, groupCoverageSlots, periodWeeksToAdjust, todayISO, weeksCovering } from "./lib/date";
import { seasonLockTitle, useSocleValidated } from "./lib/socle";
import { useWeekAdapt } from "./lib/useWeekAdapt";
import { WindowAlreadyPlannedNotice } from "./WindowAlreadyPlannedNotice";
import { entryIcon, entryLabel, holidayIcon, isHolidayAnchor, isHolidayWeekChild } from "./lib/markers";
import { useCalendarEntries, useCreateCutoff, useCreateEvent, useCreateVenueClosure, useDeleteEntry, useSchedulePlanForEntry, useSchedulePlans } from "./queries";
import { WeekPickerDialog } from "./WeekPickerDialog";

type Mode = "list" | "event" | "closure" | "cutoff";

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

  return (
    <Modal label={`Jour ${iso}`} title={formatFrDate(iso)} onClose={onClose}>
      <div className="mt-4">
        {mode === "list" ? <DayList entries={entries} holiday={holiday} publicHoliday={publicHoliday} onCreate={setMode} onClose={onClose} /> : null}
        {mode === "event" ? <EventForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "closure" ? <ClosureForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
        {mode === "cutoff" ? <CutoffForm iso={iso} onBack={() => setMode("list")} onDone={onClose} /> : null}
      </div>
    </Modal>
  );
}

function DayList({ entries, holiday, publicHoliday, onCreate, onClose }: { entries: CalendarEntry[]; holiday?: SchoolHoliday; publicHoliday?: PublicHoliday; onCreate: (m: Mode) => void; onClose: () => void }) {
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
                <span className="flex min-w-0 items-center gap-2">
                  {/* Same emoji marker as the month calendar (decorative → aria-hidden;
                      the title/fallback text carries the meaning). */}
                  <span aria-hidden className="text-base leading-none">{entryIcon(entry)}</span>
                  <span className="truncate">{entry.title || entryLabel(entry)}</span>
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
        <p className="text-sm text-muted-foreground">Rien ce jour-là — la semaine type tourne normalement.</p>
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

  const entry = entries.find((e) => e.schoolHolidayId === holiday.id) ?? null;
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
    <div className="space-y-2 rounded-md border border-warning/50 bg-warning/10 px-3 py-2">
      <p className="flex items-center gap-2 text-sm">
        {/* Same season emoji as the calendar (🎄/🎃/…) — decorative, the text names it. */}
        <span aria-hidden className="text-base leading-none">{holidayIcon(holiday)}</span>
        <span>
          <span className="font-medium">Vacances</span> — {holiday.label}
        </span>
      </p>
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
                  {`sem. du ${frDateShort(child.startDate)}${span} ${null !== chosen ? "✅" : wip ? "· en cours" : "· à faire"}`}
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
        <p className="text-xs text-muted-foreground">Hors de la saison en cours — rien à adapter.</p>
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
    </div>
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
function DateRangeFields({ startDate, endDate, onStart, onEnd }: { startDate: string; endDate: string; onStart: (value: string) => void; onEnd: (value: string) => void }) {
  const today = todayISO();
  return (
    <div className="grid grid-cols-2 gap-2">
      <label className="block text-xs text-muted-foreground">
        Du
        <input type="date" className={`${fieldClass} mt-1`} value={startDate} min={today} onChange={(e) => onStart(e.target.value)} />
      </label>
      <label className="block text-xs text-muted-foreground">
        Jusqu'au
        <input type="date" className={`${fieldClass} mt-1`} value={endDate} min={startDate} onChange={(e) => onEnd(e.target.value)} />
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
function useDateRange(iso: string) {
  const [today] = useState(todayISO);
  const [startDate, setStartDate] = useState(iso);
  const [endDate, setEndDate] = useState(iso);
  const setStart = (value: string) => {
    setStartDate(value);
    setEndDate((prev) => (prev < value ? value : prev));
  };
  return { startDate, endDate, setStart, setEnd: setEndDate, valid: startDate >= today && endDate >= startDate };
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

function formatFrDate(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  const date = new Date(y, m - 1, d);
  return date.toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
}
