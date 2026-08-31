import { IN_FLIGHT_STATUSES } from "@/shared/lib/scheduleStatus";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { QueryClient } from "@tanstack/react-query";
import { useCallback, useState } from "react";

import { download, slugFilename } from "@/shared/lib/download";
import { errorMessage } from "@/shared/lib/errorMessage";
import { registerLongAction, unregisterLongAction } from "@/shared/lib/longActionAbort";
import { isScheduleStreamConnected, useScheduleStream } from "./lib/scheduleStream";
import { toast } from "@/shared/stores/toastStore";

import type { LockLevel, PlaceSlotBody, SlotMovePatch } from "./api";
import { EngineTimeoutError, GenerationInProgressError, MoveRejectedError, OverlaysExistError, SlotEditError, TargetLockedError, VerdictAbandonedError } from "./api";
import * as planningApi from "./api";

/**
 * Lot C PR-2 — un TRAITEMENT LONG (rail de retouche) enregistre son `AbortController` le temps de
 * l'appel, pour que le bouton « Abandonner » du voile bloquant puisse l'aborter, et le retire au
 * settle (succès, refus ou abandon). Le `signal` file jusqu'à ky.
 */
function runLongAction<T>(fn: (signal: AbortSignal) => Promise<T>): Promise<T> {
  const controller = registerLongAction();
  return fn(controller.signal).finally(() => unregisterLongAction(controller));
}

/** Le paquet réinvalidé après un déplacement/placement ACCEPTÉ — et après un ABANDON (le serveur a
 *  pu l'appliquer quand même). Maison unique pour que les deux chemins restent alignés. */
function invalidateMovePacket(queryClient: QueryClient): void {
  void queryClient.invalidateQueries({ queryKey: ["slots"] });
  void queryClient.invalidateQueries({ queryKey: ["schedules"] });
  void queryClient.invalidateQueries({ queryKey: ["diagnostics"] });
  // P2-44 PR-5 : le placement qui change rejoue le diff socle↔période.
  void queryClient.invalidateQueries({ queryKey: ["socle-deviation"] });
}

/**
 * Le onError du rail move/place. Un ABANDON volontaire (voile bloquant) est intercepté ICI : on
 * resynchronise le paquet d'un geste accepté (le serveur a pu écrire avant l'abort) et on NOMME
 * l'abandon — jamais « réessayez ». Tout le reste passe au feedback existant (métier tu, transport
 * toasté).
 */
function onSlotEditError(queryClient: QueryClient, error: unknown): void {
  if (error instanceof VerdictAbandonedError) {
    invalidateMovePacket(queryClient);
    toast.info("Déplacement abandonné. Le serveur a pu l'appliquer quand même — le planning a été rechargé.");
    return;
  }
  ownSlotEditFeedback(error);
}

/**
 * P2-30 — le rail de retouche (move/place) délègue le feedback des refus au niveau `mutate()`
 * de la page (toasts CONTEXTUELS : noms d'équipes, panneau, surlignage). Mais le filet global
 * `MutationCache.onError` (queryClient) ne toaste QUE les mutations SANS onError de NIVEAU HOOK
 * — un onError `mutate()` ne le désarme pas. Sans ce onError hook, un refus MÉTIER tombait donc
 * dans le filet et devenait « Problème de connexion. Vérifiez votre réseau. » (mensonger : le
 * réseau va bien). Ici le HOOK possède son feedback : il TAIT les erreurs métier (la page les
 * affiche) et ne parle que d'un vrai échec transport — remplaçant le filet, jamais le doublant.
 */
const isBusinessSlotEditError = (error: unknown): boolean =>
  error instanceof MoveRejectedError ||
  error instanceof TargetLockedError ||
  error instanceof SlotEditError ||
  error instanceof GenerationInProgressError ||
  // Le timeout moteur (504) est NOMMÉ par la page (toast du geste réel, ou modale d'essai en
  // échec) : le hook se tait, sinon le message générique du filet le doublerait.
  error instanceof EngineTimeoutError;

