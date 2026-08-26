import { type QueryClient, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { readState, type ReadState } from "@/shared/lib/readState";
import { isScheduleStreamConnected, useScheduleStream } from "@/features/planning/lib/scheduleStream";

import { activeTeams, pausedTeamIds } from "./lib/activeLayer";

import type { CoachPayload, ConstraintPayload, SlotPayload, Team, TeamCoachRole, TeamPayload, Venue, VenuePayload, VenueTravelTimePayload } from "./api";
import * as wizardApi from "./api";

/**
 * Invalide une famille de référence pour TOUS les écrans, pas seulement le wizard (D-25).
 *
 * ⚑ Le wizard écrit sous `["wizard", …]` et n'invalidait que cette clé — mais Planning et
 * Matchs lisent les MÊMES ressources sous `["teams"]`/`["venues"]`/`["coaches"]`, avec un
 * `staleTime` de cinq minutes. Conséquence mesurée : après avoir ajouté un gymnase dans
 * l'assistant, l'écran Matchs ne le proposait pas pendant cinq minutes, et une équipe
 * renommée y gardait son ancien nom. Rien ne le signalait — le cache faisait son travail.
 *
 * ⚠ Reste ouvert (D-25 volet a) : `planning` et `matches` déclarent la même clé avec des
 * `queryFn` DIFFÉRENTES. Ça marche aujourd'hui parce que les deux appellent le même endpoint ;
 * le jour où l'une ajoute un filtre, l'écran monté en second recevra silencieusement les
 * données de l'autre.
 */
export const invalidateEverywhere = (queryClient: QueryClient, family: "teams" | "venues" | "coaches"): Promise<void> =>
  Promise.all([
    queryClient.invalidateQueries({ queryKey: ["wizard", family] }),
    queryClient.invalidateQueries({ queryKey: [family] }),
  ]).then(() => undefined);

export function useWizardTeams() {
  return useQuery({ queryKey: ["wizard", "teams"], queryFn: wizardApi.listTeams, staleTime: 30_000 });
}

export function useSportCategories() {
  return useQuery({ queryKey: ["sport_categories"], queryFn: wizardApi.listSportCategories, staleTime: 300_000 });
}

export function usePriorityTiers() {
  return useQuery({ queryKey: ["priority_tiers"], queryFn: wizardApi.listPriorityTiers, staleTime: 300_000 });
}

// Per-entity save: every create/update/delete persists immediately, then the list
// is invalidated. "Suivant" only validates + navigates — nothing to flush.
export function useCreateTeam() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: TeamPayload) => wizardApi.createTeam(body),
    onSuccess: () => invalidateEverywhere(queryClient, "teams"),
  });
}

export function useUpdateTeam() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: TeamPayload }) => wizardApi.updateTeam(id, body),
    onSuccess: () => invalidateEverywhere(queryClient, "teams"),
  });
}

export function useDeleteTeam() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteTeam(id),
    onSuccess: () => invalidateEverywhere(queryClient, "teams"),
  });
}

/** Atomic bulk reorder for the sort UI (one transaction, no per-team version races). */
export function useReorderTeams() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (items: { id: string; priorityTierId: number; tierOrder: number }[]) => wizardApi.reorderTeams(items),
    onSuccess: () => invalidateEverywhere(queryClient, "teams"),
  });
}

// --- Venues + slots (W2) ---

export function useWizardVenues() {
  return useQuery({ queryKey: ["wizard", "venues"], queryFn: wizardApi.listVenues, staleTime: 30_000 });
}

export function useVenueSlots() {
  return useQuery({ queryKey: ["wizard", "venue_slots"], queryFn: wizardApi.listVenueSlots, staleTime: 30_000 });
}

/**
 * Les salles FFBB d'un CP (P2-20 — combobox de l'étape Gymnases). Désactivée
 * tant que le CP n'a pas 5 chiffres (le serveur refuserait de toute façon).
 * staleTime long : le référentiel des salles d'une commune ne bouge pas
 * pendant une session de saisie — une requête par commune consultée.
 */
