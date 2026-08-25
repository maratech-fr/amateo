import { ChevronLeft, ChevronRight, Info, MousePointerClick, Plus, Upload, Wand2 } from "lucide-react";
import { type ReactNode, useEffect, useMemo, useState } from "react";

import { FeedbackButton } from "@/features/feedback/FeedbackButton";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { StepRail } from "@/shared/components/ui/step-rail";

import type { Category, Coach, Fixture, Team, Venue } from "./api";
import { AwayList } from "./AwayList";
import { ConflictRadar } from "./ConflictRadar";
import { FbiEntryList } from "./FbiEntryList";
import { FixtureFormDialog } from "./FixtureFormDialog";
import { ImportFbiDialog } from "./ImportFbiDialog";
import { isInEnvelope, resolveEnvelope } from "./lib/envelope";
import { depositDaysAgo, relativeDepositLabel } from "./lib/fbiFreshness";
import { datelessConflicts, defaultLoopStep, deriveLoopSteps, offModelCount, sameWeekendRotationCount } from "./lib/loopSteps";
import { todayISO } from "@/shared/lib/clock";
import { ModuleVisitBanner } from "./ModuleVisitBanner";
import { placementToastMessage } from "./lib/placementToast";
import { buildWeekendGrid, isPlacedOnGrid, listWeekends, weekendKeyOf, weekLabel } from "./lib/weekendGrid";
import { PlacementPanel } from "./PlacementPanel";
import { useCategories, useCoaches, useCompetitions, useConflicts, useDeleteFixture, useFixtures, useLatestFbiIngestion, useLeagueWindows, useLockFixture, useMatchSlotRotations, useModuleVisit, useMoveFixture, usePlaceFixture, usePlaceMatches, usePriorityTiers, useReopenFixture, useSubmitFixture, useSwapFixtures, useTeamMatchHabits, useTeams, useUnlockFixture, useUnplaceFixture, useVenueMatchWindows, useVenues, useVenueUnavailabilities } from "./queries";
import { toast } from "@/shared/stores/toastStore";
import { useCredits } from "@/shared/credits/useCredits";
import { useMatchesStore } from "./store";
import { UnplacedList } from "./UnplacedList";
import { WeekendGrid } from "./WeekendGrid";

function byId<T extends { id: string }>(rows: T[] | undefined): Map<string, T> {
  return new Map((rows ?? []).map((row) => [row.id, row]));
}