function ownSlotEditFeedback(error: unknown): void {
  if (isBusinessSlotEditError(error)) {
    return; // la page possède l'affichage contextuel (noms d'équipes, panneau, surlignage)
  }
  void errorMessage(error).then((message) => toast.error(message));
}

// D-31 : la liste vit avec le type (`api.ts`) — elle était déclarée cinq fois.
const IN_FLIGHT = IN_FLIGHT_STATUSES;

/**
 * List of the club's schedules. While any schedule is mid-generation, the Mercure
 * stream (FRT-04) pushes progress and the poll degrades to a slow fallback — the
 * publisher is best-effort, so polling never dies entirely: a missed event
 * self-heals on the next poll, at 2.5 s only when the stream is NOT connected.
 * `enabled: false` for consumers that only need the list conditionally (e.g. the
 * wizard's period-abandon guard) — avoids fetching/polling from pages that never
 * read the data.
 */
export function useSchedules(enabled = true) {
  const query = useQuery({
    queryKey: ["schedules"],
    queryFn: planningApi.listSchedules,
    enabled,
    staleTime: 30_000,
    refetchInterval: (query) =>
      (query.state.data ?? []).some((s) => IN_FLIGHT.includes(s.status))
        ? (isScheduleStreamConnected() ? 15_000 : 2500)
        : false,
  });
  useScheduleStream(enabled && (query.data ?? []).some((s) => IN_FLIGHT.includes(s.status)));

  return query;
}

export function useSlots(scheduleId: string | null) {
  return useQuery({
    queryKey: ["slots", scheduleId],
    queryFn: () => planningApi.getSlots(scheduleId as string),
    enabled: null !== scheduleId,
    staleTime: 30_000,
    // Changer de version/période garde la grille précédente à l'écran le temps que les
    // nouveaux créneaux arrivent (au lieu de la vider) — l'écran la VOILE alors pour dire
    // que ça travaille (cf. `slotsBusy` dans PlanningPage). Patron `admin/queries`.
    placeholderData: (previous) => previous,
  });
}

export function useDiagnostics(scheduleId: string | null) {
  return useQuery({
    queryKey: ["diagnostics", scheduleId],
    queryFn: () => planningApi.getDiagnostics(scheduleId as string),
    enabled: null !== scheduleId,
    staleTime: 30_000,
  });
}

/**
 * P2-44 PR-5 — les écarts NOMMÉS d'une version de plan de FERMETURE vs le socle pointé
 * (`GET /schedules/{id}/socle-deviation`). `scheduleId` NUL désarme la query : l'appelant (la
 * PlanningPage embarquée) ne l'arme QUE sur une fermeture, version COMPLETED — sur une vacance ou
 * `/planning` autonome la route n'est JAMAIS appelée. Réinvalidée après move/lock/place (le diff se
 * relit une fois le placement changé).
 */
export function useSocleDeviation(scheduleId: string | null) {
  return useQuery({
    queryKey: ["socle-deviation", scheduleId],
    queryFn: () => planningApi.getSocleDeviation(scheduleId as string),
    enabled: null !== scheduleId,
    staleTime: 30_000,
  });
}

/**
 * P2-52 — l'impact de dépointage de la VALIDATION. Armé (scheduleId non nul) seulement quand le
 * gestionnaire est sur le point de valider : on n'interroge pas l'impact avant que le geste soit
 * envisagé. `staleTime: 0` — la donnée doit être fraîche au moment de confirmer (un gymnase a pu
 * disparaître depuis le dernier rendu).
 */
export function useValidateImpact(scheduleId: string | null) {
  return useQuery({
    queryKey: ["validate-impact", scheduleId],
    queryFn: () => planningApi.getValidateImpact(scheduleId as string),
    enabled: null !== scheduleId,
    staleTime: 0,
  });
}

