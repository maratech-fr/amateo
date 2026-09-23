import { ArrowRight, CalendarX2, Info, Plus, Upload, Wand2 } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
import { applyFamilyFilter, countByFamily, DEFAULT_KINDS, familiesPresent, KINDS, normalizeKinds, revealPlan } from "./lib/consultFilter";
import { isInEnvelope, resolveEnvelope } from "./lib/envelope";
import { depositDaysAgo, relativeDepositLabel } from "./lib/fbiFreshness";
import { datelessConflicts } from "./lib/loopSteps";
import { conflictsByFixture, monthLabel } from "./lib/monthView";
import { listPhases, phaseCompleteness, phaseFixtures, scopeConflictsToPhase } from "./lib/phaseView";
import { placementToastMessage } from "./lib/placementToast";
import { useMatchFilterChain } from "./lib/useMatchFilterChain";
import { useMonthView } from "./lib/useMonthView";
import { usePlacementGuards } from "./lib/usePlacementGuards";
import { useWeekView } from "./lib/useWeekView";
import {
  applyConsultToParams,
  applyFbiToParams,
  applyFilterToParams,
  applyMatchToParams,
  applyWeekendToParams,
  decodeConsultParams,
  decodeFbiParam,
  decodeFilterParams,
  decodeMatchParam,
  decodeWeekendParam,
  hasConsultParams,
} from "./lib/urlState";
import { isPlacedOnGrid, matchMinutesByCategory, weekendKeyOf, weekLabel } from "./lib/weekendGrid";
import { MatchesFilterBar } from "./MatchesFilterBar";
import { ModuleVisitBanner } from "./ModuleVisitBanner";
import { MonthTable } from "./MonthTable";
import { PhaseTable } from "./PhaseTable";
import {
  useCategories,
  useCloseFbiCorrection,
  useCoaches,
  useCompetitions,
  useConflicts,
  useFbiCorrections,
  useFixtures,
  useLatestFbiIngestion,
  useLeagueWindows,
  useMatchSlotRotations,
  useModuleVisit,
  usePlaceMatches,
  usePriorityTiers,
  useReopenFbiCorrection,
  useSportCategoryDurations,
  useSubmitFixture,
  useTeamMatchHabits,
  useTeams,
  useVenueMatchWindows,
  useVenues,
  useVenueUnavailabilities,
} from "./queries";
import { useMatchesStore } from "./store";
import { HiddenMatchesWeekNotice } from "./UnpairedVenueLabelsBanner";
import { WeekCounters } from "./WeekCounters";
import { GRID_CONTAINER_ID, PLACE_HEADING_ID, WeekWorkbench } from "./WeekWorkbench";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
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
  const teamCoaches = useTeamCoaches();
  const coachPlayers = useCoachPlayers();
  const placeMatches = usePlaceMatches();
  const submitFixture = useSubmitFixture();
  const moduleVisit = useModuleVisit();
  const freshness = useLatestFbiIngestion();
  const fbiCorrections = useFbiCorrections();
  const closeFbiCorrection = useCloseFbiCorrection();
  const reopenFbiCorrection = useReopenFbiCorrection();

  const [editFixture, setEditFixture] = useState<Fixture | null>(null);
  // Deep-link « FBI — à faire » (`fbi=1`, depuis le cockpit) : lu UNE fois au montage
  // depuis l'URL (l'adresse fait foi — l'état vit dans l'URL, pas posé après coup).
  const [fbiModalOpen, setFbiModalOpen] = useState(() => decodeFbiParam(new URLSearchParams(window.location.search)));
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
    consultAway,
    consultTemporality,
    consultMonth,
    consultPhaseId,
    setConsultKinds,
    setConsultFamilies,
    setConsultTypicalWeek,
    setConsultAway,
    setConsultTemporality,
    setConsultMonth,
    setConsultPhaseId,
  } = useMatchesStore();

  // A5 — région live persistante : remplie après la levée des masques (« N matchs affichés »),
  // elle survit au retrait de l'indice « masqués » (qui disparaît dès que rien n'est masqué).
  const [revealLive, setRevealLive] = useState("");

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);
  const categoriesMap = useMemo<Map<string, Category>>(() => byId(categories.data), [categories.data]);
  const competitionsMap = useMemo<Map<string, Competition>>(() => byId(competitions.data), [competitions.data]);
  const coachesMap = useMemo<Map<string, Coach>>(() => byId(coaches.data), [coaches.data]);
  const matchDurations = useMemo(() => matchMinutesByCategory(categoryDurations.data ?? []), [categoryDurations.data]);

  const allFixtures = useMemo<Fixture[]>(() => fixtures.data ?? [], [fixtures.data]);
  const openCorrections = useMemo(() => fbiCorrections.data ?? [], [fbiCorrections.data]);
  // « FBI à faire » GLOBAL (toutes semaines) : domiciles PLACED (à saisir) + entrées
  // ouvertes du registre (à corriger). Calculé ici — la page charge déjà les fixtures.
  const fbiTodoCount = useMemo(
    () => allFixtures.filter((f) => "HOME" === f.homeAway && "PLACED" === f.status).length + openCorrections.length,
    [allFixtures, openCorrections],
  );
  const windows = useMemo(() => leagueWindows.data?.items ?? [], [leagueWindows.data]);
  const resolvedTeamWindows = useMemo(() => leagueWindows.data?.resolvedTeamWindows ?? {}, [leagueWindows.data]);
  const habits = useMemo(() => habitsQuery.data ?? [], [habitsQuery.data]);
  const rotations = useMemo(() => rotationsQuery.data ?? [], [rotationsQuery.data]);
  const allConflicts = useMemo<Conflict[]>(() => conflicts.data?.conflicts ?? [], [conflicts.data]);

  // D2 — les trois lectures du club qui GARDENT le geste de placement (accès match,
  // indisponibilités, enveloppe ligue), suspendues tant qu'elles ne sont pas prêtes.
  const placementGuards = usePlacementGuards(matchWindows, unavailabilities, leagueWindows);

  // Chaîne de filtrage : PR-1 (équipe/coach/gymnase) → types → « Extérieurs ».
  const { filtered, coachTeamRoles, filterActive, filterLabel, effectiveKinds, effectiveFamilies, kindResult, visibleFixtures } = useMatchFilterChain(
    filterMode,
    filterIds,
    allFixtures,
    allConflicts,
    teamCoaches,
    coachPlayers,
    coachesMap,
    venuesMap,
    teamsMap,
    competitionsMap,
    consultKinds,
    consultFamilies,
    consultAway,
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
  const { weekends, activeWeekend, weekendIndex, weekendFixtures, weekConflicts, weekFamilyCounts, radarConflicts, weekCounts, weekHiddenBreakdown } = useWeekView(
    visibleFixtures,
    selectedWeekend,
    kindFixtures,
    kindConflicts,
    effectiveFamilies,
    filtered,
    effectiveKinds,
    consultAway,
    competitionsMap,
  );

  // ── Temporalité MOIS ───────────────────────────────────────────────────────────
  const { months, activeMonth, monthConflicts, monthFamilyCounts, monthCbf, monthGroups, monthIndex } = useMonthView(visibleFixtures, consultMonth, kindConflicts, effectiveFamilies);

  // ── Temporalité PHASE ──────────────────────────────────────────────────────────
  const phases = useMemo(() => listPhases(competitions.data ?? [], teams.data ?? []), [competitions.data, teams.data]);
  const activePhaseId = useMemo(() => {
    if (0 === phases.length) {
      return null;
    }
    return phases.some((p) => p.competitionId === consultPhaseId) ? consultPhaseId : phases[0].competitionId;
  }, [phases, consultPhaseId]);
  // Complétude + conflits + scoping = toute la compétition (extérieurs INCLUS).
  const phaseGroupsAll = useMemo(() => (null === activePhaseId ? [] : phaseFixtures(kindFixtures, activePhaseId)), [kindFixtures, activePhaseId]);
  const phaseAllFixtures = useMemo(() => phaseGroupsAll.flatMap((g) => g.fixtures), [phaseGroupsAll]);
  // Table d'AFFICHAGE (extérieurs masqués si l'interrupteur est éteint).
  const phaseGroupsRaw = useMemo(() => (null === activePhaseId ? [] : phaseFixtures(visibleFixtures, activePhaseId)), [visibleFixtures, activePhaseId]);
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
  // UN seul effet, deux temps : (1) au premier passage utile (données prêtes), SEED depuis
  // l'URL — filtre PR-1 sur store vierge, filtres Consulter SEULEMENT si l'URL porte une clé
  // Consulter (sinon le store, mémoire de session non persistée, est GARDÉ), semaine ; (2) à
  // CHAQUE passage, re-SYNCHRONISE l'URL depuis le store. Fusionnés (au lieu d'un effet
  // d'écriture séparé gardé par un ref) pour que la re-synchro parte DÈS la passe de seed même
  // quand aucune valeur n'a changé — cas « URL nue, store gardé » : un ref ne redéclencherait
  // aucun effet et l'adresse ne se re-synchroniserait qu'à la prochaine interaction.
  const [searchParams, setSearchParams] = useSearchParams();
  const seededRef = useRef(false);
  // Le geste « aller à cette rencontre » après rendu : focalise la cellule de la grille
  // week-end (`[data-fixture-id]`, la sélection porte déjà l'anneau `ring-accent`), sinon
  // le `<h2>` « À placer » (repli d'un extérieur sans cellule propre). Partagé par le clic
  // de table (Mois/Phase) et le deep-link `match=` du seed. `useCallback` : stable, sûr en
  // dépendance d'effet. Déclaré AVANT le seed — un `const` postérieur serait en zone morte
  // au moment où le tableau de dépendances est évalué.
  const focusFixtureCell = useCallback((fixtureId: string): void => {
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
  }, []);
  useEffect(() => {
    // `match=` (deep-link vers une rencontre) exige les fixtures chargées : on attend les
    // quatre lectures pour que le seed ne rate jamais la mise en évidence.
    if (undefined === teams.data || undefined === coaches.data || undefined === venues.data || undefined === fixtures.data) {
      return;
    }
    // `touchedStore` : le seed a-t-il écrit dans le store CETTE passe ? Si oui, on NE
    // synchronise PAS l'URL maintenant (les valeurs lues plus bas sont encore celles d'AVANT
    // le seed → on clobberait le deep-link) ; l'écriture du store redéclenche l'effet et la
    // passe suivante synchronise avec les valeurs seedées. Si non (URL nue, store gardé), les
    // valeurs lues SONT à jour → on synchronise dès cette passe.
    let touchedStore = false;
    if (!seededRef.current) {
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
          touchedStore = true;
        }
      }
      // Mémoire de session : l'URL FAIT FOI dès qu'elle porte au moins une clé Consulter
      // (seed complet, clé absente = son défaut — un lien partagé dit vrai) ; sinon (URL nue,
      // ex. retour par l'onglet « Calendrier » vers `/matchs`) on NE TOUCHE PAS l'état
      // Consulter du store — la re-synchro ci-dessous repoussera le store dans l'adresse.
      if (hasConsultParams(searchParams)) {
        const consult = decodeConsultParams(searchParams);
        setConsultKinds(consult.kinds);
        setConsultFamilies(consult.families);
        setConsultTypicalWeek(consult.typicalWeek);
        setConsultAway(consult.away);
        setConsultTemporality(consult.temps);
        setConsultMonth(consult.month);
        setConsultPhaseId(consult.phaseId);
        touchedStore = true;
      }
      // Semaine : ne seede QUE si l'URL la porte ET que le store est à l'auto (jamais
      // clobber une semaine posée par une navigation « Voir la semaine »).
      const weekend = decodeWeekendParam(searchParams);
      if (null !== weekend && null === useMatchesStore.getState().selectedWeekend) {
        setSelectedWeekend(weekend);
        touchedStore = true;
      }
      // Deep-link `match=` : met EN ÉVIDENCE une rencontre précise (« Voir la semaine » depuis
      // Conflits). One-shot — le param est retiré à la re-synchro (`applyMatchToParams(_, null)`
      // ci-dessous) ; la mise en évidence vit ensuite dans le store (`selectedFixtureId`).
      const matchId = decodeMatchParam(searchParams);
      const matchFixture = null === matchId ? undefined : fixtures.data.find((f) => f.id === matchId);
      if (undefined !== matchFixture) {
        setConsultTemporality("semaine");
        // Semaine : seulement si l'URL ne la porte pas déjà (un lien « Voir la semaine » porte
        // les deux — `semaine` fait foi) et que le store est encore à l'auto.
        if (null === weekend && null === useMatchesStore.getState().selectedWeekend) {
          setSelectedWeekend(weekendKeyOf(matchFixture.matchDate));
        }
        setSelectedFixtureId(matchFixture.id);
        // Lève le masque qui cacherait ce match (type de compétition décoché, extérieur masqué)
        // — sinon la cellule visée n'existe pas. Redondant quand l'URL portait déjà la levée
        // (le lien de Conflits l'inclut), inoffensif (`normalizeKinds` dédoublonne).
        const plan = revealPlan([matchFixture], consultKinds ?? DEFAULT_KINDS, competitionsMap);
        if (plan.away) {
          setConsultAway(true);
        }
        if (plan.kinds.length > 0) {
          setConsultKinds(normalizeKinds([...(consultKinds ?? DEFAULT_KINDS), ...plan.kinds]));
        }
        focusFixtureCell(matchFixture.id);
        touchedStore = true;
      }
    }
    if (touchedStore) {
      return;
    }
    // Re-synchro : pousse le store (valeurs à jour) dans l'URL (`replace`), autres params
    // préservés. No-op quand l'adresse reflète déjà le store.
    const withFilter = applyFilterToParams(searchParams, filterMode, filterIds);
    const withConsult = applyConsultToParams(withFilter, {
      kinds: consultKinds,
      families: consultFamilies,
      typicalWeek: consultTypicalWeek,
      away: consultAway,
      temps: consultTemporality,
      month: consultMonth,
      phaseId: consultPhaseId,
    });
    const withWeekend = applyWeekendToParams(withConsult, selectedWeekend);
    // `match=` est TOUJOURS retiré à la re-synchro : c'est un deep-link one-shot consommé au
    // seed (« retiré en replace après sélection »), jamais un état porté par l'adresse.
    const next = applyMatchToParams(withWeekend, null);
    if (next.toString() !== searchParams.toString()) {
      setSearchParams(next, { replace: true });
    }
  }, [teams.data, coaches.data, venues.data, fixtures.data, searchParams, filterMode, filterIds, consultKinds, consultFamilies, consultTypicalWeek, consultAway, consultTemporality, consultMonth, consultPhaseId, selectedWeekend, setFilterMode, toggleFilterId, setConsultKinds, setConsultFamilies, setConsultTypicalWeek, setConsultAway, setConsultTemporality, setConsultMonth, setConsultPhaseId, setSelectedWeekend, setSelectedFixtureId, focusFixtureCell, competitionsMap, setSearchParams]);

  // Ouvrir/fermer la liste « FBI — à faire » synchronise le param `fbi` (l'URL fait foi).
  const openFbiModal = (): void => {
    setFbiModalOpen(true);
    setSearchParams(applyFbiToParams(searchParams, true), { replace: true });
  };
  const closeFbiModal = (): void => {
    setFbiModalOpen(false);
    setSearchParams(applyFbiToParams(searchParams, false), { replace: true });
  };

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
    focusFixtureCell(fixture.id);
  };

  // A5 — lève SEULEMENT les masques qui cachent quelque chose sur la semaine affichée :
  // l'interrupteur Extérieurs si des extérieurs sont masqués, et coche les types concernés ;
  // puis rend le focus à la grille et remplit la région live.
  const revealHiddenWeek = (): void => {
    if (weekHiddenBreakdown.away > 0) {
      setConsultAway(true);
    }
    const hiddenKinds = KINDS.filter((k) => (weekHiddenBreakdown.byKind.get(k) ?? 0) > 0);
    if (hiddenKinds.length > 0) {
      setConsultKinds(normalizeKinds([...effectiveKinds, ...hiddenKinds]));
    }
    const total = weekHiddenBreakdown.total;
    setRevealLive(`${total} match${total > 1 ? "s" : ""} affiché${total > 1 ? "s" : ""}`);
    requestAnimationFrame(() => document.getElementById(GRID_CONTAINER_ID)?.focus());
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
      {/* A5 — annonce sr-only : vide au montage, remplie après la levée des masques. */}
      <p className="sr-only" aria-live="polite">
        {revealLive}
      </p>
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
          <WeekCounters unplaced={weekCounts.unplaced} conflicts={weekCounts.conflicts} fbiTodo={fbiTodoCount} onScrollToPlace={scrollToPlace} onOpenFbi={openFbiModal} />
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
            <div className="flex flex-col gap-2">
              <EmptyState
                icon={CalendarX2}
                title={filterActive ? `Aucun match pour ${filterLabel} cette semaine` : "Aucun match cette semaine"}
                description={
                  weekHiddenBreakdown.total > 0
                    ? "Aucune rencontre avec les filtres actuels."
                    : "Aucune rencontre sur la semaine affichée. Changez de semaine ou ajustez le filtre."
                }
              />
              <HiddenMatchesWeekNotice breakdown={weekHiddenBreakdown} onReveal={revealHiddenWeek} />
            </div>
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
              guards={placementGuards}
              habits={habits}
              rotations={rotations}
              coachRoles={coachTeamRoles}
              resolvedTeamWindows={resolvedTeamWindows}
              windows={windows}
              outOfEnvelope={outOfEnvelope}
              matchDurations={matchDurations}
              newFingerprints={newConflictFingerprints}
              showGhosts={consultTypicalWeek}
              hiddenBreakdown={weekHiddenBreakdown}
              onRevealHidden={revealHiddenWeek}
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
          label="FBI — à faire"
          title="FBI — à faire"
          size="lg"
          onClose={closeFbiModal}
          footer={
            <Button variant="outline" size="sm" onClick={closeFbiModal}>
              Fermer
            </Button>
          }
        >
          <p className="mb-3 text-sm text-muted-foreground">Tout ce qui reste à reporter dans FBI, toutes semaines, par date de match.</p>
          <FbiEntryList
            fixtures={allFixtures}
            corrections={openCorrections}
            teams={teamsMap}
            venues={venuesMap}
            competitions={competitionsMap}
            today={todayISO()}
            busy={submitFixture.isPending || closeFbiCorrection.isPending || reopenFbiCorrection.isPending}
            onSubmit={(fixture) => submitFixture.mutate(fixture, { onSuccess: () => toast.success("Match marqué saisi dans FBI") })}
            onCorrected={(entries) => {
              for (const entry of entries) {
                closeFbiCorrection.mutate(entry.id);
              }
              toast.success("Correction marquée faite dans FBI");
            }}
            onUndoCorrected={(entries) => {
              for (const entry of entries) {
                reopenFbiCorrection.mutate(entry.id);
              }
            }}
          />
        </Modal>
      ) : null}
    </div>
  );
}
