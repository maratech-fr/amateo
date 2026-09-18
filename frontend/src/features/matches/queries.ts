import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import { errorMessage } from "@/shared/lib/errorMessage";
import { useMe } from "@/shared/session/queries";
import { toast } from "@/shared/stores/toastStore";

import type { CreateFixtureInput, Fixture, PlaceFixtureInput } from "./api";
import * as matchesApi from "./api";

export function useFixtures() {
  return useQuery({ queryKey: ["fixtures"], queryFn: matchesApi.getFixtures, staleTime: 30_000 });
}

export function useCompetitions() {
  return useQuery({ queryKey: ["competitions"], queryFn: matchesApi.getCompetitions, staleTime: 300_000 });
}

/**
 * RMM-6 — l'écriture bulk des échéances de saisie. Invalide `competitions` : les
 * champs lus (`entryDeadline`/`effectiveEntryDeadline`/`deadlineSource`) y vivent.
 * 422/409 remontent par `onError` (toast) ; l'éditeur ajoute son alerte de formulaire.
 */
export function useSetEntryDeadlines() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ competitionIds, deadline }: { competitionIds: string[]; deadline: string | null }) => matchesApi.setEntryDeadlines(competitionIds, deadline),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["competitions"] }),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useLeagueWindows() {
  return useQuery({ queryKey: ["league-match-windows"], queryFn: matchesApi.getLeagueWindows, staleTime: 300_000 });
}

/** The conflict radar is recomputed server-side — keep it fresh (short stale). */
export function useConflicts() {
  return useQuery({ queryKey: ["fixtures", "conflicts"], queryFn: matchesApi.getConflicts, staleTime: 10_000 });
}

/**
 * P4-207 — pose/remplace la résolution d'un conflit (par empreinte). On invalide
 * `["fixtures","conflicts"]` SEULEMENT : la résolution vit sur le flux du radar, et
 * aucune empreinte ne change (pas de `useModuleVisit` à rejouer). Toast succès/erreur
 * (jamais de sauvegarde muette) ; le message serveur (422/403) est affiché tel quel.
 */
export function useSetConflictResolution() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ fingerprint, status, note }: { fingerprint: string; status: matchesApi.ConflictResolutionStatus; note?: string }) =>
      matchesApi.putConflictResolution(fingerprint, { status, note }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
      toast.success("Statut enregistré.");
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/** P4-207 — remet un conflit « à traiter » (efface sa résolution). Même invalidation
 *  ciblée que la pose ; toast succès/erreur. */
export function useClearConflictResolution() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (fingerprint: string) => matchesApi.deleteConflictResolution(fingerprint),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
      toast.success("Statut enregistré.");
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/**
 * RMM-3 — le « gardien » à l'ouverture. Le POST part au montage du module (le
 * layout, `enabled` piloté par la garde socle) et le delta d'UNE ouverture ne se
 * refetch JAMAIS en cours de session : `staleTime: Infinity`. La grâce serveur rend
 * le F5 idempotent côté back, mais côté client on ne re-POST pas à chaque re-render
 * ni à la navigation boucle⇄configuration (même montage de layout) — un seul POST.
 * Le bandeau résumé et les chips « Nouveau » lisent tous CE cache (même clé), donc
 * un seul appel les nourrit tous. `retry: false` : un badge raté n'est pas une
 * erreur à réessayer, la prochaine visite le rejouera.
 */
export function useModuleVisit(enabled = true) {
  return useQuery({
    queryKey: ["matches", "module-visit"],
    queryFn: matchesApi.postModuleVisit,
    enabled,
    staleTime: Infinity,
    gcTime: Infinity,
    retry: false,
  });
}

/**
 * RMM-6 PR-3 — l'outlook J-7 des échéances de saisie, consommé par la tuile
 * cockpit (`FbiDeadlineCard`). Lecture seule, ouvert au Membre. La règle J-7
 * (`withinWindow`) et le bloc gardien sont calculés BACKEND — le front n'invente
 * rien. Frais court : une échéance approche et le compte de matchs à saisir bouge
 * à chaque saisie FBI marquée.
 */
export function useDeadlineOutlook() {
  return useQuery({ queryKey: ["matches", "deadline-outlook"], queryFn: matchesApi.getDeadlineOutlook, staleTime: 30_000 });
}

/** Le registre « à corriger dans FBI » (entrées ouvertes du club+saison). */
export function useFbiCorrections() {
  return useQuery({ queryKey: ["fbi-corrections"], queryFn: matchesApi.getFbiCorrections, staleTime: 30_000 });
}

/** Fermer/rouvrir une entrée bouge le registre ET le compteur global `fbiTodo`. */
function invalidateFbiCorrections(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["fbi-corrections"] });
  void queryClient.invalidateQueries({ queryKey: ["matches", "deadline-outlook"] });
}

