import { ArrowRight, CalendarX2, Info, Plus, Upload, Wand2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router";

import { useCoachPlayers, useTeamCoaches } from "@/features/planning/queries";
import { FeedbackButton } from "@/features/feedback/FeedbackButton";
import { Button } from "@/shared/components/ui/button";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Modal } from "@/shared/components/ui/modal";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { todayISO } from "@/shared/lib/clock";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { useCredits } from "@/shared/credits/useCredits";
import { toast } from "@/shared/stores/toastStore";

import type { Category, Coach, Competition, Conflict, Fixture, Team, Venue } from "./api";
import { CalendarControls } from "./CalendarControls";
import { FbiEntryList } from "./FbiEntryList";
import { FfbbEngagementsDialog } from "./FfbbEngagementsDialog";
import { FixtureFormDialog } from "./FixtureFormDialog";
import { CONFLICT_FAMILIES } from "./lib/conflictLabels";
import { applyFamilyFilter, applyKindFilter, countByFamily, familiesPresent, KINDS, scopeConflictsToWeek } from "./lib/consultFilter";
import { isInEnvelope, resolveEnvelope } from "./lib/envelope";
import { depositDaysAgo, relativeDepositLabel } from "./lib/fbiFreshness";
import { datelessConflicts, deriveWeekCounters } from "./lib/loopSteps";
import { applyMatchFilter } from "./lib/matchFilter";
import { conflictsByFixture, groupByDay, listMonths, monthLabel, resolveActiveMonth, scopeConflictsToMonth } from "./lib/monthView";
import { listPhases, phaseCompleteness, phaseFixtures, scopeConflictsToPhase } from "./lib/phaseView";
import { placementToastMessage } from "./lib/placementToast";
import {
  applyConsultToParams,
  applyFilterToParams,
  applyWeekendToParams,
  decodeConsultParams,
  decodeFilterParams,
  decodeWeekendParam,
} from "./lib/urlState";
import { isPlacedOnGrid, listWeekends, matchMinutesByCategory, resolveActiveWeekend, weekendKeyOf, weekLabel } from "./lib/weekendGrid";
import { MatchesFilterBar } from "./MatchesFilterBar";
import { ModuleVisitBanner } from "./ModuleVisitBanner";
import { MonthTable } from "./MonthTable";
import { PhaseTable } from "./PhaseTable";
import {
  useCategories,
  useCoaches,
  useCompetitions,
  useConflicts,
  useFixtures,
  useLatestFbiIngestion,
  useLeagueWindows,
  useMatchSlotRotations,
  useModuleVisit,
  useOpponentTravel,
  usePlaceMatches,
  usePriorityTiers,
  useReopenFixture,
  useSportCategoryDurations,
  useSubmitFixture,
  useTeamMatchHabits,
  useTeams,
  useVenueMatchWindows,
  useVenues,
  useVenueUnavailabilities,
} from "./queries";
import { useMatchesStore } from "./store";
import { WeekCounters } from "./WeekCounters";
import { PLACE_HEADING_ID, WeekWorkbench } from "./WeekWorkbench";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

/** En-tête de groupe d'un JOUR (Mois), ex. « sam. 3 oct. » avec l'initiale capitalisée. */
function dayHeaderLabel(dateIso: string): string {
  const label = new Date(`${dateIso}T12:00:00Z`).toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long" });
  return label.charAt(0).toUpperCase() + label.slice(1);
}

/**
 * PR 3b — le **Calendrier**, l'écran unique du module matchs : il FUSIONNE l'ancienne
 * boucle « Semaine » (placer, échanger, saisir dans FBI) et l'ancien « Consulter »
 * (Semaine · Mois · Phase, lecture des placés/conflits). Le rail a disparu ; la
 * semaine porte une barre de trois compteurs (`WeekCounters`). Chaîne PURE reprise de
 * Consulter : `applyMatchFilter` (PR-1) → `applyKindFilter` → scope temporel →
 * `countByFamily` → `applyFamilyFilter`. Zéro règle métier nouvelle.
 */
