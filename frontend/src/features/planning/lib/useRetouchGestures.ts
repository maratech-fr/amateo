import { useEffect, useState } from "react";

import { type Compromise, EngineTimeoutError, EngineVerificationInterruptedError, type EvictedSlot, GenerationInProgressError, type LockLevel, type MoveViolation, MoveRejectedError, type RemovedSession, type Slot, SlotEditError, TargetLockedError, type UnmetPresence, VerdictAbandonedError } from "../api";
import type { EvictFailureKind } from "../EvictConfirmDialog";
import type { MoveFeedback } from "../SlotDetail";
import type { ViewMode } from "../store";
import { toast } from "@/shared/stores/toastStore";
import { useDeleteSlot, useDeleteSlotGroup, useMoveDryRun, useMoveGroup, useMoveGroupDryRun, useMoveSlot, usePlaceSlot } from "../queries";
import type { DriftEntry } from "./drift";
import { isEmptySlotId } from "./emptySlots";
import { toHourMinute } from "./grid";

/**
 * P4-329 — le contexte d'un déplacement de GROUPE porté par la modale d'éviction : le bloc et sa
 * case source (le serveur résout les créneaux membres depuis la case, jamais des slotIds client).
 * `startTime` reste au format du créneau source (normalisé par `toHourMinute` à l'envoi).
 */
type EvictGroupContext = { blockId: string; source: { venueId: string; dayOfWeek: number; startTime: string } };

/**
 * Le sujet RETOUCHE / ÉVICTION / ANNULATION (P4-255 PR 2) : c'est UN sujet — la preuve est dans le
 * code, la purge au changement de version (`retouchVersion`) les vide TOUS ensemble. `targetMode`,
 * `evictDialog`, `compromiseNotice`, `undo`, `evictionNotice`, `rejectionHandled`, `retouchVersion`,
 * leurs handlers, les dérivations `moveState`/`moveGroupState`, l'effet de remise à zéro et les
 * QUATRE mutations. Déplacement VERBATIM depuis la page — chaque corps migre caractère pour
 * caractère, tableaux de dépendances inchangés, hooks appelés inconditionnellement dans le même
 * ordre.
 *
 * ⚠ Deux blocs sont des AJUSTEMENTS EN PHASE DE RENDU (`retouchVersion` et `rejectionHandled`) :
 * un corps de hook s'exécute en phase de rendu, ils y migrent comme des blocs `if` nus, ordre
 * interne conservé (le lint proscrit un `setState` dans un effet). Le bloc de péremption du
 * `targetMode` (P4-119 d) en est un troisième, qui lit `driftEntries`.
 *
 * ~14 paramètres individuels (jamais un objet d'options qui changerait d'identité à chaque rendu) :
 * c'est large, c'est assumé. Le hook retourne les états, les setters et les dérivations sous leurs
 * noms actuels, pour que le JSX reste intact.
 */