export function useFfbbSalles(postalCode: string) {
  return useQuery({
    queryKey: ["wizard", "ffbb_salles", postalCode],
    queryFn: () => wizardApi.listFfbbSalles(postalCode),
    enabled: /^\d{5}$/.test(postalCode),
    staleTime: 3_600_000,
    // Best-effort comme au register : la FFBB en panne ne doit ni retenter en
    // boucle ni faire du bruit — la saisie libre reste le chemin.
    retry: false,
  });
}

/** Salles proches du club (P2-21 lot D) — radius null = auto. Même profil best-effort que useFfbbSalles. */
export function useFfbbSallesProches(radiusKm: number | null) {
  return useQuery({
    queryKey: ["wizard", "ffbb_salles_proches", radiusKm],
    queryFn: () => wizardApi.listFfbbSallesProches(radiusKm),
    staleTime: 3_600_000,
    retry: false,
  });
}

export function useCreateVenue() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: VenuePayload) => wizardApi.createVenue(body),
    onSuccess: () => invalidateEverywhere(queryClient, "venues"),
  });
}

export function useUpdateVenue() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: VenuePayload }) => wizardApi.updateVenue(id, body),
    onSuccess: () => invalidateEverywhere(queryClient, "venues"),
  });
}

/**
 * P3-16 — l'impact d'une suppression, calculé par le serveur. Requête montée SEULEMENT quand
 * une suppression est en attente de confirmation (`id` non nul) : c'est une lecture par
 * entité, pas une donnée d'écran.
 *
 * ⚠ `staleTime: 0` DÉLIBÉRÉMENT : entre deux ouvertures de la modale, le club a pu changer.
 * Annoncer un impact périmé serait revenir au défaut qu'on corrige.
 */
export function useDeletionImpact(kind: wizardApi.DeletableKind, id: string | null) {
  return useQuery({
    queryKey: ["wizard", "deletion_impact", kind, id],
    queryFn: () => wizardApi.fetchDeletionImpact(kind, id ?? ""),
    enabled: null !== id,
    staleTime: 0,
    gcTime: 0,
  });
}

export function useDeleteVenue() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteVenue(id),
    onSuccess: () => {
      void invalidateEverywhere(queryClient, "venues");
      void queryClient.invalidateQueries({ queryKey: ["wizard", "venue_slots"] });
    },
  });
}

// --- Travel-time matrix + geocoding (P2-53 RMM-8) ---

const TRAVEL_TIMES_KEY = ["wizard", "venue_travel_times"] as const;

/** La matrice de trajet du club+saison courant (SeasonFilter serveur-side). */
export function useVenueTravelTimes() {
  return useQuery({ queryKey: TRAVEL_TIMES_KEY, queryFn: wizardApi.listVenueTravelTimes, staleTime: 30_000 });
}

export function useCreateVenueTravelTime() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: VenueTravelTimePayload) => wizardApi.createVenueTravelTime(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TRAVEL_TIMES_KEY }),
  });
}

export function useUpdateVenueTravelTime() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: VenueTravelTimePayload }) => wizardApi.updateVenueTravelTime(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TRAVEL_TIMES_KEY }),
  });
}

/** L'autofill IGN — remplit AUTO, préserve les MANUAL. Après coup la matrice est réinvalidée. */
export function useAutofillVenueTravelTimes() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => wizardApi.autofillVenueTravelTimes(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TRAVEL_TIMES_KEY }),
  });
}

/** Géocodage à la demande (le clic « Localiser »). Une mutation, pas une requête permanente. */
export function useGeocode() {
  return useMutation({
    mutationFn: (q: string) => wizardApi.geocodeAddress(q),
  });
}

export function useCreateSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: SlotPayload) => wizardApi.createSlot(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "venue_slots"] }),
  });
}

export function useUpdateSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: SlotPayload }) => wizardApi.updateSlot(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "venue_slots"] }),
  });
}

export function useDeleteSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteSlot(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "venue_slots"] }),
  });
}

// --- Period-editable structure (F1): slots + team overrides scoped to a period ---

export function usePeriodSlots(schedulePlanId: string | null) {
  return useQuery({
    queryKey: ["wizard", "period_slots", schedulePlanId],
    queryFn: () => wizardApi.listPeriodSlots(schedulePlanId as string),
    enabled: null !== schedulePlanId,
    staleTime: 30_000,
  });
}

