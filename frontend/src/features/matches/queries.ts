import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import type { CreateFixtureInput, Fixture, PlaceFixtureInput } from "./api";
import * as matchesApi from "./api";

export function useFixtures() {
  return useQuery({ queryKey: ["fixtures"], queryFn: matchesApi.getFixtures, staleTime: 30_000 });
}

export function useCompetitions() {
  return useQuery({ queryKey: ["competitions"], queryFn: matchesApi.getCompetitions, staleTime: 300_000 });
}

export function useLeagueWindows() {
  return useQuery({ queryKey: ["league-match-windows"], queryFn: matchesApi.getLeagueWindows, staleTime: 300_000 });
}

/** The conflict radar is recomputed server-side — keep it fresh (short stale). */
export function useConflicts() {
  return useQuery({ queryKey: ["fixtures", "conflicts"], queryFn: matchesApi.getConflicts, staleTime: 10_000 });
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

export function useCategories() {
  return useQuery({ queryKey: ["categories"], queryFn: matchesApi.getCategories, staleTime: 300_000 });
}

export function useCoaches() {
  return useQuery({ queryKey: ["coaches"], queryFn: matchesApi.getCoaches, staleTime: 300_000 });
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
    onError: () => toast.error("Création du match impossible"),
  });
}

export function usePlaceFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ fixture, input }: { fixture: Fixture; input: PlaceFixtureInput }) => matchesApi.placeFixture(fixture, input),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Placement impossible"),
  });
}

// ── Manual loop (P1-4 PR E1) ─────────────────────────────────────────────────

export function useUpdateFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ fixture, input }: { fixture: Fixture; input: matchesApi.EditFixtureInput }) => matchesApi.updateFixture(fixture, input),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Modification du match impossible"),
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
    onError: () => toast.error("Déplacement impossible"),
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

// ── FFBB pairing (P1-4 PR F) ─────────────────────────────────────────────────

/** Fetched when the dialog OPENS only — on-demand consumption, never cached long. */
export function useFfbbEngagements(enabled: boolean) {
  return useQuery({ queryKey: ["ffbb", "engagements"], queryFn: matchesApi.getFfbbEngagements, enabled, staleTime: 0, gcTime: 0, retry: false });
}

export function useConfirmFfbbPairings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (pairings: { ffbbCompetitionId: string; teamId: string }[]) => matchesApi.confirmFfbbPairings(pairings),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["competitions"] });
      void queryClient.invalidateQueries({ queryKey: ["ffbb", "engagements"] });
      toast.success("Appariements enregistrés");
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

export function useCreateVenueMatchWindow() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.createVenueMatchWindow,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["venue_match_windows"] }),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteVenueMatchWindow() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.deleteVenueMatchWindow,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["venue_match_windows"] }),
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
    mutationFn: ({ file, mappings }: { file: File; mappings: matchesApi.FbiMapping[] }) =>
      matchesApi.importFbiFixtures(file, mappings),
    onSuccess: () => {
      invalidateFixtures(queryClient);
      // The import persists the new Division↔team mappings as competitions.
      void queryClient.invalidateQueries({ queryKey: ["competitions"] });
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}