// Reference data (names + grouping). Long-lived — rarely changes within a session.
export function useTeams() {
  return useQuery({ queryKey: ["teams"], queryFn: planningApi.getTeams, staleTime: 300_000 });
}

export function useVenues() {
  return useQuery({ queryKey: ["venues"], queryFn: planningApi.getVenues, staleTime: 300_000 });
}

/** Cf. `getTrainingSlots` : la couche de la version affichée, jamais celle de la saison par défaut. */
export function useTrainingSlots(schedulePlanId: string | null) {
  return useQuery({
    queryKey: ["training-slots", schedulePlanId],
    queryFn: () => planningApi.getTrainingSlots(schedulePlanId),
    staleTime: 300_000,
  });
}

export function useCoaches() {
  return useQuery({ queryKey: ["coaches"], queryFn: planningApi.getCoaches, staleTime: 300_000 });
}

/** Club constraints — the slot wrap (F1) composes the applicable ones client-side. */
export function useConstraints() {
  return useQuery({ queryKey: ["constraints"], queryFn: planningApi.getConstraints, staleTime: 300_000 });
}

export function useCategories() {
  return useQuery({ queryKey: ["categories"], queryFn: planningApi.getCategories, staleTime: 300_000 });
}

export function useTeamCoaches() {
  return useQuery({ queryKey: ["team_coaches"], queryFn: planningApi.getTeamCoaches, staleTime: 300_000 });
}

export function useCoachPlayers() {
  return useQuery({ queryKey: ["coach_player_memberships"], queryFn: planningApi.getCoachPlayers, staleTime: 300_000 });
}

// --- 2b: adjust + regenerate loop ---------------------------------------------

export function useLockSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, lockLevel }: { id: string; lockLevel: LockLevel }) => planningApi.lockSlot(id, lockLevel),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["slots"] });
      // P2-44 PR-5 : un placement qui change rejoue le diff socle↔période.
      void queryClient.invalidateQueries({ queryKey: ["socle-deviation"] });
    },
    // Un verrouillage qui échoue (moteur/réseau) restait MUET : le cadenas ne bougeait pas
    // sans un mot. On remonte le motif du serveur (patron useReopenSchedule/useRegenerate).
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useMoveSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    // Lot C PR-2 : traitement LONG (verdict moteur, > 30 s sur un club dense) → voile non
    // relâchable au chrono, bouton d'abandon.
    meta: { veil: "long" },
    mutationFn: ({ id, patch }: { id: string; patch: SlotMovePatch }) => runLongAction((signal) => planningApi.moveSlot(id, patch, signal)),
    // Un déplacement accepté change le placement (slots) ET pose le marqueur « score
    // périmé » sur le planning (schedules) — et le moteur a rejugé la légalité, donc les
    // diagnostics du planning peuvent bouger : on rafraîchit les trois. Un refus throw :
    // onSuccess ne part pas, rien n'est réinvalidé (rien n'a bougé).
    // ⚠ P2-30 : le TOAST vit désormais côté page (mutate-level) — l'éviction, l'annulation et
    // le raccourci ont besoin des NOMS d'équipes et de l'issue exacte ; un toast générique ici
    // en aurait fait deux. La page centralise donc tous les mots du geste.
    onSuccess: () => invalidateMovePacket(queryClient),
    // Le hook POSSÈDE son feedback (sinon le filet global toaste « Problème de connexion »
    // sur un refus métier) : il tait le métier, ne parle que d'un vrai transport ; un ABANDON
    // resynchronise (le serveur a pu écrire) et se NOMME.
    onError: (error) => onSlotEditError(queryClient, error),
  });
}