/**
 * Les créneaux de la COUCHE qu'on est en train d'éditer : ceux du socle quand on
 * travaille la saison (`schedulePlanId` null), la grille que la période POSSÈDE quand
 * on travaille une période (#8 — copie du modèle de saison prise à la naissance du plan).
 *
 * Lire le socle depuis une période était sain tant que le backend unionnait les deux
 * couches. Il ne le fait plus : une réservation posée sur un créneau de saison absent
 * de la grille de la période devient un épinglage orphelin, et OrphanPinGuard refuse
 * alors DÉFINITIVEMENT de générer cette période (revue #8, round 4).
 */
export function useGridSlots(schedulePlanId: string | null) {
  const seasonal = useVenueSlots();
  const period = usePeriodSlots(schedulePlanId);

  return null === schedulePlanId ? seasonal : period;
}

// --- #8 : le mode d'un gymnase pour une période (sparse) + la reprise de sa grille ---

export function useVenuePeriodOverrides(schedulePlanId: string | null) {
  return useQuery({
    queryKey: ["wizard", "venue_period_overrides", schedulePlanId],
    queryFn: () => wizardApi.listVenuePeriodOverrides(schedulePlanId as string),
    enabled: null !== schedulePlanId,
    staleTime: 30_000,
  });
}

/**
 * Toute écriture de mode PEUT refaire la grille du gymnase côté serveur (VIERGE la vide,
 * le retour à « hériter » depuis VIERGE la recopie). On invalide donc les créneaux de la
 * période EN PLUS des overrides — sans quoi l'écran affiche une grille que le serveur
 * vient de détruire, et le gestionnaire épingle sur des créneaux qui n'existent plus.
 */
function invalidatePeriodGrid(queryClient: ReturnType<typeof useQueryClient>, schedulePlanId: string | null): void {
  void queryClient.invalidateQueries({ queryKey: ["wizard", "venue_period_overrides", schedulePlanId] });
  void queryClient.invalidateQueries({ queryKey: ["wizard", "period_slots", schedulePlanId] });
  // Indispo informative (2026-08-18) : un changement de mode OU de masque jour change
  // l'ÉTAT EFFECTIF que `/calendar-entries/{id}/conflicts` sert (`effectiveClosedWeekdays`,
  // `disabledVenueIds`). La rangée de coches jour et le bandeau LISENT cet endpoint — sans
  // l'invalider, ils resteraient périmés (la clé est par entryId, on invalide le préfixe).
  void queryClient.invalidateQueries({ queryKey: ["entry-conflicts"] });
  // Vider ou reprendre une grille supprime EN CASCADE les réservations du gymnase côté
  // serveur (VenuePeriodGrid::clear → purgeChildrenOfSlot). Sans invalider leur cache,
  // l'onglet « Réserver » et le récap montrent des épinglages fantômes, et le décompte de
  // la confirmation suivante ment (invariant n°4 — revue #8 PR-B).
  void queryClient.invalidateQueries({ queryKey: ["wizard", "reservations", schedulePlanId] });
}

/**
 * Upsert d'un réglage (période, gymnase) : mode ET/OU masque jour. Le PUT du serveur REMPLACE
 * mode + masque — l'appelant envoie donc l'ÉTAT COMPLET voulu (mode préservé, masque complet).
 * `existingId` absent → POST (création), présent → PUT. Pour REVENIR au défaut « hériter » (mode
 * null ET masque vide), utiliser `useClearVenuePeriodMode` (DELETE) — une ligne vide serait refusée.
 */
export function useSetVenuePeriodMode(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ venueId, mode = null, dayOverrides = null, existingId }: { venueId: string; mode?: wizardApi.VenuePeriodMode | null; dayOverrides?: wizardApi.VenueDayMask | null; existingId?: string }) =>
      undefined === existingId
        ? wizardApi.createVenuePeriodOverride({ schedulePlanId: schedulePlanId as string, venueId, mode, dayOverrides })
        : wizardApi.updateVenuePeriodOverride(existingId, { schedulePlanId: schedulePlanId as string, venueId, mode, dayOverrides }),
    onSuccess: () => invalidatePeriodGrid(queryClient, schedulePlanId),
  });
}