export function useCloseFbiCorrection() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => matchesApi.closeFbiCorrection(id),
    onSuccess: () => invalidateFbiCorrections(queryClient),
    onError: () => toast.error("Impossible de marquer la correction faite dans FBI"),
  });
}

export function useReopenFbiCorrection() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => matchesApi.reopenFbiCorrection(id),
    onSuccess: () => invalidateFbiCorrections(queryClient),
    onError: () => toast.error("Impossible d'annuler cette correction"),
  });
}

// Reference data (names + envelope axes). Long-lived within a session.
export function useTeams() {
  return useQuery({ queryKey: ["teams"], queryFn: matchesApi.getTeams, staleTime: 300_000 });
}

export function usePriorityTiers() {
  return useQuery({ queryKey: ["priority_tiers"], queryFn: matchesApi.getPriorityTiers, staleTime: 300_000 });
}

export function useVenues() {
  return useQuery({ queryKey: ["venues"], queryFn: matchesApi.getVenues, staleTime: 300_000 });
}

/** E2 — la clé de l'inventaire agrégé des libellés de salle FBI/FFBB. Séparée de
 * `["venues"]` : c'est une AGRÉGATION (compteurs + suggestion), pas la liste des
 * gymnases ; l'attache/le retrait/l'import l'invalident explicitement. */
export const VENUE_LABEL_INVENTORY_KEY = ["venue-label-inventory"] as const;

/**
 * E2 — l'inventaire des libellés de salle FBI/FFBB de la saison (`GET
 * /api/venues/fbi-labels`), nourrissant l'écran d'appariement, le bandeau des
 * libellés non appariés et le résumé de section. Frais court (30 s) : chaque
 * import/attache le déplace. `undefined` (chargement/échec) ⇒ les consommateurs
 * restent MUETS (jamais un « 0 non apparié » fabriqué — `readState`).
 */
export function useVenueLabelInventory() {
  return useQuery({ queryKey: VENUE_LABEL_INVENTORY_KEY, queryFn: matchesApi.getVenueLabelInventory, staleTime: 30_000 });
}

export function useCategories() {
  return useQuery({ queryKey: ["categories"], queryFn: matchesApi.getCategories, staleTime: 300_000 });
}

export function useCoaches() {
  return useQuery({ queryKey: ["coaches"], queryFn: matchesApi.getCoaches, staleTime: 300_000 });
}

export function useSportCategoryDurations() {
  return useQuery({ queryKey: ["sport_category_durations"], queryFn: matchesApi.getSportCategoryDurations, staleTime: 300_000 });
}

/**
 * P2-54 RMM-9 — l'écriture d'une durée de match par catégorie. Toast succès/erreur
 * (jamais de sauvegarde muette, FRT-27) + invalidation de la liste des durées.
 */
export function useUpdateSportCategoryDuration() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ category, input }: { category: matchesApi.SportCategoryDuration; input: matchesApi.SportCategoryDurationInput }) =>
      matchesApi.updateSportCategoryDuration(category, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["sport_category_durations"] });
      toast.success("Durée enregistrée.");
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/** Any fixture write changes the radar → invalidate both. */
function invalidateFixtures(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["fixtures"] });
  // Un match créé ou supprimé change l'ENGAGEMENT de son équipe (elle est engagée dès
  // qu'elle porte un match), et `isEngaged` ne voyage que dans ["wizard","teams"] —
  // que rien d'autre n'invalide. Sans ça, l'écran des équipes garde jusqu'à 30 s des
  // lignes déverrouillées juste après l'import FBI, c'est-à-dire au moment PRÉCIS où
  // l'engagement naît : il offrirait « Supprimer » sur une équipe que le serveur
  // refuse — le geste qui finit en 409 que ce champ existe pour ne jamais proposer.
  void queryClient.invalidateQueries({ queryKey: ["wizard", "teams"] });
}