/**
 * P2-32 — un ESSAI (dry-run) d'un déplacement : le moteur juge (verdict + compromis) SANS rien
 * écrire. Sert à remplir la modale d'éviction (D6) avant la confirmation. AUCUNE invalidation
 * (rien n'a bougé). Le hook POSSÈDE son feedback (même règle que {@link useMoveSlot}) : il TAIT
 * les erreurs métier (la page les affiche : verrou de cible, modale de refus) et ne parle que
 * d'un vrai transport — sinon le filet global `MutationCache.onError` toasterait « Problème de
 * connexion » sur un refus/verrou. ⚠ Un essai REFUSÉ arrive en 200 {valid:false} : il RÉSOUT
 * (onSuccess), il ne lève pas — seul un verrou/une génération/un transport passe par onError.
 */
export function useMoveDryRun() {
  return useMutation({
    // Lot C PR-2 : l'essai passe aussi sous le voile LONG (mêmes secondes que le vrai geste) —
    // abandonnable, mais SANS resync (un essai n'écrit jamais : la modale garde sa phase interrupted).
    meta: { veil: "long" },
    mutationFn: ({ id, patch }: { id: string; patch: SlotMovePatch }) => runLongAction((signal) => planningApi.moveSlot(id, { ...patch, dryRun: true }, signal)),
    // La page possède TOUT le feedback de l'essai : un refus métier ferme la modale et le toast
    // contextuel, un ÉCHEC de l'essai (timeout, moteur indisponible) laisse la modale ouverte en
    // état d'échec qui NOMME la cause. Le hook se tait donc (mais désarme le filet global, qui
    // sinon toasterait « Problème de connexion » par-dessus la modale) — cf. useMoveSlot.
    onError: () => {},
  });
}

/**
 * P2-30 — PLACER une séance à la dérive sous le verdict moteur. Mêmes invalidations que
 * {@link useMoveSlot} : le placement crée un créneau (slots), périme le score (schedules) et
 * fait rejuger la légalité (diagnostics). Le toast/undo/raccourci vit côté page (contexte des
 * noms d'équipes) — un refus throw, onSuccess ne part pas.
 */
export function usePlaceSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    // Lot C PR-2 : traitement LONG (même verdict moteur que move).
    meta: { veil: "long" },
    mutationFn: ({ scheduleId, body }: { scheduleId: string; body: PlaceSlotBody }) => runLongAction((signal) => planningApi.placeSlot(scheduleId, body, signal)),
    onSuccess: () => invalidateMovePacket(queryClient),
    // Idem move : le hook possède son feedback, le filet global ne double plus ; un ABANDON
    // resynchronise et se NOMME.
    onError: (error) => onSlotEditError(queryClient, error),
  });
}

/**
 * P2-51 PR-6 (D11) — déplacer un BLOC de mutualisation ENTIER sous le verdict du moteur. MÊME paquet
 * d'invalidation que {@link useMoveSlot} (`invalidateMovePacket` : slots, schedules, diagnostics,
 * socle-deviation) — un déplacement de groupe change N placements, périme le score et fait rejuger la
 * légalité. Le hook POSSÈDE son feedback (le filet global ne double pas les refus métier) ; un
 * ABANDON resynchronise et se NOMME. Le toast/undo contextuel vit côté page (noms d'équipes).
 */
export function useMoveGroup() {
  const queryClient = useQueryClient();
  return useMutation({
    // Traitement LONG (verdict moteur, comme move) : voile non relâchable au chrono, bouton d'abandon.
    meta: { veil: "long" },
    mutationFn: (body: planningApi.MoveGroupBody) => runLongAction((signal) => planningApi.moveGroup(body, signal)),
    onSuccess: () => invalidateMovePacket(queryClient),
    onError: (error) => onSlotEditError(queryClient, error),
  });
}

export function useGenerate() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) => planningApi.generateSchedule(scheduleId),
    // The controller flips the schedule to PENDING synchronously; refetch starts the poll.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

export type ExportFormat = "pdf" | "xlsx";

const EXPORT_POLL_MS = 1500;
const EXPORT_TIMEOUT_MS = 60_000;
const sleep = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));


/**
 * Export a schedule to PDF (async worker → poll status → download the file)
 * or XLSX (synchronous blob → download). `busy` is the in-flight format, or null.
 * `exportName` names the downloaded file after the PLANNING (slugified), not a
 * generic "planning" (founder feedback 2026-07-18).
 */