export function CalendarPage() {
  const credits = useCredits();
  const placeCreditSuffix = null !== credits ? ` (${credits.remaining})` : "";
  const placeCreditsBlocked = null !== credits && !credits.canPlaceMatches;

  const fixtures = useFixtures();
  const competitions = useCompetitions();
  const leagueWindows = useLeagueWindows();
  const conflicts = useConflicts();
  const teams = useTeams();
  const priorityTiers = usePriorityTiers();
  const venues = useVenues();
  const categories = useCategories();
  const categoryDurations = useSportCategoryDurations();
  const coaches = useCoaches();
  const matchWindows = useVenueMatchWindows();
  const unavailabilities = useVenueUnavailabilities();
  const habitsQuery = useTeamMatchHabits();
  const rotationsQuery = useMatchSlotRotations();
  const opponentTravel = useOpponentTravel();
  const teamCoaches = useTeamCoaches();
  const coachPlayers = useCoachPlayers();
  const placeMatches = usePlaceMatches();
  const submitFixture = useSubmitFixture();
  const reopenFixture = useReopenFixture();
  const moduleVisit = useModuleVisit();
  const freshness = useLatestFbiIngestion();

  const [editFixture, setEditFixture] = useState<Fixture | null>(null);
  const [fbiModalOpen, setFbiModalOpen] = useState(false);
  const [ffbbDialogOpen, setFfbbDialogOpen] = useState(false);

  const {
    selectedWeekend,
    setSelectedWeekend,
    setSelectedFixtureId,
    setUnplacedReasons,
    fixtureFormOpen,
    setFixtureFormOpen,
    filterMode,
    filterIds,
    setFilterMode,
    toggleFilterId,
    clearFilter,
    consultKinds,
    consultFamilies,
    consultTypicalWeek,
    consultTemporality,
    consultMonth,
    consultPhaseId,
    setConsultKinds,
    setConsultFamilies,
    setConsultTypicalWeek,
    setConsultTemporality,
    setConsultMonth,
    setConsultPhaseId,
  } = useMatchesStore();

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);
  const categoriesMap = useMemo<Map<string, Category>>(() => byId(categories.data), [categories.data]);
  const competitionsMap = useMemo<Map<string, Competition>>(() => byId(competitions.data), [competitions.data]);
  const coachesMap = useMemo<Map<string, Coach>>(() => byId(coaches.data), [coaches.data]);
  const matchDurations = useMemo(() => matchMinutesByCategory(categoryDurations.data ?? []), [categoryDurations.data]);

  const allFixtures = useMemo<Fixture[]>(() => fixtures.data ?? [], [fixtures.data]);
  const windows = useMemo(() => leagueWindows.data?.items ?? [], [leagueWindows.data]);
  const resolvedTeamWindows = useMemo(() => leagueWindows.data?.resolvedTeamWindows ?? {}, [leagueWindows.data]);
  const habits = useMemo(() => habitsQuery.data ?? [], [habitsQuery.data]);
  const rotations = useMemo(() => rotationsQuery.data ?? [], [rotationsQuery.data]);
  const allConflicts = useMemo<Conflict[]>(() => conflicts.data?.conflicts ?? [], [conflicts.data]);

  // ── Chaîne : PR-1 (équipe/coach/gymnase) → type de compétition ─────────────────
  const filtered = useMemo(
    () => applyMatchFilter({ mode: filterMode, ids: filterIds, fixtures: allFixtures, conflicts: allConflicts, teamCoaches: teamCoaches.data ?? [], coachPlayers: coachPlayers.data ?? [] }),
    [filterMode, filterIds, allFixtures, allConflicts, teamCoaches.data, coachPlayers.data],
  );
  const coachTeamRoles = filtered.coachTeamRoles ?? undefined;
  const filterActive = filterIds.length > 0;
  const filterLabel = useMemo(() => {
    if (!filterActive) {
      return "";
    }
    return filterIds
      .map((id) => {
        if ("coach" === filterMode) {
          const coach = coachesMap.get(id);
          return undefined === coach ? null : `${coach.firstName} ${coach.lastName}`.trim();
        }
        return ("gymnase" === filterMode ? venuesMap.get(id)?.name : teamsMap.get(id)?.name) ?? null;
      })
      .filter((name): name is string => null !== name)
      .join(", ");
  }, [filterActive, filterIds, filterMode, coachesMap, venuesMap, teamsMap]);

  const effectiveKinds = consultKinds ?? KINDS;
  const effectiveFamilies = consultFamilies ?? CONFLICT_FAMILIES;
  const kindResult = useMemo(
    () => applyKindFilter(filtered.fixtures, filtered.conflicts, effectiveKinds, competitionsMap),
    [filtered.fixtures, filtered.conflicts, effectiveKinds, competitionsMap],
  );
  const kindFixtures = kindResult.fixtures;
  const kindConflicts = kindResult.conflicts;

  const isWeek = "semaine" === consultTemporality;
  const isMonth = "mois" === consultTemporality;
  const isPhase = "phase" === consultTemporality;

  // Domiciles placés hors enveloppe ligue (surface de travail de la grille).
  const outOfEnvelope = useMemo<Set<string>>(() => {
    const set = new Set<string>();
    for (const fixture of allFixtures) {
      if (!isPlacedOnGrid(fixture) || null === fixture.kickoffTime || null === fixture.competitionId) {
        continue;
      }
      const envelope = resolveEnvelope(fixture, resolvedTeamWindows, windows);
      if (envelope.mapped && !isInEnvelope(envelope, fixture.kickoffTime)) {
        set.add(fixture.id);
      }
    }
    return set;
  }, [allFixtures, resolvedTeamWindows, windows]);

  // ── Temporalité SEMAINE ────────────────────────────────────────────────────────
  const weekends = useMemo(() => listWeekends(kindFixtures), [kindFixtures]);
  const activeWeekend = resolveActiveWeekend(weekends, selectedWeekend, weekendKeyOf(todayISO()));
  const weekendIndex = null === activeWeekend ? -1 : weekends.indexOf(activeWeekend);
  const weekendFixtures = useMemo(
    () => (null === activeWeekend ? [] : kindFixtures.filter((f) => weekendKeyOf(f.matchDate) === activeWeekend)),
    [kindFixtures, activeWeekend],
  );
  const weekConflicts = useMemo(() => scopeConflictsToWeek(kindConflicts, activeWeekend), [kindConflicts, activeWeekend]);
  const weekFamilyCounts = useMemo(() => countByFamily(weekConflicts), [weekConflicts]);
  const radarConflicts = useMemo(() => applyFamilyFilter(weekConflicts, effectiveFamilies), [weekConflicts, effectiveFamilies]);
  const weekCounts = useMemo(() => deriveWeekCounters(weekendFixtures, kindConflicts), [weekendFixtures, kindConflicts]);

  // ── Temporalité MOIS ───────────────────────────────────────────────────────────
  const months = useMemo(() => listMonths(kindFixtures), [kindFixtures]);
  const activeMonth = resolveActiveMonth(months, consultMonth, todayISO().slice(0, 7));
  const monthConflicts = useMemo(() => scopeConflictsToMonth(kindConflicts, activeMonth), [kindConflicts, activeMonth]);
  const monthFamilyCounts = useMemo(() => countByFamily(monthConflicts), [monthConflicts]);
  const monthCbf = useMemo(() => conflictsByFixture(applyFamilyFilter(monthConflicts, effectiveFamilies)), [monthConflicts, effectiveFamilies]);
  const monthGroups = useMemo(
    () => (null === activeMonth ? [] : groupByDay(kindFixtures, activeMonth).map((g) => ({ key: g.date, label: dayHeaderLabel(g.date), fixtures: g.fixtures }))),
    [kindFixtures, activeMonth],
  );
  const monthIndex = null === activeMonth ? -1 : months.indexOf(activeMonth);

  // ── Temporalité PHASE ──────────────────────────────────────────────────────────
  const phases = useMemo(() => listPhases(competitions.data ?? [], teams.data ?? []), [competitions.data, teams.data]);
  const activePhaseId = useMemo(() => {
    if (0 === phases.length) {
      return null;
    }
    return phases.some((p) => p.competitionId === consultPhaseId) ? consultPhaseId : phases[0].competitionId;
  }, [phases, consultPhaseId]);
  const phaseGroupsRaw = useMemo(() => (null === activePhaseId ? [] : phaseFixtures(kindFixtures, activePhaseId)), [kindFixtures, activePhaseId]);
  const phaseAllFixtures = useMemo(() => phaseGroupsRaw.flatMap((g) => g.fixtures), [phaseGroupsRaw]);
  const phaseConflicts = useMemo(
    () => scopeConflictsToPhase(kindConflicts, activePhaseId, phaseAllFixtures.map((f) => f.id)),
    [kindConflicts, activePhaseId, phaseAllFixtures],
  );
  const phaseFamilyCounts = useMemo(() => countByFamily(phaseConflicts), [phaseConflicts]);
  const phaseCbf = useMemo(() => conflictsByFixture(applyFamilyFilter(phaseConflicts, effectiveFamilies)), [phaseConflicts, effectiveFamilies]);
  const phaseGroups = useMemo(() => phaseGroupsRaw.map((g) => ({ key: g.weekend, label: weekLabel(g.weekend), fixtures: g.fixtures })), [phaseGroupsRaw]);
  const activePhaseCompetition = null === activePhaseId ? undefined : competitionsMap.get(activePhaseId);
  const completeness = useMemo(
    () => (undefined === activePhaseCompetition ? null : phaseCompleteness(activePhaseCompetition, phaseAllFixtures, kindConflicts)),
    [activePhaseCompetition, phaseAllFixtures, kindConflicts],
  );

  // Chips familles : présentes sur la temporalité affichée, compteur AVANT le filtre familles.
  const familyCounts = isWeek ? weekFamilyCounts : isMonth ? monthFamilyCounts : phaseFamilyCounts;
  const scopedConflicts = isWeek ? weekConflicts : isMonth ? monthConflicts : phaseConflicts;
  const present = useMemo(() => familiesPresent(scopedConflicts), [scopedConflicts]);
  const familyChips = CONFLICT_FAMILIES.filter((family) => present.has(family));

  const newConflictFingerprints = useMemo<Set<string>>(() => new Set(moduleVisit.data?.newConflictFingerprints ?? []), [moduleVisit.data]);
  const dateless = useMemo(() => datelessConflicts(kindConflicts), [kindConflicts]);
  const latestDeposit = freshness.data?.latest ?? null;
  const depositReminder =
    null === latestDeposit ? "Aucun dépôt FBI cette saison" : `Dernier dépôt FBI ${relativeDepositLabel(depositDaysAgo(latestDeposit.depositedAt, todayISO()))}`;

  // ── Deep-link fusionné : filtre PR-1 + filtres Consulter + semaine ──────────────
  const [searchParams, setSearchParams] = useSearchParams();
  const seededRef = useRef(false);
  useEffect(() => {
    if (seededRef.current || undefined === teams.data || undefined === coaches.data || undefined === venues.data) {
      return;
    }
    seededRef.current = true;
    // Filtre PR-1 : seedé seulement sur un store VIERGE (une navigation depuis Conflits/
    // la file l'a déjà peuplé ; re-toggler l'effacerait).
    if (0 === filterIds.length && "equipe" === filterMode) {
      const { mode, ids } = decodeFilterParams(searchParams);
      const known = new Set(("coach" === mode ? coaches.data : "gymnase" === mode ? venues.data : teams.data).map((r) => r.id));
      const kept = ids.filter((id) => known.has(id));
      if ("equipe" !== mode || kept.length > 0) {
        setFilterMode(mode);
        kept.forEach(toggleFilterId);
      }
    }
    const consult = decodeConsultParams(searchParams);
    setConsultKinds(consult.kinds);
    setConsultFamilies(consult.families);
    setConsultTypicalWeek(consult.typicalWeek);
    setConsultTemporality(consult.temps);
    setConsultMonth(consult.month);
    setConsultPhaseId(consult.phaseId);
    // Semaine : ne seede QUE si l'URL la porte ET que le store est à l'auto (jamais
    // clobber une semaine posée par une navigation « Voir la semaine »).
    const weekend = decodeWeekendParam(searchParams);
    if (null !== weekend && null === useMatchesStore.getState().selectedWeekend) {
      setSelectedWeekend(weekend);
    }
  }, [teams.data, coaches.data, venues.data, searchParams, filterMode, filterIds.length, setFilterMode, toggleFilterId, setConsultKinds, setConsultFamilies, setConsultTypicalWeek, setConsultTemporality, setConsultMonth, setConsultPhaseId, setSelectedWeekend]);
  useEffect(() => {
    if (!seededRef.current) {
      return;
    }
    const withFilter = applyFilterToParams(searchParams, filterMode, filterIds);
    const withConsult = applyConsultToParams(withFilter, {
      kinds: consultKinds,
      families: consultFamilies,
      typicalWeek: consultTypicalWeek,
      temps: consultTemporality,
      month: consultMonth,
      phaseId: consultPhaseId,
    });
    const next = applyWeekendToParams(withConsult, selectedWeekend);
    if (next.toString() !== searchParams.toString()) {
      setSearchParams(next, { replace: true });
    }
  }, [filterMode, filterIds, consultKinds, consultFamilies, consultTypicalWeek, consultTemporality, consultMonth, consultPhaseId, selectedWeekend, searchParams, setSearchParams]);

  // « à placer » : ramène la liste dans le champ et lui donne le focus (le `<h2>`).
  const scrollToPlace = (): void => {
    const heading = document.getElementById(PLACE_HEADING_ID);
    // `scrollIntoView` n'existe pas sous jsdom (aucun moteur de layout) — appel gardé.
    heading?.scrollIntoView?.({ block: "nearest" });
    heading?.focus();
  };

  // Cliquer une ligne de table (Mois/Phase) bascule en Semaine, pose la semaine, et,
  // pour un domicile, ouvre le panneau ; après rendu, focalise la cellule (ou le `<h2>`).
  const onSelectFromTable = (fixtureId: string): void => {
    const fixture = allFixtures.find((f) => f.id === fixtureId);
    if (undefined === fixture) {
      return;
    }
    setConsultTemporality("semaine");
    setSelectedWeekend(weekendKeyOf(fixture.matchDate));
    setSelectedFixtureId(fixture.id);
    requestAnimationFrame(() => {
      const cell = document.querySelector<HTMLElement>(`[data-fixture-id="${fixtureId}"]`);
      if (null !== cell) {
        cell.focus();
        // `scrollIntoView` n'existe pas sous jsdom (aucun moteur de layout) — appel gardé.
        cell.scrollIntoView?.({ block: "nearest" });
        return;
      }
      document.getElementById(PLACE_HEADING_ID)?.focus();
    });
  };

  // Trois lectures fondatrices (doctrine `readState`).
  if (readLoading(fixtures) || readLoading(teams) || readLoading(venues)) {
    return <FullPageSpinner />;
  }
  if (readFailed(fixtures) || readFailed(teams) || readFailed(venues)) {
    return (
      <div className="flex flex-col gap-4">
        <LoadErrorHint
          onRetry={() => {
            void fixtures.refetch();
            void teams.refetch();
            void venues.refetch();
          }}
        />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {/* (c) — barre d'actions : retour discret · Nouveau match · Placer auto (SEUL bouton primaire). */}
      <div className="flex flex-wrap items-center justify-end gap-2">
        <FeedbackButton screen="/matchs" />
        <Button variant="outline" size="sm" onClick={() => setFixtureFormOpen(true)}>
          <Plus className="size-4" />
          Nouveau match
        </Button>
        <Button
          size="sm"
          disabled={placeMatches.isPending || placeCreditsBlocked}
          onClick={() =>
            placeMatches.mutate(undefined, {
              onSuccess: (result) => {
                setUnplacedReasons(new Map(result.unplaced.map((u) => [u.matchId, u.message])));
                toast.success(placementToastMessage(result));
              },
            })
          }
        >
          <Wand2 className="size-4" />
          {placeMatches.isPending ? "Placement…" : `Placer automatiquement${placeCreditSuffix}`}
        </Button>
      </div>

      {/* Filtre PR-1 partagé (équipe/coach/gymnase). */}
      <MatchesFilterBar
        mode={filterMode}
        selected={filterIds}
        teams={teams.data ?? []}
        coaches={coaches.data ?? []}
        venues={venues.data ?? []}
        tiers={priorityTiers.data ?? []}
        onModeChange={setFilterMode}
        onToggle={toggleFilterId}
        onClear={clearFilter}
      />

      {/* Chips (type + familles) · contrôle segmenté · navigateur · rappel de fraîcheur. */}
      <CalendarControls
        familyChips={familyChips}
        familyCounts={familyCounts}
        weekends={weekends}
        activeWeekend={activeWeekend}
        weekendIndex={weekendIndex}
        months={months}
        activeMonth={activeMonth}
        monthIndex={monthIndex}
        phases={phases}
        activePhaseId={activePhaseId}
        completeness={completeness}
        depositReminder={depositReminder}
      />

      {/* Le gardien : ce qui a bougé depuis la dernière visite. */}
      <ModuleVisitBanner delta={moduleVisit.data} />

      {/* Conflits SANS date (compétition incomplète…) → renvoi vers l'onglet Conflits. */}
      {dateless.length > 0 ? (
        <Link
          to="/matchs/conflits"
          className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted"
        >
          <Info className="size-4 shrink-0" aria-hidden="true" />
          {dateless.length} conflit{dateless.length > 1 ? "s" : ""} hors semaine (compétition incomplète…) — voir dans Conflits
          <ArrowRight className="size-3.5 shrink-0" aria-hidden="true" />
        </Link>
      ) : null}

      {/* ── Le corps, selon la temporalité ─────────────────────────────────────── */}
      {isWeek ? (
        <>
          <WeekCounters unplaced={weekCounts.unplaced} conflicts={weekCounts.conflicts} fbiToEnter={weekCounts.fbiToEnter} onScrollToPlace={scrollToPlace} onOpenFbi={() => setFbiModalOpen(true)} />
          {null === activeWeekend ? (
            <div className="flex flex-col items-start gap-3">
              <EmptyState icon={Upload} title="Aucun match importé" description="Importez vos rencontres FBI pour commencer la saison." />
              {filterActive ? null : (
                <Button variant="outline" size="sm" asChild>
                  <Link to="/matchs/importer">
                    <Upload className="size-4" />
                    Importer des rencontres
                  </Link>
                </Button>
              )}
            </div>
          ) : 0 === weekendFixtures.length ? (
            <EmptyState
              icon={CalendarX2}
              title={filterActive ? `Aucun match pour ${filterLabel} cette semaine` : "Aucun match cette semaine"}
              description="Aucune rencontre sur la semaine affichée. Changez de semaine ou ajustez le filtre."
            />
          ) : (
            <WeekWorkbench
              activeWeekend={activeWeekend}
              weekendFixtures={weekendFixtures}
              filteredFixtures={kindFixtures}
              allFixtures={allFixtures}
              radarConflicts={radarConflicts}
              radarLoaded={undefined !== conflicts.data}
              seasonPlanChosen={conflicts.data?.seasonPlanChosen}
              conflictsError={conflicts.isError}
              teamsMap={teamsMap}
              venuesMap={venuesMap}
              categoriesMap={categoriesMap}
              coachesMap={coachesMap}
              venues={venues.data ?? []}
              matchWindows={matchWindows.data ?? []}
              unavailabilities={unavailabilities.data ?? []}
              habits={habits}
              rotations={rotations}
              opponentTravel={opponentTravel.data ?? []}
              coachRoles={coachTeamRoles}
              resolvedTeamWindows={resolvedTeamWindows}
              windows={windows}
              outOfEnvelope={outOfEnvelope}
              matchDurations={matchDurations}
              newFingerprints={newConflictFingerprints}
              showGhosts={consultTypicalWeek}
              onEditFixture={setEditFixture}
            />
          )}
        </>
      ) : null}

      {isMonth ? (
        <MonthTable
          activeMonth={activeMonth}
          monthLabel={null === activeMonth ? "" : monthLabel(activeMonth)}
          groups={monthGroups}
          teams={teamsMap}
          venues={venuesMap}
          conflictsByFixture={monthCbf}
          coachRoles={coachTeamRoles}
          filterActive={filterActive}
          onSelectFixture={onSelectFromTable}
        />
      ) : null}

      {isPhase ? (
        <PhaseTable
          phaseCount={phases.length}
          groups={phaseGroups}
          competition={activePhaseCompetition}
          teams={teamsMap}
          venues={venuesMap}
          conflictsByFixture={phaseCbf}
          coachRoles={coachTeamRoles}
          onSelectFixture={onSelectFromTable}
          onOpenFfbb={() => setFfbbDialogOpen(true)}
        />
      ) : null}

      {fixtureFormOpen ? <FixtureFormDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} competitions={competitions.data ?? []} onClose={() => setFixtureFormOpen(false)} /> : null}
      {null !== editFixture ? (
        <FixtureFormDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} competitions={competitions.data ?? []} fixture={editFixture} onClose={() => setEditFixture(null)} />
      ) : null}
      {ffbbDialogOpen ? <FfbbEngagementsDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} onClose={() => setFfbbDialogOpen(false)} /> : null}
      {fbiModalOpen ? (
        <Modal
          label="À recopier dans FBI"
          title={`À recopier dans FBI — ${null === activeWeekend ? "" : weekLabel(activeWeekend)}`}
          size="lg"
          onClose={() => setFbiModalOpen(false)}
          footer={
            <Button variant="outline" size="sm" onClick={() => setFbiModalOpen(false)}>
              Fermer
            </Button>
          }
        >
          <FbiEntryList
            fixtures={weekendFixtures}
            teams={teamsMap}
            venues={venuesMap}
            competitions={competitionsMap}
            today={todayISO()}
            busy={submitFixture.isPending || reopenFixture.isPending}
            onSubmit={(fixture) => submitFixture.mutate(fixture, { onSuccess: () => toast.success("Match marqué saisi dans FBI") })}
            onReopen={(fixture) => reopenFixture.mutate(fixture)}
          />
        </Modal>
      ) : null}
    </div>
  );
}