export function MatchesPage() {
  // §4bis pt 2 — le placement de matchs consomme 1 crédit : solde sur le bouton,
  // désactivé à 0 (Découverte bridée). Si la requête part quand même, le 403
  // serveur remonte via `usePlaceMatches`.onError (errorMessage → toast).
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
  const coaches = useCoaches();
  const matchWindows = useVenueMatchWindows();
  const unavailabilities = useVenueUnavailabilities();
  const habitsQuery = useTeamMatchHabits();
  const rotationsQuery = useMatchSlotRotations();
  const placeFixture = usePlaceFixture();
  const placeMatches = usePlaceMatches();
  const moveFixture = useMoveFixture();
  const unplaceFixture = useUnplaceFixture();
  const lockFixture = useLockFixture();
  const unlockFixture = useUnlockFixture();
  const deleteFixture = useDeleteFixture();
  const swapFixtures = useSwapFixtures();
  const submitFixture = useSubmitFixture();
  const reopenFixture = useReopenFixture();
  // P1-4 PR E1 — fixture whose identity fields are being edited (dialog).
  const [editFixture, setEditFixture] = useState<Fixture | null>(null);

  const {
    selectedWeekend,
    railStep,
    selectedFixtureId,
    unplacedReasons,
    swapSourceId,
    fixtureFormOpen,
    importDialogOpen,
    setSelectedWeekend,
    setRailStep,
    setUnplacedReasons,
    setSelectedFixtureId,
    setSwapSourceId,
    setFixtureFormOpen,
    setImportDialogOpen,
  } = useMatchesStore();

  const teamsMap = useMemo<Map<string, Team>>(() => byId(teams.data), [teams.data]);
  const venuesMap = useMemo<Map<string, Venue>>(() => byId(venues.data), [venues.data]);
  const categoriesMap = useMemo<Map<string, Category>>(() => byId(categories.data), [categories.data]);
  const coachesMap = useMemo<Map<string, Coach>>(() => byId(coaches.data), [coaches.data]);

  const allFixtures = useMemo<Fixture[]>(() => fixtures.data ?? [], [fixtures.data]);
  const windows = useMemo(() => leagueWindows.data?.items ?? [], [leagueWindows.data]);
  // P1-4 PR E2 (dette iv) — the team↔window join is resolved by the SERVER.
  const resolvedTeamWindows = useMemo(() => leagueWindows.data?.resolvedTeamWindows ?? {}, [leagueWindows.data]);
  const habits = useMemo(() => habitsQuery.data ?? [], [habitsQuery.data]);
  const rotations = useMemo(() => rotationsQuery.data ?? [], [rotationsQuery.data]);
  const allConflicts = useMemo(() => conflicts.data?.conflicts ?? [], [conflicts.data]);

  // RMM-3 — le « gardien » : le delta de visite est POSTé au montage du layout ;
  // ici on LIT le même cache (une seule requête pour tout le module). Le bandeau
  // résumé et les chips « Nouveau » du radar en dérivent. Empreintes des conflits
  // neufs depuis la dernière visite — appartenance d'ensemble, pas une redérivation.
  const moduleVisit = useModuleVisit();
  const newConflictFingerprints = useMemo<Set<string>>(() => new Set(moduleVisit.data?.newConflictFingerprints ?? []), [moduleVisit.data]);

  // RMM-4 — la fraîcheur, en rappel DISCRET près du rail (jamais un bandeau).
  const freshness = useLatestFbiIngestion();
  const latestDeposit = freshness.data?.latest ?? null;
  const depositReminder =
    null === latestDeposit ? "Aucun dépôt FBI cette saison" : `Dernier dépôt FBI ${relativeDepositLabel(depositDaysAgo(latestDeposit.depositedAt, todayISO()))}`;

  // Placed home fixtures out of their league envelope (only when the team maps).
  const outOfEnvelope = useMemo<Set<string>>(() => {
    const set = new Set<string>();
    for (const fixture of allFixtures) {
      if (!isPlacedOnGrid(fixture) || null === fixture.kickoffTime) {
        continue;
      }
      const envelope = resolveEnvelope(fixture, resolvedTeamWindows, windows);
      if (envelope.mapped && !isInEnvelope(envelope, fixture.kickoffTime)) {
        set.add(fixture.id);
      }
    }
    return set;
  }, [allFixtures, resolvedTeamWindows, windows]);

  const weekends = useMemo(() => listWeekends(allFixtures), [allFixtures]);
  const activeWeekend = selectedWeekend ?? weekends[0] ?? null;
  const weekendIndex = null === activeWeekend ? -1 : weekends.indexOf(activeWeekend);

  const weekendFixtures = useMemo(
    () => (null === activeWeekend ? [] : allFixtures.filter((f) => weekendKeyOf(f.matchDate) === activeWeekend)),
    [allFixtures, activeWeekend],
  );

  const grid = useMemo(
    () => buildWeekendGrid(weekendFixtures, venuesMap, teamsMap, outOfEnvelope, habits, activeWeekend),
    [weekendFixtures, venuesMap, teamsMap, outOfEnvelope, habits, activeWeekend],
  );

  // RMM-1 PR3 (L3) — les 5 étapes de la boucle, DÉRIVÉES de la semaine affichée
  // (zéro état stocké). La vue courante = `railStep` posé par l'utilisateur, sinon
  // le premier trou recalculé en continu — le rail ne saute jamais SOUS un choix.
  const steps = useMemo(() => deriveLoopSteps({ weekFixtures: weekendFixtures, habits, conflicts: allConflicts }), [weekendFixtures, habits, allConflicts]);
  const effectiveStep = railStep ?? defaultLoopStep(steps);
  const dateless = useMemo(() => datelessConflicts(allConflicts), [allConflicts]);
  const offModel = useMemo(() => offModelCount(weekendFixtures, habits, rotations), [weekendFixtures, habits, rotations]);
  // RMM-5 PR-4 — deux membres d'un même créneau partagé reçoivent le même week-end : signal neutre.
  const sameWeekendRotations = useMemo(() => sameWeekendRotationCount(weekendFixtures, rotations), [weekendFixtures, rotations]);

  const selectedFixture = allFixtures.find((f) => f.id === selectedFixtureId) ?? null;
  const selectedEnvelope = useMemo(
    () => (null === selectedFixture ? null : resolveEnvelope(selectedFixture, resolvedTeamWindows, windows)),
    [selectedFixture, resolvedTeamWindows, windows],
  );

  const swapSource = allFixtures.find((f) => f.id === swapSourceId) ?? null;

  // RMM-1 PR4 (L6) — le mode échange se VOIT sur la grille : les candidates sont
  // les AUTRES domiciles placés (PLACED, jamais la source elle-même — mêmes cibles
  // qu'accepte `onGridSelect`). `null` hors mode → aucune cellule estompée.
  const swapCandidateIds = useMemo<Set<string> | null>(() => {
    if (null === swapSourceId) {
      return null;
    }
    return new Set(weekendFixtures.filter((f) => isPlacedOnGrid(f) && "PLACED" === f.status && f.id !== swapSourceId).map((f) => f.id));
  }, [swapSourceId, weekendFixtures]);

  // RMM-1 PR4 (L6) — Échap sort du mode échange (WCAG 2.1.2 escape-route) ; le
  // bandeau et le style des cellules retombent avec `swapSourceId`.
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

  // P1-4 PR E1 — a grid click either picks the swap partner (swap mode) or opens
  // the panel of the clicked match.
  function onGridSelect(fixtureId: string): void {
    if (null !== swapSource) {
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
        {
          onSuccess: () => toast.success("Placements échangés (gymnase + heure — les dates ne bougent pas)"),
        },
      );
      setSwapSourceId(null);
      setSelectedFixtureId(null);
      return;
    }
    setSelectedFixtureId(fixtureId);
  }

  if (fixtures.isLoading || teams.isLoading || venues.isLoading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  // ── Shared blocks reused across the step views ─────────────────────────────
  const panelBlock =
    null !== selectedFixture && null !== selectedEnvelope && "HOME" === selectedFixture.homeAway ? (
      <PlacementPanel
        key={selectedFixture.id}
        fixture={selectedFixture}
        venues={venues.data ?? []}
        matchWindows={matchWindows.data ?? []}
        unavailabilities={unavailabilities.data ?? []}
        habits={habits}
        teamLabel={teamsMap.get(selectedFixture.teamId)?.name ?? "Équipe ?"}
        categoryLabel={categoriesMap.get(teamsMap.get(selectedFixture.teamId)?.sportCategoryId ?? "")?.name ?? "—"}
        envelope={selectedEnvelope}
        busy={mutating}
        onClose={() => setSelectedFixtureId(null)}
        onPlace={(input) => {
          const mutation = "PLACED" === selectedFixture.status ? moveFixture : placeFixture;
          mutation.mutate({ fixture: selectedFixture, input }, { onSuccess: () => setSelectedFixtureId(null) });
        }}
        onUnplace={() => unplaceFixture.mutate(selectedFixture)}
        onToggleLock={() => {
          const mutation = "SOLVER" === selectedFixture.placementSource ? lockFixture : unlockFixture;
          mutation.mutate(selectedFixture);
        }}
        onStartSwap={() => setSwapSourceId(selectedFixture.id)}
        onEdit={() => setEditFixture(selectedFixture)}
        onDelete={() => deleteFixture.mutate(selectedFixture.id, { onSuccess: () => setSelectedFixtureId(null) })}
        onSubmit={() => submitFixture.mutate(selectedFixture, { onSuccess: () => toast.success("Match marqué saisi dans FBI") })}
        onReopen={() => reopenFixture.mutate(selectedFixture)}
      />
    ) : null;

  // RMM-1 PR4 (L6) — le slot du panneau est PERMANENT : quand rien n'est
  // sélectionné, un état vide occupe la place (fin du saut de colonne). Le
  // panneau réel le remplace dès qu'un domicile est choisi.
  const panelSlot = panelBlock ?? (
    <Card className="border-dashed">
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

  const gridBlock = (
    <div className="h-[32rem]">
      <WeekendGrid model={grid} onSelectFixture={onGridSelect} selectedFixtureId={swapSourceId ?? selectedFixtureId} swapCandidateIds={swapCandidateIds} />
    </div>
  );

  const awayBlock = (
    <AwayList fixtures={weekendFixtures} teams={teamsMap} habits={habits} onEdit={setEditFixture} onDelete={(fixture) => deleteFixture.mutate(fixture.id)} />
  );

  // Trois états, jamais deux : « pas de conflit » ne doit pas se confondre avec
  // « je n'ai pas pu regarder » (même défense que RadarPanel).
  const conflictErrorBlock =
    false === conflicts.data?.seasonPlanChosen ? (
      <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-sm text-foreground">
        Le planning de la saison n'est plus validé — les conflits avec les entraînements ne sont pas évalués.
      </p>
    ) : conflicts.isError ? (
      <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-sm text-foreground">
        Les conflits n'ont pas pu être vérifiés — rechargez la page avant de placer un match.
      </p>
    ) : null;

  // RMM-1 PR3 — l'écart au modèle : un SIGNAL neutre, PAS une erreur (ni rouge, ni
  // triangle — distinct de « hors fenêtre ligue »). « c'est un signal, c'est pas
  // bloquant » (verbatim fondateur).
  const offModelBadge =
    offModel > 0 ? (
      <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground">
        <Info className="size-3.5" />
        {offModel} match{offModel > 1 ? "s" : ""} hors modèle
      </span>
    ) : null;

  // RMM-5 PR-4 — le signal « même week-end » : deux équipes d'un créneau partagé
  // reçoivent le même week-end (l'alternance dit une seule). Pilule NEUTRE, jamais
  // une erreur — cohérente avec l'écart au modèle (« c'est un signal, pas bloquant »).
  const sameWeekendBadge =
    sameWeekendRotations > 0 ? (
      <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground">
        <Info className="size-3.5" />
        {sameWeekendRotations} créneau{sameWeekendRotations > 1 ? "x" : ""} partagé{sameWeekendRotations > 1 ? "s" : ""} : deux équipes reçoivent ce week-end
      </span>
    ) : null;

  // ── The current step's VIEW (rail⇄vue : filtres de la même page) ────────────
  let content: ReactNode;
  if ("batch" === effectiveStep) {
    const home = weekendFixtures.filter((f) => "HOME" === f.homeAway).length;
    const away = weekendFixtures.length - home;
    content = (
      <div className="flex flex-col gap-4">
        <Button size="sm" className="w-fit" onClick={() => setImportDialogOpen(true)}>
          <Upload className="size-4" />
          Importer FBI
        </Button>
        {0 === weekendFixtures.length ? (
          <EmptyState icon={Upload} title="Aucun match importé" description="Importez votre fichier FBI pour commencer la semaine." />
        ) : (
          <p className="text-sm text-muted-foreground">
            {home} domicile{home > 1 ? "s" : ""} · {away} extérieur{away > 1 ? "s" : ""} importés cette semaine.
          </p>
        )}
      </div>
    );
  } else if ("model" === effectiveStep) {
    content = (
      <div className="flex flex-col gap-3">
        {offModelBadge}
        {sameWeekendBadge}
        {panelSlot}
        {swapBanner}
        {gridBlock}
        {awayBlock}
      </div>
    );
  } else if ("disputes" === effectiveStep) {
    content = (
      <div className="flex flex-col gap-3">
        {conflictErrorBlock}
        {undefined === conflicts.data ? null : <ConflictRadar conflicts={conflicts.data.conflicts} teams={teamsMap} coaches={coachesMap} newFingerprints={newConflictFingerprints} />}
      </div>
    );
  } else if ("fbiEntry" === effectiveStep) {
    content = (
      <Card>
        <CardHeader>
          <CardTitle className="text-base">À recopier dans FBI</CardTitle>
        </CardHeader>
        <CardContent>
          <FbiEntryList
            fixtures={weekendFixtures}
            teams={teamsMap}
            venues={venuesMap}
            busy={mutating}
            onSubmit={(fixture) => submitFixture.mutate(fixture, { onSuccess: () => toast.success("Match marqué saisi dans FBI") })}
            onReopen={(fixture) => reopenFixture.mutate(fixture)}
          />
        </CardContent>
      </Card>
    );
  } else {
    // "homeSlots" — placer les domiciles : liste à placer + panel + grille.
    content = (
      <div className="flex flex-col gap-4">
        <Button
          size="sm"
          className="w-fit"
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
        <div className="grid gap-4 lg:grid-cols-[minmax(18rem,22rem)_1fr]">
          <div className="flex flex-col gap-4">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">À placer</CardTitle>
              </CardHeader>
              <CardContent>
                <UnplacedList fixtures={allFixtures} teams={teamsMap} selectedFixtureId={selectedFixtureId} unplacedReasons={unplacedReasons} onSelect={setSelectedFixtureId} />
              </CardContent>
            </Card>
            {panelSlot}
          </div>
          <div className="flex flex-col gap-2">
            {swapBanner}
            {gridBlock}
            {awayBlock}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {/* Barre utilitaire réduite : le geste rare « Nouveau match » ; l'action
          PRIMAIRE vit dans la vue de l'étape courante (L5), pas ici. */}
      <div className="flex flex-wrap items-center justify-end gap-2">
        {/* P5-6 — porte contextuelle (pas de scheduleId sur cet écran). */}
        <FeedbackButton screen="/matchs" />
        <Button variant="outline" size="sm" onClick={() => setFixtureFormOpen(true)}>
          <Plus className="size-4" />
          Nouveau match
        </Button>
      </div>

      {/* L7 — la SEMAINE calendaire est l'axe primaire. */}
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
        {/* RMM-4 — rappel de fraîcheur DISCRET (muted, sans bordure ni icône). */}
        <span className="ml-auto text-xs text-muted-foreground">{depositReminder}</span>
      </div>

      {/* RMM-3 — le « gardien » : en tête, un HEADS-UP amical de ce qui a bougé
          depuis la dernière visite (matchs arrivés, conflits neufs, planning changé).
          Frère du bandeau des conflits sans date, mais ton accent (info, pas
          warning) ; muet en première visite ou delta vide. */}
      <ModuleVisitBanner delta={moduleVisit.data} />

      {/* Bandeau GLOBAL au-dessus du rail : les conflits SANS date (compétition
          incomplète…) sortent du compte hebdo — on les montre quand même. */}
      {dateless.length > 0 ? (
        <button
          type="button"
          onClick={() => setRailStep("disputes")}
          className="flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-left text-sm text-muted-foreground transition-colors hover:bg-muted"
        >
          <Info className="size-4 shrink-0" />
          {dateless.length} signalement{dateless.length > 1 ? "s" : ""} hors semaine (compétition incomplète…) — voir les litiges
        </button>
      ) : null}

      <div className="flex flex-col gap-4 md:flex-row">
        <StepRail steps={steps} currentId={effectiveStep} onSelect={setRailStep} />
        <div className="min-w-0 flex-1">{content}</div>
      </div>

      {fixtureFormOpen ? <FixtureFormDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} competitions={competitions.data ?? []} onClose={() => setFixtureFormOpen(false)} /> : null}
      {null !== editFixture ? (
        <FixtureFormDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} competitions={competitions.data ?? []} fixture={editFixture} onClose={() => setEditFixture(null)} />
      ) : null}
      {importDialogOpen ? <ImportFbiDialog teams={teams.data ?? []} tiers={priorityTiers.data ?? []} onClose={() => setImportDialogOpen(false)} /> : null}
    </div>
  );
}
