import { IN_FLIGHT_STATUSES } from "@/shared/lib/scheduleStatus";
import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, GitCompare, Loader2, Lock, Pencil, Sparkles, Star, Undo2, X } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router";

import { useRenamePlanning } from "@/features/auth/queries";
import { useMe, useWorkingSeason } from "@/shared/session/queries";
import { FeedbackButton } from "@/features/feedback/FeedbackButton";
import { useWizardStore } from "@/features/wizard/store";
// Same ["priority_tiers"] query key as the matches/wizard hooks — one cache entry.
import { usePriorityTiers } from "@/features/matches/queries";
import { DeletePlanningButton } from "@/features/cockpit/DeletePlanningButton";
import { useEntryConflicts, useSchedulePlans } from "@/features/cockpit/queries";
import { useConstraintValidation, useReservations, useSharedTrainingBlocks, useTeamPeriodOverrides, useWizardTeamTagAssignments, useWizardTeamTags } from "@/features/wizard/queries";
import { coachFullName } from "@/shared/lib/coachName";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { armNavTransition } from "@/shared/stores/navTransitionStore";
import { toast } from "@/shared/stores/toastStore";
import { useCredits } from "@/shared/credits/useCredits";
import { Button } from "@/shared/components/ui/button";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { type Compromise, EngineTimeoutError, EngineVerificationInterruptedError, type EvictedSlot, GenerationInProgressError, type MoveViolation, MoveRejectedError, OverlaysExistError, type Slot, SlotEditError, TargetLockedError, VerdictAbandonedError } from "./api";
import { CompromiseList } from "./CompromiseList";
import { DiagnosticsPanel } from "./DiagnosticsPanel";
import { DriftBanner } from "./DriftBanner";
import { EvictConfirmDialog, type EvictDialogPhase, type EvictFailureKind } from "./EvictConfirmDialog";
import { LocksPanel } from "./LocksPanel";
import { ExportMenu } from "./ExportMenu";
import { GenerationWaiting } from "./GenerationWaiting";
import { buildTagTeamIds } from "./lib/applicableConstraints";
import { topSeveritySummary } from "./lib/diagnosticsSummary";
import { computeDrift } from "./lib/drift";
import { computeClosedWindows } from "./lib/closedWindows";
import { computeEmptySlots, isEmptySlotId } from "./lib/emptySlots";
import { violationHighlightSlotIds } from "./lib/violationHighlight";
import { buildClubView } from "./lib/clubView";
import { ClubViewTable } from "./ClubViewTable";
import { availableResourceGroups, buildGrid, DAYS, type Lookups, slotGroupKey, toHourMinute } from "./lib/grid";
import { PlanningToolbar } from "./PlanningToolbar";
import { useCategories, useCoachPlayers, useCoaches, useConstraints, useDeleteSchedule, useDiagnostics, useFillSchedule, useLockSlot, useMoveDryRun, useMoveGroup, useMoveSlot, usePlaceSlot, useRegenerate, useRegenerateFromVersion, useRegenerateOverlay, useReopenSchedule, useSchedules, useSlots, useSocleDeviation, useTeamCoaches, useTeams, useTrainingSlots, useValidateImpact, useValidateSchedule, useVenues } from "./queries";
import { blocksForSlot } from "./lib/blockSession";
import { ResourceFilter } from "./ResourceFilter";
import { SlotDetail, type MoveFeedback } from "./SlotDetail";

import { pickLandingScheduleId } from "./lib/pickLandingSchedule";
import { stalenessMessage } from "./lib/staleness";
import type { ToReplaceEntry } from "./lib/toReplaceReason";
import { isSeasonPlanType, planRepresentative, visibleOverlayVersions, visibleSeasonPlans } from "./lib/versions";
import { SeasonComparisonModal } from "./SeasonComparisonModal";
import { ValidateDialog } from "./ValidateDialog";
import { capacityShortfallSentence } from "./lib/capacityShortfall";
import { deviatedSlots } from "./lib/socleDeviationCells";
import { usePlanningStore, type ViewMode } from "./store";
import { SocleDeviationPanel } from "./SocleDeviationPanel";
import { ToReplaceList } from "./ToReplaceList";
import { WeekGrid } from "./WeekGrid";

// D-31 : foyer unique dans `api.ts`.
const IN_FLIGHT: readonly string[] = IN_FLIGHT_STATUSES;

/** jour ISO → abréviation, pour le libellé du raccourci d'éviction (« Lun 18:00 »). */
const DAY_ABBR = new Map(DAYS.map((d) => [d.n, d.label]));

/** `embedded` = rendered inside the wizard's Génération step, where the sticky
 *  wizard header + footer eat extra vertical space, so the grid must be shorter.
 *
 *  `scopePlanId` (bug fondateur 2026-08-19) — la PORTÉE d'affichage : non-null ⇒ l'écran
 *  ne montre QUE les versions de ce plan (une période), y atterrit, en tire son titre et sa
 *  toolbar, et ne retombe JAMAIS sur le plan de saison (fail-closed, doctrine PeriodAnchor).
 *  Null (le défaut, et la page `/planning` autonome) ⇒ comportement STRICTEMENT inchangé :
 *  `pickLandingScheduleId` y reste légitime.
 *
 *  `calendarEntryId` (P2-43 volet v) — l'entrée de calendrier de la PÉRIODE affichée, passée par
 *  `GenerateStep` en embarqué (elle l'a déjà en main). Null (page autonome) ⇒ dérivée du plan de
 *  la version affichée. Sert à LIRE l'état de fermeture des gymnases servi par le backend.
 *
 *  `toReplace` (P2-44 PR-2) — les séances du socle NON reprises par une transcription, SERVIES par
 *  la route et passées par `GenerateStep` (session d'écran). Non-vide ⇒ panneau « à replacer » +
 *  mise en évidence des vides. Null (page autonome, ou pas de transcription) ⇒ rien de tout ça.
 *
 *  `isClosurePeriod` (P2-44 PR-5) — la période affichée est une FERMETURE (`GenerateStep` lit déjà
 *  `periodEntry.periodType`, le serveur reste seul juge — la route refuse 422 sinon). SEUL déclencheur
 *  du panneau « Écarts avec le planning de saison » : sur une vacance ou `/planning` autonome, la route
 *  n'est JAMAIS appelée. */
