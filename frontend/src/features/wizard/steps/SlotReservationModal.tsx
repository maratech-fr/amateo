import { AlertTriangle, CalendarClock, Lock, Trash2, Undo2, Users } from "lucide-react";
import { useId, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { CtcMark } from "@/shared/components/ui/ctc-mark";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { coachFullName } from "@/shared/lib/coachName";
import { errorMessage } from "@/shared/lib/errorMessage";
import { parseTime } from "@/shared/lib/time";

import type { Closure } from "@/features/cockpit/api";

import type { Coach, PriorityTier, Reservation, SharedTrainingBlock, Team, TeamCoach, TeamSoloBudget, Venue, VenueTrainingSlot, VenueTravelTime } from "../api";
import { blockedIntervalsByCoach, conflictingReservation, lateArrivalTolerated, mainCoachByTeam } from "../lib/coachDoubleBooking";
import { dayLabel, hhmm } from "../lib/days";
import { closureLabel } from "../lib/venueClosures";
import { offerableGroups, postedGroupOnSlot } from "../lib/groupReservation";
import { assignableTeams, effectiveSlotCapacity, slotKey } from "../lib/reservationSlots";
import { useCreateGroupReservation, useCreateReservation, useDeleteReservation } from "../queries";
import { WizardStepLink } from "../WizardStepLink";
import { WIZARD_TEXTS } from "../texts";

/** Préfixe des valeurs de la section « Entraînements mutualisés » du sélecteur (P2-51). */
const BLOCK_VALUE_PREFIX = "block:";

type CoachPlayerLink = { teamId: string; coachId: string; isActive: boolean };

interface Props {
  slot: VenueTrainingSlot;
  venue: Venue;
  teams: Team[];
  tiers: PriorityTier[];
  reservations: Reservation[];
  /** null = les liens ne sont pas connus (chargement ou échec) — surtout PAS un tableau vide. */
  teamCoaches: TeamCoach[] | null;
  /** Chantier H — les coachs NOMMÉS (du club+saison) : le CTA de relais nomme l'adjoint éligible
   *  qui ouvrira la séance (« {Prénom Nom} (adjoint) »), pas un « adjoint » générique. */
  coaches?: Coach[];
  coachesPending: boolean;
  coachesFailed: boolean;
  onRetryCoaches: () => void;
  venues: Venue[];
  disabledVenueIds?: ReadonlySet<string>;
  /** Équipes en pause pour la période : nommées sur un épinglage existant, jamais proposées. */
  pausedTeamIds?: ReadonlySet<string>;
  /** P2-22 D2 — ce créneau tombe un jour de fermeture du gymnase : ajout FERMÉ, retrait ouvert
   *  (l'épinglage sur un jour fermé est orphelin et bloque la génération). Même patron que
   *  `disabledVenueIds`, au grain JOUR : atteignable pour corriger, pas pour aggraver. */
  slotClosed?: boolean;
  /** P2-37 D6 — ce gymnase est ENTIÈREMENT fermé sur la fenêtre (donnée serveur) : le refus est
   *  d'un cran plus fort qu'un jour fermé, et le message le dit comme le serveur (D3). */
  venueFullyClosed?: boolean;
  /** Les fermetures de CE gymnase (titre + bornes) — pour dire à l'écran la même chose que le
   *  refus serveur : quelle fermeture, sur quelles dates. */
  venueClosures?: Closure[];
  venueCanSplit: Map<string, boolean>;
  /** P2-51 — les BLOCS de mutualisation de la PORTÉE courante, posables comme une équipe à part
   *  entière : une entrée par bloc, qui réserve la case pour tous ses membres. */
  sharedTrainingBlocks?: SharedTrainingBlock[];
  /** Les rôles de joueur et les contraintes : la modale juge comme le récap et le radar. */
  coachPlayers?: CoachPlayerLink[];
  constraints?: Parameters<typeof blockedIntervalsByCoach>[0];
  /** Chantier H — la matrice de trajet entre gymnases, servie au wizard : la modale calcule le
   *  retard d'arrivée (fin de la séance quittée + barème − début de celle rejointe) pour PROPOSER
   *  de le tolérer au lieu de refuser durement. Barème à vélo par défaut (le wizard n'a pas le
   *  statut véhiculé du coach) ; le serveur reste l'autorité (récap). */
  venueTravelTimes?: readonly Pick<VenueTravelTime, "venueAId" | "venueBId" | "drivingMinutes" | "walkingMinutes">[];
  /** P2-60 — le budget solo par équipe servi par le backend (résidu R(T), posées, appartenance à un
   *  bloc). `null` = pas connu (chargement ou échec) : la saisie est FERMÉE (fail-closed), jamais un
   *  repli sur `sessionsPerWeek`. Le sélecteur AFFICHE ce budget, il ne l'invente pas. */
  teamSoloBudgets: TeamSoloBudget[] | null;
  budgetsPending: boolean;
  budgetsFailed: boolean;
  onRetryBudgets: () => void;
  schedulePlanId: string | null;
  onClose: () => void;
}

/**
 * Affecter/retirer des équipes sur UN créneau (onglet « Réserver »).
 *
 * P2-9 PR C — la modale est TRANSACTIONNELLE (décision fondateur 2026-07-31) : on compose
 * ses ajouts et ses retraits, puis on VALIDE. Auparavant chaque geste partait aussitôt,
 * donc rien ne pouvait s'interposer entre le choix et l'écriture — or c'est précisément là
 * que le contrôle doit vivre : « on ajoute un bouton de validation qui affecte au moment
 * du ok et c'est là que le validator intervient ».
 *
 * Le contrôle : affecter une équipe dont le coach MAIN est déjà ailleurs à la même heure
 * est une IMPOSSIBILITÉ PHYSIQUE, que le solveur ne peut pas rattraper (un verrou HARD est
 * pré-placé hors modèle, ALIGN-07). Le récap la refuse déjà (PR B) ; ici on l'annonce au
 * moment du geste, avec le motif, pour que le gestionnaire comprenne au lieu de subir un
 * refus plus tard.
 *
 * Fermer sans valider ABANDONNE le brouillon (décision fondateur) : comportement standard
 * d'un dialogue OK/Annuler, et les changements en attente sont visibles à l'écran.
 */
export function SlotReservationModal({
  slot,
  venue,
  teams,
  tiers,
  reservations,
  teamCoaches,
  coaches = [],
  coachesPending,
  coachesFailed,
  onRetryCoaches,
  venues,
  disabledVenueIds,
  pausedTeamIds,
  slotClosed = false,
  venueFullyClosed = false,
  venueClosures = [],
  venueCanSplit,
  sharedTrainingBlocks = [],
  coachPlayers = [],
  constraints = [],
  venueTravelTimes = [],
  teamSoloBudgets,
  budgetsPending,
  budgetsFailed,
  onRetryBudgets,
  schedulePlanId,
  onClose,
}: Props) {
  const create = useCreateReservation();
  const del = useDeleteReservation();
  const createGroup = useCreateGroupReservation();
  const blockedGroupsDescId = useId();
  const relayInputId = useId();

  // Brouillon local : rien n'est écrit avant « Valider ».
  const [added, setAdded] = useState<string[]>([]);
  // P2-51 — BLOCS de mutualisation posés dans le brouillon (rail batch, champ `sharedTrainingBlockId`).
  const [addedBlocks, setAddedBlocks] = useState<string[]>([]);
  const [removed, setRemoved] = useState<string[]>([]);
  // Lot 4bis — créneau LIBRE en attente dans le brouillon (un max par case) : son libellé, ou null.
  const [addedFree, setAddedFree] = useState<string | null>(null);
  const [freeLabel, setFreeLabel] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitError, setSubmitError] = useState<string | null>(null);
  // Chantier H — tolérance d'arrivée tardive par équipe ajoutée (0 = aucune), et l'offre de relais
  // en attente (un dédoublement TOLÉRABLE que le gestionnaire peut accepter au lieu de subir le refus).
  const [toleranceByTeam, setToleranceByTeam] = useState<Record<string, number>>({});
  const [pendingRelay, setPendingRelay] = useState<{ teamId: string; lateMinutes: number; adjointName: string } | null>(null);
  const [relayValue, setRelayValue] = useState(0);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const teamById = new Map(teams.map((t) => [t.id, t]));
  const venueName = new Map(venues.map((v) => [v.id, v.name]));
  const coachByTeam = mainCoachByTeam(teamCoaches ?? []);
  // La modale juge comme le récap et le radar : indisponibilités, rôles de joueur, groupes déclarés.
  const playersByTeam = new Map<string, string[]>();
  for (const link of coachPlayers) {
    if (link.isActive) {
      playersByTeam.set(link.teamId, [...(playersByTeam.get(link.teamId) ?? []), link.coachId]);
    }
  }
  const holdContext = { blockedByCoach: blockedIntervalsByCoach(constraints), playersByTeam, groups: sharedTrainingBlocks.map((block) => block.teamIds) };
  // Chantier H — adjoints (ASSISTANT) par équipe : « a-t-on un adjoint qui peut ouvrir la séance
  // rejointe en retard ? » (la disponibilité fine est vérifiée serveur). Barème de trajet à vélo.
  const assistantsByTeam = new Map<string, string[]>();
  for (const link of teamCoaches ?? []) {
    if ("ASSISTANT" === link.role) {
      assistantsByTeam.set(link.teamId, [...(assistantsByTeam.get(link.teamId) ?? []), link.coachId]);
    }
  }
  const coachNameById = new Map(coaches.map((c) => [c.id, coachFullName(c)]));
  const travelBareme = (venueA: string, venueB: string): number => {
    const row = venueTravelTimes.find((t) => (t.venueAId === venueA && t.venueBId === venueB) || (t.venueAId === venueB && t.venueBId === venueA));
    const walking = row?.walkingMinutes;
    return null === walking || undefined === walking ? 20 : walking;
  };
  // La règle ne peut pas s'appliquer sans les liens équipe→coach : une Map vide ne trouve
  // AUCUN conflit. Plutôt que d'autoriser en aveugle (fail-open), on ferme la saisie.
  const guardReady = null !== teamCoaches;
  // P2-60 — le budget solo doit être chargé pour offrir (et étiqueter) une réservation individuelle.
  // Sans lui : FERMÉ (fail-closed), jamais un repli sur `sessionsPerWeek` (le front n'invente pas la règle).
  const budgetReady = null !== teamSoloBudgets;
  const budgetByTeam = new Map((teamSoloBudgets ?? []).map((b) => [b.teamId, b]));
  const key = slotKey(slot.venueId, slot.dayOfWeek, slot.startTime);
  const capacity = effectiveSlotCapacity(slot, venueCanSplit);

  const onSlot = reservations.filter((r) => slotKey(r.venueId, r.dayOfWeek, r.startTime) === key && !removed.includes(r.id));
  // Lot 4bis — on sépare les réservations d'ÉQUIPE des créneaux LIBRES (sans équipe) : la logique
  // d'équipe (lignes, lot, occupants) ne voit que les premières ; les seconds occupent une place.
  const teamOnSlot = onSlot.filter((r) => null !== r.teamId);
  const freeOnSlot = onSlot.filter((r) => null === r.teamId);
  const occupied = onSlot.length + added.length + (null !== addedFree ? 1 : 0);

  // Le picker doit refléter le BROUILLON, pas l'état serveur : une équipe ajoutée à
  // l'instant ne doit plus être proposée, une équipe retirée doit redevenir choisissable.
  // Le backend écarte les réservations d'un gymnase DÉSACTIVÉ pour la période (elles ne
  // partiront pas au solveur) — la modale doit faire pareil, sinon elle refuse un geste que
  // le récap accepte, en citant un gymnase qui ne sert plus. La parité doit tenir dans les
  // DEUX sens : ni plus permissive, ni plus stricte.
  const inScope = (r: Reservation): boolean => undefined === disabledVenueIds || !disabledVenueIds.has(r.venueId);

  const draftReservations: Reservation[] = [
    ...reservations.filter((r) => !removed.includes(r.id) && inScope(r)),
    ...added.map((teamId) => ({
      id: `draft-${teamId}`,
      schedulePlanId,
      teamId,
      venueId: slot.venueId,
      dayOfWeek: slot.dayOfWeek,
      startTime: hhmm(slot.startTime),
      durationMinutes: slot.durationMinutes,
    })),
  ];
  // ATTEINDRE n'est pas AUTORISER (revue #342 round 2). Un gymnase désactivé reste
  // accessible pour qu'on puisse RETIRER l'épinglage qui bloque la génération ; y AJOUTER
  // en recréerait un — même 422, même impasse. Une équipe en pause suit la même logique :
  // son nom reste lisible sur un épinglage existant, mais elle n'est plus proposée (le
  // solveur ne la verra pas, l'épingler serait un geste sans effet).
  const venueDisabled = true === disabledVenueIds?.has(slot.venueId);
  // `disabledVenueIds` fond DÉSACTIVÉ (override) et ENTIÈREMENT FERMÉ (fermeture, P2-37). On
  // sépare pour dire le BON motif : le désactivé « override » n'est que ce qui reste une fois
  // la fermeture écartée.
  const overrideDisabled = venueDisabled && !venueFullyClosed;
  // Trois causes qui ferment l'AJOUT (le retrait, lui, reste ouvert — geste correctif) :
  // gymnase entièrement fermé, jour fermé, ou désactivé pour la période (P2-22 D2 · P2-37 D6).
  const blockAdd = venueFullyClosed || slotClosed || venueDisabled;
  // Le libellé des fermetures du gymnase (titre + bornes) — même substance que le refus serveur.
  const closureText = venueClosures.map(closureLabel).join(" · ");
  const offerable = undefined === pausedTeamIds ? teams : teams.filter((t) => !pausedTeamIds.has(t.id));
  // P2-60 — équipes offrables individuellement, chacune avec N (résidu restant). `added` est le
  // brouillon d'ajouts non sauvés (retiré de N côté client). Vide tant que le budget n'est pas prêt.
  const assignable = blockAdd || !budgetReady ? [] : assignableTeams(offerable, tiers, slot, draftReservations, venueCanSplit, budgetByTeam, added);
  const assignableIds = new Set(assignable.map((a) => a.team.id));
  const remainingByTeam = new Map(assignable.map((a) => [a.team.id, a.remaining]));
  // Occupantes de CETTE case (posées + brouillon d'ajouts) : ni proposées, ni montrées « à résidu
  // nul » — elles sont déjà là.
  const occupant = new Set<string>([...teamOnSlot.map((r) => r.teamId as string), ...added]);
  const draftAddedCount = new Map<string, number>();
  for (const teamId of added) {
    draftAddedCount.set(teamId, (draftAddedCount.get(teamId) ?? 0) + 1);
  }
  // P4-164 — RÉSIDU 0 VISIBLE : une équipe offrable dont tous les créneaux solo sont déjà posés
  // RESTE dans la liste, mais DÉSACTIVÉE avec son motif (elle « disparaissait » avant, laissant le
  // gestionnaire chercher pourquoi). Exclues : les occupantes de la case, et celles sans ligne de
  // budget (fail-closed, dérive). Le front n'invente rien : le résidu vient du backend.
  const residuExhausted = (team: Team): boolean => {
    if (assignableIds.has(team.id) || occupant.has(team.id)) {
      return false;
    }
    const budget = budgetByTeam.get(team.id);
    if (undefined === budget) {
      return false;
    }
    return budget.residual - budget.individualUsed - (draftAddedCount.get(team.id) ?? 0) <= 0;
  };
  const selectorTeams = blockAdd || !budgetReady ? [] : offerable.filter((t) => assignableIds.has(t.id) || residuExhausted(t));
  // Le NOM du bloc d'une équipe (jamais son id) — pour aiguiller « passez par le groupe … ».
  const blockNameOf = (teamId: string): string | null => {
    const block = sharedTrainingBlocks.find((b) => b.teamIds.includes(teamId));
    return undefined === block ? null : lotLabel(block.teamIds);
  };
  // D2/P4-164 — métadonnées d'option (présentation pure, la valeur reste `team.id`) : résidu à
  // droite (« reste N créneau(x) »), « hors groupe » en sous-ligne pour un membre de bloc ; ou état
  // désactivé + motif quand tous les créneaux solo sont posés.
  const optionMetaFor = (team: Team): { count?: string; sub?: string; disabled?: boolean } => {
    if (assignableIds.has(team.id)) {
      const n = remainingByTeam.get(team.id) ?? 0;
      return { count: WIZARD_TEXTS.soloResidual(n), sub: true === budgetByTeam.get(team.id)?.inBlock ? WIZARD_TEXTS.outOfGroup : undefined };
    }
    const name = true === budgetByTeam.get(team.id)?.inBlock ? blockNameOf(team.id) : null;
    return { disabled: true, sub: `${WIZARD_TEXTS.allSlotsPlaced}${null === name ? "" : ` — passez par le groupe ${name}`}` };
  };

  // MUTUALISATION (P2-51). Un bloc occupe la case SEUL : il exige un créneau libre dans le brouillon
  // (retraits déjà pris en compte — c'est ce qui rend « retirer SM4 + poser le bloc » faisable en UNE
  // validation), et une fois posé, aucune autre équipe ne s'y ajoute.
  const blockById = new Map(sharedTrainingBlocks.map((b) => [b.id, b]));
  // Un lot posé se lit par son `teamIds` (seul champ utile au rendu).
  const postedLot = postedGroupOnSlot(teamOnSlot, sharedTrainingBlocks);
  // P2-62 — le retrait en attente d'un lot entier s'affiche en UNE ligne (symétrique de la ligne
  // posée) : les réservations `removed` de CETTE case dont l'ensemble égale un entraînement
  // mutualisé forment un lot, restauré d'un seul Undo. `onSlot` filtrant déjà `removed`, on relit
  // les réservations retirées ici plutôt que via `onSlot`.
  const removedOnSlot = reservations.filter((r) => removed.includes(r.id) && slotKey(r.venueId, r.dayOfWeek, r.startTime) === key && null !== r.teamId);
  const removedLot = postedGroupOnSlot(removedOnSlot, sharedTrainingBlocks);
  const hasDraftedMutualisation = addedBlocks.length > 0;
  // La case est occupée par une mutualisation : lot DÉJÀ écrit, ou lot dans le brouillon.
  const groupOccupies = null !== postedLot || hasDraftedMutualisation;
  // Lot 4bis — un créneau libre (posé ou en brouillon) rend la case NON vide : un bloc exige un
  // créneau entièrement libre, donc il n'est plus posable.
  const slotEmptyInDraft = 0 === onSlot.length && 0 === added.length && !hasDraftedMutualisation && null === addedFree;
  // L'offre des blocs, avec ses règles d'ergonomie (capacité, séances communes, membre en pause) via
  // le patron `offerableGroups` (structurel `GroupLike`).
  const blockOffer = blockAdd ? { offerable: [], blocked: [] } : offerableGroups(sharedTrainingBlocks, teams, draftReservations, slotEmptyInDraft, budgetByTeam, pausedTeamIds);
  // La section « Entraînements mutualisés », valeurs préfixées `block:` → `sharedTrainingBlockId`.
  const mutualisationOptions = blockOffer.offerable.map((g) => ({ value: `${BLOCK_VALUE_PREFIX}${g.id}`, label: g.label }));
  const blockedMutualisations = blockOffer.blocked;
  const hasAnyMutualisation = sharedTrainingBlocks.length > 0;
  // Guide (b) : la case porte des équipes individuelles alors que des mutualisations existent — dire
  // POURQUOI aucune n'est proposée et COMMENT débloquer (retirer les équipes). Une absence muette
  // serait le pire cas (le gestionnaire chercherait sans comprendre).
  const showGroupGuide = guardReady && budgetReady && !blockAdd && !groupOccupies && hasAnyMutualisation && !slotEmptyInDraft;

  const lotLabel = (teamIds: string[]): string => teamIds.map((id) => teamName.get(id) ?? "?").join(" + ");

  const pick = (teamId: string) => {
    if ("" === teamId) {
      return;
    }
    const candidate = { teamId, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: hhmm(slot.startTime), durationMinutes: slot.durationMinutes };
    const clash = conflictingReservation(candidate, draftReservations, coachByTeam, holdContext);
    if (null !== clash) {
      // Chantier H — le dédoublement est-il un RETARD TOLÉRABLE que l'adjoint peut couvrir ? On
      // propose alors de le tolérer plutôt que de refuser durement.
      const relay = relayOffer(candidate, clash);
      if (null !== relay) {
        setError(null);
        setPendingRelay({ teamId, lateMinutes: relay.lateMinutes, adjointName: coachNameById.get(relay.adjointId) ?? "Un adjoint" });
        setRelayValue(relay.lateMinutes);

        return;
      }
      // Le message nomme l'équipe, l'heure et le gymnase : sans ça le gestionnaire sait
      // qu'on refuse, pas ce qu'il doit changer.
      setError(
        `${teamName.get(teamId) ?? "Cette équipe"} ne peut pas être ajoutée : son coach entraîne déjà ${teamName.get(clash.teamId ?? "") ?? "une autre équipe"} à ${hhmm(clash.startTime)} à ${venueName.get(clash.venueId) ?? "un autre gymnase"}.`,
      );

      return;
    }
    setError(null);
    setAdded((prev) => [...prev, teamId]);
  };

  /**
   * Chantier H — ce dédoublement est-il un retard TOLÉRABLE ? Oui ssi la séance qu'on REJOINT (la
   * plus tardive) est celle qu'on ajoute (le candidat), que le retard géométrique (fin de la séance
   * quittée + barème de trajet − début de celle rejointe) tient dans le plafond 30, et que l'équipe
   * rejointe a un adjoint (ASSISTANT ≠ le coach dédoublé). Rend le retard (minutes) ou null. La
   * disponibilité FINE de l'adjoint reste vérifiée serveur (récap).
   */
  const relayOffer = (candidate: { teamId: string; venueId: string; startTime: string; durationMinutes: number }, clash: Reservation): { lateMinutes: number; adjointId: string } | null => {
    const candidateStart = parseTime(candidate.startTime);
    const clashStart = parseTime(clash.startTime);
    if (null === candidateStart || null === clashStart || candidateStart <= clashStart) {
      return null; // on ne tolère que la séance REJOINTE (la plus tardive) = le candidat
    }
    const bareme = travelBareme(clash.venueId, candidate.venueId);
    const lateMinutes = Math.max(0, clashStart + clash.durationMinutes + bareme - candidateStart);
    const tolerable = lateArrivalTolerated(
      { teamId: clash.teamId ?? "", coachId: "", venueId: clash.venueId, dayOfWeek: clash.dayOfWeek, startMinutes: clashStart, durationMinutes: clash.durationMinutes },
      { teamId: candidate.teamId, coachId: "", venueId: candidate.venueId, dayOfWeek: slot.dayOfWeek, startMinutes: candidateStart, durationMinutes: candidate.durationMinutes },
      30,
      bareme,
    );
    if (!tolerable) {
      return null;
    }
    const doubledCoaches = coachByTeam.get(candidate.teamId) ?? [];
    const adjointId = (assistantsByTeam.get(candidate.teamId) ?? []).find((id) => !doubledCoaches.includes(id));
    return undefined === adjointId ? null : { lateMinutes, adjointId };
  };

  // Le sélecteur porte deux sémantiques d'écriture (ajouter UNE équipe, ou poser TOUT un bloc) :
  // la valeur préfixée `block:` aiguille vers le rail batch, tout le reste reste un ajout unitaire.
  const onSelect = (value: string) => {
    if (value.startsWith(BLOCK_VALUE_PREFIX)) {
      setError(null);
      setAddedBlocks((prev) => [...prev, value.slice(BLOCK_VALUE_PREFIX.length)]);

      return;
    }
    pick(value);
  };

  /**
   * Les écritures partent une par une, et le brouillon est PURGÉ AU FUR ET À MESURE. C'est
   * ce qui rend une reprise sûre : après un échec, « Valider » ne rejoue que ce qui reste
   * — sinon une suppression déjà passée repartirait en 404 (lot bloqué), ou une création
   * déjà passée se dupliquerait, `reservation` ne portant aucune contrainte d'unicité.
   * Le lot n'est pas atomique côté serveur (pas de transaction HTTP) : on ne peut donc pas
   * promettre le tout-ou-rien, mais on peut garantir qu'on ne perd ni ne double rien.
   */
  const submit = async () => {
    setSubmitError(null);
    // Phase 1 — retraits PUIS ajouts unitaires. Les retraits d'abord : ils libèrent de la capacité
    // (et VIDENT la case) pour les ajouts du même lot, groupe compris (rail exclusif : créneau libre).
    try {
      for (const id of [...removed]) {
        await del.mutateAsync(id);
        setRemoved((prev) => prev.filter((pending) => pending !== id));
      }
      for (const teamId of [...added]) {
        // Chantier H — la tolérance d'arrivée tardive posée sur cet ajout voyage au backend (0 sinon).
        await create.mutateAsync({ teamId, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: hhmm(slot.startTime), durationMinutes: slot.durationMinutes, schedulePlanId, coachLateToleranceMinutes: toleranceByTeam[teamId] ?? 0 });
        setAdded((prev) => prev.filter((pending) => pending !== teamId));
      }
      // Lot 4bis — le créneau LIBRE en attente part SANS teamId (le backend garde le XOR et refuse
      // une case pleine). Purgé du brouillon dès qu'il est passé (reprise sûre comme les ajouts).
      if (null !== addedFree) {
        await create.mutateAsync({ label: addedFree, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: hhmm(slot.startTime), durationMinutes: slot.durationMinutes, schedulePlanId });
        setAddedFree(null);
      }
    } catch (e) {
      // La modale RESTE ouverte, avec ce qui n'est pas passé (l'ajout refusé garde sa ligne « à
      // valider » et son Undo). D3 (P2-60) : un 422 sur un ajout unitaire (ex. résidu solo dépassé)
      // porte un message parlant du serveur — on l'affiche tel quel via `errorMessage`, comme le
      // rail groupe, sans déplacer le focus. Le budget est FAIL-SAFE : le verdict reste au serveur.
      setSubmitError(await errorMessage(e));

      return;
    }
    // Phase 2 — chaque groupe posé part en UN SEUL appel au rail batch (N réservations, un flush
    // atomique côté serveur), APRÈS les retraits (la case est alors libre). Un 422 serveur reste
    // seul juge : on affiche SON motif (le front n'est qu'un garde-fou d'ergonomie).
    try {
      // P2-51 — chaque bloc part par le rail batch, sous le champ `sharedTrainingBlockId`.
      for (const blockId of [...addedBlocks]) {
        await createGroup.mutateAsync({ sharedTrainingBlockId: blockId, venueId: slot.venueId, dayOfWeek: slot.dayOfWeek, startTime: hhmm(slot.startTime), durationMinutes: slot.durationMinutes, schedulePlanId });
        setAddedBlocks((prev) => prev.filter((pending) => pending !== blockId));
      }
    } catch (e) {
      setSubmitError(await errorMessage(e));

      return;
    }
    onClose();
  };

  const busy = create.isPending || del.isPending || createGroup.isPending;
  const dirty = added.length > 0 || removed.length > 0 || addedBlocks.length > 0 || null !== addedFree;
  // Fermer pendant l'envoi laisserait des mutations en vol s'appliquer sans trace à l'écran.
  const dismiss = () => {
    if (!busy) {
      onClose();
    }
  };

  return (
    <Modal
      label={WIZARD_TEXTS.reserveSlotModalLabel}
      title={`${venue.name} · ${dayLabel(slot.dayOfWeek)} ${hhmm(slot.startTime)}`}
      onClose={dismiss}
      footer={
        <>
          <Button variant="ghost" onClick={dismiss} disabled={busy}>
            {WIZARD_TEXTS.cancel}
          </Button>
          <Button onClick={() => void submit()} disabled={busy || !dirty}>
            {busy ? "Enregistrement…" : "Valider"}
          </Button>
        </>
      }
    >
      <p className="mb-3 text-xs text-muted-foreground">
        Fixe une équipe sur ce créneau (verrou pris en compte à chaque génération). Ce créneau accepte {capacity} équipe{capacity > 1 ? "s" : ""}.
      </p>

      {onSlot.length > 0 || added.length > 0 || removed.length > 0 || addedBlocks.length > 0 || null !== addedFree ? (
        <ul className="mb-3 flex flex-col gap-1">
          {/* Un lot mutualisé DÉJÀ écrit = UNE ligne (membres nommés), pas N verrous anonymes : son
              retrait empile les N `DELETE`. Sinon, les réservations individuelles ligne à ligne. */}
          {null !== postedLot ? (
            <li key="posted-lot" className="flex items-start gap-2 rounded-md border border-border bg-card px-3 py-1.5 text-sm">
              <Users className="mt-0.5 size-3.5 shrink-0 text-accent" />
              <span className="flex-1 font-medium">
                {lotLabel(postedLot.group.teamIds)} <span className="font-normal text-muted-foreground">{WIZARD_TEXTS.mutualizedTrainingTag}</span>
              </span>
              <button
                type="button"
                aria-label={`Retirer l'entraînement mutualisé ${lotLabel(postedLot.group.teamIds)}`}
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                onClick={() => {
                  setError(null);
                  setRemoved((prev) => [...prev, ...postedLot.reservationIds]);
                }}
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ) : (
            teamOnSlot.map((r) => (
              <li key={r.id} className="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-1.5 text-sm">
                <Lock className="size-3.5 text-accent" />
                <span className="flex flex-1 items-center font-medium">
                  {true === teamById.get(r.teamId ?? "")?.isCtc ? <CtcMark partner={teamById.get(r.teamId ?? "")?.ctcPartner} /> : null}
                  {teamName.get(r.teamId ?? "") ?? "?"}
                </span>
                <button
                  type="button"
                  aria-label={`Retirer ${teamName.get(r.teamId ?? "") ?? "l'équipe"}`}
                  className="rounded p-1 text-muted-foreground hover:text-destructive"
                  onClick={() => {
                    setError(null); // le refus affiché peut devenir caduc en libérant la place
                    setRemoved((prev) => [...prev, r.id]);
                  }}
                >
                  <Trash2 className="size-4" />
                </button>
              </li>
            ))
          )}
          {/* P2-62 — un lot mutualisé retiré = UNE ligne « à valider » + UN Undo qui restaure les
              N ids (symétrique de la ligne posée). Un Undo par membre promettait le retrait d'UNE
              seule équipe, que le serveur ne fait pas : il vide toute la case bloc-complète. */}
          {null !== removedLot ? (
            <li key="removed-lot" className="flex items-start gap-2 rounded-md border border-dashed border-destructive/50 bg-surface-destructive px-3 py-1.5 text-sm">
              <Trash2 className="mt-0.5 size-3.5 shrink-0 text-destructive" />
              <span className="flex-1 font-medium line-through">
                {lotLabel(removedLot.group.teamIds)} <span className="font-normal text-muted-foreground no-underline">{WIZARD_TEXTS.mutualizedTrainingTag}</span>
              </span>
              <span className="text-xs text-muted-foreground">{WIZARD_TEXTS.removalToValidate}</span>
              <button
                type="button"
                aria-label={`Annuler le retrait de l'entraînement mutualisé ${lotLabel(removedLot.group.teamIds)}`}
                className="rounded p-1 text-muted-foreground hover:text-foreground"
                onClick={() => setRemoved((prev) => prev.filter((id) => !removedLot.reservationIds.includes(id)))}
              >
                <Undo2 className="size-4" />
              </button>
            </li>
          ) : null}
          {/* Un retrait en attente reste NOMMÉ et annulable : le remplacer par un compteur
              anonyme empêchait de savoir quelle équipe on avait retirée, et de revenir en
              arrière autrement qu'en fermant la modale — ce qui abandonnait aussi les ajouts. */}
          {reservations
            .filter((r) => removed.includes(r.id) && (null === removedLot || !removedLot.reservationIds.includes(r.id)))
            .map((r) => (
              <li key={`removed-${r.id}`} className="flex items-center gap-2 rounded-md border border-dashed border-destructive/50 bg-surface-destructive px-3 py-1.5 text-sm">
                <Trash2 className="size-3.5 text-destructive" />
                <span className="flex-1 font-medium line-through">{null === r.teamId ? (r.label ?? "Créneau libre") : (teamName.get(r.teamId) ?? "?")}</span>
                <span className="text-xs text-muted-foreground">{WIZARD_TEXTS.removalToValidate}</span>
                <button
                  type="button"
                  aria-label={`Annuler le retrait de ${null === r.teamId ? (r.label ?? "ce créneau libre") : (teamName.get(r.teamId) ?? "l'équipe")}`}
                  // AUD-A11Y-15 — p-1 : 16 px d'icône + 4 px de part et d'autre = 24 px, le
                  // minimum WCAG 2.5.8. Ce bouton était le seul des trois du fichier resté nu
                  // après AUD-A11Y-12 (ses jumeaux :213 et :251 l'avaient déjà).
                  className="rounded p-1 text-muted-foreground hover:text-foreground"
                  onClick={() => setRemoved((prev) => prev.filter((id) => id !== r.id))}
                >
                  <Undo2 className="size-4" />
                </button>
              </li>
            ))}
          {added.map((teamId) => (
            <li key={`draft-${teamId}`} className="flex flex-wrap items-center gap-2 rounded-md border border-dashed border-accent/60 bg-surface-accent px-3 py-1.5 text-sm">
              <Lock className="size-3.5 text-accent" />
              <span className="flex-1 font-medium">{teamName.get(teamId) ?? "?"}</span>
              {/* Chantier H — la tolérance d'arrivée tardive posée sur cet ajout : pastille + retrait (= 0). */}
              {(toleranceByTeam[teamId] ?? 0) > 0 ? (
                <>
                  <StatusPill variant="warning" icon={<CalendarClock className="size-3" aria-hidden="true" />}>
                    {WIZARD_TEXTS.lateTolerancePill(toleranceByTeam[teamId] ?? 0)}
                  </StatusPill>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setToleranceByTeam((prev) => ({ ...prev, [teamId]: 0 }))}
                  >
                    {WIZARD_TEXTS.removeLateTolerance}
                  </Button>
                </>
              ) : (
                <span className="text-xs text-muted-foreground">{WIZARD_TEXTS.toValidate}</span>
              )}
              <button
                type="button"
                aria-label={`Annuler l'ajout de ${teamName.get(teamId) ?? "l'équipe"}`}
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                onClick={() => {
                  setError(null);
                  setToleranceByTeam((prev) => {
                    const next = { ...prev };
                    delete next[teamId];

                    return next;
                  });
                  setAdded((prev) => prev.filter((id) => id !== teamId));
                }}
              >
                <Undo2 className="size-4" />
              </button>
            </li>
          ))}
          {/* P2-51 — un BLOC posé dans le brouillon = UNE ligne « à valider » (même traitement
              accent-dashed qu'un ajout unitaire, membres nommés) ; l'annuler le retire du brouillon. */}
          {addedBlocks.map((blockId) => {
            const members = blockById.get(blockId)?.teamIds ?? [];

            return (
              <li key={`draft-block-${blockId}`} className="flex items-start gap-2 rounded-md border border-dashed border-accent/60 bg-surface-accent px-3 py-1.5 text-sm">
                <Users className="mt-0.5 size-3.5 shrink-0 text-accent" />
                <span className="flex-1 font-medium">
                  {lotLabel(members)} <span className="font-normal text-muted-foreground">{WIZARD_TEXTS.mutualizedTrainingTag}</span>
                </span>
                <span className="text-xs text-muted-foreground">{WIZARD_TEXTS.toValidate}</span>
                <button
                  type="button"
                  aria-label={`Annuler l'ajout de l'entraînement mutualisé ${lotLabel(members)}`}
                  className="rounded p-1 text-muted-foreground hover:text-destructive"
                  onClick={() => {
                    setError(null);
                    setAddedBlocks((prev) => prev.filter((id) => id !== blockId));
                  }}
                >
                  <Undo2 className="size-4" />
                </button>
              </li>
            );
          })}
          {/* Lot 4bis — les CRÉNEAUX LIBRES déjà posés sur la case (retrait immédiat au brouillon). */}
          {freeOnSlot.map((r) => (
            <li key={`free-${r.id}`} className="flex items-center gap-2 rounded-md border border-border bg-card px-3 py-1.5 text-sm">
              <CalendarClock className="size-3.5 text-accent" />
              <span className="flex-1 font-medium">
                {r.label ?? "Créneau réservé"} <span className="font-normal text-muted-foreground">{WIZARD_TEXTS.freeSlotTag}</span>
              </span>
              <button
                type="button"
                aria-label={`Retirer le créneau libre ${r.label ?? ""}`}
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                onClick={() => {
                  setError(null);
                  setRemoved((prev) => [...prev, r.id]);
                }}
              >
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
          {/* Lot 4bis — le créneau libre en attente dans le brouillon = UNE ligne « à valider » + undo. */}
          {null !== addedFree ? (
            <li key="draft-free" className="flex items-center gap-2 rounded-md border border-dashed border-accent/60 bg-surface-accent px-3 py-1.5 text-sm">
              <CalendarClock className="size-3.5 text-accent" />
              <span className="flex-1 font-medium">
                {addedFree} <span className="font-normal text-muted-foreground">{WIZARD_TEXTS.freeSlotTag}</span>
              </span>
              <span className="text-xs text-muted-foreground">{WIZARD_TEXTS.toValidate}</span>
              <button
                type="button"
                aria-label={`Annuler le créneau libre ${addedFree}`}
                className="rounded p-1 text-muted-foreground hover:text-destructive"
                onClick={() => {
                  setError(null);
                  setAddedFree(null);
                }}
              >
                <Undo2 className="size-4" />
              </button>
            </li>
          ) : null}
        </ul>
      ) : null}

      {/* Les motifs de blocage disent la MÊME chose que le refus serveur (P2-37 D3) : la
          fermeture TOTALE prime sur le jour fermé (refus d'un cran plus fort), et le désactivé
          « override » n'est que ce qui reste une fois la fermeture écartée. */}
      {venueFullyClosed ? (
        <NoticeBanner
          tone="warning"
          role="status"
          message={<>Ce gymnase est indisponible sur toute la période{closureText ? ` — ${closureText}` : ""} : la séance ne peut pas y être réservée. Retirez les réservations ci-dessus pour débloquer la génération ; ajustez ou levez la fermeture pour rouvrir ce gymnase.</>}
        />
      ) : slotClosed ? (
        <NoticeBanner
          tone="warning"
          role="status"
          message={<>Ce gymnase est fermé ce jour-là{closureText ? ` — ${closureText}` : ""} : la séance ne peut pas y être réservée ici. Retirez les réservations ci-dessus pour débloquer la génération.</>}
        />
      ) : overrideDisabled ? (
        <NoticeBanner
          tone="warning"
          role="status"
          message={<>{venue.name} est désactivé pour cette période : on ne peut plus y ajouter d&apos;équipe. Retirez les réservations ci-dessus pour débloquer la génération.</>}
        />
      ) : !guardReady ? (
        <p role="status" className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
          {coachesPending ? (
            "Vérification des coachs en cours…"
          ) : (
            <>
              {coachesFailed ? "Impossible de vérifier les coachs" : "Vérification des coachs indisponible"} — la saisie est bloquée pour ne pas créer un conflit sans le voir.{" "}
              <button type="button" className="underline" onClick={onRetryCoaches}>
                {WIZARD_TEXTS.retry}
              </button>
            </>
          )}
        </p>
      ) : !budgetReady ? (
        // P2-60 — FAIL-CLOSED : sans le budget solo, on ne peut ni offrir ni étiqueter une
        // réservation individuelle sans risquer un dépassement de résidu. On ferme la saisie
        // (comme la garde coach) plutôt que de retomber sur `sessionsPerWeek`. `status` poli + retry.
        <p role="status" className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
          {budgetsPending ? (
            "Chargement des créneaux réservables…"
          ) : (
            <>
              {budgetsFailed ? "Impossible de charger les créneaux réservables" : "Créneaux réservables indisponibles"} — la saisie est bloquée pour ne pas dépasser le nombre de créneaux libres d'une équipe.{" "}
              <button type="button" className="underline" onClick={onRetryBudgets}>
                {WIZARD_TEXTS.retry}
              </button>
            </>
          )}
        </p>
      ) : groupOccupies ? (
        // Un groupe occupe la case SEUL (règle b) : plus d'ajout individuel. Le lot ci-dessus le
        // nomme, la ligne reste donc sans libellé. `status` poli : c'est un état issu du brouillon.
        <NoticeBanner tone="warning" role="status" message={WIZARD_TEXTS.groupOccupiesSlotNotice} />
      ) : occupied < capacity ? (
        selectorTeams.length > 0 || mutualisationOptions.length > 0 ? (
          <>
            {/* Groupes par rang (demande fondateur 2026-08-04) : 49 équipes à plat sont illisibles —
                TeamSelect est le home unique du découpage S/A/B/C/D. La section « Entraînements
                mutualisés » vient EN TÊTE (P2-51), avant les paliers, valeurs préfixées `block:`. */}
            <TeamSelect
              aria-label={WIZARD_TEXTS.addTeamLabel}
              aria-describedby={blockedMutualisations.length > 0 ? blockedGroupsDescId : undefined}
              wrapperClassName="w-full"
              value=""
              onValueChange={onSelect}
              disabled={busy}
              teams={selectorTeams}
              tiers={tiers}
              optionMeta={optionMetaFor}
              mutualisationGroups={mutualisationOptions}
              placeholder={WIZARD_TEXTS.addTeamPlaceholder}
            />
            {/* Raison NOMMÉE par bloc indisponible (séances communes atteintes, membre en pause, membre au plafond) :
                liste muette interdite. Contenu STATIQUE au rendu (pas une région live) — relié au
                sélecteur par `aria-describedby` pour qu'un lecteur d'écran l'entende en arrivant dessus. */}
            {blockedMutualisations.length > 0 ? (
              <ul id={blockedGroupsDescId} className="mt-2 flex flex-col gap-0.5">
                {blockedMutualisations.map((g) => (
                  <li key={g.id} className="text-xs text-muted-foreground">
                    {g.label} — {g.reason} <span className="font-medium">{WIZARD_TEXTS.unavailableTag}</span>
                  </li>
                ))}
              </ul>
            ) : null}
          </>
        ) : (
          // D1 (P2-60) — `role="status"` : l'invite REMPLACE le contrôle quand le dernier résidu est
          // consommé ; un lecteur d'écran doit l'entendre. Couvre aussi « uniquement en groupe ».
          <EmptyHint role="status" className="text-xs">
            {WIZARD_TEXTS.noTeamAvailable}
          </EmptyHint>
        )
      ) : (
        <div className="text-xs text-muted-foreground">
          <p>Créneau complet ({occupied}/{capacity}).</p>
          {/* P2-25 lien A — c'est l'instant où l'on comprend qu'il faut plus de place. Plutôt
              que subir, on va RÉGLER ce créneau là où il vit (étape Gymnases), positionné
              dessus. Retour nommé « ← Retour à la réservation ». */}
          <WizardStepLink
            step="venues"
            params={{ slot: slot.id }}
            from="reservation"
            className="mt-1 inline-flex items-center gap-1 font-medium text-accent underline underline-offset-2 hover:text-accent"
          >
            {WIZARD_TEXTS.adjustSlotInVenues}
          </WizardStepLink>
        </div>
      )}

      {/* Guide (b) — la case porte des équipes alors que des groupes existent : dire POURQUOI aucun
          groupe n'est proposé et où agir (les retirer). `status` poli, comme « Créneau complet ». */}
      {showGroupGuide ? (
        <NoticeBanner tone="warning" role="status" className="mt-3" message={WIZARD_TEXTS.groupNeedsFreeSlotNotice} />
      ) : null}

      {/* Lot 4bis — poser un CRÉNEAU LIBRE (sans équipe) : il occupe la case comme une place en
          moins. Un max par case, donc l'offre disparaît dès qu'un créneau libre est posé ou en
          brouillon. Fermé quand la case est pleine, bloquée (fermeture/désactivée) ou occupée par
          une mutualisation — mêmes gardes que l'ajout d'équipe. */}
      {!blockAdd && !groupOccupies && guardReady && budgetReady && 0 === freeOnSlot.length && null === addedFree && occupied < capacity ? (
        <div className="mt-3 border-t border-border pt-3">
          <p className="mb-1 text-xs font-medium text-foreground">{WIZARD_TEXTS.freeSlotTitle}</p>
          <p className="mb-2 text-xs text-muted-foreground">{WIZARD_TEXTS.freeSlotHint}</p>
          <div className="flex items-center gap-2">
            <Input
              aria-label={WIZARD_TEXTS.freeSlotInputLabel}
              className="flex-1"
              maxLength={40}
              value={freeLabel}
              onChange={(e) => setFreeLabel(e.target.value)}
              placeholder={WIZARD_TEXTS.freeSlotPlaceholder}
              disabled={busy}
            />
            <Button
              size="sm"
              variant="outline"
              disabled={busy || "" === freeLabel.trim()}
              onClick={() => {
                setError(null);
                setAddedFree(freeLabel.trim());
                setFreeLabel("");
              }}
            >
              {WIZARD_TEXTS.placeFreeSlot}
            </Button>
          </div>
        </div>
      ) : null}

      {/* Chantier H — l'OFFRE de relais : au lieu du refus dur, on propose de tolérer le retard, avec
          le retard pré-rempli (modifiable 0..30) et un adjoint qui ouvrira la séance. */}
      {null !== pendingRelay ? (
        <div className="mt-3 flex flex-col gap-2">
          <NoticeBanner tone="warning" role="status" message={WIZARD_TEXTS.lateRelayNotice(pendingRelay.lateMinutes, pendingRelay.adjointName)} />
          <div className="flex items-center gap-2">
            <label className="text-xs font-medium text-foreground" htmlFor={relayInputId}>
              {WIZARD_TEXTS.lateToleranceInputLabel}
            </label>
            <Input
              id={relayInputId}
              type="number"
              min={0}
              max={30}
              className="w-20"
              value={String(relayValue)}
              onChange={(e) => {
                const parsed = Number.parseInt(e.target.value, 10);
                setRelayValue(Number.isNaN(parsed) ? 0 : Math.min(30, Math.max(0, parsed)));
              }}
              disabled={busy}
            />
          </div>
          <Button
            size="sm"
            onClick={() => {
              const teamId = pendingRelay.teamId;
              setToleranceByTeam((prev) => ({ ...prev, [teamId]: relayValue }));
              setAdded((prev) => [...prev, teamId]);
              setPendingRelay(null);
            }}
          >
            {WIZARD_TEXTS.tolerateLateArrivalCta(relayValue, pendingRelay.adjointName)}
          </Button>
        </div>
      ) : null}

      {null !== error || null !== submitError ? (
        <NoticeBanner tone="destructive" role="alert" className="mt-3" icon={<AlertTriangle className="size-3.5 text-destructive" />} message={<span>{submitError ?? error}</span>} />
      ) : null}

    </Modal>
  );
}