export function useCreateFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateFixtureInput) => matchesApi.createFixture(input),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function usePlaceFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ fixture, input }: { fixture: Fixture; input: PlaceFixtureInput }) => matchesApi.placeFixture(fixture, input),
    onSuccess: () => invalidateFixtures(queryClient),
    // D2 — le serveur peut REFUSER un placement (hors fenêtre d'accès match /
    // indisponibilité) avec un message parlant : on le RESTITUE (via `errorMessage`,
    // qui lit les `violations`/`detail`), on ne le remplace pas par un générique.
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

// ── Manual loop (P1-4 PR E1) ─────────────────────────────────────────────────

export function useUpdateFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ fixture, input }: { fixture: Fixture; input: matchesApi.EditFixtureInput }) => matchesApi.updateFixture(fixture, input),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => matchesApi.deleteFixture(id),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Suppression du match impossible"),
  });
}

export function useUnplaceFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (fixture: Fixture) => matchesApi.unplaceFixture(fixture),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Dé-placement impossible"),
  });
}

/** « Marquer saisi dans FBI » — ferme la boucle hebdo (status → SUBMITTED). */
export function useSubmitFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (fixture: Fixture) => matchesApi.submitFixture(fixture),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Impossible de marquer le match saisi dans FBI"),
  });
}

/** « Corriger — repasser en Placé » — sortie de SUBMITTED (status → PLACED). */
export function useReopenFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (fixture: Fixture) => matchesApi.reopenFixture(fixture),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Impossible de repasser le match en Placé"),
  });
}

export function useMoveFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ fixture, input }: { fixture: Fixture; input: PlaceFixtureInput }) => matchesApi.moveFixture(fixture, input),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useLockFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (fixture: Fixture) => matchesApi.lockFixture(fixture),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Verrouillage impossible"),
  });
}

export function useUnlockFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (fixture: Fixture) => matchesApi.unlockFixture(fixture),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Impossible de rendre le match au système"),
  });
}

export function useSwapFixtures() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ a, b }: { a: Fixture; b: Fixture }) => matchesApi.swapFixtures(a, b),
    // A failed second PUT still moved the first match — refresh in BOTH outcomes
    // so the grid always shows the real state.
    onSettled: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Échange interrompu — vérifiez la grille, l'état affiché est le réel"),
  });
}

// ── Trajet adverse — radar spatial (P2-54 RMM-9 PR-3) ────────────────────────

const OPPONENT_TRAVEL_KEY = ["opponents", "travel"] as const;

/** Any travel write changes the spatial radar → invalidate travel AND conflicts. */
function invalidateTravel(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: OPPONENT_TRAVEL_KEY });
  void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
}

/** Choosing/reverting a gym bumps the SHARED suggestion counters of that opponent → re-read them. */
function invalidateSuggestions(queryClient: ReturnType<typeof useQueryClient>, code: string): void {
  void queryClient.invalidateQueries({ queryKey: ["opponents", code, "suggestions"] });
}

export function useOpponentTravel() {
  return useQuery({ queryKey: OPPONENT_TRAVEL_KEY, queryFn: matchesApi.getOpponentTravel, staleTime: 30_000 });
}

/**
 * Le siège du club est-il localisé ? (coordonnées posées sur `me.club`) — pilote le bandeau
 * « trajets indisponibles » de la carte des trajets adverses. Le backend l'expose aussi en
 * booléen sur `GET /api/opponents/travel` (`clubGeolocated`) ; ici on lit la même vérité depuis
 * la session déjà chargée, sans jamais toucher aux coordonnées brutes.
 */
export function useClubGeolocated(): boolean {
  const { data: me } = useMe();
  return null != me?.club?.latitude && null != me.club.longitude;
}