/** Retour au défaut « hériter » : réactive un gymnase désactivé sans toucher à sa grille. */
export function useClearVenuePeriodMode(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteVenuePeriodOverride(id),
    onSuccess: () => invalidatePeriodGrid(queryClient, schedulePlanId),
  });
}

/** « Reprendre la grille du planning principal » — destructif, confirmé côté UI. */
export function useResetVenuePeriodGrid(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (venueId: string) => wizardApi.resetVenuePeriodGrid(schedulePlanId as string, venueId),
    onSuccess: () => invalidatePeriodGrid(queryClient, schedulePlanId),
  });
}

/** « Vider la grille » — ACTION atomique (pas un PUT de mode BLANK, qui serait un no-op
 *  quand le mode ne change pas). Destructif, confirmé côté UI. */
export function useClearVenuePeriodGrid(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (venueId: string) => wizardApi.clearVenuePeriodGrid(schedulePlanId as string, venueId),
    onSuccess: () => invalidatePeriodGrid(queryClient, schedulePlanId),
  });
}

export function useCreatePeriodSlot(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: Omit<SlotPayload, "schedulePlanId">) => wizardApi.createSlot({ ...body, schedulePlanId }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "period_slots", schedulePlanId] }),
  });
}

export function useUpdatePeriodSlot(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: Omit<SlotPayload, "schedulePlanId"> }) => wizardApi.updateSlot(id, { ...body, schedulePlanId }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["wizard", "period_slots", schedulePlanId] });
      // Déplacer un créneau change quelles réservations retombent dessus (et le déplacement
      // en supprime) — rafraîchir leur cache, comme les autres gestes de grille (round 2).
      void queryClient.invalidateQueries({ queryKey: ["wizard", "reservations", schedulePlanId] });
    },
  });
}

export function useDeletePeriodSlot(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteSlot(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["wizard", "period_slots", schedulePlanId] });
      // Supprimer un créneau emporte ses réservations en cascade — même raison que
      // invalidatePeriodGrid (revue #8 PR-B).
      void queryClient.invalidateQueries({ queryKey: ["wizard", "reservations", schedulePlanId] });
    },
  });
}

/**
 * P2-15 — CE QUE LA PÉRIODE CONTIENT, pour l'UI.
 *
 * Le backend filtre déjà correctement (`PeriodConstraintSelector`, #340) : le solveur ne
 * voit ni les équipes mises en pause, ni les gymnases désactivés. L'UI, elle, lisait les
 * listes de SAISON — d'où « 49 équipes » annoncées au récap quand 6 sont cochées, et des
 * gymnases désactivés encore proposés dans les sélecteurs.
 *
 * ⚠ Ces hooks n'implémentent AUCUNE règle métier : ils APPLIQUENT un override déjà chargé
 * (`isActive` d'un `TeamPeriodOverride`, `mode DISABLED` d'un `VenuePeriodOverride`). Les
 * vraies règles — défauts par type de période, résolution des tags, héritage des
 * contraintes — restent côté serveur, et doivent y rester : c'est ce qui les distingue
 * d'un quatrième miroir (cf. P2-14, P3-19).
 *
 * La RÈGLE elle-même vit dans `lib/activeLayer.ts` (fonctions pures, testables sans
 * React — les tests d'écran mockent ces hooks et ne gardent donc que le câblage).
 *
 * `schedulePlanId` null = mode socle : la liste complète, inchangée.
 *
 * FAIL-CLOSED : tant que les overrides ne sont pas LUS, on ne masque RIEN — masquer sur une
 * lecture ratée ferait croire à une période plus petite qu'elle n'est (P4-20/P4-1).
 *
 * ⚠ `layerRead` rend les TROIS états de `readState`, pas un booléen (revue #342 round 2) :
 * le premier jet repliait `loading` sur `failed`, et l'écran criait « n'a pas pu être lu »
 * à CHAQUE ouverture d'une période, sur une lecture simplement en vol. Un faux rapport
 * d'erreur récurrent apprend au gestionnaire à ignorer le bandeau — exactement le jour où
 * la lecture échoue vraiment. Charger et échouer ne se disent pas de la même façon.
 */
