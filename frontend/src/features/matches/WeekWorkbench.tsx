import { Info, MousePointerClick } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { toast } from "@/shared/stores/toastStore";

import type { Category, Coach, Conflict, Fixture, LeagueWindow, MatchSlotRotation, Team, TeamMatchHabit, Venue } from "./api";
import { AwayFixtureCard } from "./AwayFixtureCard";
import { AwayList } from "./AwayList";
import { ConflictFocusBanner } from "./ConflictFocusBanner";
import { ConflictRadar } from "./ConflictRadar";
import { isEditableAway } from "./lib/fixtureOrigin";
import type { HiddenWeekBreakdown } from "./lib/consultFilter";
import { resolveEnvelope } from "./lib/envelope";
import { offModelCount, sameWeekendRotationCount } from "./lib/loopSteps";
import type { CoachTeamRole } from "./lib/matchFilter";
import { isPlacedOnGrid, weekendKeyOf } from "./lib/weekendGrid";
import { buildWeekendGrid } from "./lib/weekendGrid";
import { PlacementPanel } from "./PlacementPanel";
import type { PlacementGuards } from "./PlacementPanel";
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
import { HiddenHomesWeekNotice, HiddenMatchesWeekNotice, UnpairedVenueLabelsBanner } from "./UnpairedVenueLabelsBanner";
import { UnplacedList } from "./UnplacedList";
import { WeekendGrid } from "./WeekendGrid";
import { WeekendGridLegend } from "./WeekendGridLegend";

/** L'id du `<h2>` « À placer » — cible du focus quand la barre `WeekCounters` renvoie à la liste. */
export const PLACE_HEADING_ID = "matches-place-heading";

