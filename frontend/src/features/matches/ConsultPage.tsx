import { CalendarCheck2, ChevronLeft, ChevronRight, Filter, Link2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router";

import { useCoachPlayers, useTeamCoaches } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Select } from "@/shared/components/ui/select";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { todayISO } from "@/shared/lib/clock";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";

import type { Coach, Competition, Conflict, ConflictType, Fixture, Team, Venue } from "./api";
import { AwayList } from "./AwayList";
import { ConflictRadar } from "./ConflictRadar";
import { FfbbEngagementsDialog } from "./FfbbEngagementsDialog";
import { CONFLICT_FAMILIES, CONFLICT_FAMILY_LABEL } from "./lib/conflictLabels";
import { applyFamilyFilter, applyKindFilter, countByFamily, KINDS, scopeConflictsToWeek, type Kind } from "./lib/consultFilter";
import { applyMatchFilter } from "./lib/matchFilter";
import { conflictsByFixture, groupByDay, listMonths, resolveActiveMonth, scopeConflictsToMonth } from "./lib/monthView";
import { listPhases, phaseCompleteness, phaseFixtures, scopeConflictsToPhase } from "./lib/phaseView";
import { applyConsultToParams, applyFilterToParams, decodeConsultParams, decodeFilterParams } from "./lib/urlState";
import { buildWeekendGrid, listWeekends, resolveActiveWeekend, weekendKeyOf, weekLabel } from "./lib/weekendGrid";
import { MatchesFilterBar } from "./MatchesFilterBar";
import { MatchRowsTable } from "./MatchRowsTable";
import { useCoaches, useCompetitions, useConflicts, useOpponentTravel, usePriorityTiers, useTeamMatchHabits, useTeams, useVenues, useFixtures } from "./queries";
import { useMatchesStore, type ConsultTemporality } from "./store";
import { WeekendGrid } from "./WeekendGrid";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

const KIND_LABEL: Record<Kind, string> = {
  amical: "Amical",
  championnat: "Championnat",
  coupe: "Coupe",
  brassage: "Brassage",
};

const TEMPORALITIES: ConsultTemporality[] = ["semaine", "mois", "phase"];
const TEMPORALITY_LABEL: Record<ConsultTemporality, string> = {
  semaine: "Semaine",
  mois: "Mois",
  phase: "Phase",
};

/** Libellé français d'un mois `YYYY-MM`, ex. « septembre 2026 ». */
function monthLabel(month: string): string {
  const [year, m] = month.split("-").map(Number);
  return new Date(year, m - 1, 1).toLocaleDateString("fr-FR", { month: "long", year: "numeric" });
}

/**
 * PR-2a/2b — l'onglet « Consulter » du module Matchs : « se rendre compte » des matchs
 * placés et des conflits, filtrés, en LECTURE SEULE (aucune mutation, aucun rail,
 * aucun panneau de placement). Trois temporalités : **Semaine** (grille + extérieurs,
 * défaut, byte-identique PR-2a), **Mois** et **Phase** (tables partagées `MatchRowsTable`).
 *
 * Il partage le filtre PR-1 (`MatchesFilterBar`, store `filterMode`/`filterIds`,
 * URL `?vue=&filtre=`) avec la boucle, et porte SES propres filtres : type de
 * compétition (chips), semaine type (Semaine seule), familles de conflits (chips +
 * compteur, sur la temporalité affichée). Chaîne PURE : `applyMatchFilter` (PR-1) →
 * `applyKindFilter` → scope (semaine/mois/phase) → `countByFamily` → `applyFamilyFilter`.
 */