export function useSetOpponentTravelManual() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: matchesApi.OpponentTravelManualInput) => matchesApi.setOpponentTravelManual(input),
    onSuccess: (_data, input) => {
      invalidateTravel(queryClient);
      invalidateSuggestions(queryClient, input.opponentOrganismeCode);
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useSetOpponentTravelAuto() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: matchesApi.OpponentTravelAutoInput) => matchesApi.setOpponentTravelAuto(input),
    onSuccess: (_data, input) => {
      invalidateTravel(queryClient);
      invalidateSuggestions(queryClient, input.opponentOrganismeCode);
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/**
 * Les gymnases connus d'un adversaire (suggestions PARTAGÉES) — lecture management, à la
 * demande (le picker de la modale). Clé `["opponents", code, "suggestions"]`, alignée sur
 * ce qu'invalident les écritures de trajet. Best-effort (retry false).
 */
export function useVenueSuggestions(code: string) {
  return useQuery({
    queryKey: ["opponents", code, "suggestions"],
    queryFn: () => matchesApi.getVenueSuggestions(code),
    enabled: "" !== code,
    staleTime: 30_000,
    retry: false,
  });
}

export function useResolveOpponentTravel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => matchesApi.resolveOpponentTravel(),
    onSuccess: (result) => {
      invalidateTravel(queryClient);
      toast.success(`Trajets recalculés : ${result.resolved} localisé(s).`);
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/** Étape serveur → libellé FR lu par le gestionnaire (jamais la clé technique). */
const REFRESH_STEP_LABELS: Record<matchesApi.OpponentRefreshStep, string> = {
  codes: "codes",
  "auto-locate": "gymnases",
  travel: "trajets",
};

/** L'état de la mise à jour des adversaires — pilote le libellé du bouton et l'annonce a11y. */
export type UpdateOpponentsStep = "idle" | "running";

export interface UpdateOpponentsController {
  run: () => void;
  isPending: boolean;
  step: UpdateOpponentsStep;
}

/**
 * PR 2b — « Mettre à jour les adversaires » : UN SEUL appel (`POST /api/opponents/refresh`)
 * qui enchaîne côté serveur les trois passes best-effort (rattrapage des codes FFBB,
 * auto-localisation des gymnases depuis le fichier FBI, recalcul des trajets AUTO — le MANUAL
 * préservé). Un unique toast résume les trois passes ; on ré-invalide les fixtures, le radar de
 * conflits et TOUT ce qui pend sous `["opponents"]` (trajet + suggestions partagées). Un échec
 * (dont le 422 du cap, message métier écrit pour être lu) remonte tel quel par `errorMessage`.
 */
export function useUpdateOpponents(): UpdateOpponentsController {
  const queryClient = useQueryClient();
  const [step, setStep] = useState<UpdateOpponentsStep>("idle");

  const run = (): void => {
    if ("idle" !== step) {
      return;
    }
    void (async () => {
      setStep("running");
      try {
        const result = await matchesApi.refreshOpponents();
        if (0 < result.failedSteps.length) {
          // Une passe a levé côté serveur : la mise à jour est PARTIELLE. On le dit
          // franchement (au lieu d'un « succès » mensonger) et on invite à relancer.
          toast.error(`Mise à jour interrompue à l'étape ${REFRESH_STEP_LABELS[result.failedSteps[0]]} — réessayez.`);
        } else {
          const codes = result.codes.resolved;
          const located = result.autoLocated.located;
          const trajets = result.travel.resolved;
          const failed = result.codes.unresolved.length + result.travel.unresolved.length;
          toast.success(
            [
              `${codes} code${codes > 1 ? "s" : ""} retrouvé${codes > 1 ? "s" : ""}`,
              `${located} gymnase${located > 1 ? "s" : ""} localisé${located > 1 ? "s" : ""}`,
              `${trajets} trajet${trajets > 1 ? "s" : ""} calculé${trajets > 1 ? "s" : ""}${0 < failed ? ` (${failed} en échec)` : ""}`,
            ].join(" · "),
          );
        }
      } catch (error) {
        toast.error(await errorMessage(error));
      }

      // La mise à jour a estampillé des codes (fixtures), bougé le trajet + les suggestions
      // partagées, et changé le radar spatial.
      void queryClient.invalidateQueries({ queryKey: ["fixtures"] });
      void queryClient.invalidateQueries({ queryKey: ["opponents"] });
      void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });

      setStep("idle");
    })();
  };

  return { run, isPending: "idle" !== step, step };
}