/**
 * Indispo INFORMATIVE (2026-08-18) — les DEUX causes d'indisponibilité sont désormais des CHAMPS
 * SERVIS par `/calendar-entries/{id}/conflicts`, plus une union recomposée localement :
 *  - `disabledVenueIds` : gymnases DÉSACTIVÉS (mode DISABLED) — le serveur les calcule (avant :
 *    dérivés localement des overrides, `activeLayer.disabledVenueIds`) ;
 *  - `fullyClosedVenueIds` : gymnases ENTIÈREMENT fermés sur la fenêtre (indisponibilité déclarée).
 * Un gymnase de l'une ou l'autre liste ne SERT pas : retiré de la liste active, présent dans
 * `disabledIds`. Le front ne redérive RIEN (règle d'or) : l'appelant passe ce qu'il a LU des
 * conflits. Défauts `[]` = mode socle ou conflits pas encore lus (fail-closed : on ne masque rien
 * tant qu'on ne sait pas). Le `layerRead` reste porté par la lecture des overrides de la période
 * (le signal fail-closed : les réglages de la période sont-ils chargés ?).
 */
export function useActiveVenues(
  schedulePlanId: string | null,
  disabledVenueIds: Iterable<string> = [],
  fullyClosedVenueIds: Iterable<string> = [],
): { venues: Venue[]; disabledIds: Set<string>; layerRead: ReadState } {
  const all = useWizardVenues();
  const overrides = useVenuePeriodOverrides(schedulePlanId);
  const venues = all.data ?? [];

  if (null === schedulePlanId) {
    return { venues, disabledIds: new Set(), layerRead: "ready" };
  }
  const layerRead = readState(overrides);
  if ("ready" !== layerRead) {
    return { venues, disabledIds: new Set(), layerRead };
  }
  // Union de DEUX champs SERVIS (désactivé + entièrement fermé) — plus aucune dérivation locale.
  const disabledIds = new Set<string>([...disabledVenueIds, ...fullyClosedVenueIds]);

  return { venues: venues.filter((v) => !disabledIds.has(v.id)), disabledIds, layerRead };
}

/** Le pendant équipes — même contrat, même fail-closed. @see useActiveVenues */
export function useActiveTeams(schedulePlanId: string | null): { teams: Team[]; pausedIds: Set<string>; layerRead: ReadState } {
  const all = useWizardTeams();
  const overrides = useTeamPeriodOverrides(schedulePlanId);
  const teams = all.data ?? [];

  if (null === schedulePlanId) {
    return { teams, pausedIds: new Set(), layerRead: "ready" };
  }
  const layerRead = readState(overrides);
  if ("ready" !== layerRead) {
    return { teams, pausedIds: new Set(), layerRead };
  }
  const pausedIds = pausedTeamIds(overrides.data ?? []);

  return { teams: activeTeams(teams, pausedIds), pausedIds, layerRead };
}

export function useTeamPeriodOverrides(schedulePlanId: string | null) {
  return useQuery({
    queryKey: ["wizard", "team_period_overrides", schedulePlanId],
    queryFn: () => wizardApi.listTeamPeriodOverrides(schedulePlanId as string),
    enabled: null !== schedulePlanId,
    staleTime: 30_000,
  });
}

export function useCreateTeamPeriodOverride(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: wizardApi.TeamPeriodOverridePayload) => wizardApi.createTeamPeriodOverride(body),
    // Return the invalidation promise so mutateAsync awaits the refetch — batch
    // seed/ramp keep `busy` until the overrides list is fresh (no click on stale state).
    // No plan refetch: the first override flips teamSelectionInitialized server-side,
    // but in-session re-seed is already guarded by seededPeriods + a non-empty overrides
    // list, and reload re-fetches the plan fresh — so mirroring the flip into cache on
    // every create only buys N redundant refetches.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "team_period_overrides", schedulePlanId] }),
  });
}

export function useUpdateTeamPeriodOverride(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: wizardApi.TeamPeriodOverridePayload }) => wizardApi.updateTeamPeriodOverride(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "team_period_overrides", schedulePlanId] }),
  });
}

export function useDeleteTeamPeriodOverride(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteTeamPeriodOverride(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "team_period_overrides", schedulePlanId] }),
  });
}