export function ConsultPage() {
  const fixtures = useFixtures();
  const competitions = useCompetitions();
  const conflicts = useConflicts();
  const teams = useTeams();
  const priorityTiers = usePriorityTiers();
  const venues = useVenues();
  const coaches = useCoaches();
  const habitsQuery = useTeamMatchHabits();
  const opponentTravel = useOpponentTravel();
  const teamCoaches = useTeamCoaches();
  const coachPlayers = useCoachPlayers();

  const navigate = useNavigate();
  const [ffbbDialogOpen, setFfbbDialogOpen] = useState(false);

  const {
    selectedWeekend,
    setSelectedWeekend,
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
  const coachesMap = useMemo<Map<string, Coach>>(() => byId(coaches.data), [coaches.data]);
  const competitionsMap = useMemo<Map<string, Competition>>(() => byId(competitions.data), [competitions.data]);

  const allFixtures = useMemo<Fixture[]>(() => fixtures.data ?? [], [fixtures.data]);
  const allConflicts = useMemo<Conflict[]>(() => conflicts.data?.conflicts ?? [], [conflicts.data]);
  const habits = useMemo(() => habitsQuery.data ?? [], [habitsQuery.data]);

  // ── Chaîne pure : PR-1 → type de compétition ─────────────────────────────────
  const filtered = useMemo(
    () => applyMatchFilter({ mode: filterMode, ids: filterIds, fixtures: allFixtures, conflicts: allConflicts, teamCoaches: teamCoaches.data ?? [], coachPlayers: coachPlayers.data ?? [] }),
    [filterMode, filterIds, allFixtures, allConflicts, teamCoaches.data, coachPlayers.data],
  );
  const effectiveKinds = consultKinds ?? KINDS;
  const effectiveFamilies = consultFamilies ?? CONFLICT_FAMILIES;
  const kindResult = useMemo(
    () => applyKindFilter(filtered.fixtures, filtered.conflicts, effectiveKinds, competitionsMap),
    [filtered.fixtures, filtered.conflicts, effectiveKinds, competitionsMap],
  );

  const kindFixtures = kindResult.fixtures;
  const kindConflicts = kindResult.conflicts;
  const coachTeamRoles = filtered.coachTeamRoles ?? undefined;
  const filterActive = filterIds.length > 0;

  const isWeek = "semaine" === consultTemporality;
  const isMonth = "mois" === consultTemporality;
  const isPhase = "phase" === consultTemporality;

  // ── Temporalité SEMAINE : elle borne les compteurs ET le radar ───────────────
  const weekends = useMemo(() => listWeekends(kindFixtures), [kindFixtures]);
  const activeWeekend = resolveActiveWeekend(weekends, selectedWeekend, weekendKeyOf(todayISO()));
  const weekConflicts = useMemo(() => scopeConflictsToWeek(kindConflicts, activeWeekend), [kindConflicts, activeWeekend]);
  const weekFamilyCounts = useMemo(() => countByFamily(weekConflicts), [weekConflicts]);
  const radarConflicts = useMemo(() => applyFamilyFilter(weekConflicts, effectiveFamilies), [weekConflicts, effectiveFamilies]);
  const weekendIndex = null === activeWeekend ? -1 : weekends.indexOf(activeWeekend);
  const weekendFixtures = useMemo(
    () => (null === activeWeekend ? [] : kindFixtures.filter((f) => weekendKeyOf(f.matchDate) === activeWeekend)),
    [kindFixtures, activeWeekend],
  );
  const grid = useMemo(
    () => buildWeekendGrid(weekendFixtures, venuesMap, teamsMap, new Set<string>(), consultTypicalWeek ? habits : [], activeWeekend),
    [weekendFixtures, venuesMap, teamsMap, consultTypicalWeek, habits, activeWeekend],
  );

  // ── Temporalité MOIS : navigateur ‹ mois ›, table groupée par jour ────────────
  const months = useMemo(() => listMonths(kindFixtures), [kindFixtures]);
  const activeMonth = resolveActiveMonth(months, consultMonth, todayISO().slice(0, 7));
  const monthConflicts = useMemo(() => scopeConflictsToMonth(kindConflicts, activeMonth), [kindConflicts, activeMonth]);
  const monthFamilyCounts = useMemo(() => countByFamily(monthConflicts), [monthConflicts]);
  const monthDisplayed = useMemo(() => applyFamilyFilter(monthConflicts, effectiveFamilies), [monthConflicts, effectiveFamilies]);
  const monthCbf = useMemo(() => conflictsByFixture(monthDisplayed), [monthDisplayed]);
  const monthGroups = useMemo(
    () => (null === activeMonth ? [] : groupByDay(kindFixtures, activeMonth).map((g) => ({ key: g.date, label: dayHeaderLabel(g.date), fixtures: g.fixtures }))),
    [kindFixtures, activeMonth],
  );
  const monthIndex = null === activeMonth ? -1 : months.indexOf(activeMonth);

  // ── Temporalité PHASE : compétition appariée, table des journées ──────────────
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
  const phaseDisplayed = useMemo(() => applyFamilyFilter(phaseConflicts, effectiveFamilies), [phaseConflicts, effectiveFamilies]);
  const phaseCbf = useMemo(() => conflictsByFixture(phaseDisplayed), [phaseDisplayed]);
  const phaseGroups = useMemo(() => phaseGroupsRaw.map((g) => ({ key: g.weekend, label: weekLabel(g.weekend), fixtures: g.fixtures })), [phaseGroupsRaw]);
  const activePhaseCompetition = null === activePhaseId ? undefined : competitionsMap.get(activePhaseId);
  const completeness = useMemo(
    () => (undefined === activePhaseCompetition ? null : phaseCompleteness(activePhaseCompetition, phaseAllFixtures, kindConflicts)),
    [activePhaseCompetition, phaseAllFixtures, kindConflicts],
  );

  // Compteurs par famille : sur la temporalité affichée, AVANT le filtre familles.
  const familyCounts = isWeek ? weekFamilyCounts : isMonth ? monthFamilyCounts : phaseFamilyCounts;

  // ── Deep-link : filtre PR-1 (partagé) + filtres Consulter, dans l'URL ─────────
  const [searchParams, setSearchParams] = useSearchParams();
  const seededRef = useRef(false);
  useEffect(() => {
    if (seededRef.current || undefined === teams.data || undefined === coaches.data || undefined === venues.data) {
      return;
    }
    seededRef.current = true;
    // Filtre PR-1 : le store est la source partagée entre les deux routes ; on ne
    // seede depuis l'URL que sur un store VIERGE (deep-link / refresh direct) — une
    // navigation depuis la boucle a déjà le store peuplé, re-toggler l'effacerait.
    if (0 === filterIds.length && "equipe" === filterMode) {
      const { mode, ids } = decodeFilterParams(searchParams);
      const known = new Set(("coach" === mode ? coaches.data : "gymnase" === mode ? venues.data : teams.data).map((r) => r.id));
      const kept = ids.filter((id) => known.has(id));
      if ("equipe" !== mode || kept.length > 0) {
        setFilterMode(mode);
        kept.forEach(toggleFilterId);
      }
    }
    // Filtres Consulter : des SET idempotents (null/null/true/semaine par défaut).
    const consult = decodeConsultParams(searchParams);
    setConsultKinds(consult.kinds);
    setConsultFamilies(consult.families);
    setConsultTypicalWeek(consult.typicalWeek);
    setConsultTemporality(consult.temps);
    setConsultMonth(consult.month);
    setConsultPhaseId(consult.phaseId);
  }, [teams.data, coaches.data, venues.data, searchParams, filterMode, filterIds.length, setFilterMode, toggleFilterId, setConsultKinds, setConsultFamilies, setConsultTypicalWeek, setConsultTemporality, setConsultMonth, setConsultPhaseId]);
  useEffect(() => {
    if (!seededRef.current) {
      return;
    }
    const withFilter = applyFilterToParams(searchParams, filterMode, filterIds);
    const next = applyConsultToParams(withFilter, {
      kinds: consultKinds,
      families: consultFamilies,
      typicalWeek: consultTypicalWeek,
      temps: consultTemporality,
      month: consultMonth,
      phaseId: consultPhaseId,
    });
    if (next.toString() !== searchParams.toString()) {
      setSearchParams(next, { replace: true });
    }
  }, [filterMode, filterIds, consultKinds, consultFamilies, consultTypicalWeek, consultTemporality, consultMonth, consultPhaseId, searchParams, setSearchParams]);

  // ── Interactions des filtres (chips + interrupteur) ──────────────────────────
  const isKindChecked = (kind: Kind): boolean => null === consultKinds || consultKinds.includes(kind);
  const toggleKind = (kind: Kind): void => {
    const active = new Set<Kind>(consultKinds ?? KINDS);
    if (active.has(kind)) {
      active.delete(kind);
    } else {
      active.add(kind);
    }
    const next = KINDS.filter((k) => active.has(k));
    setConsultKinds(next.length === KINDS.length ? null : next);
  };
  const isFamilyChecked = (family: ConflictType): boolean => null === consultFamilies || consultFamilies.includes(family);
  const toggleFamily = (family: ConflictType): void => {
    const active = new Set<ConflictType>(consultFamilies ?? CONFLICT_FAMILIES);
    if (active.has(family)) {
      active.delete(family);
    } else {
      active.add(family);
    }
    const next = CONFLICT_FAMILIES.filter((f) => active.has(f));
    setConsultFamilies(next.length === CONFLICT_FAMILIES.length ? null : next);
  };
  // Chips familles : celles PRÉSENTES (compteur > 0 sur la temporalité). Décocher
  // n'enlève pas la chip (le compteur vient d'AVANT le filtre familles).
  const familyChips = CONFLICT_FAMILIES.filter((family) => (familyCounts.get(family) ?? 0) > 0);

  // Cliquer un match renvoie vers la boucle (Placer) SUR ce week-end.
  function onSelectFixture(fixtureId: string): void {
    const fixture = allFixtures.find((f) => f.id === fixtureId);
    if (undefined === fixture) {
      return;
    }
    setSelectedWeekend(weekendKeyOf(fixture.matchDate));
    navigate("/matchs");
  }

  // Trois lectures fondatrices (doctrine `readState`, comme `MatchesPage`).
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

  const chipClass = (checked: boolean): string => cn("h-7", checked ? "" : "text-muted-foreground");

  return (
    <div className="flex flex-col gap-4">
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

      {/* Chips type de compétition (multi, aria-pressed, défaut tout coché) +
          interrupteur « Semaine type » (Semaine seule). */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-1 rounded-md border border-border p-0.5">
          {KINDS.map((kind) => (
            <Button key={kind} type="button" size="sm" aria-pressed={isKindChecked(kind)} variant={isKindChecked(kind) ? "default" : "ghost"} className={chipClass(isKindChecked(kind))} onClick={() => toggleKind(kind)}>
              {KIND_LABEL[kind]}
            </Button>
          ))}
        </div>
        {isWeek ? (
          <Button
            type="button"
            role="switch"
            size="sm"
            aria-checked={consultTypicalWeek}
            variant={consultTypicalWeek ? "default" : "ghost"}
            className={cn("h-7 gap-1.5", consultTypicalWeek ? "" : "text-muted-foreground")}
            onClick={() => setConsultTypicalWeek(!consultTypicalWeek)}
          >
            <CalendarCheck2 className="size-3.5" aria-hidden="true" />
            Semaine type
          </Button>
        ) : null}
      </div>

      {/* Chips familles de conflits (présentes) avec compteur. `role="group"` +
          nom accessible : un groupe nommé, sélecteur stable pour l'e2e. */}
      {familyChips.length > 0 ? (
        <div role="group" aria-label="Familles de conflits" className="flex flex-wrap items-center gap-1.5">
          {familyChips.map((family) => (
            <Button
              key={family}
              type="button"
              size="sm"
              aria-pressed={isFamilyChecked(family)}
              variant={isFamilyChecked(family) ? "default" : "ghost"}
              className={cn("h-7 gap-1.5 border border-border", isFamilyChecked(family) ? "" : "text-muted-foreground")}
              onClick={() => toggleFamily(family)}
            >
              {CONFLICT_FAMILY_LABEL[family]}
              <span className="tabular-nums text-xs">{familyCounts.get(family) ?? 0}</span>
            </Button>
          ))}
        </div>
      ) : null}

      {/* Contrôle segmenté de temporalité (Semaine · Mois · Phase) + le navigateur
          propre à la temporalité affichée. */}
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label="Temporalité" className="flex items-center gap-1 rounded-md border border-border p-0.5">
          {TEMPORALITIES.map((temporality) => (
            <Button
              key={temporality}
              type="button"
              size="sm"
              aria-pressed={temporality === consultTemporality}
              variant={temporality === consultTemporality ? "default" : "ghost"}
              className={chipClass(temporality === consultTemporality)}
              onClick={() => setConsultTemporality(temporality)}
            >
              {TEMPORALITY_LABEL[temporality]}
            </Button>
          ))}
        </div>

        {isWeek ? (
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" disabled={weekendIndex <= 0} onClick={() => setSelectedWeekend(weekends[weekendIndex - 1] ?? null)} aria-label="Semaine précédente">
              <ChevronLeft className="size-4" />
            </Button>
            <span className="text-sm font-medium">{null === activeWeekend ? "Aucun match" : weekLabel(activeWeekend)}</span>
            <Button
              variant="outline"
              size="sm"
              disabled={weekendIndex < 0 || weekendIndex >= weekends.length - 1}
              onClick={() => setSelectedWeekend(weekends[weekendIndex + 1] ?? null)}
              aria-label="Semaine suivante"
            >
              <ChevronRight className="size-4" />
            </Button>
          </div>
        ) : null}

        {isMonth ? (
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" disabled={monthIndex <= 0} onClick={() => setConsultMonth(months[monthIndex - 1] ?? null)} aria-label="Mois précédent">
              <ChevronLeft className="size-4" />
            </Button>
            <span className="text-sm font-medium first-letter:uppercase">{null === activeMonth ? "Aucun match" : monthLabel(activeMonth)}</span>
            <Button
              variant="outline"
              size="sm"
              disabled={monthIndex < 0 || monthIndex >= months.length - 1}
              onClick={() => setConsultMonth(months[monthIndex + 1] ?? null)}
              aria-label="Mois suivant"
            >
              <ChevronRight className="size-4" />
            </Button>
          </div>
        ) : null}

        {isPhase && phases.length > 0 ? (
          <div className="flex items-center gap-3">
            <div className="w-64">
              <Select aria-label="Phase (compétition appariée)" value={activePhaseId ?? ""} onChange={(e) => setConsultPhaseId(e.target.value)}>
                {phases.map((phase) => (
                  <option key={phase.competitionId} value={phase.competitionId}>
                    {phase.label}
                  </option>
                ))}
              </Select>
            </div>
            {null !== completeness ? (
              <span className="text-sm font-medium text-foreground tabular-nums">
                {completeness.imported} / {completeness.expected} journées importées
              </span>
            ) : null}
          </div>
        ) : null}
      </div>

      {/* ── Contenu selon la temporalité ──────────────────────────────────────── */}
      {isWeek ? (
        <>
          {0 === weekendFixtures.length ? (
            <EmptyState
              icon={Filter}
              title={filterActive ? "Aucun match pour ce filtre cette semaine" : "Aucun match cette semaine"}
              description="Aucun match placé sur la semaine affichée. Changez de semaine ou ajustez les filtres."
            />
          ) : (
            <div className="flex flex-col gap-3">
              <div className="h-[32rem]">
                <WeekendGrid model={grid} onSelectFixture={onSelectFixture} />
              </div>
              {/* Lecture seule : ni crayon ni corbeille (onEdit/onDelete omis). */}
              <AwayList fixtures={weekendFixtures} teams={teamsMap} habits={habits} travel={opponentTravel.data ?? []} coachRoles={coachTeamRoles} />
            </div>
          )}
          {/* Le radar (Semaine seule), nourri des conflits filtrés. */}
          {undefined === conflicts.data ? null : <ConflictRadar conflicts={radarConflicts} teams={teamsMap} coaches={coachesMap} />}
        </>
      ) : null}

      {isMonth ? (
        0 === monthGroups.length ? (
          <EmptyState
            icon={Filter}
            title={null === activeMonth ? "Aucun match" : `Aucun match ${filterActive ? "pour ce filtre " : ""}en ${monthLabel(activeMonth)}`}
            description="Aucun match placé sur le mois affiché. Changez de mois ou ajustez les filtres."
          />
        ) : (
          <MatchRowsTable caption={`Matchs de ${null === activeMonth ? "" : monthLabel(activeMonth)}`} groups={monthGroups} teams={teamsMap} venues={venuesMap} conflictsByFixture={monthCbf} coachRoles={coachTeamRoles} onSelectFixture={onSelectFixture} />
        )
      ) : null}

      {isPhase ? (
        0 === phases.length ? (
          <div className="flex flex-col items-start gap-3">
            <EmptyState icon={Link2} title="Aucune compétition appariée" description="Appariez une compétition FFBB à une de vos équipes pour la consulter par phase." />
            <Button variant="outline" size="sm" onClick={() => setFfbbDialogOpen(true)}>
              <Link2 className="size-4" />
              Engagements FFBB
            </Button>
          </div>
        ) : 0 === phaseGroups.length ? (
          <EmptyState icon={Filter} title="Aucun match pour cette phase" description="Aucune rencontre importée sur cette compétition. Choisissez une autre phase ou ajustez les filtres." />
        ) : (
          <MatchRowsTable caption={`Journées de ${activePhaseCompetition?.name ?? "la phase"}`} groups={phaseGroups} teams={teamsMap} venues={venuesMap} conflictsByFixture={phaseCbf} coachRoles={coachTeamRoles} onSelectFixture={onSelectFixture} />
        )
      ) : null}

      {ffbbDialogOpen ? <FfbbEngagementsDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} onClose={() => setFfbbDialogOpen(false)} /> : null}
    </div>
  );
}

/** En-tête de groupe d'un JOUR (Mois), ex. « sam. 3 oct. » avec l'initiale capitalisée. */
function dayHeaderLabel(dateIso: string): string {
  const label = new Date(`${dateIso}T12:00:00Z`).toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long" });
  return label.charAt(0).toUpperCase() + label.slice(1);
}