/** Salles FFBB d'une commune (le combobox « Localiser ») — best-effort, patron VenuesStep. */
export function useFfbbSalles(postalCode: string) {
  return useQuery({
    queryKey: ["ffbb_salles", postalCode],
    queryFn: () => matchesApi.listFfbbSalles(postalCode),
    enabled: /^\d{5}$/.test(postalCode),
    staleTime: 3_600_000,
    retry: false,
  });
}

// ── FFBB pairing (P1-4 PR F) ─────────────────────────────────────────────────

/** Fetched when the dialog OPENS only — on-demand consumption, never cached long. */
export function useFfbbEngagements(enabled: boolean) {
  return useQuery({ queryKey: ["ffbb", "engagements"], queryFn: matchesApi.getFfbbEngagements, enabled, staleTime: 0, gcTime: 0, retry: false });
}

export function useConfirmFfbbPairings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (pairings: { ffbbCompetitionId: string; teamId: string; competitionId?: string }[]) => matchesApi.confirmFfbbPairings(pairings),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["competitions"] });
      void queryClient.invalidateQueries({ queryKey: ["ffbb", "engagements"] });
      toast.success("Appariements enregistrés");
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/** RMM-4 PR-3 — the FFBB-API channel: fetched ON DEMAND (a management gesture,
 * never cached — the button controls `enabled`). FBI stays the truth. */
export function useFfbbRencontres(enabled: boolean) {
  return useQuery({ queryKey: ["ffbb", "rencontres"], queryFn: matchesApi.getFfbbRencontres, enabled, staleTime: 0, gcTime: 0, retry: false });
}

export function useApplyFfbbRencontres() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ decisions, creations }: { decisions: matchesApi.DeviationDecision[]; creations: matchesApi.RencontreCreation[] }) =>
      matchesApi.applyFfbbRencontres(decisions, creations),
    onSuccess: () => {
      invalidateFixtures(queryClient);
      // Créations amicales : une compétition appariée matérialise le competitionId.
      void queryClient.invalidateQueries({ queryKey: ["competitions"] });
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

// ── Traitement des rencontres (PR-3a — la file « Importer ») ─────────────────

/**
 * PR-3a — le geste de traitement (ligne ou masse). Invalide `["fixtures"]` : le
 * `reviewState`/`pendingDeviations`/`reviewedAt` y vivent, donc la file et le
 * badge se recalculent. Le toast des rencontres SAUTÉES (geste masse) est composé
 * par l'appelant, qui seul connaît le nom d'équipe et les dates (jamais un id).
 */
export function useReviewFixtures() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: matchesApi.ReviewFixturesBody) => matchesApi.reviewFixtures(body),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/**
 * PR-3a — tranche UN écart pendant. Le backend rejoue le moteur et renvoie l'état
 * de traitement à jour ; on invalide `["fixtures"]` pour que la file et le badge
 * suivent (un `take_source` sur date/salle dé-place le match — la vue Semaine bouge).
 */
export function useResolveFixtureDeviation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: matchesApi.ResolveDeviationInput) => matchesApi.resolveFixtureDeviation(input),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/**
 * P4-187b — rattache un libellé de salle FBI/FFBB à un gymnase. Invalide
 * `["fixtures"]` (le `suggestedVenueId` et le `venueId` backfillé y vivent, la file
 * de traitement et le radar se recalculent) ET `["venues"]` (le gymnase gagne un
 * `externalLabels`). Le toast de succès (« N rattachés ») est composé par
 * l'appelant, qui seul connaît le nom du gymnase ; ici on ne fait que l'invalidation
 * et le 422 nommé du serveur (`errorMessage` → toast, affiché tel quel).
 */