export function useScheduleExport(scheduleId: string | null, exportName: string | null = null) {
  const [busy, setBusy] = useState<ExportFormat | null>(null);

  const run = useCallback(
    async (format: ExportFormat, venueId: planningApi.ExportVenueScope): Promise<void> => {
      if (null === scheduleId || null !== busy) {
        return;
      }
      const fileBase = slugFilename(exportName ?? "planning");
      setBusy(format);
      try {
        if ("xlsx" === format) {
          const blob = await planningApi.exportScheduleXlsx(scheduleId, venueId);
          download(URL.createObjectURL(blob), `${fileBase}.xlsx`);
          return;
        }
        await planningApi.exportSchedulePdf(scheduleId, venueId);
        // The worker writes the file path with a scope suffix (-all / -<venueId8>);
        // the schedule row carries a single, shared export URL, so only download
        // once it matches THIS request's scope — guards against another in-flight
        // export (other tab/scope) whose 'completed' + URL we'd otherwise grab.
        const scopeToken = `-${null === venueId ? "all" : venueId.slice(0, 8)}.${format}`;
        const deadline = Date.now() + EXPORT_TIMEOUT_MS;
        for (;;) {
          await sleep(EXPORT_POLL_MS);
          const schedule = await planningApi.getSchedule(scheduleId);
          if ("failed" === schedule.pdfExportStatus || Date.now() > deadline) {
            throw new Error("export failed");
          }
          const url = schedule.pdfExportUrl;
          if ("completed" === schedule.pdfExportStatus && null != url && url.endsWith(scopeToken)) {
            download(url, `${fileBase}.${format}`);
            return;
          }
        }
      } catch {
        toast.error("Export impossible — réessayez.");
      } finally {
        setBusy(null);
      }
    },
    [scheduleId, busy, exportName],
  );

  return { run, busy };
}

/** Lock a COMPLETED schedule → VALIDATED (read-only). */
export function useValidateSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, confirmDeleteOverlays }: { id: string; confirmDeleteOverlays?: boolean }) => planningApi.validateSchedule(id, { confirmDeleteOverlays }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      // Valider peut SUPPRIMER les plans secondaires (confirmDeleteOverlays) — même
      // destruction serveur que rouvrir, qui l'invalide déjà. Sans ça le cockpit
      // continue d'afficher des périodes dont l'overlay n'existe plus.
      void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
      // Le radar dérive désormais du POINTEUR, que ce geste déplace : ses conflits ET
      // son `seasonPlanChosen` changent. L'ancienne baseline était auto-posée et
      // collante, donc valider/rouvrir ne changeaient jamais cette réponse — d'où
      // l'oubli. Sans ça le cockpit garde jusqu'à 30 s un radar d'avant le geste.
      void queryClient.invalidateQueries({ queryKey: ["entry-conflicts"] });
      // Validating moves the plan's pointer (surfaced on /me.seasonPlan), which
      // unlocks matches + secondary plans — refresh it so the home screen follows.
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

/** Reopen the version the plan points at → editable again. Accepts the overlay-delete confirm flag. */
export function useReopenSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, confirmDeleteOverlays }: { id: string; confirmDeleteOverlays?: boolean }) =>
      planningApi.reopenSchedule(id, { confirmDeleteOverlays }),
    // Hook-level = unmount-safe. OverlaysExistError is UI state (escalation
    // dialog, handled by the caller's mutate-level onError); everything else
    // toasts here so a failure is never silent.
    onError: (error) => {
      if (!(error instanceof OverlaysExistError)) {
        toast.error("Réouverture impossible");
      }
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
      // Rouvrir DÉPOINTE le plan (inv. 2), donc /me.seasonPlan.chosenScheduleId
      // passe à null — et c'est lui qui verrouille le module matchs côté client.
      // Sans ça, useMe (staleTime 60 s) laisse l'onglet Matchs ouvert pendant une
      // minute alors que le serveur refuse déjà les écritures (SocleGuard, 409).
      void queryClient.invalidateQueries({ queryKey: ["me"] });
      // Même raison que pour la validation : le radar dérive du pointeur, qui vient
      // de tomber — ses conflits deviennent « impact non évalué ».
      void queryClient.invalidateQueries({ queryKey: ["entry-conflicts"] });
    },
  });
}