export function usePeriodConstraintOverrides(schedulePlanId: string | null) {
  return useQuery({
    queryKey: ["wizard", "constraint_period_overrides", schedulePlanId],
    queryFn: () => wizardApi.listConstraintPeriodOverrides(schedulePlanId as string),
    enabled: null !== schedulePlanId,
    staleTime: 30_000,
  });
}

export function useCreatePeriodConstraintOverride(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: wizardApi.ConstraintPeriodOverridePayload) => wizardApi.createConstraintPeriodOverride(body),
    // Return the invalidation promise so mutateAsync awaits the refetch (accurate busy state).
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "constraint_period_overrides", schedulePlanId] }),
  });
}

export function useUpdatePeriodConstraintOverride(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: wizardApi.ConstraintPeriodOverridePayload }) => wizardApi.updateConstraintPeriodOverride(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "constraint_period_overrides", schedulePlanId] }),
  });
}

export function useDeletePeriodConstraintOverride(schedulePlanId: string | null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteConstraintPeriodOverride(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "constraint_period_overrides", schedulePlanId] }),
  });
}

// --- Coaches + links (W3) ---

export function useWizardCoaches() {
  return useQuery({ queryKey: ["wizard", "coaches"], queryFn: wizardApi.listCoaches, staleTime: 30_000 });
}

export function useWizardTeamCoaches() {
  return useQuery({ queryKey: ["wizard", "team_coaches"], queryFn: wizardApi.listTeamCoaches, staleTime: 30_000 });
}

export function useWizardCoachPlayers() {
  return useQuery({ queryKey: ["wizard", "coach_players"], queryFn: wizardApi.listCoachPlayers, staleTime: 30_000 });
}

export function useCreateCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CoachPayload) => wizardApi.createCoach(body),
    onSuccess: () => invalidateEverywhere(queryClient, "coaches"),
  });
}

export function useUpdateCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: CoachPayload }) => wizardApi.updateCoach(id, body),
    onSuccess: () => invalidateEverywhere(queryClient, "coaches"),
  });
}

export function useDeleteCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteCoach(id),
    onSuccess: () => {
      void invalidateEverywhere(queryClient, "coaches");
      void queryClient.invalidateQueries({ queryKey: ["wizard", "team_coaches"] });
      void queryClient.invalidateQueries({ queryKey: ["wizard", "coach_players"] });
    },
  });
}

export function useCreateTeamCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: { teamId: string; coachId: string; role: TeamCoachRole }) => wizardApi.createTeamCoach(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "team_coaches"] }),
  });
}

export function useDeleteTeamCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteTeamCoach(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "team_coaches"] }),
  });
}

export function useCreateCoachPlayer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: { teamId: string; coachId: string; isActive: boolean }) => wizardApi.createCoachPlayer(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "coach_players"] }),
  });
}

export function useDeleteCoachPlayer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteCoachPlayer(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "coach_players"] }),
  });
}

// --- Constraints (W4) ---

/** In period mode, list the period's dated constraints; else base-plan (permanent) constraints. */
export function useWizardConstraints(calendarEntryId?: string | null) {
  return useQuery({
    queryKey: ["wizard", "constraints", calendarEntryId ?? "base"],
    queryFn: () => wizardApi.listConstraints(calendarEntryId ? { calendarEntryId } : { permanent: "1" }),
    staleTime: 30_000,
  });
}

/**
 * Server-backed reservations (team→slot HARD pins), scoped base vs period overlay.
 *
 * `enabled` n'est PAS déductible de `schedulePlanId` : `null` y est AMBIGU — c'est l'ancre
 * légitime du socle, et aussi ce que vaut un plan pas encore résolu. Sans ce drapeau, la
 * fenêtre de chargement d'une période sert les réservations DU SOCLE en les faisant passer
 * pour celles de la période (récap faux, avertissements faux). L'appelant tranche avec le
 * `ready` de `usePeriodAnchor` — lui seul sait s'il est en mode période.
 */
export function useReservations(schedulePlanId?: string | null, enabled = true) {
  return useQuery({
    queryKey: ["wizard", "reservations", schedulePlanId ?? "base"],
    queryFn: () => wizardApi.listReservations(schedulePlanId ? { schedulePlanId } : undefined),
    enabled,
    staleTime: 30_000,
  });
}

