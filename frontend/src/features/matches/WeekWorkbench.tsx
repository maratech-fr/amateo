import { Info, MousePointerClick } from "lucide-react";
import { useEffect, useMemo } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { toast } from "@/shared/stores/toastStore";

import type { Category, Coach, Conflict, Fixture, LeagueWindow, MatchSlotRotation, OpponentTravel, Team, TeamMatchHabit, Venue, VenueMatchWindow, VenueUnavailability } from "./api";
import { AwayList } from "./AwayList";
import { ConflictRadar } from "./ConflictRadar";
import { resolveEnvelope } from "./lib/envelope";
import { offModelCount, sameWeekendRotationCount } from "./lib/loopSteps";
import type { CoachTeamRole } from "./lib/matchFilter";
import { isPlacedOnGrid, weekendKeyOf } from "./lib/weekendGrid";
import { buildWeekendGrid } from "./lib/weekendGrid";
import { PlacementPanel } from "./PlacementPanel";
import {
  useDeleteFixture,
  useLockFixture,
  useMoveFixture,
  usePlaceFixture,
  useReopenFixture,
  useSubmitFixture,
  useSwapFixtures,
  useUnlockFixture,
  useUnplaceFixture,
} from "./queries";
import { useMatchesStore } from "./store";
import { HiddenHomesWeekNotice, UnpairedVenueLabelsBanner } from "./UnpairedVenueLabelsBanner";
import { UnplacedList } from "./UnplacedList";
import { WeekendGrid } from "./WeekendGrid";

/** L'id du `<h2>` « À placer » — cible du focus quand la barre `WeekCounters` renvoie à la liste. */
export const PLACE_HEADING_ID = "matches-place-heading";

interface WeekWorkbenchProps {
  /** Clé samedi de la semaine affichée (jamais null : la page borne l'appel). */
  activeWeekend: string;
  /** Rencontres de la semaine affichée (déjà bucketées + filtrées par la page). */
  weekendFixtures: Fixture[];
  /** Toutes les rencontres filtrées (PR-1) — la liste « À placer » couvre TOUTES les semaines (P4-197). */
  filteredFixtures: Fixture[];
  /** Toutes les rencontres (lookup panneau/échange, hors filtre). */
  allFixtures: Fixture[];
  /** Conflits de la semaine, filtrés par famille (chaîne Consulter) — le radar. */
  radarConflicts: Conflict[];
  /** Le radar n'est rendu qu'une fois les conflits chargés (jamais un faux « aucun clash »). */
  radarLoaded: boolean;
  /** Le plan de saison ne pointe plus de version → les conflits d'entraînement ne sont pas évalués. */
  seasonPlanChosen: boolean | undefined;
  /** La lecture des conflits a échoué (rien en cache). */
  conflictsError: boolean;
  teamsMap: Map<string, Team>;
  venuesMap: Map<string, Venue>;
  categoriesMap: Map<string, Category>;
  coachesMap: Map<string, Coach>;
  venues: Venue[];
  matchWindows: VenueMatchWindow[];
  unavailabilities: VenueUnavailability[];
  habits: TeamMatchHabit[];
  rotations: MatchSlotRotation[];
  opponentTravel: OpponentTravel[];
  coachRoles?: Map<string, CoachTeamRole>;
  resolvedTeamWindows: Record<string, string[]>;
  windows: LeagueWindow[];
  outOfEnvelope: Set<string>;
  matchDurations: Map<string, number>;
  newFingerprints: ReadonlySet<string>;
  /** « Semaine type » (interrupteur de la page) : les fantômes d'habitude sur la grille. */
  showGhosts: boolean;
  /** Le crayon d'un extérieur / du panneau ouvre le dialogue d'édition (porté par la page). */
  onEditFixture: (fixture: Fixture) => void;
}

