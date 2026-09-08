import { CalendarCheck2, ChevronLeft, ChevronRight, Filter } from "lucide-react";
import { useEffect, useMemo, useRef } from "react";
import { useNavigate, useSearchParams } from "react-router";

import { useCoachPlayers, useTeamCoaches } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { todayISO } from "@/shared/lib/clock";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";

import type { Coach, Competition, Conflict, ConflictType, Fixture, Team, Venue } from "./api";
import { AwayList } from "./AwayList";
import { ConflictRadar } from "./ConflictRadar";
import { CONFLICT_FAMILIES, CONFLICT_FAMILY_LABEL } from "./lib/conflictLabels";
import { applyFamilyFilter, applyKindFilter, countByFamily, KINDS, scopeConflictsToWeek, type Kind } from "./lib/consultFilter";
import { applyMatchFilter } from "./lib/matchFilter";
import { applyConsultToParams, applyFilterToParams, decodeConsultParams, decodeFilterParams } from "./lib/urlState";
import { buildWeekendGrid, listWeekends, resolveActiveWeekend, weekendKeyOf, weekLabel } from "./lib/weekendGrid";
import { MatchesFilterBar } from "./MatchesFilterBar";
import { useCoaches, useCompetitions, useConflicts, useOpponentTravel, usePriorityTiers, useTeamMatchHabits, useTeams, useVenues, useFixtures } from "./queries";
import { useMatchesStore } from "./store";
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

/**
 * PR-2a — l'onglet « Consulter » du module Matchs : « se rendre compte » des matchs
 * placés et des conflits, filtrés, en LECTURE SEULE (aucune mutation, aucun rail,
 * aucun panneau de placement). Temporalité : Semaine (2b ajoutera mois/phase).
 *
 * Il partage le filtre PR-1 (`MatchesFilterBar`, store `filterMode`/`filterIds`,
 * URL `?vue=&filtre=`) avec la boucle, et porte SES propres filtres : type de
 * compétition (chips), semaine type (ghosts d'habitude on/off), familles de conflits
 * (chips + compteur). La chaîne PURE, ordonnée : `applyMatchFilter` (PR-1) →
 * `applyKindFilter` → `countByFamily` (compteurs) → `applyFamilyFilter`.
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
    setConsultKinds,
    setConsultFamilies,
    setConsultTypicalWeek,
  } = useMatchesStore();

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);
  const coachesMap = useMemo<Map<string, Coach>>(() => byId(coaches.data), [coaches.data]);
  const competitionsMap = useMemo<Map<string, Competition>>(() => byId(competitions.data), [competitions.data]);

  const allFixtures = useMemo<Fixture[]>(() => fixtures.data ?? [], [fixtures.data]);
  const allConflicts = useMemo<Conflict[]>(() => conflicts.data?.conflicts ?? [], [conflicts.data]);
  const habits = useMemo(() => habitsQuery.data ?? [], [habitsQuery.data]);

  // ── Chaîne pure : PR-1 → type de compétition → compteurs → familles ──────────
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
  const coachTeamRoles = filtered.coachTeamRoles ?? undefined;
  const filterActive = filterIds.length > 0;

  // La semaine affichée (temporalité Semaine) : elle borne les compteurs ET le radar.
  const weekends = useMemo(() => listWeekends(kindFixtures), [kindFixtures]);
  const activeWeekend = resolveActiveWeekend(weekends, selectedWeekend, weekendKeyOf(todayISO()));

  // Compteurs par famille : APRÈS PR-1 + type + scope semaine, AVANT le filtre
  // familles — une chip décochée garde donc son compteur (sur la semaine affichée).
  const weekConflicts = useMemo(() => scopeConflictsToWeek(kindResult.conflicts, activeWeekend), [kindResult.conflicts, activeWeekend]);
  const familyCounts = useMemo(() => countByFamily(weekConflicts), [weekConflicts]);
  const radarConflicts = useMemo(() => applyFamilyFilter(weekConflicts, effectiveFamilies), [weekConflicts, effectiveFamilies]);

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
    // Filtres Consulter : des SET idempotents (null/null/true par défaut).
    const consult = decodeConsultParams(searchParams);
    setConsultKinds(consult.kinds);
    setConsultFamilies(consult.families);
    setConsultTypicalWeek(consult.typicalWeek);
  }, [teams.data, coaches.data, venues.data, searchParams, filterMode, filterIds.length, setFilterMode, toggleFilterId, setConsultKinds, setConsultFamilies, setConsultTypicalWeek]);
  useEffect(() => {
    if (!seededRef.current) {
      return;
    }
    const withFilter = applyFilterToParams(searchParams, filterMode, filterIds);
    const next = applyConsultToParams(withFilter, { kinds: consultKinds, families: consultFamilies, typicalWeek: consultTypicalWeek, temps: "semaine" });
    if (next.toString() !== searchParams.toString()) {
      setSearchParams(next, { replace: true });
    }
  }, [filterMode, filterIds, consultKinds, consultFamilies, consultTypicalWeek, searchParams, setSearchParams]);

  const weekendIndex = null === activeWeekend ? -1 : weekends.indexOf(activeWeekend);
  const weekendFixtures = useMemo(
    () => (null === activeWeekend ? [] : kindFixtures.filter((f) => weekendKeyOf(f.matchDate) === activeWeekend)),
    [kindFixtures, activeWeekend],
  );
  const grid = useMemo(
    () => buildWeekendGrid(weekendFixtures, venuesMap, teamsMap, new Set<string>(), consultTypicalWeek ? habits : [], activeWeekend),
    [weekendFixtures, venuesMap, teamsMap, consultTypicalWeek, habits, activeWeekend],
  );

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
  // Chips familles : celles PRÉSENTES (compteur > 0 après PR-1 + type). Décocher
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
          interrupteur « Semaine type » (ghosts d'habitude). */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-1 rounded-md border border-border p-0.5">
          {KINDS.map((kind) => (
            <Button key={kind} type="button" size="sm" aria-pressed={isKindChecked(kind)} variant={isKindChecked(kind) ? "default" : "ghost"} className={chipClass(isKindChecked(kind))} onClick={() => toggleKind(kind)}>
              {KIND_LABEL[kind]}
            </Button>
          ))}
        </div>
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

      {/* Navigateur de semaine (temporalité Semaine). */}
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

      {/* Le radar, nourri des conflits filtrés (passerelle/calendriers incomplets
          restent repliés par son mécanisme de sévérité). */}
      {undefined === conflicts.data ? null : <ConflictRadar conflicts={radarConflicts} teams={teamsMap} coaches={coachesMap} />}
    </div>
  );
}