export function useCreateReservation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: wizardApi.ReservationPayload) => wizardApi.createReservation(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "reservations"] }),
  });
}

export function useDeleteReservation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteReservation(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "reservations"] }),
  });
}

/** P2-46 PR-3 — pose un groupe de mutualisation sur une case : UN appel au rail batch pour ses N réservations. */
export function useCreateGroupReservation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: wizardApi.GroupReservationPayload) => wizardApi.createGroupReservation(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "reservations"] }),
  });
}

// --- Shared training groups (P2-27) : N teams train TOGETHER, K common sessions ---

/**
 * Les groupes de mutualisation de la portée courante — socle (`schedulePlanId` null) ou période
 * (un UUID). Même patron que `useReservations`, y compris l'ambiguïté du `null` : `enabled` n'est
 * PAS déductible de `schedulePlanId` (`null` est à la fois l'ancre socle légitime ET un plan pas
 * encore résolu), donc l'appelant en mode période tranche avec le `ready` de l'ancre. En portée
 * socle le provider renvoie socle ET périodes : le consommateur filtre `null === schedulePlanId`.
 */
export function useSharedTrainingGroups(schedulePlanId?: string | null, enabled = true) {
  return useQuery({
    queryKey: ["wizard", "shared_training_groups", schedulePlanId ?? "base"],
    queryFn: () => wizardApi.listSharedTrainingGroups(schedulePlanId ? { schedulePlanId } : undefined),
    enabled,
    staleTime: 30_000,
  });
}

export function useCreateSharedTrainingGroup() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: wizardApi.SharedTrainingGroupPayload) => wizardApi.createSharedTrainingGroup(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "shared_training_groups"] }),
  });
}

export function useUpdateSharedTrainingGroup() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: { teamIds: string[]; commonSessions: number } }) => wizardApi.updateSharedTrainingGroup(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "shared_training_groups"] }),
  });
}

export function useDeleteSharedTrainingGroup() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteSharedTrainingGroup(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "shared_training_groups"] }),
  });
}

export function useWizardTeamTags() {
  return useQuery({ queryKey: ["wizard", "team_tags"], queryFn: wizardApi.listTeamTags, staleTime: 30_000 });
}

export function useWizardTeamTagAssignments() {
  return useQuery({ queryKey: ["wizard", "team_tag_assignments"], queryFn: wizardApi.listTeamTagAssignments, staleTime: 30_000 });
}

// P2-22 D7 — une contrainte DATÉE (fermeture de gymnase) modifie les `closures` servies par
// /calendar-entries/{id}/conflicts. La grille de la période lit cet endpoint : sans invalider
// ["entry-conflicts"], elle reste périmée après la saisie/suppression d'une fermeture.
function invalidateConstraints(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["wizard", "constraints"] });
  void queryClient.invalidateQueries({ queryKey: ["entry-conflicts"] });
}

export function useCreateConstraint() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: ConstraintPayload) => wizardApi.createConstraint(body),
    onSuccess: () => invalidateConstraints(queryClient),
  });
}

export function useUpdateConstraint() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: ConstraintPayload }) => wizardApi.updateConstraint(id, body),
    onSuccess: () => invalidateConstraints(queryClient),
  });
}

export function useDeleteConstraint() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteConstraint(id),
    onSuccess: () => invalidateConstraints(queryClient),
  });
}

// --- Implicit "well-being" rules, adjustable (P2-28) — scoped season / period (PR2) ---

/**
 * Les 4 règles bien-être RÉSOLUES (défauts inclus) pour la PORTÉE courante : saison (`null`) ou la
 * copie d'un plan de période (un UUID).
 *
 * ⚠ La portée entre dans la CLÉ DE CACHE (`… ?? "season"`) — sans quoi le cache de saison et celui
 * d'une période se contamineraient l'un l'autre (le panneau afficherait les valeurs de l'autre en
 * basculant). Même patron que `useReservations`/`useSharedTrainingGroups`.
 */
export function useImplicitRuleSettings(schedulePlanId: string | null = null) {
  return useQuery({
    queryKey: ["wizard", "implicit_rule_settings", schedulePlanId ?? "season"],
    queryFn: () => wizardApi.listImplicitRuleSettings(schedulePlanId),
    staleTime: 30_000,
  });
}