export function PlanningPage({ embedded = false, scopePlanId = null, calendarEntryId = null, toReplace = null, isClosurePeriod = false }: { embedded?: boolean; scopePlanId?: string | null; calendarEntryId?: string | null; toReplace?: ToReplaceEntry[] | null; isClosurePeriod?: boolean } = {}) {
  const { data: schedules = [], isLoading: schedulesLoading } = useSchedules();
  const { data: me } = useMe();
  // §4bis pt 2 — solde de crédits sur « Régénérer » (Découverte bridée seulement).
  const credits = useCredits();
  const { viewMode, selectedScheduleId, selectedSlotId, resourceFilter, setViewMode, setSelectedScheduleId, setSelectedSlotId, toggleResource, clearResourceFilter } =
    usePlanningStore();
  const [highlightSlotIds, setHighlightSlotIds] = useState<Set<string>>(new Set());
  // Déverrouiller un créneau né d'une RÉSERVATION de gymnase demande confirmation (F1) : c'est
  // un engagement pris hors de l'app, à ne pas relâcher par inadvertance. On mémorise LE créneau
  // visé (et non un booléen) : le cadenas de la grille (PR 2) peut viser un créneau NON
  // sélectionné, la confirmation doit muter celui-là, pas le sélectionné.
  const [pendingUnlockSlotId, setPendingUnlockSlotId] = useState<string | null>(null);
  // PR 3 — panneau latéral des verrous manuels + lentille (surbrillance de la grille par
  // origine de verrou). Fermer le panneau ÉTEINT la lentille : pas d'état fantôme.
  const [locksPanelOpen, setLocksPanelOpen] = useState(false);
  const [lockLens, setLockLens] = useState(false);
  // P2-44 (PR-2) — la modale « Comparer avec la saison » (consultation du socle).
  const [compareOpen, setCompareOpen] = useState(false);
  // P2-30 (geste 1/2) — le mode cible « click-click ». `move` déplace un créneau existant
  // (`sourceSlotId`) ; `place` place une séance À LA DÉRIVE pour une équipe (`teamId`). Null =
  // consultation. Le geste 4 (undo, profondeur 1, session) et le raccourci d'éviction vivent
  // AUSSI en état de page — ils ont besoin des noms d'équipes et de l'issue exacte du verdict.
  // P4-119 (d) : un placement porte le CONTEXTE où il fut armé (version + vue) — son ancre est
  // l'entrée de dérive du bandeau, pas un panneau de créneau ; changer de vue ou de version le fait
  // tomber comme le panneau ferme un déplacement (cf. le désarmement en phase de rendu plus bas).
  const [targetMode, setTargetMode] = useState<
    | { kind: "move"; sourceSlotId: string }
    // P2-51 PR-6 (D11) — déplacer un BLOC entier : ancré au créneau source (comme « move »), il porte
    // le bloc et sa case source (le serveur résout les créneaux membres depuis la case).
    | { kind: "move-group"; sourceSlotId: string; blockId: string; source: { venueId: string; dayOfWeek: number; startTime: string } }
    | { kind: "place"; teamId: string; scheduleId: string | null; view: ViewMode }
    | null
  >(null);
  // P2-32 (D6) — la modale d'éviction, désormais alimentée par un ESSAI (dry-run). `checking`
  // pendant que le moteur juge, `accepted` (compromis nommés) ou `refused` (motifs) ensuite.
  // Rien n'est ÉCRIT tant qu'on n'a pas confirmé un état `accepted`.
  const [evictDialog, setEvictDialog] = useState<
    | { phase: "checking"; sourceSlotId: string; targetSlot: Slot }
    | { phase: "accepted"; sourceSlotId: string; targetSlot: Slot; compromises: Compromise[] }
    | { phase: "refused"; sourceSlotId: string; targetSlot: Slot; violations: MoveViolation[] }
    | { phase: "failed"; sourceSlotId: string; targetSlot: Slot; failureKind: EvictFailureKind }
    | null
  >(null);
  // P2-32 (geste 3) — les compromis NOMMÉS du dernier geste ÉCRIT accepté (N>0), pour le bandeau
  // dismissible. Null = aucun (pas de bandeau). Purgé au geste suivant / changement de version.
  const [compromiseNotice, setCompromiseNotice] = useState<Compromise[] | null>(null);
  // Le dernier geste annulable (profondeur 1). `move` = déplacement simple (inverse = re-move) ;
  // `move-evict` = déplacement avec éviction (inverse = re-move PUIS replacement de l'évincée).
  const [undo, setUndo] = useState<
    | { kind: "move"; slotId: string; sourceTeamId: string; from: { dayOfWeek: number; startTime: string; venueId: string } }
    | { kind: "move-evict"; slotId: string; sourceTeamId: string; from: { dayOfWeek: number; startTime: string; venueId: string }; evicted: EvictedSlot }
    | null
  >(null);
  // Raccourci d'éviction : proposer de REMETTRE l'évincée sur la case que la source vient de
  // libérer (position + équipe évincée). Effacé après usage / nouveau geste / changement de version.
  const [evictionNotice, setEvictionNotice] = useState<{ evicted: EvictedSlot; freed: { dayOfWeek: number; startTime: string; venueId: string } } | null>(null);
  // Source partagée avec le cockpit (radar/DayDialog) — une seule dérivation de
  // la saison de travail, plus de copie inline qui pourrait diverger.
  const workingSeason = useWorkingSeason();

  // Portée d'affichage (bug fondateur 2026-08-19). `scoped` ⇒ l'écran ne connaît QUE les
  // versions de ce plan de période : le socle et les autres périodes n'entrent ni dans
  // l'atterrissage, ni dans la toolbar, ni dans le titre. Sans portée, tout est inchangé.
  const scoped = null !== scopePlanId;
  const scopeVersions = useMemo(() => (scoped ? visibleOverlayVersions(schedules, scopePlanId) : null), [scoped, schedules, scopePlanId]);
  // La version sur laquelle atterrir (règle ARBITRÉE fondateur 2026-08-19). EMBARQUÉ (étape
  // Génération) ⇒ la version la plus RÉCENTE du plan en portée — période via la portée, saison
  // via les versions de saison —, génération EN VOL comprise : le gestionnaire doit revoir la
  // génération qu'il vient de lancer, pas le pointeur (le seed BCCL, V1 transcrite POINTÉE,
  // ramenait sinon toujours la V1). NON embarqué (`/planning` autonome, cockpit) ⇒ POINTEUR
  // d'abord, STRICTEMENT inchangé (frontière de `pickLanding.test.ts`). Fail-closed en portée :
  // on atterrit DANS la portée ou nulle part, JAMAIS via `pickLandingScheduleId` (socle).
  const landingScheduleId = useMemo(() => {
    if (scoped) {
      const versions = scopeVersions ?? [];
      return embedded ? (versions.at(-1)?.id ?? null) : (planRepresentative(versions)?.id ?? versions.at(-1)?.id ?? null);
    }
    if (embedded) {
      return visibleSeasonPlans(schedules).at(-1)?.id ?? null;
    }
    return schedules.length > 0 ? pickLandingScheduleId(schedules) : null;
  }, [scoped, embedded, scopeVersions, schedules]);

  // Keep a valid selection: default to the season base plan, else the latest
  // completed. A selection archived concurrently (sibling validation in another
  // tab) is invalid too — the selector has no option for it. En portée, la sélection
  // n'est valide que si elle appartient À la portée : une sélection de saison laissée
  // par un autre écran ne survit donc pas (le bug d'origine).
  const selectionInScope = !scoped || (null !== scopeVersions && scopeVersions.some((s) => s.id === selectedScheduleId));
  const validScheduleId = schedules.some((s) => s.id === selectedScheduleId) && selectionInScope ? selectedScheduleId : null;
  useEffect(() => {
    if (null !== validScheduleId) {
      return;
    }
    if (null !== landingScheduleId && landingScheduleId !== selectedScheduleId) {
      setSelectedScheduleId(landingScheduleId);
    }
  }, [validScheduleId, landingScheduleId, selectedScheduleId, setSelectedScheduleId]);

  // La COUCHE de créneaux de la version affichée (#8) : le socle lit la grille de
  // saison, une période lit la sienne. Dérivée ici, avant les requêtes, pour que
  // l'écran et l'export montrent les mêmes créneaux vides.
  const displayed = schedules.find((s) => s.id === validScheduleId) ?? null;
  const slotLayerId = null !== displayed && !isSeasonPlanType(displayed.planType) ? (displayed.schedulePlanId ?? null) : null;

  // P2-44 PR-5 — les écarts NOMMÉS vs le socle. Armés UNIQUEMENT sur l'écran embarqué et porté
  // (`transcriptionSurface`), d'une FERMETURE (le serveur reste seul juge — 422 sinon), et d'une
  // version TERMINÉE. Sur une vacance ou `/planning` autonome, `scheduleId` reste nul → la route
  // n'est JAMAIS appelée. Le calcul est SERVI ; le front NOMME (agrégat + lignes), il ne redérive rien.
  const socleDeviationArmed = embedded && scoped && isClosurePeriod && null !== displayed && "COMPLETED" === displayed.status;
  const { data: socleDeviation = null } = useSocleDeviation(socleDeviationArmed ? validScheduleId : null);

  // P2-44 PR-4 — le compteur de carence : la capacité chiffrée du récap, sur la surface de
  // FERMETURE seulement (HOLIDAY : jamais appelée). Le gate serveur reste la source unique du
  // calcul (bloc-aware) ; on ne fait qu'AFFICHER `capacity`.
  const capacityArmed = embedded && scoped && isClosurePeriod && null !== calendarEntryId;
  const { data: capacityValidation } = useConstraintValidation(capacityArmed, calendarEntryId);
  const capacity = capacityValidation?.capacity ?? null;

  // Requête des créneaux de la version affichée. On garde la requête ENTIÈRE (pas juste
  // `data`) : son `isFetching` voile la grille pendant qu'elle (re)charge — sinon, en
  // changeant de version/période, la grille gardait l'ancien contenu (placeholderData)
  // sans qu'aucun signe ne dise que ça travaillait (retour fondateur : « ça mouline »).
  const slotsQuery = useSlots(validScheduleId);
  const generatedSlots = useMemo(() => slotsQuery.data ?? [], [slotsQuery.data]);
  // Chargement ÉVIDENT : dès qu'une requête de créneaux est en vol pour la version
  // affichée. Le voile lui-même vit dans la branche grille (jamais par-dessus
  // GenerationWaiting, qui a son propre rendu).
  const slotsBusy = null !== validScheduleId && slotsQuery.isFetching;

  // Génération en ÉCHEC : aucun créneau généré, mais les réservations (verrous HARD
  // posés par le gestionnaire) existent indépendamment du résultat — « par défaut il y
  // a au moins les créneaux réservés qui doivent s'afficher » (fondateur, 2026-08-05).
  // Elles entrent dans la grille en pseudo-créneaux HARD, LECTURE SEULE (ids
  // `reservation-*` : aucun PATCH slot ne doit les viser), sur la MÊME couche que le
  // payload du solveur : socle = réservations permanentes, période = celles de son plan.
  const isFailed = "FAILED" === displayed?.status;
  // P2-43 volet (v) — l'état de fermeture des gymnases SERVI par le backend pour la PÉRIODE
  // affichée (`GET /calendar-entries/{id}/conflicts`, foyer unique déjà consommé par le wizard).
  // L'entrée de calendrier : prop en embarqué (Génération l'a en main), sinon dérivée du plan de
  // la version affichée — JAMAIS le socle (une version de saison n'a pas d'entrée de période).
  const { data: allSchedulePlans } = useSchedulePlans();
  const periodPlan =
    null !== displayed && !isSeasonPlanType(displayed.planType) && null !== displayed.schedulePlanId
      ? ((allSchedulePlans ?? []).find((p) => p.id === displayed.schedulePlanId) ?? null)
      : null;
  const periodEntryId = calendarEntryId ?? periodPlan?.calendarEntryId ?? null;
  const entryConflicts = useEntryConflicts(periodEntryId);
  const conflictsUnresolved = readLoading(entryConflicts) || readFailed(entryConflicts);
  // FAIL-CLOSED sur l'OFFRE : on n'ARME pas un geste cible tant que l'état de fermeture n'est pas
  // connu (le moteur refuserait un placement sur un couple fermé). Le socle n'a rien à attendre ;
  // une version de PÉRIODE dont le plan n'est pas encore résolu compte comme non résolue (on ne
  // DEVINE pas l'absence de fermeture). Fail-CLOSED sur l'offre, fail-OPEN sur l'affichage.
  const periodPlanPending = null !== displayed && !isSeasonPlanType(displayed.planType) && null === calendarEntryId && undefined === allSchedulePlans;
  const closuresResolved = !periodPlanPending && (null === periodEntryId || !conflictsUnresolved);
  // P2-15 — un gymnase DÉSACTIVÉ pour la période garde ses créneaux en base (le backend
  // les écarte du payload, il ne les supprime pas) : sans ce filtre, l'écran de génération
  // affichait TOUS les gymnases du club alors qu'un seul sert — « du bruit pour rien ».
  // On filtre à la SOURCE : la grille, ses fenêtres vides et le sélecteur en dérivent tous.
  // On lit l'état SERVI (`disabledVenueIds`), plus de re-dérivation locale depuis les overrides
  // (le wizard a migré de même — règle d'or). FAIL-CLOSED sur l'AFFICHAGE (P4-20) : lecture ratée
  // / pas encore résolue ⇒ on ne masque rien.
  const disabledVenueIds = useMemo(
    () => new Set(conflictsUnresolved ? [] : (entryConflicts.data?.disabledVenueIds ?? [])),
    [conflictsUnresolved, entryConflicts.data],
  );
  // P2-30 (dérive) : les overrides d'équipe de la PÉRIODE (seuil/désactivation) — mêmes hooks
  // que le wizard. Sur le socle (slotLayerId=null) le hook est inerte → `computeDrift` reçoit
  // `null` et lit le seuil de saison.
  const teamOverridesQuery = useTeamPeriodOverrides(slotLayerId);
  const reservationsQuery = useReservations(slotLayerId, isFailed);
  const reservationSlots = useMemo<Slot[]>(
    () => !isFailed || null === validScheduleId ? [] : (reservationsQuery.data ?? []).filter((r) => !disabledVenueIds.has(r.venueId)).map((r) => ({
      id: `reservation-${r.id}`,
      scheduleId: validScheduleId,
      teamId: r.teamId,
      venueId: r.venueId,
      coachId: null,
      dayOfWeek: r.dayOfWeek,
      startTime: r.startTime,
      durationMinutes: r.durationMinutes,
      lockLevel: "HARD" as const,
      // Ces pseudo-créneaux SONT des réservations — l'origine du verrou est explicite.
      lockOrigin: "RESERVATION" as const,
    })),
    [isFailed, reservationsQuery.data, validScheduleId, disabledVenueIds],
  );
  const slots = useMemo(
    () => isFailed && 0 === generatedSlots.length ? reservationSlots : generatedSlots,
    [isFailed, generatedSlots, reservationSlots],
  );

  const diagnosticsQuery = useDiagnostics(validScheduleId);
  // `useMemo` et non `?? []` : le repli littéral fabriquait un tableau NEUF à chaque rendu,
  // ce qui invalidait le `useMemo` du filtrage en aval à chaque fois (avertissement lint).
  const allDiagnostics = useMemo(() => diagnosticsQuery.data ?? [], [diagnosticsQuery.data]);
  // « Pas encore lu » n'est pas « rien à signaler » : tant que la requête est en vol, le
  // panneau ne doit pas annoncer un planning propre (revue #350, doctrine `readState`).
  const diagnosticsPending = null !== validScheduleId && undefined === diagnosticsQuery.data;
  const { data: trainingSlots = [] } = useTrainingSlots(slotLayerId);
  const { data: teams = [] } = useTeams();
  const { data: venues = [] } = useVenues();
  const { data: coaches = [] } = useCoaches();
  const { data: tiers = [] } = usePriorityTiers();
  const { data: categories = [] } = useCategories();
  const { data: teamCoaches = [] } = useTeamCoaches();
  const { data: coachPlayers = [] } = useCoachPlayers();
  const { data: constraints = [] } = useConstraints();
  // Résolution tag→équipes (saison courante) : le wrap n'affiche une contrainte CLUB ciblant
  // un tag QUE sur les équipes taguées — miroir de l'éclatement `CLUB+targetTag` du backend
  // (ScheduleConstraintBuilder). Mêmes hooks que le wizard (ConstraintsStep), pas un second
  // chemin de données ; les assignations sont déjà filtrées à la saison côté serveur.
  const { data: teamTags = [] } = useWizardTeamTags();
  const { data: teamTagAssignments = [] } = useWizardTeamTagAssignments();
  const tagTeamIds = useMemo(() => buildTagTeamIds(teamTags, teamTagAssignments), [teamTags, teamTagAssignments]);

  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const lockMutation = useLockSlot();
  const moveMutation = useMoveSlot();
  const moveGroupMutation = useMoveGroup();
  const dryRunMutation = useMoveDryRun();
  const placeMutation = usePlaceSlot();
  const regenerateMutation = useRegenerate();
  const regenerateOverlayMutation = useRegenerateOverlay();
  const fillMutation = useFillSchedule();
  const validateMutation = useValidateSchedule();
  const reopenMutation = useReopenSchedule();
  const deleteMutation = useDeleteSchedule();
  const regenerateFromMutation = useRegenerateFromVersion();
  const [regenerateFromOpen, setRegenerateFromOpen] = useState(false);
  const renamePlanning = useRenamePlanning();
  const [editingPlanningName, setEditingPlanningName] = useState<string | null>(null);
  // Repli CONTEXTUEL (P4-40). En boucle de travail, replié par défaut : la grille prend
  // toute la largeur pour vérifier, une barre compacte rouvre l'aside — c'est la demande
  // utilisateur d'origine, inchangée. Au sortir d'une génération lancée DEPUIS LE WIZARD
  // (`embedded`), ouvert : « sinon on risque de ne pas le voir si on n'est pas familier
  // avec l'écran génération » (retour terrain). Les deux règles ne se contredisent pas —
  // la seconde nomme un contexte que la première n'avait pas distingué.
  const [diagnosticsCollapsed, setDiagnosticsCollapsed] = useState(true);
  const [validateOpen, setValidateOpen] = useState(false);
  // P2-52 — l'impact de dépointage de la validation, interrogé UNIQUEMENT quand la modale « Valider »
  // est ouverte (le geste est envisagé). N=0 → l'annonce ne s'affiche pas ; N>0 → le confirm gagne
  // l'avertissement « salle perdue » ; en vol / échec → le bouton Valider reste désactivé.
  const validateImpactQuery = useValidateImpact(validateOpen ? validScheduleId : null);
  const orphanImpact = useMemo(
    () => ({
      orphanCount: validateImpactQuery.data?.orphanedFixtures ?? 0,
      declaredCount: validateImpactQuery.data?.declaredOrphanedFixtures ?? 0,
      loading: readLoading(validateImpactQuery),
      failed: readFailed(validateImpactQuery),
      onRetry: () => void validateImpactQuery.refetch(),
    }),
    [validateImpactQuery],
  );
  // Reopening the baseline with period overlays → 409; confirm to delete them.
  const [reopenOverlayCount, setReopenOverlayCount] = useState<number | null>(null);

  // Validating a non-baseline version with overlays → 409 escalation (same
  // destructive idiom as reopen): confirm, then re-POST with the flag.
  const [validateOverlayCount, setValidateOverlayCount] = useState<number | null>(null);
  const validate = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    validateMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => {
          setValidateOverlayCount(null);
          setValidateOpen(false);
          // Validated → land on /planning, the screen of the version IN FORCE. Valider
          // est la SORTIE de l'espace de travail (l'étape Génération du wizard) : le socle
          // validé devient la version en vigueur, et /planning en porte le badge de statut
          // et « Rouvrir » (symétrie stricte, 2026-08-20 — Valider ↔ Rouvrir).
          navigate("/planning");
        },
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setValidateOpen(false);
            setValidateOverlayCount(error.count);
          }
        },
      },
    );
  };

  const reopen = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    reopenMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => {
          setReopenOverlayCount(null);
          // RÈGLE : toute navigation vers /wizard DÉCLARE son mode — aucun héritage du mode
          // ambiant du localStorage. Sans quoi rouvrir un overlay ouvrait la SAISON (ou la
          // mauvaise période) : `jumpTo("generate")` SEUL laissait le mode persisté décider.
          // On le dérive de la version rouverte : plan non-SEASON → mode période ancré sur SON
          // entrée (schedulePlanId → plan → calendarEntryId) ; plan SEASON → mode saison.
          const reopened = displayed; // === selectedSchedule ; `displayed` est en portée ici
          const reopenedEntryId =
            null !== reopened && !isSeasonPlanType(reopened.planType) && null !== reopened.schedulePlanId
              ? ((allSchedulePlans ?? []).find((p) => p.id === reopened.schedulePlanId)?.calendarEntryId ?? null)
              : null;
          if (null !== reopenedEntryId) {
            useWizardStore.getState().startPeriodMode(reopenedEntryId);
          } else {
            useWizardStore.getState().exitPeriodMode();
          }
          // Reopened to rework the plan → the wizard's generation step (mode already declared).
          useWizardStore.getState().jumpTo("generate");
          navigate("/wizard");
        },
        // Generic failures are toasted by the hook (unmount-safe); only the
        // 409 escalation is UI state handled here.
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setReopenOverlayCount(error.count);
          }
        },
      },
    );
  };

  const selectedSchedule = displayed;
  // Suppression d'un planning SECONDAIRE (overlay) depuis l'en-tête (retour fondateur
  // 2026-07-19) : l'entrée de calendrier de son plan (jamais pour le socle SEASON).
  // `allSchedulePlans` est déjà lu plus haut (dérivation de la fermeture de période).
  const overlayDeleteEntryId =
    null !== selectedSchedule && !isSeasonPlanType(selectedSchedule.planType) && null !== selectedSchedule.schedulePlanId
      ? ((allSchedulePlans ?? []).find((p) => p.id === selectedSchedule.schedulePlanId)?.calendarEntryId ?? null)
      : null;
  // ADR-0002 inv. 12 : LE nom vit sur le PLAN, jamais sur la version. Tout ce que
  // l'en-tête montre ou modifie (titre, stylo, nom de fichier exporté, popup de
  // suppression) doit donc désigner le plan de la version AFFICHÉE — pas le plan de
  // saison. Il était codé en dur : renommer un planning de période renommait le
  // planning de la SAISON, et l'en-tête affichait son nom sur toutes les périodes.
  // `null` = plan pas encore résolu (collection en vol, ou plan absent) : l'appelant
  // dégrade, il ne devine pas.
  // Le club n'a AUCUNE version : on est dans le contexte SAISON par défaut, le plan de
  // saison reste le sujet de l'en-tête. Sans ce cas, un club qui n'a jamais généré perdait
  // le nom de son planning ET son stylo — il ne pouvait plus le nommer (revue #339 round 1).
  // ⚠ La condition porte sur « le club n'a aucune version » (`schedules.length`), PAS sur
  // « aucune version RÉSOLUE » : entre deux refetch, la sélection du store peut ne pas se
  // retrouver dans la liste, et un repli sur ce signal-là ré-armerait le plan de SAISON comme
  // cible du stylo alors que le gestionnaire est sur une période — le bug d'origine, de retour
  // par une porte transitoire (revue #339 round 2).
  // Entre deux refetch, la sélection du store peut ne plus être dans la liste (suppression
  // d'une version, sélection persistée d'une autre saison) : `selectedSchedule` est alors null
  // UNE passe de rendu, le temps que l'effet d'atterrissage rejoue. Plutôt que de laisser
  // l'en-tête retomber sur un générique — ou pire, sur le plan de SAISON alors qu'on regarde
  // une période —, on lit dès maintenant la version que cet effet va choisir : la MÊME
  // fonction, donc le même résultat, sans flash et sans deviner (revue #339 round 3).
  // L'en-tête lit dès maintenant la version que l'effet d'atterrissage va choisir (la MÊME
  // fonction, donc le même résultat, sans flash) : en portée, la version de la période — jamais
  // le socle ; hors portée, l'atterrissage embarqué/pointeur selon le contexte.
  const headerSchedule = selectedSchedule ?? (null !== landingScheduleId ? (schedules.find((s) => s.id === landingScheduleId) ?? null) : null);
  const displayedPlan: { id: string; name: string } | null = scoped
    ? ((allSchedulePlans ?? []).find((p) => p.id === scopePlanId) ?? null)
    : null === headerSchedule || isSeasonPlanType(headerSchedule.planType)
      ? (me?.seasonPlan ?? null)
      : ((allSchedulePlans ?? []).find((p) => p.id === headerSchedule.schedulePlanId) ?? null);
  // Le TITRE tolère un plan non encore résolu (collection des plans en vol) : la photo
  // `Schedule.name` porte le nom du plan à la création, donc un libellé juste dans l'immense
  // majorité des cas — bien mieux que le générique « Planning ». Le STYLO, lui, reste
  // conditionné au plan résolu : on ne propose pas un geste dont on n'a pas la cible.
  const displayedPlanName = displayedPlan?.name ?? headerSchedule?.name ?? null;
  const isGenerating = null !== selectedSchedule && IN_FLIGHT.includes(selectedSchedule.status);
  // Lot C (défaut terrain fondateur 2026-08-21) — l'écran de génération s'affiche dès qu'une
  // version DU PLAN EN PORTÉE est en vol, en saison comme en période. `isGenerating` ne dérive
  // que de la SÉLECTION : au lancement, la nouvelle version PENDING naît alors que la sélection
  // embarquée pointe encore l'ancienne COMPLETED (ou rien, le temps que la liste se rafraîchisse)
  // — ce trou tombait sinon sur le petit voile « Chargement des créneaux… » (branche `slotsBusy`),
  // au lieu du MÊME écran qu'en saison. La portée est une VRAIE borne : en portée période, les
  // versions de CE plan (`schedulePlanId`) seul ; sinon, celles de la saison — une version en vol
  // d'un autre plan (autre période, ou la saison quand on est en portée période) NE la déclenche PAS.
  // DÉCISION FONDATEUR 2026-08-21 (assumée) : pendant une régénération, un gestionnaire qui
  // sélectionne manuellement une ancienne version COMPLETED voit l'écran d'attente jusqu'à la fin
  // du vol — c'est la lettre de la règle (« une version du plan en portée est en vol »).
  const scopeInFlight = useMemo(
    () => schedules.some((s) => (scoped ? s.schedulePlanId === scopePlanId : isSeasonPlanType(s.planType)) && IN_FLIGHT.includes(s.status)),
    [schedules, scoped, scopePlanId],
  );
  // ⚑ Cette dérivation est la SEULE porte : tout ce qui se tait ou se grise « pendant une
  // génération » la lit — bannières (dérive, périmé, échec), barre d'outils, suppression
  // d'overlay, comparaison. Lire `isGenerating` seul laisserait ces gestes flotter AU-DESSUS de
  // l'écran d'attente, alors que la règle dit qu'il REMPLACE le contenu (retour fondateur
  // 2026-08-21, en relisant PR-1).
  const showGenerationWaiting = isGenerating || scopeInFlight;
  // Read-only = its plan points at it: this version IS the calendar in force.
  const isReadOnly = true === selectedSchedule?.isChosen;
  const regenerateDisabled =
    null !== selectedSchedule
    && isSeasonPlanType(selectedSchedule.planType)
    && selectedSchedule.snapshotHash === me?.seasonPlan?.currentStructureHash;
  // regenerateFromMutation.isPending: "Charger cette version" no longer creates a
  // PENDING schedule (nothing sets isGenerating), so its own restore must disable
  // the action here — else a second click double-runs the destructive restore.
  const actionBusy = validateMutation.isPending || reopenMutation.isPending || deleteMutation.isPending || regenerateFromMutation.isPending;
  const busy = lockMutation.isPending || moveMutation.isPending || dryRunMutation.isPending || placeMutation.isPending;

  // When a running generation finishes, pull the fresh slots + diagnostics.
  const prevStatus = useRef<string | null>(null);
  useEffect(() => {
    const status = selectedSchedule?.status ?? null;
    if (null !== prevStatus.current && IN_FLIGHT.includes(prevStatus.current) && null !== status && !IN_FLIGHT.includes(status)) {
      void queryClient.invalidateQueries({ queryKey: ["slots", validScheduleId] });
      void queryClient.invalidateQueries({ queryKey: ["diagnostics", validScheduleId] });
    }
    prevStatus.current = status;
  }, [selectedSchedule?.status, validScheduleId, queryClient]);

  const selectedSlot = slots.find((s) => s.id === selectedSlotId) ?? null;

  // P2-51 PR-6 (D11) — les BLOCS de mutualisation de la portée affichée (socle si `slotLayerId` nul,
  // sinon le plan de période) : sur le socle le provider renvoie socle ET périodes → on filtre. Ils
  // servent à DÉRIVER (fail-safe) si le créneau sélectionné est une séance de bloc — le `Slot` ne
  // porte aucun marqueur d'appartenance, le serveur reste seul juge (rail `move-group`).
  const { data: allSharedBlocks = [] } = useSharedTrainingBlocks(slotLayerId);
  const sharedBlocks = useMemo(
    () => (null === slotLayerId ? allSharedBlocks.filter((b) => null === b.schedulePlanId) : allSharedBlocks),
    [allSharedBlocks, slotLayerId],
  );
  // Le bloc dont le créneau sélectionné est une séance (tous les membres co-localisés sur sa case).
  // Le premier suffit : une même case portant DEUX blocs pleins recouvrant cette équipe est un cas
  // dégénéré — le serveur trancherait de toute façon. `null` = créneau ordinaire (déplacement simple).
  const selectedSlotBlock = useMemo(
    () => (null === selectedSlot ? null : (blocksForSlot(selectedSlot, sharedBlocks, slots)[0] ?? null)),
    [selectedSlot, sharedBlocks, slots],
  );

  // PR 3 — les créneaux verrouillés À LA MAIN (le compteur toolbar + la liste du panneau).
  // SEULS les MANUAL comptent : ni les réservations de gymnase (RESERVATION), ni les verrous
  // d'origine indécidable (UNKNOWN) — c'est le « travail de verrouillage » du gestionnaire.
  const manualLocks = useMemo(() => slots.filter((s) => "MANUAL" === s.lockOrigin), [slots]);
  const closeLocksPanel = useCallback(() => {
    setLocksPanelOpen(false);
    setLockLens(false);
  }, []);

  // F1 (PR 2) — LE point d'entrée UNIQUE de la bascule de verrou, partagé par le panneau de
  // détail ET le cadenas de la grille : la règle RÉSERVATION (déverrouiller → confirmation)
  // s'écrit ainsi une seule fois. MANUAL/UNKNOWN et tout verrouillage mutent directement.
  const requestToggleLock = useCallback(
    (slotId: string) => {
      const slot = slots.find((s) => s.id === slotId);
      if (undefined === slot) {
        return;
      }
      const locked = "NONE" !== slot.lockLevel;
      if (locked && "RESERVATION" === slot.lockOrigin) {
        setPendingUnlockSlotId(slotId);
        return;
      }
      lockMutation.mutate({ id: slotId, lockLevel: locked ? "NONE" : "HARD" });
    },
    [slots, lockMutation],
  );

  // F2b — le retour du dernier déplacement, dérivé de la mutation (verdict moteur). Un refus
  // (422) arrive en MoveRejectedError avec ses motifs ; une génération en cours en
  // GenerationInProgressError ; toute autre erreur (moteur injoignable) → « error ».
  const moveReset = moveMutation.reset;
  const moveState: MoveFeedback = moveMutation.isPending
    ? { status: "pending" }
    : moveMutation.error instanceof MoveRejectedError
      ? { status: "rejected", violations: moveMutation.error.violations }
      : moveMutation.error instanceof GenerationInProgressError
        ? { status: "blocked" }
        : // P4-119 (b) : l'attente coupée CÔTÉ CLIENT a son propre message — jamais « moteur injoignable ».
          moveMutation.error instanceof EngineVerificationInterruptedError
          ? { status: "interrupted" }
          : // P2-30 : verrou de cible / cible incohérente sont TOASTÉS (message serveur propre),
            // pas rendus en panneau — ni un « moteur injoignable » ni un refus de légalité.
            moveMutation.error instanceof TargetLockedError || moveMutation.error instanceof SlotEditError
            ? { status: "idle" }
            : null !== moveMutation.error && undefined !== moveMutation.error
              ? { status: "error" }
              : { status: "idle" };

  // P2-51 PR-6 — le verdict du dernier déplacement de GROUPE, même dérivation que `moveState` (un
  // refus 422 → rejected ; slot_unavailable/verrou → toasté, panneau idle ; interruption → nommée).
  const moveGroupReset = moveGroupMutation.reset;
  const moveGroupState: MoveFeedback = moveGroupMutation.isPending
    ? { status: "pending" }
    : moveGroupMutation.error instanceof MoveRejectedError
      ? { status: "rejected", violations: moveGroupMutation.error.violations }
      : moveGroupMutation.error instanceof GenerationInProgressError
        ? { status: "blocked" }
        : moveGroupMutation.error instanceof EngineVerificationInterruptedError
          ? { status: "interrupted" }
          : moveGroupMutation.error instanceof TargetLockedError || moveGroupMutation.error instanceof SlotEditError
            ? { status: "idle" }
            : null !== moveGroupMutation.error && undefined !== moveGroupMutation.error
              ? { status: "error" }
              : { status: "idle" };

  // Changer de créneau sélectionné efface le verdict du précédent — sinon un refus resterait
  // affiché sous un autre créneau.
  useEffect(() => {
    moveReset();
    moveGroupReset();
  }, [selectedSlotId, moveReset, moveGroupReset]);

  // Un déplacement REFUSÉ surligne le créneau de l'équipe EN CONFLIT (le moteur l'a nommée) —
  // présentation pure, on retrouve juste où elle siège dans le cache affiché. Ajustement en
  // phase de rendu (le lint du dépôt interdit setState dans un effet), clé = l'instance
  // d'erreur : au reset (changement de créneau, nouvel essai), moveState quitte « rejected »
  // et le surlignage s'efface — sans jamais écraser un surlignage venu d'un diagnostic.
  // P2-51 PR-6 — le déplacement de GROUPE surligne ses conflits de la MÊME façon : on suit le refus
  // ACTIF (déplacement simple OU de groupe — jamais les deux à la fois : un seul geste est en vol).
  const [rejectionHandled, setRejectionHandled] = useState<unknown>(null);
  const activeRejection = "rejected" === moveState.status ? moveMutation.error : "rejected" === moveGroupState.status ? moveGroupMutation.error : null;
  const activeRejectionViolations = "rejected" === moveState.status ? moveState.violations : "rejected" === moveGroupState.status ? moveGroupState.violations : [];
  if (null !== activeRejection && activeRejection !== rejectionHandled) {
    setRejectionHandled(activeRejection);
    setHighlightSlotIds(violationHighlightSlotIds(activeRejectionViolations, slots));
  } else if (null === activeRejection && null !== rejectionHandled) {
    setRejectionHandled(null);
    setHighlightSlotIds(new Set());
  }

  const lookups: Lookups = useMemo(() => {
    // teamId → main coachId (the engine leaves slot.coachId empty).
    const teamCoach = new Map<string, string>();
    for (const link of teamCoaches) {
      if ("MAIN" === link.role && !teamCoach.has(link.teamId)) {
        teamCoach.set(link.teamId, link.coachId);
      }
    }
    // teamId → coachIds that are players of the team (coach view shows these too).
    const teamPlayerCoaches = new Map<string, string[]>();
    for (const link of coachPlayers) {
      if (link.isActive) {
        teamPlayerCoaches.set(link.teamId, [...(teamPlayerCoaches.get(link.teamId) ?? []), link.coachId]);
      }
    }
    // P2-17 — libellé de groupe d'une fenêtre (clé gymnase|jour|minute) : le front AFFICHE
    // le champ calculé par le backend, il ne le re-dérive pas. Vide/trim→ignoré.
    const groupLabels = new Map<string, string>();
    for (const ts of trainingSlots) {
      const label = (ts.groupLabel ?? "").trim();
      if ("" !== label) {
        groupLabels.set(slotGroupKey(ts.venueId, ts.dayOfWeek, ts.startTime), label);
      }
    }
    return {
      teams: new Map(teams.map((t) => [t.id, t])),
      venues: new Map(venues.map((v) => [v.id, v])),
      coaches: new Map(coaches.map((c) => [c.id, c])),
      teamCoach,
      teamPlayerCoaches,
      groupLabels,
    };
  }, [teams, venues, coaches, teamCoaches, coachPlayers, trainingSlots]);

  // Defined venue windows the solver left unfilled ("créneaux vides"). Injected
  // into the grid in the GYMNASE view only (they have no team/coach) so they
  // show as `vide` cells even without a click; also listed as warnings below.
  const layerSlots = useMemo(
    () => (0 === disabledVenueIds.size ? trainingSlots : trainingSlots.filter((ts) => !disabledVenueIds.has(ts.venueId))),
    [trainingSlots, disabledVenueIds],
  );
  // ⚠ On filtre ce qui est OFFERT (les fenêtres libres), JAMAIS ce qui EXISTE (les séances
  // placées) — revue #342 round 2. Le premier jet filtrait aussi les séances : l'écran
  // cessait alors de montrer des séances que l'EXPORT, rendu côté serveur par scheduleId,
  // contenait toujours — le PDF remis aux coachs et l'écran se contredisaient, et une
  // version entièrement placée dans un gymnase désactivé rendait une grille blanche sans
  // un mot. Une version est un FAIT DÉJÀ ARRIVÉ ; la composition de la période est un
  // réglage pour la PROCHAINE génération. Cacher le fait ne le supprime pas : on l'annonce
  // (`staleVenueSessions`) et on invite à régénérer.
  const emptySlots = useMemo(() => computeEmptySlots(layerSlots, slots, validScheduleId ?? ""), [layerSlots, slots, validScheduleId]);
  // P2-33 : la vue « jour » réutilise le layout gymnase (colonnes = gymnases) ; elle montre
  // donc aussi les fenêtres libres (mode cible P2-30), sinon passer en « Par jour · tous les
  // jours » escamoterait silencieusement les cases vides — une « surprise à l'arrivée » que la
  // décision fondateur (défaut = le rendu actuel) proscrit. Le filtre jour les rétrécit ensuite.
  const gridSlots = useMemo(
    () => ("gymnase" === viewMode || "jour" === viewMode ? [...slots, ...emptySlots] : slots),
    [viewMode, slots, emptySlots],
  );

  // P2-43 volet (v) — les couples (gymnase, jour) FERMÉS de la période, lus de l'état SERVI. La
  // grille en MARQUE ses fenêtres vides (inertes + nommées) et l'offre les exclut. Vide sur le
  // socle ou tant que les conflits ne sont pas résolus (fail-open sur l'affichage).
  const closedWindows = useMemo(
    () => computeClosedWindows(conflictsUnresolved ? undefined : entryConflicts.data, (venueId) => lookups.venues.get(venueId)?.name ?? "Gymnase"),
    [conflictsUnresolved, entryConflicts.data, lookups],
  );

  // --- P2-30 : mode cible, éviction, dérive, undo -----------------------------------------
  const teamNameOf = (teamId: string): string => lookups.teams.get(teamId)?.name ?? "une équipe";

  // Changer de version PURGE tout état éphémère de retouche (mode cible, undo, raccourci,
  // éviction en attente) — ajustement en PHASE DE RENDU (patron `asideSeededFor`, le lint
  // proscrit un setState d'état dérivé dans un effet).
  const [retouchVersion, setRetouchVersion] = useState<string | null>(null);
  if (retouchVersion !== validScheduleId) {
    setRetouchVersion(validScheduleId);
    setTargetMode(null);
    setUndo(null);
    setEvictionNotice(null);
    setEvictDialog(null);
    setCompromiseNotice(null);
  }

  // Séances à la dérive (geste 3) : COMPLETED seulement, hors génération. Seuil = backend
  // (saison, ou override de période) ; FAIL-CLOSED — sur un plan de période dont les overrides
  // ne sont pas encore lus, on ne DEVINE pas une dérive (on n'affiche rien).
  const driftEntries = useMemo(() => {
    if (null === displayed || "COMPLETED" !== displayed.status) {
      return [];
    }
    let overrides: { teamId: string; isActive: boolean; sessionsPerWeek: number | null }[] | null;
    if (null === slotLayerId) {
      overrides = null; // socle → seuil de saison
    } else if (readFailed(teamOverridesQuery) || readLoading(teamOverridesQuery)) {
      return []; // période, overrides pas encore connus → pas de dérive fantôme
    } else {
      overrides = teamOverridesQuery.data ?? [];
    }
    return computeDrift(teams.map((t) => ({ id: t.id, sessionsPerWeek: t.sessionsPerWeek })), generatedSlots, overrides);
  }, [displayed, slotLayerId, teamOverridesQuery, teams, generatedSlots]);

  // P4-119 (d) — l'armement d'un geste cible SUIT son ancre et tombe dès qu'elle disparaît, sinon
  // le mode restait armé et chaque clic devenait une nouvelle tentative de déplacement (fondateur
  // piégé, 2026-08-19). Un DÉPLACEMENT est ancré au panneau de son créneau source (`selectedSlotId`)
  // : le fermer, en ouvrir un autre, changer de vue ou de version l'annule — ces trois derniers
  // vident déjà `selectedSlotId` (cf. store), la seule condition `sourceSlotId !== selectedSlotId`
  // les couvre tous. Un PLACEMENT est ancré à l'entrée de dérive de son équipe ET au contexte où il
  // fut armé : l'équipe qui cesse de dériver, un changement de vue ou de version le fait tomber.
  // Redérivé en phase de RENDU, jamais en effet (le lint du dépôt interdit un setState en effet —
  // même idiome que `rejectionHandled` plus bas) ; converge (une fois null, la condition est fausse).
  if (null !== targetMode) {
    const stale =
      "move" === targetMode.kind || "move-group" === targetMode.kind
        ? targetMode.sourceSlotId !== selectedSlotId
        : targetMode.scheduleId !== validScheduleId || targetMode.view !== viewMode || !driftEntries.some((d) => d.teamId === targetMode.teamId);
    if (stale) {
      setTargetMode(null);
    }
  }

  const cancelTarget = () => {
    const source = targetMode?.kind === "move" || targetMode?.kind === "move-group" ? targetMode.sourceSlotId : null;
    setTargetMode(null);
    // Le focus revient sur la source (a11y) — best-effort, inerte en jsdom.
    if (null !== source) {
      requestAnimationFrame(() => (document.querySelector(`[data-slot-id="${source}"] button`) as HTMLElement | null)?.focus?.());
    }
  };
  // P2-43 volet (v) — l'OFFRE est fail-closed : tant que l'état de fermeture d'une période n'est
  // pas résolu, on n'ARME pas de geste cible (on ne sait pas quelles cases le moteur refusera).
  // Le socle et une période aux conflits déjà lus arment normalement (comportement inchangé).
  const guardArm = (): boolean => {
    if (!closuresResolved) {
      toast.error("Vérification des fermetures de gymnase en cours — réessayez dans un instant.");
      return false;
    }
    return true;
  };
  // Armer/désarmer le déplacement d'un créneau depuis son panneau (toggle).
  const armMove = (slotId: string) => {
    if (!guardArm()) {
      return;
    }
    setTargetMode((cur) => (cur?.kind === "move" && cur.sourceSlotId === slotId ? null : { kind: "move", sourceSlotId: slotId }));
  };
  // P2-51 PR-6 (D11) — armer/désarmer le déplacement d'un BLOC entier depuis le panneau d'une de ses
  // séances. La case source est celle du créneau ; le serveur résout les créneaux membres.
  const armMoveGroup = (slotId: string, blockId: string, source: { venueId: string; dayOfWeek: number; startTime: string }) => {
    if (!guardArm()) {
      return;
    }
    setTargetMode((cur) => (cur?.kind === "move-group" && cur.sourceSlotId === slotId ? null : { kind: "move-group", sourceSlotId: slotId, blockId, source }));
  };
  // Armer le placement d'une équipe à la dérive (le panneau de détail se ferme : pas de source).
  // On fige le contexte (version + vue) pour que le geste tombe si l'un change (P4-119 d).
  const armPlace = (teamId: string) => {
    if (!guardArm()) {
      return;
    }
    setSelectedSlotId(null);
    setTargetMode({ kind: "place", teamId, scheduleId: validScheduleId, view: viewMode });
  };

  // Déplacer un créneau (éventuellement en évinçant l'occupant de la cible) sous le verdict
  // moteur. Le TOAST, l'undo et le raccourci d'éviction sont décidés ICI (noms d'équipes).
  const doMove = (sourceSlotId: string, patch: { dayOfWeek: number; startTime: string; venueId: string }, evictSlotId?: string) => {
    const source = slots.find((s) => s.id === sourceSlotId);
    if (undefined === source) {
      return;
    }
    const from = { dayOfWeek: source.dayOfWeek, startTime: toHourMinute(source.startTime), venueId: source.venueId };
    const sourceTeamId = source.teamId;
    moveMutation.mutate(
      { id: sourceSlotId, patch: undefined === evictSlotId ? patch : { ...patch, evictSlotId } },
      {
        onSuccess: (result) => {
          setEvictDialog(null);
          setTargetMode(null);
          // P2-32 — les compromis NOMMÉS du geste : bandeau (N>0) + suffixe de toast « — N compromis ».
          const compromises = result.compromises ?? [];
          setCompromiseNotice(compromises.length > 0 ? compromises : null);
          const suffix = compromises.length > 0 ? ` — ${compromises.length} compromis` : "";
          if (undefined !== result.evicted) {
            setUndo({ kind: "move-evict", slotId: sourceSlotId, sourceTeamId, from, evicted: result.evicted });
            setEvictionNotice({ evicted: result.evicted, freed: from });
            toast.success(`${teamNameOf(sourceTeamId)} déplacée — ${teamNameOf(result.evicted.teamId)} est à replacer${compromises.length > 0 ? suffix : "."}`);
          } else {
            setUndo({ kind: "move", slotId: sourceSlotId, sourceTeamId, from });
            setEvictionNotice(null);
            toast.success(`Créneau déplacé${compromises.length > 0 ? suffix : "."}`);
          }
        },
        onError: (error) => {
          setEvictDialog(null);
          // Verrou de cible / cible incohérente / moteur trop lent : message serveur propre (le
          // timeout est NOMMÉ, pas un numéro nu), on RESTE en mode cible.
          if (error instanceof TargetLockedError || error instanceof SlotEditError || error instanceof EngineTimeoutError) {
            toast.error(error.message);
          }
          // Un refus de légalité (MoveRejectedError) s'affiche dans le panneau (moveState) et
          // surligne le conflit (phase de rendu) — le mode cible reste armé pour réessayer.
        },
      },
    );
  };

  // P2-51 PR-6 (D11) — déplacer TOUT un bloc de mutualisation vers une case, atomiquement, sous le
  // verdict moteur. Le serveur résout les créneaux membres depuis la case source (jamais de slotIds
  // client). Un refus de légalité s'affiche dans le panneau (`moveGroupState`) + surligne le conflit ;
  // verrou de cible / cible incohérente / moteur trop lent sont toastés (message serveur), mode armé.
  const doMoveGroup = (blockId: string, source: { venueId: string; dayOfWeek: number; startTime: string }, target: { venueId: string; dayOfWeek: number; startTime: string }) => {
    if (null === validScheduleId) {
      return;
    }
    moveGroupMutation.mutate(
      {
        scheduleId: validScheduleId,
        blockId,
        source: { venueId: source.venueId, dayOfWeek: source.dayOfWeek, startTime: toHourMinute(source.startTime) },
        target: { venueId: target.venueId, dayOfWeek: target.dayOfWeek, startTime: toHourMinute(target.startTime) },
      },
      {
        onSuccess: (result) => {
          setTargetMode(null);
          // Un déplacement de groupe n'a pas d'inverse d'un clic (il faudrait rejouer move-group) :
          // on n'arme aucun undo, et on invalide un éventuel undo d'un geste simple précédent.
          setUndo(null);
          setEvictionNotice(null);
          setHighlightSlotIds(new Set());
          const compromises = result.compromises ?? [];
          setCompromiseNotice(compromises.length > 0 ? compromises : null);
          toast.success(compromises.length > 0 ? `Groupe déplacé — ${compromises.length} compromis` : "Groupe déplacé.");
        },
        onError: (error) => {
          if (error instanceof VerdictAbandonedError) {
            return; // déjà nommé + resynchronisé par le hook
          }
          if (error instanceof TargetLockedError || error instanceof SlotEditError || error instanceof EngineTimeoutError) {
            toast.error(error.message);
          }
          // MoveRejectedError / GenerationInProgress / interruption : rendus par le panneau
          // (`moveGroupState`) + surlignage — le mode cible reste armé pour réessayer ailleurs.
        },
      },
    );
  };

  // Placer une séance à la dérive pour une équipe, à une position donnée, sous le verdict moteur.
  const doPlace = (teamId: string, position: { dayOfWeek: number; startTime: string; venueId: string }) => {
    if (null === validScheduleId) {
      return;
    }
    placeMutation.mutate(
      { scheduleId: validScheduleId, body: { teamId, ...position } },
      {
        onSuccess: (result) => {
          setTargetMode(null);
          setUndo(null); // un placement n'a pas d'inverse (aucun endpoint de suppression de créneau)
          setEvictionNotice(null);
          setHighlightSlotIds(new Set());
          const compromises = result.compromises ?? [];
          setCompromiseNotice(compromises.length > 0 ? compromises : null);
          toast.success(compromises.length > 0 ? `Séance placée — ${compromises.length} compromis` : "Séance placée.");
        },
        onError: (error) => {
          // Lot C PR-2 : un ABANDON volontaire est déjà NOMMÉ + resynchronisé par le hook —
          // surtout pas le doubler d'un « réessayez » (VerdictAbandonedError étant une sous-classe
          // de EngineVerificationInterruptedError, il faut l'intercepter AVANT cette branche).
          if (error instanceof VerdictAbandonedError) {
            return;
          }
          if (error instanceof MoveRejectedError) {
            toast.error(error.violations[0]?.message ?? "Placement refusé par le moteur.");
            setHighlightSlotIds(violationHighlightSlotIds(error.violations, slots));
          } else if (error instanceof GenerationInProgressError) {
            toast.error("Une génération est en cours pour ce club — réessayez ensuite.");
          } else if (error instanceof EngineVerificationInterruptedError) {
            // P4-119 (b) : attente coupée côté client — on NOMME l'interruption, jamais « indisponible ».
            toast.error("La vérification a été interrompue avant la réponse — réessayez.");
          } else if (error instanceof TargetLockedError || error instanceof SlotEditError || error instanceof EngineTimeoutError) {
            toast.error(error.message);
          } else {
            toast.error("Le moteur n'a pas répondu — réessayez.");
          }
          // On reste en mode placement pour réessayer ailleurs.
        },
      },
    );
  };

  // P2-32 — l'ESSAI (dry-run) qui remplit la modale d'éviction : le moteur juge SANS écrire. Un
  // essai REFUSÉ arrive en 200 {valid:false} → onSuccess (pas onError) ; seuls verrou/génération/
  // transport passent par onError (la modale se ferme alors, le mode cible reste armé).
  const runEvictDryRun = (sourceSlotId: string, targetSlot: Slot) => {
    dryRunMutation.mutate(
      { id: sourceSlotId, patch: { dayOfWeek: targetSlot.dayOfWeek, startTime: toHourMinute(targetSlot.startTime), venueId: targetSlot.venueId, evictSlotId: targetSlot.id } },
      {
        onSuccess: (result) => {
          if (result.valid) {
            setEvictDialog({ phase: "accepted", sourceSlotId, targetSlot, compromises: result.compromises ?? [] });
          } else {
            const violations = result.violations ?? [];
            setEvictDialog({ phase: "refused", sourceSlotId, targetSlot, violations });
            // Surligner le conflit nommé (présentation pure, même chemin qu'un placement refusé).
            setHighlightSlotIds(violationHighlightSlotIds(violations, slots));
          }
        },
        onError: (error) => {
          // Refus MÉTIER (verrou D3, cible incohérente, génération en cours) : comportement
          // inchangé — la modale se ferme, le motif est toasté, le mode cible reste armé.
          if (error instanceof TargetLockedError || error instanceof SlotEditError) {
            setEvictDialog(null);
            toast.error(error.message);
            return;
          }
          if (error instanceof GenerationInProgressError) {
            setEvictDialog(null);
            toast.error("Une génération est en cours pour ce club — réessayez ensuite.");
            return;
          }
          // ÉCHEC de l'essai lui-même : la modale RESTE ouverte et NOMME la cause, avec [Réessayer].
          // Rien n'est tranché — demande fondateur : ne jamais se fermer en silence. Trois causes
          // DISTINCTES (P4-119 b) : le serveur a jugé le moteur trop lent (504 → `timeout`), l'attente
          // a été coupée CÔTÉ CLIENT (`interrupted`, surtout pas « indisponible » : rien ne le prouve),
          // ou une vraie panne réseau/5xx (`unreachable`).
          const failureKind = error instanceof EngineTimeoutError ? "timeout" : error instanceof EngineVerificationInterruptedError ? "interrupted" : "unreachable";
          setEvictDialog({ phase: "failed", sourceSlotId, targetSlot, failureKind });
        },
      },
    );
  };

  // Relancer l'essai depuis l'état `failed` de la modale : on repasse en « Vérification… » et on
  // rejoue le dry-run sur la MÊME cible.
  const retryEvictDryRun = () => {
    if (null === evictDialog) {
      return;
    }
    const { sourceSlotId, targetSlot } = evictDialog;
    setEvictDialog({ phase: "checking", sourceSlotId, targetSlot });
    runEvictDryRun(sourceSlotId, targetSlot);
  };

  // Confirmer l'éviction depuis la modale (état `accepted`) : le move RÉEL part (sans dryRun).
  const confirmEvict = () => {
    if (null === evictDialog) {
      return;
    }
    const { sourceSlotId, targetSlot } = evictDialog;
    doMove(sourceSlotId, { dayOfWeek: targetSlot.dayOfWeek, startTime: toHourMinute(targetSlot.startTime), venueId: targetSlot.venueId }, targetSlot.id);
  };

  // P2-43 volet (v) — la CEINTURE de l'offre (défense en profondeur) : un couple (gymnase, jour)
  // fermé n'est jamais une cible, même si un bouton fuyait. La grille filtre déjà l'offre ; ceci
  // garde le geste côté page.
  const isClosedTarget = (venueId: string, dayOfWeek: number): boolean => closedWindows.has(`${venueId}|${dayOfWeek}`);

  // Un clic sur une case de la grille EN mode cible (la grille route tout ici). La page décide :
  // annuler (re-clic source), déplacer/placer sur une case libre, ou évincer (via l'essai).
  const onPickTarget = (cellSlotId: string) => {
    if (null === targetMode) {
      return;
    }
    // P2-51 PR-6 (D11) — déplacement de GROUPE : la case cible (fenêtre libre OU occupée) part au
    // rail move-group. Pas d'éviction ici (le rail n'en porte pas) : le moteur tranche, violations
    // affichées telles quelles. Cliquer la case SOURCE (une de ses séances membres) annule.
    if (targetMode.kind === "move-group") {
      const { blockId, source } = targetMode;
      let targetCase: { venueId: string; dayOfWeek: number; startTime: string } | null = null;
      if (isEmptySlotId(cellSlotId)) {
        const win = gridSlots.find((s) => s.id === cellSlotId);
        if (undefined !== win && !isClosedTarget(win.venueId, win.dayOfWeek)) {
          targetCase = { venueId: win.venueId, dayOfWeek: win.dayOfWeek, startTime: toHourMinute(win.startTime) };
        }
      } else {
        const targetSlot = slots.find((s) => s.id === cellSlotId);
        if (undefined !== targetSlot && !isClosedTarget(targetSlot.venueId, targetSlot.dayOfWeek)) {
          targetCase = { venueId: targetSlot.venueId, dayOfWeek: targetSlot.dayOfWeek, startTime: toHourMinute(targetSlot.startTime) };
        }
      }
      if (null === targetCase) {
        return;
      }
      const srcKey = `${source.venueId}|${source.dayOfWeek}|${toHourMinute(source.startTime)}`;
      const tgtKey = `${targetCase.venueId}|${targetCase.dayOfWeek}|${targetCase.startTime}`;
      if (srcKey === tgtKey) {
        cancelTarget();
        return;
      }
      doMoveGroup(blockId, source, targetCase);
      return;
    }
    if (targetMode.kind === "move" && cellSlotId === targetMode.sourceSlotId) {
      cancelTarget();
      return;
    }
    if (isEmptySlotId(cellSlotId)) {
      const win = gridSlots.find((s) => s.id === cellSlotId);
      if (undefined === win || isClosedTarget(win.venueId, win.dayOfWeek)) {
        return;
      }
      const position = { dayOfWeek: win.dayOfWeek, startTime: toHourMinute(win.startTime), venueId: win.venueId };
      if (targetMode.kind === "move") {
        doMove(targetMode.sourceSlotId, position);
      } else {
        doPlace(targetMode.teamId, position);
      }
      return;
    }
    // Case OCCUPÉE.
    const targetSlot = slots.find((s) => s.id === cellSlotId);
    if (undefined === targetSlot || isClosedTarget(targetSlot.venueId, targetSlot.dayOfWeek)) {
      return;
    }
    if (targetMode.kind === "move") {
      // D6 + P2-32 : la modale s'ouvre en VÉRIFICATION et un ESSAI (dry-run) part — rien n'est
      // écrit. Le verdict (accepté avec compromis / refusé) remplira la modale.
      setEvictDialog({ phase: "checking", sourceSlotId: targetMode.sourceSlotId, targetSlot });
      runEvictDryRun(targetMode.sourceSlotId, targetSlot);
    } else {
      // Placement sur une case occupée : le moteur tranche (capacité). Pas d'éviction ici — le
      // rail /place-slot n'en porte pas (PR A) ; un refus se lit et on réessaie.
      doPlace(targetMode.teamId, { dayOfWeek: targetSlot.dayOfWeek, startTime: toHourMinute(targetSlot.startTime), venueId: targetSlot.venueId });
    }
  };

  // P2-30 · P2-51 PR-6 — l'état du mode cible passé à la grille (jamais sur un planning lecture
  // seule/FAILED). Un déplacement (simple OU de groupe) marque sa SOURCE et propose des cibles
  // (variante « move ») ; un placement à la dérive n'a pas de source (variante « place »).
  const gridTargetMode =
    null !== targetMode && !isReadOnly && !isFailed
      ? {
          active: true as const,
          sourceSlotId: targetMode.kind === "move" || targetMode.kind === "move-group" ? targetMode.sourceSlotId : null,
          variant: (targetMode.kind === "place" ? "place" : "move") as "move" | "place",
        }
      : undefined;

  // Annuler le dernier geste (profondeur 1). Move simple = move inverse ; move-éviction = move
  // inverse PUIS replacement de l'évincée (2 verdicts) ; échec partiel = toast honnête.
  const runUndo = () => {
    if (null === undo || null === validScheduleId) {
      return;
    }
    const current = undo;
    moveMutation.mutate(
      { id: current.slotId, patch: current.from },
      {
        onSuccess: () => {
          // Le geste est REVERTÉ : le bandeau de compromis qu'il avait produit n'a plus lieu d'être.
          setCompromiseNotice(null);
          if (current.kind === "move") {
            setUndo(null);
            setEvictionNotice(null);
            toast.success("Dernier geste annulé.");
            return;
          }
          placeMutation.mutate(
            {
              scheduleId: validScheduleId,
              body: { teamId: current.evicted.teamId, dayOfWeek: current.evicted.dayOfWeek, startTime: toHourMinute(current.evicted.startTime), venueId: current.evicted.venueId, durationMinutes: current.evicted.durationMinutes },
            },
            {
              onSuccess: () => {
                setUndo(null);
                setEvictionNotice(null);
                toast.success("Dernier geste annulé.");
              },
              onError: () => {
                // La source est revenue mais l'évincée n'a pas pu être replacée : on le dit sans mentir.
                setUndo(null);
                setEvictionNotice(null);
                toast.error(`${teamNameOf(current.sourceTeamId)} est revenue, ${teamNameOf(current.evicted.teamId)} reste à replacer.`);
              },
            },
          );
        },
        onError: () => toast.error("Annulation impossible — réessayez."),
      },
    );
  };

  // Raccourci d'éviction : remettre l'évincée sur la case que la source vient de libérer.
  const placeEvictedShortcut = () => {
    if (null === evictionNotice || null === validScheduleId) {
      return;
    }
    const { evicted, freed } = evictionNotice;
    placeMutation.mutate(
      { scheduleId: validScheduleId, body: { teamId: evicted.teamId, dayOfWeek: freed.dayOfWeek, startTime: freed.startTime, venueId: freed.venueId } },
      {
        onSuccess: (result) => {
          setEvictionNotice(null);
          setUndo(null); // l'évincée est replacée ailleurs que par l'inverse : l'undo n'a plus de sens
          const compromises = result.compromises ?? [];
          setCompromiseNotice(compromises.length > 0 ? compromises : null);
          toast.success(compromises.length > 0 ? `${teamNameOf(evicted.teamId)} replacée — ${compromises.length} compromis` : `${teamNameOf(evicted.teamId)} replacée.`);
        },
        onError: (error) => toast.error(error instanceof MoveRejectedError ? (error.violations[0]?.message ?? "Replacement refusé par le moteur.") : "Replacement impossible — réessayez."),
      },
    );
  };
  // ----------------------------------------------------------------------------------------
  // Les séances de cette version que la période ne servirait plus : elles restent à
  // l'écran (et dans l'export), mais le gestionnaire doit savoir qu'elles sont périmées.
  // Sur les créneaux GÉNÉRÉS uniquement : les pseudo-réservations d'un FAILED ne sont
  // pas des « séances de ce planning » (et celles d'un gymnase désactivé sont déjà
  // filtrées à la source, comme le fait le payload).
  const staleVenueSessions = useMemo(
    () => (0 === disabledVenueIds.size ? 0 : generatedSlots.filter((s) => disabledVenueIds.has(s.venueId)).length),
    [generatedSlots, disabledVenueIds],
  );

  // From gridSlots (incl. empty windows in gymnase view) so a venue that has ONLY
  // empty slots still appears in the ResourceFilter picker — otherwise focusVenue
  // could filter to a venue the picker cannot show/clear.
  const resourceGroups = useMemo(() => availableResourceGroups(gridSlots, viewMode, lookups, tiers), [gridSlots, viewMode, lookups, tiers]);
  // P3-20 — la vue « club » a son propre rendu (matrice équipes × jours) : on ne paie pas un
  // layout temporel que personne n'affiche (d'où les créneaux vidés en entrée de `buildGrid`).
  const isClubView = "club" === viewMode;
  const model = useMemo(() => buildGrid(isClubView ? [] : gridSlots, viewMode, lookups, new Set(resourceFilter)), [isClubView, gridSlots, viewMode, lookups, resourceFilter]);
  const clubModel = useMemo(
    () => (isClubView ? buildClubView(gridSlots, lookups, new Set(resourceFilter), tiers) : null),
    [isClubView, gridSlots, lookups, resourceFilter, tiers],
  );

  // Un diagnostic qui NOMME une colonne absente de l'écran proposerait un focus vers rien —
  // un clic qui vide la grille (revue #342). Seul sort le diagnostic d'un gymnase désactivé
  // dont il ne reste AUCUNE séance : s'il en porte encore, sa colonne existe et son
  // diagnostic reste actionnable.
  const hiddenVenueIds = useMemo(() => {
    if (0 === disabledVenueIds.size) {
      return disabledVenueIds;
    }
    const placed = new Set(slots.map((s) => s.venueId));

    return new Set([...disabledVenueIds].filter((id) => !placed.has(id)));
  }, [disabledVenueIds, slots]);
  const diagnostics = useMemo(
    () => (0 === hiddenVenueIds.size ? allDiagnostics : allDiagnostics.filter((d) => null === d.venueId || !hiddenVenueIds.has(d.venueId))),
    [allDiagnostics, hiddenVenueIds],
  );

  // P4-40 — l'aside s'ouvre au sortir d'une génération lancée DEPUIS LE WIZARD, mais
  // seulement s'il a quelque chose à montrer.
  //
  // ⚠ Deux raisons d'attendre les diagnostics plutôt que d'initialiser à `!embedded`
  // (revue #350) : (1) au premier rendu ils ne sont pas encore là, donc l'aside s'ouvrait
  // TOUJOURS — y compris sur une génération propre, où il volait 20rem de largeur à la
  // grille dans une hauteur embarquée déjà courte pour n'afficher que « le planning est
  // propre » ; (2) refermer l'aside est un geste, pas un accident.
  //
  // ⚠ L'amorce est indexée sur la VERSION affichée, pas sur un booléen « déjà fait »
  // (revue #350 round 2) : le premier jet gardait ici le verrou à un coup que le correctif
  // du panneau venait pourtant de condamner un cran plus bas. Conséquence, après UN repli
  // manuel aucune version suivante ne rouvrait l'aside — les erreurs d'une V2 restaient
  // derrière la barre compacte, « on risque de ne pas le voir » de nouveau. Les deux
  // moitiés de la même règle se déclenchent donc sur le même signal.
  const [asideSeededFor, setAsideSeededFor] = useState<string | null>(null);
  if (embedded && !diagnosticsPending && null !== validScheduleId && asideSeededFor !== validScheduleId) {
    setAsideSeededFor(validScheduleId);
    // Les DEUX sens, une fois les diagnostics lus : une version qui en porte ouvre l'aside,
    // une version propre le referme. Ne traiter que l'ouverture laissait 20rem occupés par
    // « le planning est propre » après un passage d'une version bavarde à une version
    // saine — dans une hauteur embarquée déjà courte (revue #350 round 2).
    setDiagnosticsCollapsed(0 === diagnostics.length);
  }

  // Clicking the solver's "unused_slot" warning brings its venue column on screen
  // (venue view, filtered to that venue) so the concerned `vide` cell is visible.
  const focusVenue = useCallback(
    (venueId: string) => {
      armNavTransition(); // GESTE (clic sur un diagnostic) arme le voile changement de page
      setViewMode("gymnase");
      clearResourceFilter();
      toggleResource(venueId);
    },
    [setViewMode, clearResourceFilter, toggleResource],
  );

  // Cliquer un diagnostic `conflict` ouvre LE créneau fautif (SlotDetail) et l'amène à l'écran.
  // rAF + appels optionnels (précédent ConstraintsStep) : on laisse React peindre le créneau
  // sélectionné avant de scroller, et `scrollIntoView` n'existe pas en jsdom.
  const openSlot = useCallback(
    (slotId: string) => {
      setSelectedSlotId(slotId);
      requestAnimationFrame(() => document.querySelector(`[data-slot-id="${slotId}"]`)?.scrollIntoView?.({ block: "center", inline: "center", behavior: "smooth" }));
    },
    [setSelectedSlotId],
  );

  // Le chemin SURLIGNAGE (tous les autres types de diagnostic) n'amenait PAS la grille au
  // créneau : « ça illumine mais je dois chercher pour le trouver » (retour fondateur
  // 2026-08-15). Même recette que openSlot — le PREMIER créneau surligné est centré ; un
  // clic qui ÉTEINT le surlignage (set vide) ne scrolle pas.
  const highlightSlots = useCallback((slotIds: Set<string>) => {
    setHighlightSlotIds(slotIds);
    const [first] = slotIds;
    if (undefined !== first) {
      requestAnimationFrame(() => document.querySelector(`[data-slot-id="${first}"]`)?.scrollIntoView?.({ block: "center", inline: "center", behavior: "smooth" }));
    }
  }, []);

  const selectedCell = model.cells.find((c) => c.slotId === selectedSlotId) ?? null;

  // Sélectionner un créneau REPLIE les diagnostics (retour fondateur : « réduire
  // automatiquement le panel de diagnostique, sinon c'est impossible de le relancer »). Repli,
  // et non masquage : la décision d'hier (masquer sauf s'il restait une ERROR) enterrait la
  // place ET l'accès au panneau. Replié, la barre garde le compte + la sévérité max VISIBLES et
  // rouvre d'un clic ; rien n'est enterré, une ERREUR reste signalée — d'où le retrait de
  // l'exception ERROR (un cas particulier de moins). À la FERMETURE du créneau, on restaure
  // l'état d'avant la sélection.
  //
  // Ajustement en phase de rendu (le lint du dépôt interdit `setState` dans un effet), clé = la
  // transition de sélection, à l'image de `asideSeededFor` plus haut.
  const slotSelected = null !== selectedCell && null !== selectedSlot;
  const activeSlotId = slotSelected ? selectedSlotId : null;
  const [slotCollapse, setSlotCollapse] = useState<{ slotId: string | null; restoreExpanded: boolean }>({ slotId: null, restoreExpanded: false });
  if (slotCollapse.slotId !== activeSlotId) {
    if (null !== activeSlotId && null === slotCollapse.slotId) {
      // Ouverture d'un créneau (depuis aucun) : mémoriser l'expansion courante, puis replier.
      setSlotCollapse({ slotId: activeSlotId, restoreExpanded: !diagnosticsCollapsed });
      setDiagnosticsCollapsed(true);
    } else if (null === activeSlotId && null !== slotCollapse.slotId) {
      // Fermeture du créneau : restaurer l'état d'avant (ne ré-ouvrir que si c'était ouvert).
      if (slotCollapse.restoreExpanded) {
        setDiagnosticsCollapsed(false);
      }
      setSlotCollapse({ slotId: null, restoreExpanded: false });
    } else {
      // Passage d'un créneau à un autre : garder le repli, suivre juste l'id.
      setSlotCollapse((prev) => ({ ...prev, slotId: activeSlotId }));
    }
  }

  const categoryLabel = useMemo(() => {
    if (null === selectedCell) {
      return "—";
    }
    const slot = slots.find((s) => s.id === selectedCell.slotId);
    const team = slot ? lookups.teams.get(slot.teamId) : undefined;
    const category = team ? categories.find((c) => c.id === team.sportCategoryId) : undefined;
    return category?.name ?? "—";
  }, [selectedCell, slots, lookups, categories]);

  if (schedulesLoading) {
    return <FullPageSpinner />;
  }

  // P2-44 (PR-2) — le surfaçage de la transcription ne vit QUE sur l'écran de génération d'une
  // période (embarqué + porté) : le panneau « à replacer » (servi, jamais redérivé), la mise en
  // évidence des vides quand il est non-vide, et la comparaison avec le socle pointé.
  const transcriptionSurface = embedded && scoped;
  const emphasizeEmpty = transcriptionSurface && null !== toReplace && toReplace.length > 0;
  const seasonComparisonId = transcriptionSurface ? (planRepresentative(visibleSeasonPlans(schedules))?.id ?? null) : null;
  const venueNameOf = (venueId: string): string => lookups.venues.get(venueId)?.name ?? "Gymnase";

  // P2-44 PR-4 — la table `slotId → origine de saison` des créneaux déviés, pour marquer la grille.
  // Présentation pure : le backend a déjà décidé l'écart (`socleDeviation.moved`).
  const socleDeviatedSlots = deviatedSlots(socleDeviation, venueNameOf);

  const planningTitle = displayedPlanName ?? "Planning";
  // Nom du fichier exporté = nom du PLAN affiché (retour fondateur 2026-07-18).
  // Il lisait `selectedSchedule.name`, c'est-à-dire le nom de la VERSION — que les
  // clients inventaient : le fichier remis aux coachs s'appelait « Version de période ».
  // Repli sur le nom de la version si le plan n'est pas encore résolu : un fichier au
  // nom imparfait vaut mieux qu'un « planning.xlsx » anonyme (revue #339).
  const exportName = displayedPlanName;
  const structureDiverged =
    null !== selectedSchedule && isSeasonPlanType(selectedSchedule.planType)
    && typeof selectedSchedule.generatedTeamCount === "number" && teams.length > 0
    && selectedSchedule.generatedTeamCount !== teams.length;

  return (
    <div>
      <div className="mb-4 flex items-center gap-3">
        {me?.club?.logoUrl ? <img src={me.club.logoUrl} alt="" className="size-8 shrink-0 rounded object-contain" /> : null}
        {null !== editingPlanningName ? (
          <input
            // eslint-disable-next-line jsx-a11y/no-autofocus -- inline rename field revealed on demand
            autoFocus
            aria-label="Nom du planning"
            value={editingPlanningName}
            onChange={(e) => setEditingPlanningName(e.target.value)}
            onKeyDown={(e) => {
              if ("Enter" === e.key) {
                // Le plan AFFICHÉ (cf. displayedPlan) : c'était `me.seasonPlan.id` en dur,
                // donc renommer un planning de période renommait celui de la saison.
                // Un nom vidé n'écrase rien — un plan sans nom n'a plus d'identité à lire.
                if (null !== displayedPlan && "" !== editingPlanningName.trim()) {
                  renamePlanning.mutate({ planId: displayedPlan.id, name: editingPlanningName.trim() });
                }
                setEditingPlanningName(null);
              } else if ("Escape" === e.key) {
                setEditingPlanningName(null);
              }
            }}
            onBlur={() => setEditingPlanningName(null)}
            className="h-9 rounded-md border border-input bg-background px-3 text-xl font-semibold"
          />
        ) : (
          <>
            {/* ADR-0002 inv. 12: THE plan's name lives here, on the plan — not in the version selector. */}
            <h1 className="border-l-[3px] border-accent pl-3 text-2xl font-semibold">{planningTitle}</h1>
            {/* « principal » qualifie LE planning de la saison (le plan SEASON), par
                opposition aux plannings secondaires de période — pas la version choisie.
                En portée période, il ne peut jamais apparaître (la portée n'expose aucune
                version de saison — bug fondateur 2026-08-19). */}
            {!scoped && null !== selectedSchedule && isSeasonPlanType(selectedSchedule.planType) ? (
              <span className="flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">
                <Star className="size-3" />
                principal
              </span>
            ) : null}
            {/* Pas de plan résolu = rien à renommer : proposer le geste enverrait
                l'écriture sur un id qu'on n'a pas (c'est ce qui la faisait retomber
                sur le plan de saison). */}
            {null !== displayedPlan && workingSeason && !workingSeason.isReadonly ? (
              <Button size="sm" variant="ghost" className="h-8 px-2" aria-label="Renommer le planning" title="Renommer le planning" onClick={() => setEditingPlanningName(displayedPlan.name)}>
                <Pencil className="size-4" />
              </Button>
            ) : null}
            {/* Supprimer : plannings SECONDAIRES uniquement (jamais le socle), et
                jamais pendant une génération en vol (la cascade emporterait la version
                en cours de solve — revue B2 F3) → retour cockpit. */}
            {null !== overlayDeleteEntryId && workingSeason && !workingSeason.isReadonly && !showGenerationWaiting ? (
              <DeletePlanningButton calendarEntryId={overlayDeleteEntryId} schedulePlanId={selectedSchedule?.schedulePlanId ?? null} title={displayedPlanName ?? "ce planning"} onDeleted={() => navigate("/")} iconOnly />
            ) : null}
          </>
        )}
        {/* P5-6 — porte contextuelle : joint le planning affiché au signalement. */}
        <FeedbackButton className="ml-auto" screen="/planning" scheduleId={validScheduleId} />
      </div>

      {/* Planning PÉRIMÉ (pas faux) : retouché à la main (F2b), une contrainte a changé (F2c),
          une DONNÉE DU CLUB a changé (P4-87), ou des équipes ont été ajoutées/retirées
          (structureDiverged, fusionné ICI plutôt qu'en bandeau séparé). UNE seule bannière qui
          nomme sa/ses cause(s) ; sur un planning validé (lecture seule) elle propose « rouvrir
          puis régénérer », jamais un geste qui finirait en 409. Voir lib/staleness. */}
      {(() => {
        const stale = showGenerationWaiting || null === selectedSchedule
          ? null
          : stalenessMessage({
            manuallyEdited: true === selectedSchedule.manuallyEditedSinceGeneration,
            constraintsChanged: true === selectedSchedule.constraintsChangedSinceGeneration,
            resourcesChanged: true === selectedSchedule.resourcesChangedSinceGeneration,
            structureDiverged,
            readOnly: isReadOnly,
          });
        return null === stale ? null : (
          <p className="mb-4 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
            {stale}
          </p>
        );
      })()}


      {scoped && (null === scopeVersions || 0 === scopeVersions.length) ? (
        // Portée sans aucune version : état vide EXPLICITE, jamais un repli sur une version
        // de saison (fail-closed — bug fondateur 2026-08-19). Le lanceur vit à l'étape
        // Génération ; ici on nomme l'absence plutôt que de montrer autre chose.
        <EmptyState title="Aucune version pour cette période" description="Générez le planning de cette période pour le voir apparaître ici." />
      ) : 0 === schedules.length ? (
        <EmptyState title="Aucun planning" description="Passez par l'assistant pour saisir vos données et générer un premier planning." />
      ) : (
        <>
          <div className="mb-4">
            <PlanningToolbar
              schedules={schedules}
              scopePlanId={scopePlanId}
              selectedScheduleId={validScheduleId}
              onSelectSchedule={(id) => {
                armNavTransition(); // GESTE (selecteur de version) arme le voile ; les appels programmatiques non
                setSelectedScheduleId(id);
              }}
              viewMode={viewMode}
              onViewMode={(mode) => {
                armNavTransition(); // GESTE (bascule de vue) arme le voile
                setViewMode(mode);
              }}
              isGenerating={showGenerationWaiting || regenerateMutation.isPending || regenerateOverlayMutation.isPending}
              actionBusy={actionBusy}
              disableRegenerate={regenerateDisabled}
              outputCredits={null === credits ? null : { count: credits.remaining, blocked: !credits.canGenerate }}
              onRegenerate={() => {
                if (null === validScheduleId) {
                  return;
                }
                const select = { onSuccess: (created: { id: string }) => setSelectedScheduleId(created.id) };
                // An overlay "Régénérer" creates a NEW version UNDER its period's plan
                // (ADR-0002 C4); a season plan regenerates from the current structure.
                const overlayPlanId = !isSeasonPlanType(selectedSchedule?.planType) ? selectedSchedule?.schedulePlanId ?? null : null;
                if (null !== overlayPlanId) {
                  regenerateOverlayMutation.mutate(overlayPlanId, select);
                } else {
                  regenerateMutation.mutate(validScheduleId, select);
                }
              }}
              onValidate={() => setValidateOpen(true)}
              onReopen={() => reopen()}
              onDelete={() => validScheduleId && deleteMutation.mutate(validScheduleId)}
              onRegenerateFrom={() => setRegenerateFromOpen(true)}
              embedded={embedded}
              rightSlot={
                null !== validScheduleId && !showGenerationWaiting && slots.length > 0 ? (
                  <ExportMenu
                    scheduleId={validScheduleId}
                    venues={venues}
                    exportName={exportName}
                    screenFilterCount={resourceFilter.length}
                  />
                ) : null
              }
              // Le filtre part en ligne 1, contre le sélecteur de vue dont il porte le
              // libellé (P4-43). ⚠ Il n'est toujours PAS couplé à l'export (le rendu PDF
              // est serveur et ignore tout filtre client) — mais depuis P4-62 l'export
              // ANNONCE son périmètre quand l'écran est filtré : on ne masque jamais ce
              // qu'un export contient.
              filterSlot={<ResourceFilter viewMode={viewMode} groups={resourceGroups} selected={resourceFilter} onToggle={toggleResource} onClear={clearResourceFilter} />}
            />
          </div>

          {/* P2-44 (PR-4) — le compteur de carence : au démarrage d'une FERMETURE, une phrase
              factuelle et neutre dit combien de places manquent (jamais une alarme). Statique
              (pas d'aria-live) : elle est là dès l'arrivée, elle ne s'annonce pas. Rien si la
              capacité n'est pas connue (aucun payload). */}
          {null !== capacity ? (
            <p className="mb-4 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm tabular-nums">{capacityShortfallSentence(capacity.demand, capacity.offer)}</p>
          ) : null}

          {/* P2-44 (PR-2) — après une transcription depuis le socle : la comparaison avec le
              planning de saison (consultation) et la liste « à replacer » servie par la route.
              Le bouton apparaît dès qu'un socle est consultable ; le panneau, quand la route a
              renvoyé des séances non reprises (session d'écran — voir ToReplaceList). */}
          {null !== seasonComparisonId ? (
            <div className="mb-4">
              <Button variant="outline" size="sm" onClick={() => setCompareOpen(true)}>
                <GitCompare className="size-4" />
                Comparer avec la saison
              </Button>
            </div>
          ) : null}
          {null !== toReplace && toReplace.length > 0 ? (
            <ToReplaceList entries={toReplace} teamName={teamNameOf} venueName={venueNameOf} />
          ) : null}

          {/* P2-44 PR-5 — les écarts NOMMÉS vs le socle (déplacées + non replacées), SERVIS par la
              route ; le panneau s'AJOUTE à « à replacer » (décision fondateur : les deux affichés).
              Ne rend rien tant qu'il n'y a aucun écart. */}
          {null !== socleDeviation ? (
            <SocleDeviationPanel moved={socleDeviation.moved} unplaced={socleDeviation.unplaced} teamName={teamNameOf} venueName={venueNameOf} onSelectSlot={openSlot} />
          ) : null}

          {/* P2-30 (geste 3) — les équipes à la dérive : un clic ARME le placement (mode cible).
              COMPLETED + modifiable seulement. */}
          {!showGenerationWaiting && !isReadOnly ? (
            <DriftBanner
              entries={driftEntries}
              teamName={teamNameOf}
              onPlace={armPlace}
              activeTeamId={targetMode?.kind === "place" ? targetMode.teamId : null}
            />
          ) : null}

          {/* P2-44 PR-3 (ADR-0004) — LE COMBLEMENT : sur une version de PÉRIODE qui a des séances
              à replacer (le prédicat SERVI de la dérive, jamais recomposé ici), un solve PARTIEL
              qui FIGE le placé et ne place que les trous. Outil d'appoint — « Régénérer » (solve
              complet) reste dans la barre d'outils. La V+1 est sélectionnée à la réussite ; les
              refus (409/422) sont servis et affichés par le hook. */}
          {!showGenerationWaiting && !isReadOnly && null !== slotLayerId && driftEntries.length > 0 ? (
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <Button
                size="sm"
                variant="outline"
                disabled={null === validScheduleId || fillMutation.isPending}
                onClick={() => {
                  if (null === validScheduleId) {
                    return;
                  }
                  fillMutation.mutate(validScheduleId, { onSuccess: (created) => setSelectedScheduleId(created.id) });
                }}
              >
                <Sparkles className="size-4" />
                Combler automatiquement
              </Button>
              <span className="text-xs text-muted-foreground">Place les séances à replacer sans toucher au reste du planning.</span>
            </div>
          ) : null}

          {/* P2-30 (geste 2) + P2-32 (geste 3) — UN SEUL bandeau combiné : en tête le raccourci
              d'éviction (remettre l'évincée sur la case libérée — le toast n'a pas d'action), et
              dessous les compromis NOMMÉS du dernier geste écrit. Le close efface les deux ; le
              geste suivant / le changement de version les remplacent. */}
          {null !== evictionNotice || null !== compromiseNotice ? (
            <div className="mb-4 flex flex-col gap-2 rounded-md border border-accent/40 bg-accent/10 px-3 py-2 text-sm" role="status">
              <div className="flex flex-wrap items-center gap-3">
                {null !== evictionNotice ? (
                  <>
                    <span className="text-foreground">
                      <span className="font-medium">{teamNameOf(evictionNotice.evicted.teamId)}</span> est à replacer.
                    </span>
                    <Button size="sm" variant="outline" className="h-8" disabled={busy} onClick={placeEvictedShortcut}>
                      Remettre {teamNameOf(evictionNotice.evicted.teamId)} sur {DAY_ABBR.get(evictionNotice.freed.dayOfWeek) ?? "?"} {evictionNotice.freed.startTime}
                    </Button>
                  </>
                ) : (
                  <span className="font-medium text-foreground">
                    {compromiseNotice?.length} compromis sur ce geste
                  </span>
                )}
                <button
                  type="button"
                  onClick={() => {
                    setEvictionNotice(null);
                    setCompromiseNotice(null);
                  }}
                  aria-label="Ignorer"
                  className="ml-auto rounded p-1 text-muted-foreground hover:text-foreground"
                >
                  <X className="size-4" />
                </button>
              </div>
              {null !== compromiseNotice ? <CompromiseList compromises={compromiseNotice} /> : null}
            </div>
          ) : null}

          {/* Ce planning a été généré quand un gymnase servait encore la période : ses
              séances restent affichées ET exportées, mais elles ne décrivent plus la
              période telle qu'elle est réglée. On le dit plutôt que de les escamoter. */}
          {!showGenerationWaiting && staleVenueSessions > 0 ? (
            <p className="mb-3 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
              {staleVenueSessions} séance(s) de ce planning sont placées dans un gymnase désactivé depuis pour cette période — régénérez-la pour qu'elles en sortent.
            </p>
          ) : null}

          {/* Génération en échec : la grille ne montre que les RÉSERVATIONS (pseudo-créneaux
              lecture seule) — on le dit, sinon elles passeraient pour un planning généré.
              Et l'export ne les contient pas : il rend les créneaux du serveur (§7.2 pt 3). */}
          {!showGenerationWaiting && isFailed && slots.length > 0 && 0 === generatedSlots.length ? (
            <p className="mb-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-foreground">
              La génération a échoué : aucun créneau n'a été placé. Seuls vos créneaux réservés sont affichés — ils restent acquis quoi qu'il arrive. Les exports sont vides pour ce planning.
            </p>
          ) : null}

          {showGenerationWaiting ? (
            <GenerationWaiting />
          ) : 0 === slots.length ? (
            isFailed ? (
              <EmptyState title="Génération en échec" description="Aucun créneau n'a été placé, et ce planning n'a aucune réservation à afficher. Corrigez les contraintes signalées puis régénérez." />
            ) : slotsBusy ? (
              // PREMIER chargement d'une version (aucune donnée précédente à voiler) : « Planning
              // vide » MENTIRAIT tant que la requête n'a pas répondu. On affiche l'état de
              // chargement — même indicateur que le voile de la grille — jusqu'au verdict. Une
              // fois la réponse arrivée et RÉELLEMENT vide, `slotsBusy` retombe → « Planning vide ».
              <div className="flex h-64 items-center justify-center rounded-lg border border-border bg-card" role="status" aria-busy="true" aria-live="polite">
                <span className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                  <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                  Chargement des créneaux…
                </span>
              </div>
            ) : (
              <EmptyState title="Planning vide" description="Ce planning ne contient aucun créneau placé pour le moment." />
            )
          ) : (
            // grid-rows-[minmax(0,1fr)] gives the single row a DEFINITE size (the
            // container height) — with the default `auto` row the children's h-full
            // cannot resolve, the WeekGrid lays out at full content height and
            // overflows the page instead of scrolling internally.
            //
            // The right column only exists when there is something to show: the
            // slot-detail panel (opened on click) or, for an editable planning,
            // the diagnostics. In read-only consultation with no slot selected the
            // grid takes the full width; closing the panel returns to full width.
            (() => {
              const showDetail = null !== selectedCell && null !== selectedSlot;
              // The diagnostics aside only claims grid width when it has content
              // to show: a selected slot's detail, or the (expanded) diagnostics.
              // ⚠ « Déplié » est un état de l'ASIDE, pas une promesse de contenu : c'est
              // l'amorce ci-dessus qui replie l'aside sur une version sans diagnostic. Le
              // rouvrir à la main reste possible et respecté, y compris à vide. Sélectionner
              // un créneau replie les diagnostics (cf. `slotCollapse`) : ils cohabitent alors
              // avec le détail dans l'aside dès qu'on les rouvre — chacun borné à la grille.
              const showDiagnostics = !isReadOnly && !diagnosticsCollapsed;
              const showAside = showDetail || showDiagnostics || locksPanelOpen;
              // Barre repliée : le compte TOTAL + la sévérité la plus haute restent lisibles —
              // replier ne doit rien enterrer (« Diagnostics (6) · 2 erreurs »).
              const topSummary = topSeveritySummary(diagnostics);
              const height = embedded ? "lg:h-[max(calc(100vh-24rem),26rem)]" : "lg:h-[calc(100vh-16rem)]";
              return (
                <div className={`${showAside ? "lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:grid-rows-[minmax(0,1fr)] lg:gap-4" : ""} ${height}`}>
                  {/* min-h-0 is essential: without it the flex-1 grid wrapper keeps
                      its content height and overflows past the container, spilling
                      under the sticky footer (revue #204 — grille coupée en 2). */}
                  <div className="relative flex min-h-0 min-w-0 flex-col gap-2 lg:h-full">
                    {/* Collapsed panels → compact bar buttons re-open the aside; the grid keeps
                        full width until then (user request). Verrous manuels vit À CÔTÉ de
                        Diagnostics avec la MÊME affordance (retour fondateur : un clic ouvre,
                        le repli du panneau referme — plus de bouton dans la toolbar). */}
                    <div className="flex shrink-0 flex-wrap items-center gap-2 self-start empty:hidden">
                      {!isReadOnly && diagnosticsCollapsed ? (
                        <button
                          type="button"
                          onClick={() => setDiagnosticsCollapsed(false)}
                          className="flex items-center gap-2 rounded-md border border-border px-2 py-1 text-sm hover:bg-muted"
                        >
                          <AlertTriangle className={`size-4 ${diagnostics.length > 0 ? "text-warning" : "text-muted-foreground"}`} />
                          <span>Diagnostics du système{diagnostics.length > 0 ? ` (${diagnostics.length})` : ""}</span>
                          {null !== topSummary ? <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{topSummary}</span> : null}
                        </button>
                      ) : null}
                      {!locksPanelOpen && manualLocks.length > 0 ? (
                        <button
                          type="button"
                          onClick={() => setLocksPanelOpen(true)}
                          title="Voir les verrous posés à la main"
                          className="flex items-center gap-2 rounded-md border border-border px-2 py-1 text-sm hover:bg-muted"
                        >
                          <Lock className="size-4 text-accent" />
                          <span>Verrous manuels ({manualLocks.length})</span>
                        </button>
                      ) : null}
                      {/* P2-30 (geste 4) — annuler le dernier geste (profondeur 1, session). */}
                      {null !== undo && !isReadOnly ? (
                        <button
                          type="button"
                          onClick={runUndo}
                          disabled={busy}
                          className="flex items-center gap-2 rounded-md border border-border px-2 py-1 text-sm hover:bg-muted disabled:opacity-60"
                        >
                          <Undo2 className="size-4 text-muted-foreground" />
                          <span>Annuler le dernier geste</span>
                        </button>
                      ) : null}
                    </div>
                    <div className="relative min-h-0 min-w-0 flex-1" aria-busy={slotsBusy}>
                      {/* Chargement des créneaux : la grille reste en place mais VOILÉE
                          (opacité + voile qui CAPTE les clics — `pointer-events-none` sur
                          le contenu voilé, le voile lui-même intercepte) + indicateur
                          centré. Rien ne « passe au travers » vers une grille périmée. */}
                      <div className={slotsBusy ? "pointer-events-none h-full opacity-40 transition-opacity" : "h-full"}>
                        {null !== clubModel ? (
                          // Une vue différente, les MÊMES gestes : la page passe exactement les
                          // mêmes handlers qu'à la grille (P3-20, décision fondateur).
                          <ClubViewTable
                            model={clubModel}
                            selectedSlotId={selectedSlotId}
                            onSelectSlot={setSelectedSlotId}
                            highlightSlotIds={highlightSlotIds}
                            onToggleLock={isReadOnly || isFailed ? undefined : requestToggleLock}
                            lockLens={lockLens}
                            targetMode={gridTargetMode}
                            onPickTarget={onPickTarget}
                            onCancelTarget={cancelTarget}
                          />
                        ) : (
                        <WeekGrid
                          model={model}
                          selectedSlotId={selectedSlotId}
                          onSelectSlot={setSelectedSlotId}
                          highlightSlotIds={highlightSlotIds}
                          // Lecture seule (validé) ou FAILED (pseudo-créneaux sans existence
                          // serveur) → pas de bascule : le cadenas reste indicateur passif.
                          onToggleLock={isReadOnly || isFailed ? undefined : requestToggleLock}
                          lockLens={lockLens}
                          // P2-30 — mode cible click-click (jamais sur un planning lecture seule/FAILED).
                          targetMode={gridTargetMode}
                          onPickTarget={onPickTarget}
                          onCancelTarget={cancelTarget}
                          // P2-43 volet (v) — les couples fermés de la période : cases vides
                          // MARQUÉES (inertes + nommées), jamais offertes en cible.
                          closedWindows={closedWindows}
                          // P2-44 (PR-2) — après une transcription, les « trous » sont mis en
                          // évidence (jamais les cases fermées) pour qu'on voie où combler.
                          emphasizeEmpty={emphasizeEmpty}
                          // P2-44 (PR-4) — les créneaux qui s'écartent du socle, marqués DANS la grille.
                          deviatedSlots={socleDeviatedSlots}
                        />
                        )}
                      </div>
                      {slotsBusy ? (
                        <div className="absolute inset-0 z-20 flex items-center justify-center rounded-lg bg-background/50" role="status" aria-live="polite">
                          <span className="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-muted-foreground shadow-lg">
                            <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                            Chargement des créneaux…
                          </span>
                        </div>
                      ) : null}
                    </div>
                  </div>
                  {showAside ? (
                    <div className="mt-4 flex min-h-0 flex-col gap-4 lg:mt-0 lg:h-full">
                      {/* PR 3 — le panneau des verrous manuels cohabite dans l'aside (même
                          patron que SlotDetail/DiagnosticsPanel) ; sa lentille surligne la grille. */}
                      {locksPanelOpen ? (
                        <div className="min-h-0 flex-1">
                          <LocksPanel
                            locks={manualLocks}
                            lookups={lookups}
                            selectedSlotId={selectedSlotId}
                            onSelectSlot={openSlot}
                            lensActive={lockLens}
                            onToggleLens={() => setLockLens((on) => !on)}
                            onCollapse={closeLocksPanel}
                          />
                        </div>
                      ) : null}
                      {null !== selectedCell && null !== selectedSlot ? (
                        <SlotDetail
                          key={selectedSlot.id}
                          cell={selectedCell}
                          slot={selectedSlot}
                          venues={venues}
                          categoryLabel={categoryLabel}
                          constraints={constraints}
                          tagTeamIds={tagTeamIds}
                          // La description d'une contrainte NOMME sa cible : on passe les
                          // résolveurs de nom équipe/coach depuis les lookups déjà en main.
                          teamName={(id) => lookups.teams.get(id)?.name}
                          coachName={(id) => {
                            const c = lookups.coaches.get(id);
                            return undefined === c ? undefined : coachFullName(c);
                          }}
                          busy={busy}
                          // P2-51 PR-6 : sur une séance de bloc, le panneau montre l'état du geste
                          // de GROUPE (verdict move-group), sinon celui du déplacement simple.
                          moveState={null !== selectedSlotBlock ? moveGroupState : moveState}
                          // Un pseudo-créneau de réservation (planning FAILED) n'existe pas
                          // côté serveur : déplacer/verrouiller le viserait dans le vide.
                          readOnly={isReadOnly || isFailed}
                          // P2-30 · PR-6 : ce créneau est-il la source du mode cible armé (simple ou groupe) ?
                          armed={
                            null !== selectedSlotBlock
                              ? targetMode?.kind === "move-group" && targetMode.sourceSlotId === selectedSlot.id
                              : targetMode?.kind === "move" && targetMode.sourceSlotId === selectedSlot.id
                          }
                          // P2-51 PR-6 (D11) : séance de bloc → « Déplacer le groupe » (compte de membres).
                          groupSession={null !== selectedSlotBlock ? { memberCount: selectedSlotBlock.teamIds.length } : null}
                          onClose={() => setSelectedSlotId(null)}
                          // Même point d'entrée que le cadenas de la grille : la règle
                          // RÉSERVATION (confirmation) vit dans `requestToggleLock`, pas ici.
                          onToggleLock={() => requestToggleLock(selectedSlot.id)}
                          // « Déplacer » arme le mode cible click-click ; sur une séance de bloc, il
                          // arme le déplacement de GROUPE (la case source = celle du créneau).
                          onArmMove={() =>
                            null !== selectedSlotBlock
                              ? armMoveGroup(selectedSlot.id, selectedSlotBlock.id, { venueId: selectedSlot.venueId, dayOfWeek: selectedSlot.dayOfWeek, startTime: selectedSlot.startTime })
                              : armMove(selectedSlot.id)
                          }
                        />
                      ) : null}
                      {showDiagnostics ? (
                        <div className="min-h-[12rem] flex-1">
                          <DiagnosticsPanel diagnostics={diagnostics} slots={slots} emptySlots={emptySlots} lookups={lookups} onHighlight={highlightSlots} onFocusVenue={focusVenue} onOpenSlot={openSlot} onCollapse={() => setDiagnosticsCollapsed(true)} openMostSevere={embedded} seedToken={validScheduleId} pending={diagnosticsPending} />
                        </div>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              );
            })()
          )}
        </>
      )}

      {validateOpen ? (
        <ValidateDialog
          hasAlerts={diagnostics.length > 0}
          siblingCount={selectedSchedule?.capabilities?.versionsDeletedOnValidate ?? 0}
          busy={validateMutation.isPending}
          orphan={orphanImpact}
          onCancel={() => setValidateOpen(false)}
          onConfirm={() => validate()}
        />
      ) : null}

      <ConfirmDialog
        open={reopenOverlayCount !== null}
        destructive
        title={`Rouvrir « ${displayedPlanName ?? "ce planning"} » ?`}
        description={`Rouvrir « ${displayedPlanName ?? "ce planning"} » supprimera ${reopenOverlayCount ?? 0} planning${(reopenOverlayCount ?? 0) > 1 ? "s" : ""} secondaire${(reopenOverlayCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}
        confirmLabel="Rouvrir et supprimer"
        confirmPhrase="modifier mon planning de saison"
        onConfirm={() => reopen(true)}
        onCancel={() => setReopenOverlayCount(null)}
      />

      <ConfirmDialog
        open={null !== pendingUnlockSlotId}
        title="Déverrouiller ce créneau réservé ?"
        description="Ce créneau vient d'une réservation de gymnase. En le déverrouillant, la prochaine génération pourra le déplacer ou le libérer — vérifiez auprès du gymnase avant de continuer."
        confirmLabel="Déverrouiller"
        onConfirm={() => {
          if (null !== pendingUnlockSlotId) {
            lockMutation.mutate({ id: pendingUnlockSlotId, lockLevel: "NONE" });
          }
          setPendingUnlockSlotId(null);
        }}
        onCancel={() => setPendingUnlockSlotId(null)}
      />

      {/* P2-30 (D6) + P2-32 — évincer un créneau occupé ouvre une modale alimentée par un ESSAI
          (dry-run) : « Vérification… », puis le verdict (compromis nommés / motifs de refus)
          AVANT toute écriture. Le déplacement réel ne part qu'à la confirmation d'un essai accepté. */}
      <EvictConfirmDialog
        open={null !== evictDialog}
        phase={(evictDialog?.phase ?? "checking") as EvictDialogPhase}
        occupantName={null !== evictDialog ? teamNameOf(evictDialog.targetSlot.teamId) : ""}
        compromises={null !== evictDialog && "accepted" === evictDialog.phase ? evictDialog.compromises : []}
        violations={null !== evictDialog && "refused" === evictDialog.phase ? evictDialog.violations : []}
        failureKind={null !== evictDialog && "failed" === evictDialog.phase ? evictDialog.failureKind : "timeout"}
        busy={busy}
        onConfirm={confirmEvict}
        onRetry={retryEvictDryRun}
        onClose={() => setEvictDialog(null)}
      />

      <ConfirmDialog
        open={validateOverlayCount !== null}
        title={`Valider « ${displayedPlanName ?? "cette version"} » et remplacer le planning principal ?`}
        description={`Cette version deviendra le planning principal ; ${validateOverlayCount ?? 0} planning${(validateOverlayCount ?? 0) > 1 ? "s" : ""} de période bâti${(validateOverlayCount ?? 0) > 1 ? "s" : ""} sur l'ancien principal ser${(validateOverlayCount ?? 0) > 1 ? "ont" : "a"} supprimé${(validateOverlayCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}
        confirmLabel="Valider et remplacer"
        destructive
        onConfirm={() => validate(true)}
        onCancel={() => setValidateOverlayCount(null)}
      />

      <ConfirmDialog
        open={regenerateFromOpen}
        title="Charger cette version ?"
        description={
          "number" === typeof selectedSchedule?.generatedTeamCount ? (
            <>
              La structure actuelle ({teams.length} équipe{teams.length > 1 ? "s" : ""}) sera remplacée par celle de cette version ({selectedSchedule.generatedTeamCount} équipe{selectedSchedule.generatedTeamCount > 1 ? "s" : ""}) et son planning s'affichera. Les données de structure actuelles seront écrasées ; vous pourrez ensuite « Régénérer » pour créer une nouvelle version.
            </>
          ) : null
        }
        confirmLabel="Charger"
        destructive
        onConfirm={() => {
          if (null !== validScheduleId) {
            regenerateFromMutation.mutate(validScheduleId, { onSuccess: (created) => setSelectedScheduleId(created.id) });
          }
          setRegenerateFromOpen(false);
        }}
        onCancel={() => setRegenerateFromOpen(false)}
      />

      {/* P2-44 (PR-2) — « Comparer avec la saison » : consultation lecture seule du socle pointé. */}
      {compareOpen && null !== seasonComparisonId ? (
        <SeasonComparisonModal seasonScheduleId={seasonComparisonId} viewMode={viewMode} onClose={() => setCompareOpen(false)} />
      ) : null}
    </div>
  );
}