export function useAttachVenueLabel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: matchesApi.AttachVenueLabelInput) => matchesApi.attachVenueLabel(input),
    onSuccess: () => {
      invalidateFixtures(queryClient);
      void queryClient.invalidateQueries({ queryKey: ["venues"] });
      void queryClient.invalidateQueries({ queryKey: VENUE_LABEL_INVENTORY_KEY });
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/**
 * P4-196 — retire un alias de salle FBI/FFBB d'un gymnase. Invalidation en
 * `onSettled` (PAS `onSuccess`) : après un 404 « gymnase supprimé entre-temps », la
 * liste des gymnases (staleTime 300 s) doit quand même se recaler. On invalide
 * directement `["venues"]` (le gymnase perd un `externalLabels`) ET `["fixtures"]`
 * (le `suggestedVenueId` est DÉRIVÉ des alias à la lecture — sans ça Importer
 * pourrait re-proposer la salle dont on vient de retirer le libellé) — jamais le
 * helper `invalidateFixtures`, dont le volet `["wizard","teams"]` n'a rien à faire
 * ici. Toast de succès ; le message serveur (409 saison archivée…) est affiché tel quel.
 */
export function useDetachVenueLabel() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: matchesApi.DetachVenueLabelInput) => matchesApi.detachVenueLabel(input),
    onSuccess: () => toast.success("Libellé retiré."),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["venues"] });
      void queryClient.invalidateQueries({ queryKey: ["fixtures"] });
      void queryClient.invalidateQueries({ queryKey: VENUE_LABEL_INVENTORY_KEY });
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

// ── Capacity layer (P1-4 PR B) ───────────────────────────────────────────────

/** Match access windows of the club's venues — consumed by the placement panel,
 * the wizard gate exemption and the access editor. */
export function useVenueMatchWindows() {
  return useQuery({ queryKey: ["venue_match_windows"], queryFn: matchesApi.getVenueMatchWindows, staleTime: 300_000 });
}

/**
 * Les fenêtres d'accès nourrissent le RADAR autant que la liste : `ACCESS_WINDOW_LOST` est
 * dérivé d'elles (`App\Service\MatchConflictDetector::kickoffInsideWindow`, miroir front
 * `lib/matchAccess.ts`). Sans la 2e clé, `useConflicts` (staleTime 10 s) servait son cache —
 * un accès perdu passait en FAUX VERT, un conflit résolu restait affiché — jusqu'au prochain
 * remontage. C'était la SEULE écriture nourrissant le radar à ne pas l'invalider ; ses quatre
 * frères (`invalidateTravel`, `invalidateUnavailabilities`, `invalidateHabits`,
 * `invalidateTeamLinks`) le faisaient déjà (FRT-20, 2e tranche).
 */
function invalidateMatchWindows(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["venue_match_windows"] });
  void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
}

export function useCreateVenueMatchWindow() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.createVenueMatchWindow,
    onSuccess: () => invalidateMatchWindows(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteVenueMatchWindow() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.deleteVenueMatchWindow,
    onSuccess: () => invalidateMatchWindows(queryClient),
    onError: () => toast.error("Suppression de la fenêtre impossible"),
  });
}

export function useVenueUnavailabilities() {
  return useQuery({ queryKey: ["venue_unavailabilities"], queryFn: matchesApi.getVenueUnavailabilities, staleTime: 60_000 });
}

/** Any unavailability write moves both alert surfaces (impact card + radar). */
function invalidateUnavailabilities(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["venue_unavailabilities"] });
  void queryClient.invalidateQueries({ queryKey: ["venue-unavailability-impact"] });
  void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
}

export function useCreateVenueUnavailability() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.createVenueUnavailability,
    onSuccess: () => invalidateUnavailabilities(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteVenueUnavailability() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.deleteVenueUnavailability,
    onSuccess: () => invalidateUnavailabilities(queryClient),
    onError: () => toast.error("Suppression de l'indisponibilité impossible"),
  });
}

export function useUnavailabilityImpact() {
  return useQuery({ queryKey: ["venue-unavailability-impact"], queryFn: matchesApi.getUnavailabilityImpact, staleTime: 60_000 });
}

// ── Preferences layer (P1-4 PR C) ────────────────────────────────────────────