/** PUT d'un réglage. La portée voyage dans le CORPS (uniquement en période — corps saison inchangé). */
export function useUpdateImplicitRuleSetting(schedulePlanId: string | null = null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ ruleKey, body }: { ruleKey: string; body: wizardApi.ImplicitRuleSettingPayload }) =>
      wizardApi.updateImplicitRuleSetting(ruleKey, null === schedulePlanId ? body : { ...body, schedulePlanId }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "implicit_rule_settings", schedulePlanId ?? "season"] }),
  });
}

/**
 * « Réinitialiser » une règle : DELETE par clé. Portée saison → suppression (retour au défaut) ;
 * portée période (`?schedulePlanId=`) → le serveur RE-COPIE la valeur de saison. On garde l'appel
 * saison à UN seul argument (corps de requête inchangé hors période).
 */
export function useResetImplicitRuleSetting(schedulePlanId: string | null = null) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (ruleKey: string) =>
      null === schedulePlanId ? wizardApi.resetImplicitRuleSetting(ruleKey) : wizardApi.resetImplicitRuleSetting(ruleKey, schedulePlanId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "implicit_rule_settings", schedulePlanId ?? "season"] }),
  });
}

// --- Recap + generate (W5) ---

export function useConstraintValidation(enabled: boolean, calendarEntryId?: string | null) {
  return useQuery({
    queryKey: ["wizard", "constraint_validation", calendarEntryId ?? "base"],
    queryFn: () => wizardApi.validateConstraints(calendarEntryId ?? undefined),
    enabled,
    staleTime: 0,
  });
}

/**
 * Follow a schedule's status while it is queued/generating; stops once terminal.
 * FRT-04 : le flux Mercure pousse l'avancement et invalide ce cache ; le poll ne
 * meurt pas (publieur best-effort), il ralentit tant que le flux est connecté.
 */
export function useScheduleStatus(id: string | null) {
  const query = useQuery({
    queryKey: ["wizard", "schedule_status", id],
    queryFn: () => wizardApi.getSchedule(id ?? ""),
    enabled: null !== id,
    // Lot C PR-2 : son premier fetch vit SOUS GenerationWaiting — le voiler par-dessus clignoterait.
    meta: { veil: false },
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      if ("PENDING" !== status && "GENERATING" !== status) {
        return false;
      }

      return isScheduleStreamConnected() ? 15_000 : 2500;
    },
  });
  const status = query.data?.status;
  useScheduleStream(null !== id && ("PENDING" === status || "GENERATING" === status));

  return query;
}

/**
 * Create a fresh schedule then queue its generation; resolves to the schedule id.
 * Reservations are NO LONGER materialised here — they are persisted server-side
 * (Reservation entity) and gathered by the backend at build time (base/overlay),
 * so they survive reloads and are seedable by fixtures.
 */
export function useLaunchGeneration() {
  const queryClient = useQueryClient();
  return useMutation({
    // Lot C PR-2 : rend 202 puis passe la main à GenerationWaiting — exempté du voile bloquant.
    meta: { veil: false },
    mutationFn: async ({
      schedulePlanId,
      existingScheduleId,
    }: {
      schedulePlanId?: string;
      existingScheduleId?: string;
    }) => {
      // Period mode reuses the entry's existing overlay schedule (regenerate);
      // otherwise create a fresh version under its plan (base plan, or first overlay).
      const scheduleId = existingScheduleId ?? (await wizardApi.createSchedule(schedulePlanId)).id;
      await wizardApi.generateSchedule(scheduleId);
      return scheduleId;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

/**
 * P2-44 (ADR-0004) — « Partir du planning de saison » : transcrit la version pointée du socle vers
 * la V1 d'un plan de PÉRIODE vierge, SANS solveur. On invalide la liste des versions ET le
 * calendrier : l'écran embarqué atterrit alors sur la nouvelle V1 (règle « embarqué = la plus
 * récente »). Le résultat porte la liste « à replacer », que l'appelant garde pour l'afficher.
 */
export function useTranscribeFromSocle() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (schedulePlanId: string) => wizardApi.transcribeFromSocle(schedulePlanId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
    },
  });
}