export function useRetouchGestures(
  slots: Slot[],
  validScheduleId: string | null,
  selectedSlotId: string | null,
  viewMode: ViewMode,
  isReadOnly: boolean,
  isFailed: boolean,
  closuresResolved: boolean,
  closedWindows: Map<string, string>,
  gridSlots: Slot[],
  driftEntries: DriftEntry[],
  teamNameOf: (teamId: string) => string,
  setSelectedSlotId: (id: string | null) => void,
  highlightViolations: (violations: MoveViolation[]) => void,
  clearHighlight: () => void,
) {
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
  // P4-329 — la MÊME modale sert le déplacement d'un GROUPE vers une case occupée : `group` porte
  // alors le bloc et sa case source (absent = déplacement d'un créneau simple). L'essai, la
  // confirmation et le réessai routent sur le rail move-group plutôt que /move.
  const [evictDialog, setEvictDialog] = useState<
    | { phase: "checking"; sourceSlotId: string; targetSlot: Slot; group?: EvictGroupContext }
    | { phase: "accepted"; sourceSlotId: string; targetSlot: Slot; compromises: Compromise[]; group?: EvictGroupContext }
    | { phase: "refused"; sourceSlotId: string; targetSlot: Slot; violations: MoveViolation[]; group?: EvictGroupContext }
    | { phase: "failed"; sourceSlotId: string; targetSlot: Slot; failureKind: EvictFailureKind; group?: EvictGroupContext }
    // ENG-51 — le solveur n'a pas tranché dans le temps imparti (ni accepté ni refusé).
    | { phase: "indeterminate"; sourceSlotId: string; targetSlot: Slot; group?: EvictGroupContext }
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
  // « Vider un créneau » (geste F4) — le ConfirmDialog OBLIGATOIRE, alimenté par un essai (dryRun)
  // qui NOMME les conséquences SANS rien écrire : la séance qui part (`removed`), le verrou perdu
  // (`lockLevel`), les présences SEASON_SLOTS que le retrait n'honore plus (`unmetPresence`). Null =
  // pas de confirmation en attente. Le groupe porte en plus le nombre d'équipes et la case source.
  const [removeConfirm, setRemoveConfirm] = useState<
    | { kind: "slot"; slotId: string; removed: RemovedSession; lockLevel: LockLevel; unmetPresence: UnmetPresence[] }
    | { kind: "group"; blockId: string; source: { venueId: string; dayOfWeek: number; startTime: string }; memberCount: number; unmetPresence: UnmetPresence[] }
    | null
  >(null);
  // Les présences SEASON_SLOTS non honorées par le DERNIER retrait écrit, affichées VERBATIM telles
  // que le serveur les compose, jamais redérivées côté front. Null = aucune. Purgées au geste suivant
  // / changement de version / fermeture du bandeau.
  const [presenceNotice, setPresenceNotice] = useState<UnmetPresence[] | null>(null);

  const moveMutation = useMoveSlot();
  const moveGroupMutation = useMoveGroup();
  const dryRunMutation = useMoveDryRun();
  const groupDryRunMutation = useMoveGroupDryRun();
  const placeMutation = usePlaceSlot();
  const removeMutation = useDeleteSlot();
  const removeGroupMutation = useDeleteSlotGroup();

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
              : // ENG-51 : un 200 {valid:false, indeterminate:true} résout en DATA (pas une erreur) —
                // le moteur n'a pas tranché. Bandeau NEUTRE, rien n'a bougé.
                true === moveMutation.data?.indeterminate
                ? { status: "indeterminate" }
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
              : // ENG-51 : verdict indéterminé résolu en DATA (pas une erreur) — bandeau NEUTRE.
                true === moveGroupMutation.data?.indeterminate
                ? { status: "indeterminate" }
                : { status: "idle" };

  // F4 — l'état du RETRAIT pour le panneau : un essai/retrait en vol (spinner du bouton) et, sur une
  // génération en cours (409), le bandeau NEUTRE « bloqué » réutilisé (même message que le move). Un
  // retrait n'a pas d'autre verdict (pas d'appel moteur) : ni refus ni compromis.
  const removePending = removeMutation.isPending || removeGroupMutation.isPending;
  const removeState: MoveFeedback =
    removeMutation.error instanceof GenerationInProgressError || removeGroupMutation.error instanceof GenerationInProgressError
      ? { status: "blocked" }
      : { status: "idle" };
  const removeReset = removeMutation.reset;
  const removeGroupReset = removeGroupMutation.reset;

  // Changer de créneau sélectionné efface le verdict du précédent — sinon un refus (ou le bandeau
  // « bloqué » d'un retrait) resterait affiché sous un autre créneau.
  useEffect(() => {
    moveReset();
    moveGroupReset();
    removeReset();
    removeGroupReset();
  }, [selectedSlotId, moveReset, moveGroupReset, removeReset, removeGroupReset]);

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
    highlightViolations(activeRejectionViolations);
  } else if (null === activeRejection && null !== rejectionHandled) {
    setRejectionHandled(null);
    clearHighlight();
  }

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
    setRemoveConfirm(null);
    setPresenceNotice(null);
  }

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
          // ENG-51 — verdict indéterminé : le moteur n'a pas tranché, RIEN n'a bougé côté serveur.
          // Pas d'undo, pas de toast de succès, pas de compromis — le bandeau NEUTRE (moveState,
          // dérivé de `data.indeterminate`) invite à réessayer.
          if (true === result.indeterminate) {
            setCompromiseNotice(null);
            setEvictionNotice(null);
            return;
          }
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
  const doMoveGroup = (blockId: string, source: { venueId: string; dayOfWeek: number; startTime: string }, target: { venueId: string; dayOfWeek: number; startTime: string }, evictSlotId?: string) => {
    if (null === validScheduleId) {
      return;
    }
    const freed = { venueId: source.venueId, dayOfWeek: source.dayOfWeek, startTime: toHourMinute(source.startTime) };
    moveGroupMutation.mutate(
      {
        scheduleId: validScheduleId,
        blockId,
        source: freed,
        target: { venueId: target.venueId, dayOfWeek: target.dayOfWeek, startTime: toHourMinute(target.startTime) },
        ...(undefined === evictSlotId ? {} : { evictSlotId }),
      },
      {
        onSuccess: (result) => {
          setEvictDialog(null);
          setTargetMode(null);
          // Un déplacement de groupe n'a pas d'inverse d'un clic (il faudrait rejouer move-group) :
          // on n'arme aucun undo, et on invalide un éventuel undo d'un geste simple précédent.
          setUndo(null);
          clearHighlight();
          // ENG-51 — verdict indéterminé : aucun des N créneaux n'a bougé. Pas de toast de succès,
          // pas de compromis — le bandeau NEUTRE (moveGroupState) invite à réessayer.
          if (true === result.indeterminate) {
            setEvictionNotice(null);
            setCompromiseNotice(null);
            return;
          }
          const compromises = result.compromises ?? [];
          setCompromiseNotice(compromises.length > 0 ? compromises : null);
          const suffix = compromises.length > 0 ? ` — ${compromises.length} compromis` : "";
          // P4-329 — le groupe a pris une case occupée : l'évincée passe « à replacer » (raccourci
          // un-clic « Remettre » sur la case que le groupe vient de libérer), MÊME UX que le move simple.
          if (undefined !== result.evicted) {
            setEvictionNotice({ evicted: result.evicted, freed });
            toast.success(`Groupe déplacé — ${teamNameOf(result.evicted.teamId)} est à replacer${compromises.length > 0 ? suffix : "."}`);
          } else {
            setEvictionNotice(null);
            toast.success(compromises.length > 0 ? `Groupe déplacé${suffix}` : "Groupe déplacé.");
          }
        },
        onError: (error) => {
          // P4-329 — une confirmation d'éviction a pu ouvrir la modale : on la ferme (le verdict se
          // lit alors dans le panneau / le toast), comme le move simple.
          setEvictDialog(null);
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
          clearHighlight();
          // ENG-51 — verdict indéterminé : rien n'a été créé. Message NEUTRE (ni succès ni erreur),
          // on reste en mode placement pour réessayer.
          if (true === result.indeterminate) {
            setCompromiseNotice(null);
            toast.info("La vérification a pris trop de temps, réessayez.");
            return;
          }
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
            highlightViolations(error.violations);
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
  // P4-329 — `group` présent ⇒ l'essai (et, plus tard, la confirmation) route sur le rail move-group ;
  // absent ⇒ le rail /move d'un créneau simple. Le TRAITEMENT du verdict est identique.
  const runEvictDryRun = (sourceSlotId: string, targetSlot: Slot, group?: EvictGroupContext) => {
    const onSuccess = (result: { indeterminate?: boolean; valid: boolean; compromises?: Compromise[]; violations?: MoveViolation[] }) => {
      // ENG-51 — le moteur n'a pas tranché l'essai dans le temps imparti : ni accepté ni refusé.
      // Phase NEUTRE (bandeau muted + [Réessayer]), jamais « refusé » (aucune règle en cause).
      if (true === result.indeterminate) {
        setEvictDialog({ phase: "indeterminate", sourceSlotId, targetSlot, group });
        return;
      }
      if (result.valid) {
        setEvictDialog({ phase: "accepted", sourceSlotId, targetSlot, compromises: result.compromises ?? [], group });
      } else {
        const violations = result.violations ?? [];
        setEvictDialog({ phase: "refused", sourceSlotId, targetSlot, violations, group });
        // Surligner le conflit nommé (présentation pure, même chemin qu'un placement refusé).
        highlightViolations(violations);
      }
    };
    const onError = (error: unknown) => {
      // Refus MÉTIER (verrou D3, cible incohérente/occupée par un groupe, génération en cours) :
      // la modale se ferme, le motif est toasté, le mode cible reste armé.
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
      setEvictDialog({ phase: "failed", sourceSlotId, targetSlot, failureKind, group });
    };

    if (undefined !== group) {
      if (null === validScheduleId) {
        return;
      }
      groupDryRunMutation.mutate(
        {
          scheduleId: validScheduleId,
          blockId: group.blockId,
          source: { venueId: group.source.venueId, dayOfWeek: group.source.dayOfWeek, startTime: toHourMinute(group.source.startTime) },
          target: { venueId: targetSlot.venueId, dayOfWeek: targetSlot.dayOfWeek, startTime: toHourMinute(targetSlot.startTime) },
          evictSlotId: targetSlot.id,
        },
        { onSuccess, onError },
      );
      return;
    }
    dryRunMutation.mutate(
      { id: sourceSlotId, patch: { dayOfWeek: targetSlot.dayOfWeek, startTime: toHourMinute(targetSlot.startTime), venueId: targetSlot.venueId, evictSlotId: targetSlot.id } },
      { onSuccess, onError },
    );
  };

  // Relancer l'essai depuis l'état `failed` de la modale : on repasse en « Vérification… » et on
  // rejoue le dry-run sur la MÊME cible.
  const retryEvictDryRun = () => {
    if (null === evictDialog) {
      return;
    }
    const { sourceSlotId, targetSlot, group } = evictDialog;
    setEvictDialog({ phase: "checking", sourceSlotId, targetSlot, group });
    runEvictDryRun(sourceSlotId, targetSlot, group);
  };

  // Confirmer l'éviction depuis la modale (état `accepted`) : le geste RÉEL part (sans dryRun).
  // P4-329 — un déplacement de GROUPE route sur move-group (le serveur résout les créneaux membres) ;
  // un créneau simple sur /move.
  const confirmEvict = () => {
    if (null === evictDialog) {
      return;
    }
    const { sourceSlotId, targetSlot, group } = evictDialog;
    const target = { dayOfWeek: targetSlot.dayOfWeek, startTime: toHourMinute(targetSlot.startTime), venueId: targetSlot.venueId };
    if (undefined !== group) {
      doMoveGroup(group.blockId, group.source, target, targetSlot.id);
    } else {
      doMove(sourceSlotId, target, targetSlot.id);
    }
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
    // P2-51 PR-6 (D11) · P4-329 — déplacement de GROUPE : une case LIBRE part directement au rail
    // move-group ; une case OCCUPÉE ouvre la MÊME modale d'éviction que le move simple, alimentée par
    // un ESSAI (dry-run + evictSlotId) — rien n'est écrit tant qu'on n'a pas confirmé. Cliquer la case
    // SOURCE (une de ses séances membres) annule.
    if (targetMode.kind === "move-group") {
      const { blockId, source, sourceSlotId } = targetMode;
      let targetCase: { venueId: string; dayOfWeek: number; startTime: string } | null = null;
      let occupant: Slot | null = null;
      if (isEmptySlotId(cellSlotId)) {
        const win = gridSlots.find((s) => s.id === cellSlotId);
        if (undefined !== win && !isClosedTarget(win.venueId, win.dayOfWeek)) {
          targetCase = { venueId: win.venueId, dayOfWeek: win.dayOfWeek, startTime: toHourMinute(win.startTime) };
        }
      } else {
        const targetSlot = slots.find((s) => s.id === cellSlotId);
        if (undefined !== targetSlot && !isClosedTarget(targetSlot.venueId, targetSlot.dayOfWeek)) {
          targetCase = { venueId: targetSlot.venueId, dayOfWeek: targetSlot.dayOfWeek, startTime: toHourMinute(targetSlot.startTime) };
          occupant = targetSlot;
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
      if (null !== occupant) {
        // Case OCCUPÉE : la modale s'ouvre en VÉRIFICATION, un ESSAI part (dry-run + evictSlotId).
        // Le verdict (accepté + occupant nommé / refusé) la remplit avant toute écriture.
        setEvictDialog({ phase: "checking", sourceSlotId, targetSlot: occupant, group: { blockId, source } });
        runEvictDryRun(sourceSlotId, occupant, { blockId, source });
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

  // F4 — « Retirer » ouvre un ESSAI (dryRun) qui NOMME les conséquences SANS écrire ; son succès
  // arme le ConfirmDialog OBLIGATOIRE. Une génération en cours (409) ⇒ `removeState` « bloqué »
  // (feedback au hook) et aucune confirmation. Le geste RÉEL part de `confirmRemove()`.
  const requestRemoveSlot = (slotId: string) => {
    removeMutation.mutate(
      { id: slotId, dryRun: true },
      {
        onSuccess: (result) => {
          if (true === result.dryRun) {
            setRemoveConfirm({ kind: "slot", slotId, removed: result.removed, lockLevel: result.lockLevel, unmetPresence: result.unmetPresence });
          }
        },
        onError: () => {}, // « bloqué » dérivé de removeState ; un vrai transport est toasté au hook
      },
    );
  };

  const requestRemoveGroup = (blockId: string, source: { venueId: string; dayOfWeek: number; startTime: string }, memberCount: number) => {
    if (null === validScheduleId) {
      return;
    }
    const resolvedSource = { venueId: source.venueId, dayOfWeek: source.dayOfWeek, startTime: toHourMinute(source.startTime) };
    removeGroupMutation.mutate(
      { scheduleId: validScheduleId, blockId, source: resolvedSource, dryRun: true },
      {
        onSuccess: (result) => {
          if (true === result.dryRun) {
            setRemoveConfirm({ kind: "group", blockId, source: resolvedSource, memberCount, unmetPresence: result.unmetPresence });
          }
        },
        onError: () => {},
      },
    );
  };

  // Confirmer le retrait (geste RÉEL, sans dryRun). Séance seule : réutilise le bandeau d'éviction
  // (« à replacer » + un-clic « Remettre » via `placeEvictedShortcut`, qui repose sous le verdict
  // moteur). Groupe : pas de un-clic (le trou se lit dans la dérive). Les présences non honorées
  // s'affichent verbatim dans les deux cas. Le panneau du créneau se ferme (sa séance a disparu).
  const confirmRemove = () => {
    if (null === removeConfirm) {
      return;
    }
    if (removeConfirm.kind === "slot") {
      const { slotId } = removeConfirm;
      removeMutation.mutate(
        { id: slotId },
        {
          onSuccess: (result) => {
            if (true === result.dryRun) {
              return; // garde-fou : un retrait réel ne porte jamais dryRun
            }
            setRemoveConfirm(null);
            setSelectedSlotId(null);
            setUndo(null);
            setCompromiseNotice(null);
            setEvictionNotice({ evicted: result.removed, freed: { dayOfWeek: result.removed.dayOfWeek, startTime: result.removed.startTime, venueId: result.removed.venueId } });
            setPresenceNotice(result.unmetPresence.length > 0 ? result.unmetPresence : null);
            toast.success(`${teamNameOf(result.removed.teamId)} retirée — à replacer.`);
          },
          onError: () => setRemoveConfirm(null), // « bloqué » reste affiché par removeState ; transport toasté au hook
        },
      );
      return;
    }
    if (null === validScheduleId) {
      return;
    }
    const { blockId, source } = removeConfirm;
    removeGroupMutation.mutate(
      { scheduleId: validScheduleId, blockId, source },
      {
        onSuccess: (result) => {
          if (true === result.dryRun) {
            return;
          }
          setRemoveConfirm(null);
          setSelectedSlotId(null);
          setUndo(null);
          setCompromiseNotice(null);
          setEvictionNotice(null); // groupe : pas de un-clic (décision fondateur)
          setPresenceNotice(result.unmetPresence.length > 0 ? result.unmetPresence : null);
          toast.success(`Groupe retiré — ${result.removedSlotIds.length} séances à replacer.`);
        },
        onError: () => setRemoveConfirm(null),
      },
    );
  };

  const cancelRemove = () => {
    setRemoveConfirm(null);
    removeMutation.reset();
    removeGroupMutation.reset();
  };

  return {
    targetMode,
    evictDialog,
    compromiseNotice,
    undo,
    evictionNotice,
    removeConfirm,
    presenceNotice,
    setEvictDialog,
    setEvictionNotice,
    setCompromiseNotice,
    setPresenceNotice,
    moveState,
    moveGroupState,
    removeState,
    removePending,
    gridTargetMode,
    armMove,
    armMoveGroup,
    armPlace,
    cancelTarget,
    onPickTarget,
    runUndo,
    placeEvictedShortcut,
    confirmEvict,
    retryEvictDryRun,
    requestRemoveSlot,
    requestRemoveGroup,
    confirmRemove,
    cancelRemove,
    moveMutation,
    dryRunMutation,
    placeMutation,
    removeMutation,
    removeGroupMutation,
  };
}