export function useTeamMatchHabits() {
  return useQuery({ queryKey: ["team_match_habits"], queryFn: matchesApi.getTeamMatchHabits, staleTime: 300_000 });
}

/** Habit writes move the radar (away estimation) and the grid ghosts. */
function invalidateHabits(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["team_match_habits"] });
  void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
}

export function useCreateTeamMatchHabit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.createTeamMatchHabit,
    onSuccess: () => invalidateHabits(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteTeamMatchHabit() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.deleteTeamMatchHabit,
    onSuccess: () => invalidateHabits(queryClient),
    onError: () => toast.error("Suppression de l'habitude impossible"),
  });
}

export function useTeamLinks() {
  return useQuery({ queryKey: ["team_links"], queryFn: matchesApi.getTeamLinks, staleTime: 300_000 });
}

function invalidateTeamLinks(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["team_links"] });
  void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
}

export function useCreateTeamLink() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.createTeamLink,
    onSuccess: () => invalidateTeamLinks(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useUpdateTeamLink() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ link, input }: { link: matchesApi.TeamLink; input: { linkType?: matchesApi.TeamLinkType; trainingIntensity?: matchesApi.TeamLinkIntensity } }) =>
      matchesApi.updateTeamLink(link, input),
    onSuccess: () => invalidateTeamLinks(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteTeamLink() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.deleteTeamLink,
    onSuccess: () => invalidateTeamLinks(queryClient),
    onError: () => toast.error("Suppression du lien impossible"),
  });
}

// ── Rotation A/B — shared match slots (RMM-5 PR-4) ───────────────────────────

export function useMatchSlotRotations() {
  return useQuery({ queryKey: ["match_slot_rotations"], queryFn: matchesApi.getMatchSlotRotations, staleTime: 300_000 });
}

function invalidateRotations(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["match_slot_rotations"] });
}

export function useCreateMatchSlotRotation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.createMatchSlotRotation,
    onSuccess: () => invalidateRotations(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useUpdateMatchSlotRotation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: matchesApi.MatchSlotRotationInput }) => matchesApi.updateMatchSlotRotation(id, input),
    onSuccess: () => invalidateRotations(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteMatchSlotRotation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.deleteMatchSlotRotation,
    onSuccess: () => invalidateRotations(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/** « Placer automatiquement » (P1-4 PR D) — synchronous solve; every fixture
 * surface moves (placements + radar + engagement stays as-is). */
export function usePlaceMatches() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.placeMatches,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["fixtures"] });
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/** Dry-run — writes nothing server-side, so no invalidation. */
export function useAnalyzeFbiFixtures() {
  return useMutation({
    mutationFn: (file: File) => matchesApi.analyzeFbiFixtures(file),
    // Surface the backend's actionable message (missing columns, bad format…),
    // not a fixed label — same pattern as cockpit/queries.
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useImportFbiFixtures() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ file, mappings, decisions }: { file: File; mappings: matchesApi.FbiMapping[]; decisions?: matchesApi.DeviationDecision[] }) =>
      matchesApi.importFbiFixtures(file, mappings, decisions ?? []),
    onSuccess: () => {
      invalidateFixtures(queryClient);
      // The import persists the new Division↔team mappings as competitions.
      void queryClient.invalidateQueries({ queryKey: ["competitions"] });
      // RMM-4 — every deposit is dated: the freshness feed just moved.
      void queryClient.invalidateQueries({ queryKey: ["fbi-ingestions", "latest"] });
      // E2 — un import fait naître/déplacer des libellés de salle : l'inventaire
      // (bandeau des non appariés, écran d'appariement) doit se recalculer.
      void queryClient.invalidateQueries({ queryKey: VENUE_LABEL_INVENTORY_KEY });
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/** RMM-4 — la fraîcheur : le dernier dépôt FBI du club+saison. Lecture légère,
 * ouverte au Membre ; rafraîchie après chaque import (invalidation ci-dessus). */
export function useLatestFbiIngestion() {
  return useQuery({ queryKey: ["fbi-ingestions", "latest"], queryFn: matchesApi.getLatestFbiIngestion, staleTime: 60_000 });
}