/** planning-versions: delete a work version (guards live server-side). */
export function useDeleteSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => planningApi.deleteSchedule(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

/**
 * planning-versions "Charger cette version": reload a version's context (restore
 * its structure, re-point the ★) WITHOUT solving — no new version is created, the
 * source version's plan is shown as-is. Returns the loaded version's id so the
 * caller selects it.
 */
export function useRegenerateFromVersion() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => planningApi.regenerateFromVersion(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      // The restore replaced the WHOLE structure — refresh every cached family
      // (wizard + the planning reference lists, all staleTime 300 s).
      void queryClient.invalidateQueries({ queryKey: ["wizard"] });
      for (const key of ["teams", "venues", "coaches", "categories", "priority_tiers"]) {
        void queryClient.invalidateQueries({ queryKey: [key] });
      }
    },
    // Surface the backend's reason (409: "pas de photo de structure", or a
    // generation in flight) instead of a generic flash.
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/** planning-versions (overlay versions): "Régénérer" on a period overlay creates
 *  a NEW overlay version (a new Schedule for the same period) and generates it —
 *  unlike a season plan, which regenerates from the current structure. */
export function useRegenerateOverlay() {
  const queryClient = useQueryClient();
  return useMutation({
    // Lot C PR-2 : rend 202 puis passe la main à GenerationWaiting — voiler par-dessus clignoterait.
    meta: { veil: false },
    mutationFn: async (schedulePlanId: string) => {
      const created = await planningApi.createOverlayVersion(schedulePlanId);
      await planningApi.generateSchedule(created.id);
      return created;
    },
    // Invalidate on settled, not just success: if generate fails AFTER the create,
    // the new version already exists server-side — the list must refresh either way.
    onSettled: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
    // Le motif du serveur, pas un échec anonyme : le garde d'épinglage orphelin (#8)
    // répond 422 en NOMMANT le gymnase et le jour, et c'est exactement ce que le
    // gestionnaire doit lire pour agir — l'escamoter derrière « la régénération a
    // échoué » rendait muet un garde écrit pour parler (revue #8, round 4).
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useRegenerate() {
  const queryClient = useQueryClient();
  return useMutation({
    // Lot C PR-2 : rend 202 puis passe la main à GenerationWaiting — exempté du voile.
    meta: { veil: false },
    mutationFn: (id: string) => planningApi.regenerate(id),
    // A NEW version row appears — refresh the version list (the current structure
    // is unchanged, so no need to refetch the reference families). Return the
    // promise so react-query awaits the refetch before the caller's onSuccess
    // selects the new id — otherwise it is absent from the cache and the landing
    // effect immediately reverts the selection to the baseline.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
    // Même raison que useRegenerateOverlay : le motif du serveur, pas un échec anonyme.
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/**
 * P2-44 PR-3 (comblement) — « Combler automatiquement » sur une version de PÉRIODE : crée une V+1
 * dont le serveur épingle HARD les placements de la source, et place les séances à replacer. Miroir
 * strict de {@link useRegenerate} : une nouvelle version apparaît (refetch de la liste), le motif du
 * refus serveur (409/422 nommés) est affiché plutôt qu'un échec anonyme. L'appelant sélectionne la
 * V+1 dans son onSuccess (comme « Régénérer »).
 */
export function useFillSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    // Lot C PR-2 : rend 202 puis passe la main à GenerationWaiting — exempté du voile.
    meta: { veil: false },
    mutationFn: (id: string) => planningApi.fillSchedule(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}