/** L'id du conteneur de la grille — cible du focus après la levée des masques (A5). */
export const GRID_CONTAINER_ID = "matches-week-grid";

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
  /** D2 — les gardes du placement (accès match + indisponibilités) + leur état de lecture. */
  guards: PlacementGuards;
  habits: TeamMatchHabit[];
  rotations: MatchSlotRotation[];
  coachRoles?: Map<string, CoachTeamRole>;
  resolvedTeamWindows: Record<string, string[]>;
  windows: LeagueWindow[];
  outOfEnvelope: Set<string>;
  matchDurations: Map<string, number>;
  newFingerprints: ReadonlySet<string>;
  /** « Semaine type » (interrupteur de la page) : les fantômes d'habitude sur la grille. */
  showGhosts: boolean;
  /** A5 — décompte des rencontres de la semaine masquées par les Types/l'interrupteur Extérieurs. */
  hiddenBreakdown: HiddenWeekBreakdown;
  /** A5 — lève les masques de la semaine + focalise la grille + remplit la région live (page). */
  onRevealHidden: () => void;
  /** Le crayon d'un extérieur / du panneau ouvre le dialogue d'édition (porté par la page). */
  onEditFixture: (fixture: Fixture) => void;
  /** Correctif 2 — le conflit FOCALISÉ (bandeau en tête de semaine), ou null. Calculé par la page. */
  focusedConflict: Conflict | null;
  /** Correctif 2 — « Voir » dans le radar focalise CE conflit (filtre coach + surbrillance). */
  onFocusConflict: (conflict: Conflict) => void;
  /** Correctif 2 — « Quitter le focus » : retire le filtre coach + la surbrillance (URL nettoyée). */
  onQuitFocus: () => void;
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
    guards,
    habits,
    rotations,
    coachRoles,
    resolvedTeamWindows,
    windows,
    outOfEnvelope,
    matchDurations,
    newFingerprints,
    showGhosts,
    hiddenBreakdown,
    onRevealHidden,
    onEditFixture,
    focusedConflict,
    onFocusConflict,
    onQuitFocus,
  } = props;

  const { selectedFixtureId, setSelectedFixtureId, highlightedFixtureIds, swapSourceId, setSwapSourceId, unplacedReasons, setSelectedWeekend } = useMatchesStore();

  // Correctif 3 — un extérieur IMPORTÉ cliqué s'ouvre en fiche LECTURE SEULE (pas d'édition).
  const [awayReadOnly, setAwayReadOnly] = useState<Fixture | null>(null);

  const highlightedSet = useMemo(() => new Set(highlightedFixtureIds), [highlightedFixtureIds]);

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
    () => buildWeekendGrid(weekendFixtures, venuesMap, teamsMap, outOfEnvelope, habits, activeWeekend, 15, matchDurations, showGhosts),
    [weekendFixtures, venuesMap, teamsMap, outOfEnvelope, habits, activeWeekend, matchDurations, showGhosts],
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
      openAway(clicked);
      return;
    }
    setSelectedFixtureId(fixtureId);
  }

  // Correctif 3 / 3b — un extérieur SAISI À LA MAIN reste éditable ; un extérieur IMPORTÉ
  // (FBI/FFBB) s'ouvre en LECTURE SEULE (la fédération en est la source). MÊME logique pour le
  // clic grille ET le crayon de la bande « À l'extérieur » : une seule maison, jamais deux chemins.
  function openAway(fixture: Fixture): void {
    if (isEditableAway(fixture)) {
      onEditFixture(fixture);
    } else {
      setAwayReadOnly(fixture);
    }
  }

  const panelBlock =
    null !== selectedFixture && null !== selectedEnvelope && "HOME" === selectedFixture.homeAway ? (
      <PlacementPanel
        key={selectedFixture.id}
        fixture={selectedFixture}
        venues={venues}
        guards={guards}
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
      <p className="flex items-center justify-between gap-2 rounded-md border border-accent/50 bg-surface-accent px-3 py-2 text-sm">
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
      <NoticeBanner tone="destructive" message="Le planning de la saison n'est plus validé — les conflits avec les entraînements ne sont pas évalués." />
    ) : conflictsError ? (
      <NoticeBanner tone="destructive" message="Les conflits n'ont pas pu être vérifiés — rechargez la page avant de placer un match." />
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
        {/* Correctif 2 — bandeau de focus d'un conflit, en tête de la semaine. */}
        {null !== focusedConflict ? (
          <ConflictFocusBanner conflict={focusedConflict} teams={teamsMap} coaches={coachesMap} venues={venuesMap} onQuit={onQuitFocus} />
        ) : null}
        {offModelBadge}
        {sameWeekendBadge}
        {swapBanner}
        {conflictErrorBlock}
        <div className="flex flex-col gap-2">
          <UnpairedVenueLabelsBanner />
          <div id={GRID_CONTAINER_ID} tabIndex={-1} className="h-[32rem] outline-none">
            <WeekendGrid model={grid} onSelectFixture={onGridSelect} selectedFixtureId={swapSourceId ?? selectedFixtureId} swapCandidateIds={swapCandidateIds} highlightedFixtureIds={highlightedSet} />
          </div>
          <HiddenHomesWeekNotice count={hiddenHomesThisWeek} />
          <HiddenMatchesWeekNotice breakdown={hiddenBreakdown} onReveal={onRevealHidden} />
          {/* Légende conditionnelle : « à confirmer » (cases hachurées) et « Habitude »
              (fantômes pointillés, seulement quand « Semaine type » est active). */}
          <WeekendGridLegend
            toConfirmCount={grid.cells.filter((c) => c.toConfirm).length}
            showHabits={showGhosts && grid.cells.some((c) => c.ghost)}
            showTravel={grid.cells.some((c) => true === c.hasTravel)}
          />
        </div>
        <AwayList fixtures={weekendFixtures} teams={teamsMap} habits={habits} coachRoles={coachRoles} onEdit={openAway} onDelete={(fixture) => deleteFixture.mutate(fixture.id)} />
        {radarLoaded ? <ConflictRadar conflicts={radarConflicts} teams={teamsMap} coaches={coachesMap} venues={venuesMap} newFingerprints={newFingerprints} onFocusConflict={onFocusConflict} /> : null}
      </div>
      {null !== awayReadOnly ? (
        <AwayFixtureCard fixture={awayReadOnly} teams={teamsMap} habits={habits} matchDurations={matchDurations} onClose={() => setAwayReadOnly(null)} />
      ) : null}
    </div>
  );
}