/**
 * PR 3b — l'ÉTABLI de la semaine du Calendrier : liste « À placer » + panneau de
 * placement permanent + grille week-end (colonne Extérieur, lot 3 PR-3a) + bande des
 * extérieurs + radar EN DERNIER. Extrait tel quel de l'ancien onglet Semaine
 * (`MatchesPage`), la boucle de placement conservée trait pour trait (échange,
 * verrou, dé-placement, saisie FBI) — seul le rail a disparu au profit des
 * `WeekCounters` portés par la page. Suivi P4-197 : « Placer » recadre la semaine sur
 * le match et lui rend le focus.
 */
export function WeekWorkbench(props: WeekWorkbenchProps) {
  const {
    activeWeekend,
    weekendFixtures,
    filteredFixtures,
    allFixtures,
    radarConflicts,
    radarLoaded,
    seasonPlanChosen,
    conflictsError,
    teamsMap,
    venuesMap,
    categoriesMap,
    coachesMap,
    venues,
    matchWindows,
    unavailabilities,
    habits,
    rotations,
    opponentTravel,
    coachRoles,
    resolvedTeamWindows,
    windows,
    outOfEnvelope,
    matchDurations,
    newFingerprints,
    showGhosts,
    onEditFixture,
  } = props;

  const { selectedFixtureId, setSelectedFixtureId, swapSourceId, setSwapSourceId, unplacedReasons, setSelectedWeekend } = useMatchesStore();

  const placeFixture = usePlaceFixture();
  const moveFixture = useMoveFixture();
  const unplaceFixture = useUnplaceFixture();
  const lockFixture = useLockFixture();
  const unlockFixture = useUnlockFixture();
  const deleteFixture = useDeleteFixture();
  const swapFixtures = useSwapFixtures();
  const submitFixture = useSubmitFixture();
  const reopenFixture = useReopenFixture();

  const mutating =
    placeFixture.isPending ||
    moveFixture.isPending ||
    unplaceFixture.isPending ||
    lockFixture.isPending ||
    unlockFixture.isPending ||
    deleteFixture.isPending ||
    swapFixtures.isPending ||
    submitFixture.isPending ||
    reopenFixture.isPending;

  const grid = useMemo(
    () => buildWeekendGrid(weekendFixtures, venuesMap, teamsMap, outOfEnvelope, habits, activeWeekend, 15, matchDurations, opponentTravel, showGhosts),
    [weekendFixtures, venuesMap, teamsMap, outOfEnvelope, habits, activeWeekend, matchDurations, opponentTravel, showGhosts],
  );

  const selectedFixture = allFixtures.find((f) => f.id === selectedFixtureId) ?? null;
  const selectedEnvelope = useMemo(
    () => (null === selectedFixture ? null : resolveEnvelope(selectedFixture, resolvedTeamWindows, windows)),
    [selectedFixture, resolvedTeamWindows, windows],
  );
  const swapSource = allFixtures.find((f) => f.id === swapSourceId) ?? null;

  const swapCandidateIds = useMemo<Set<string> | null>(() => {
    if (null === swapSourceId) {
      return null;
    }
    return new Set(weekendFixtures.filter((f) => isPlacedOnGrid(f) && "PLACED" === f.status && f.id !== swapSourceId).map((f) => f.id));
  }, [swapSourceId, weekendFixtures]);

  // Échap sort du mode échange (WCAG 2.1.2) ; le bandeau et le style des cellules retombent.
  useEffect(() => {
    if (null === swapSourceId) {
      return;
    }
    const onKey = (event: KeyboardEvent): void => {
      if ("Escape" === event.key) {
        setSwapSourceId(null);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [swapSourceId, setSwapSourceId]);

  const offModel = useMemo(() => offModelCount(weekendFixtures, habits, rotations), [weekendFixtures, habits, rotations]);
  const sameWeekendRotations = useMemo(() => sameWeekendRotationCount(weekendFixtures, rotations), [weekendFixtures, rotations]);

  // Suivi P4-197 — après « Placer », recadre la semaine sur le match et lui rend le focus
  // (repli : le `<h2>` « À placer » quand la cellule n'est pas sur la grille, ex. sans gymnase).
  function focusPlaced(fixtureId: string): void {
    requestAnimationFrame(() => {
      const cell = document.querySelector<HTMLElement>(`[data-fixture-id="${fixtureId}"]`);
      if (null !== cell) {
        cell.focus();
        // `scrollIntoView` n'existe pas sous jsdom (aucun moteur de layout) — appel gardé.
        cell.scrollIntoView?.({ block: "nearest" });
        return;
      }
      const heading = document.getElementById(PLACE_HEADING_ID);
      heading?.focus();
    });
  }

  function onGridSelect(fixtureId: string): void {
    const clicked = allFixtures.find((f) => f.id === fixtureId) ?? null;
    if (null !== swapSource) {
      if (null !== clicked && "AWAY" === clicked.homeAway) {
        return; // un extérieur est inerte en mode échange
      }
      if (fixtureId === swapSource.id) {
        setSwapSourceId(null);
        return;
      }
      const target = allFixtures.find((f) => f.id === fixtureId) ?? null;
      if (null === target || "PLACED" !== target.status) {
        return;
      }
      swapFixtures.mutate(
        { a: swapSource, b: target },
        { onSuccess: () => toast.success("Placements échangés (gymnase + heure — les dates ne bougent pas)") },
      );
      setSwapSourceId(null);
      setSelectedFixtureId(null);
      return;
    }
    if (null !== clicked && "AWAY" === clicked.homeAway) {
      onEditFixture(clicked);
      return;
    }
    setSelectedFixtureId(fixtureId);
  }

  const panelBlock =
    null !== selectedFixture && null !== selectedEnvelope && "HOME" === selectedFixture.homeAway ? (
      <PlacementPanel
        key={selectedFixture.id}
        fixture={selectedFixture}
        venues={venues}
        matchWindows={matchWindows}
        unavailabilities={unavailabilities}
        habits={habits}
        teamLabel={teamsMap.get(selectedFixture.teamId)?.name ?? "Équipe ?"}
        categoryLabel={categoriesMap.get(teamsMap.get(selectedFixture.teamId)?.sportCategoryId ?? "")?.name ?? "—"}
        envelope={selectedEnvelope}
        busy={mutating}
        onClose={() => setSelectedFixtureId(null)}
        onPlace={(input) => {
          const mutation = "PLACED" === selectedFixture.status ? moveFixture : placeFixture;
          mutation.mutate(
            { fixture: selectedFixture, input },
            {
              onSuccess: () => {
                setSelectedFixtureId(null);
                setSelectedWeekend(weekendKeyOf(selectedFixture.matchDate));
                focusPlaced(selectedFixture.id);
                const venueName = null === input.venueId ? "" : venuesMap.get(input.venueId)?.name ?? "";
                toast.success(`Match placé — ${frDateWeekdayNoYear(selectedFixture.matchDate)} ${input.kickoffTime}${"" !== venueName ? `, ${venueName}` : ""}`);
              },
            },
          );
        }}
        onUnplace={() => unplaceFixture.mutate(selectedFixture)}
        onToggleLock={() => {
          const mutation = "SOLVER" === selectedFixture.placementSource ? lockFixture : unlockFixture;
          mutation.mutate(selectedFixture);
        }}
        onStartSwap={() => setSwapSourceId(selectedFixture.id)}
        onEdit={() => onEditFixture(selectedFixture)}
        onDelete={() => deleteFixture.mutate(selectedFixture.id, { onSuccess: () => setSelectedFixtureId(null) })}
        onSubmit={() => submitFixture.mutate(selectedFixture, { onSuccess: () => toast.success("Match marqué saisi dans FBI") })}
        onReopen={() => reopenFixture.mutate(selectedFixture)}
      />
    ) : null;

  // Slot du panneau PERMANENT à `lg:` (fin du saut de colonne). Le placeholder est masqué
  // sous `lg:` (une carte vide y volerait tout l'écran) ; le panneau réel, lui, s'affiche partout.
  const panelSlot = panelBlock ?? (
    <Card className="hidden border-dashed lg:flex">
      <CardContent className="flex min-h-32 flex-col items-center justify-center gap-2 py-8 text-center text-sm text-muted-foreground">
        <MousePointerClick className="size-6 opacity-60" />
        <span className="font-medium text-foreground">Sélectionnez un match</span>
        <span>Choisissez un domicile sur la grille ou dans la liste pour le placer.</span>
      </CardContent>
    </Card>
  );

  const swapBanner =
    null !== swapSource ? (
      <p className="flex items-center justify-between gap-2 rounded-md border border-accent/50 bg-accent/10 px-3 py-2 text-sm">
        <span>
          Échange : cliquez le match à échanger avec <strong>{teamsMap.get(swapSource.teamId)?.name ?? "?"}</strong> (gymnase + heure — les dates ne bougent pas).
        </span>
        <Button variant="outline" size="sm" onClick={() => setSwapSourceId(null)}>
          Annuler
        </Button>
      </p>
    ) : null;

  const hiddenHomesThisWeek = weekendFixtures.filter((f) => "HOME" === f.homeAway && null === f.venueId).length;

  const conflictErrorBlock =
    false === seasonPlanChosen ? (
      <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-sm text-foreground">
        Le planning de la saison n'est plus validé — les conflits avec les entraînements ne sont pas évalués.
      </p>
    ) : conflictsError ? (
      <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-sm text-foreground">
        Les conflits n'ont pas pu être vérifiés — rechargez la page avant de placer un match.
      </p>
    ) : null;

  const offModelBadge =
    offModel > 0 ? (
      <StatusPill className="w-fit" icon={<Info className="size-3.5" aria-hidden="true" />}>
        {offModel} match{offModel > 1 ? "s" : ""} hors modèle
      </StatusPill>
    ) : null;

  const sameWeekendBadge =
    sameWeekendRotations > 0 ? (
      <StatusPill className="w-fit" icon={<Info className="size-3.5" aria-hidden="true" />}>
        {sameWeekendRotations} créneau{sameWeekendRotations > 1 ? "x" : ""} partagé{sameWeekendRotations > 1 ? "s" : ""} : deux équipes reçoivent ce week-end
      </StatusPill>
    ) : null;

  return (
    // La colonne de droite DOIT défiler elle-même (colonne Extérieur large) : `minmax(0,1fr)`
    // borne la piste, la grille défile en interne, jamais la page.
    <div className="grid gap-4 lg:grid-cols-[minmax(18rem,20rem)_minmax(0,1fr)]">
      <div className="flex flex-col gap-4">
        <Card>
          <CardHeader>
            <CardTitle id={PLACE_HEADING_ID} tabIndex={-1} className="text-base outline-none">
              À placer
            </CardTitle>
          </CardHeader>
          <CardContent>
            <UnplacedList fixtures={filteredFixtures} teams={teamsMap} selectedFixtureId={selectedFixtureId} unplacedReasons={unplacedReasons} coachRoles={coachRoles} onSelect={setSelectedFixtureId} />
          </CardContent>
        </Card>
        {panelSlot}
      </div>
      <div className="flex min-w-0 flex-col gap-2">
        {offModelBadge}
        {sameWeekendBadge}
        {swapBanner}
        {conflictErrorBlock}
        <div className="flex flex-col gap-2">
          <UnpairedVenueLabelsBanner />
          <div className="h-[32rem]">
            <WeekendGrid model={grid} onSelectFixture={onGridSelect} selectedFixtureId={swapSourceId ?? selectedFixtureId} swapCandidateIds={swapCandidateIds} />
          </div>
          <HiddenHomesWeekNotice count={hiddenHomesThisWeek} />
        </div>
        <AwayList fixtures={weekendFixtures} teams={teamsMap} habits={habits} travel={opponentTravel} coachRoles={coachRoles} onEdit={onEditFixture} onDelete={(fixture) => deleteFixture.mutate(fixture.id)} />
        {radarLoaded ? <ConflictRadar conflicts={radarConflicts} teams={teamsMap} coaches={coachesMap} newFingerprints={newFingerprints} /> : null}
      </div>
    </div>
  );
}
