import type { Reservation, Team, TeamSoloBudget } from "../api";
import { reservedTeamsBySlot, teamReservationCount } from "./reservationSlots";
import { sharedGroupLabel } from "./sharedTraining";

/**
 * P2-46 PR-3 — helpers PURS du geste « poser un entraînement mutualisé sur une case » dans la
 * modale Réserver (N équipes ensemble sur UN créneau vide = N réservations en un seul lot).
 *
 * 🔴 RIEN ICI N'AUTORISE NI N'INTERDIT — le serveur reste seul juge. Cette pré-validation est
 * FAIL-SAFE : elle guide la saisie (n'offrir un groupe que là où il PASSERA) sans jamais remplacer
 * le verdict serveur `App\Service\ReservationGroupOccupancy` (créneau vide, plafond K, plafond
 * `sessionsPerWeek` par membre, réciproque, capacité). Un 422 doit rester géré et AFFICHÉ côté
 * écran, jamais supposé impossible (précédent P2-27 PR B décision 4).
 *
 * ⚠ Ce module n'est PAS un miroir déclaré (régime 2) et ne doit pas se déclarer comme tel : il ne
 * branche AUCUN enum de contrainte partagé (`scope`, `ruleType`, `family`, `lockLevel`, `status`)
 * pour décider d'un comportement — il compare des COMPTES (occupation, K, séances/semaine) et
 * regroupe des réservations pour l'AFFICHAGE (patron `matches/lib/diagnostic.ts`). Le serveur garde
 * la règle ; l'écran ne fait que la refléter. (Le registre des miroirs se repère au NOM de son test
 * de garde écrit en source : le citer ici enrôlerait ce module par accident — même précaution que
 * `sharedTraining.ts`.)
 */

/** La forme structurelle d'un bloc de mutualisation dont ce module a besoin (voir `SharedTrainingBlock`). */
export interface GroupLike {
  id: string;
  teamIds: string[];
  /** K — le nombre de séances communes déclaré. */
  commonSessions: number;
}

/** Un groupe proposable sur la case courante, avec son libellé prêt à afficher. */
export interface OfferableGroup {
  id: string;
  label: string;
  teamIds: string[];
}

/** Un groupe qui S'APPLIQUERAIT ici mais ne peut pas encore, avec la raison NOMMÉE (jamais muette). */
export interface BlockedGroup {
  id: string;
  label: string;
  reason: string;
}

export interface GroupOfferResult {
  offerable: OfferableGroup[];
  blocked: BlockedGroup[];
}

/**
 * Combien de cases de la portée sont DÉJÀ « groupe-complètes » pour ce groupe : l'ensemble des
 * équipes réservées sur la case est EXACTEMENT l'ensemble des membres (miroir de
 * `ReservationGroupOccupancy::groupCompleteCaseCount`, pour le plafond K côté écran).
 */
export function completedGroupCaseCount(group: GroupLike, reservations: Reservation[]): number {
  const memberSet = new Set(group.teamIds);
  let count = 0;
  for (const teamIds of reservedTeamsBySlot(reservations).values()) {
    if (sameTeamSet(teamIds, memberSet)) {
      count += 1;
    }
  }

  return count;
}

/**
 * Les groupes de la portée qu'on peut poser sur la case courante, et ceux qui ne le peuvent pas
 * (avec leur raison). Renvoie tout vide quand la case n'est PAS libre dans le brouillon : un
 * entraînement mutualisé exige un créneau entièrement libre (règle a). Le brouillon inclut les
 * retraits déjà composés — retirer les équipes en place REND les groupes proposables dans le même
 * lot (le confort clé « retirer SM4 + poser le groupe » en UNE validation).
 */
export function offerableGroups(
  groups: GroupLike[],
  teams: Team[],
  reservations: Reservation[],
  slotEmptyInDraft: boolean,
  budgetByTeam: Map<string, TeamSoloBudget>,
  pausedTeamIds?: ReadonlySet<string>,
): GroupOfferResult {
  if (!slotEmptyInDraft) {
    return { offerable: [], blocked: [] };
  }
  const teamById = new Map(teams.map((t) => [t.id, t]));
  const nameOf = (id: string): string => teamById.get(id)?.name ?? "?";
  const counts = teamReservationCount(reservations);
  const offerable: OfferableGroup[] = [];
  const blocked: BlockedGroup[] = [];

  for (const group of groups) {
    const label = sharedGroupLabel(group.teamIds, group.commonSessions, nameOf);
    const pausedMember = group.teamIds.find((id) => true === pausedTeamIds?.has(id));
    if (undefined !== pausedMember) {
      blocked.push({ id: group.id, label, reason: `${nameOf(pausedMember)} est en pause cette période.` });
      continue;
    }
    if (completedGroupCaseCount(group, reservations) >= group.commonSessions) {
      const reason = group.commonSessions > 1 ? `Ses ${group.commonSessions} séances communes sont déjà posées.` : "Sa séance commune est déjà posée.";
      blocked.push({ id: group.id, label, reason });
      continue;
    }
    // D4 (P2-60) — le plafond hebdomadaire lu est le budget EFFECTIF servi par le backend
    // (`effectiveSessions`, override de période inclus), pas `team.sessionsPerWeek` : même forme,
    // source qui fait foi. Sans ligne de budget (dérive), on ne bloque pas sur ce motif.
    const maxedMember = group.teamIds.find((id) => {
      const budget = budgetByTeam.get(id);

      return undefined !== budget && (counts.get(id) ?? 0) >= budget.effectiveSessions;
    });
    if (undefined !== maxedMember) {
      blocked.push({ id: group.id, label, reason: `${nameOf(maxedMember)} a déjà toutes ses séances de la semaine.` });
      continue;
    }
    offerable.push({ id: group.id, label, teamIds: group.teamIds });
  }

  return { offerable, blocked };
}

/** Un lot mutualisé posé sur la case : le groupe et les N réservations qui le composent. */
export interface PostedGroupLot {
  group: GroupLike;
  /** Les ids des N réservations : leur retrait empile N `DELETE /reservations/{id}`. */
  reservationIds: string[];
}

/**
 * Le groupe dont les membres sont EXACTEMENT les équipes réservées sur la case, s'il existe — pour
 * afficher ces N réservations comme UN lot (une ligne, membres nommés) plutôt que N verrous
 * anonymes. Présentation (miroir de la dérivation serveur `completeGroupOn`, règle b), jamais une
 * permission : le retrait reste N `DELETE` ordinaires.
 */
export function postedGroupOnSlot(onSlotReservations: Reservation[], groups: GroupLike[]): PostedGroupLot | null {
  if (0 === onSlotReservations.length) {
    return null;
  }
  const reservedTeamIds = onSlotReservations.map((r) => r.teamId);
  for (const group of groups) {
    if (sameTeamSet(reservedTeamIds, new Set(group.teamIds))) {
      return { group, reservationIds: onSlotReservations.map((r) => r.id) };
    }
  }

  return null;
}

function sameTeamSet(teamIds: string[], memberSet: ReadonlySet<string>): boolean {
  const seen = new Set(teamIds);

  return seen.size === memberSet.size && [...memberSet].every((id) => seen.has(id));
}
