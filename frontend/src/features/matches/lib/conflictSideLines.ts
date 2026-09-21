import { frDateShortNoYear } from "@/shared/lib/date";
import { formatDurationMinutes, formatMinutes, parseTime } from "@/shared/lib/time";

import type { Conflict, ConflictFixtureView, ConflictTrainingView, HomeAway, Team, Venue } from "../api";
import { SIDE_ROLE_WORD } from "./conflictLabels";

/**
 * « détail par côté » — le BUILDER PUR du modèle de lignes d'un conflit qui gagne à
 * s'expliquer côté par côté. Deux VARIANTES, discriminées par `model.kind` :
 *
 * - `person` (P2-54, MATCH_MATCH / MATCH_TRAINING) : une ligne par côté (équipe, rôle,
 *   lieu, adversaire, horaires à 4 créneaux) + une ligne de chevauchement.
 * - `venue` (collision de gymnase VENUE_OVERLAP) : une ligne par rencontre — équipe,
 *   « vs adversaire », le GYMNASE (répété sur chaque ligne), la DATE (répétée sur
 *   chaque ligne), et le créneau (coup d'envoi → fin) — forme imposée par le fondateur.
 *   Le gymnase se loge dans `place`, la date dans `date`. `ConflictLine` choisit la
 *   mise en page sur `model.kind`. Les autres familles gardent leur ligne grise +
 *   pastille globale (le builder renvoie `null`).
 *
 * PRÉSENTATION pure — il ne décide d'AUCUN comportement métier : il choisit des
 * libellés/icônes depuis des TABLES (`HOME_AWAY_*`, `SIDE_ROLE_WORD`), jamais un
 * `switch` décideur sur un enum métier (🔴 `.claude/rules/frontend.md`). Les
 * horaires servis par le backend (`windowStart`/`windowEnd`, `kickoffTime`,
 * `estimatedKickoffTime`) sont LUS, jamais redérivés ; les seules opérations sont
 * de l'ARITHMÉTIQUE D'AFFICHAGE, commentée comme telle : la fin domicile
 * (`kickoff + matchDurationMinutes`) et la durée du chevauchement (`end − start`).
 */

export type ConflictSideKind = "home" | "away" | "training";

/**
 * Les 4 créneaux FIXES d'un côté, alignés en colonnes de tableau (le coup d'envoi toujours
 * colonne 2, quelle que soit la nature). Un créneau absent → cellule « — ».
 */
export interface ConflictSideTimes {
  /** Colonne 1 « Départ » — extérieur avec trajet modélisé seulement. */
  departure?: string;
  /** Colonne 2 « Coup d'envoi » — TOUJOURS présent (match : le coup d'envoi ; entraînement : son début). */
  kickoff: { value: string; estimated: boolean };
  /** Colonne 3 « Fin / retour » — domicile : fin ; extérieur : retour ; entraînement : fin. */
  end?: string;
  /** Colonne 4 « Durée » — durée estimée du match, DOMICILE seul. */
  duration?: string;
}

export interface ConflictSideLine {
  teamName: string;
  /** Le mot du rôle (`SIDE_ROLE_WORD`) — absent quand le côté ne porte pas de rôle. */
  roleWord?: string;
  kind: ConflictSideKind;
  /** Le texte du groupe « lieu » : « domicile » | « extérieur à X » | « extérieur (lieu inconnu) » | « Entraînement · Gymnase X » ; pour la variante `venue`, le NOM DU GYMNASE. */
  place: string;
  /** « vs <adversaire> » — côtés MATCH seulement (jamais un entraînement). */
  opponent?: string;
  /** Variante `venue` seulement : la date courte de la rencontre (« 14/03 »), répétée sur chaque ligne. */
  date?: string;
  times: ConflictSideTimes;
  /** Extérieur sans trajet modélisé : départ/retour absents, un « trajet inconnu » muet à la place. */
  travelUnknown?: boolean;
}

export interface ConflictOverlapLine {
  /** « 15:30 ». */
  start: string;
  /** « 17:25 ». */
  end: string;
  /** Minutes de recouvrement (`end − start`) — pour `formatDurationMinutes`. */
  minutes: number;
  /** Début et fin tombent sur DEUX jours différents → répéter la date. */
  crossDay: boolean;
  /** Dates courtes du début/fin — présentes SEULEMENT quand `crossDay` (sinon inutiles). */
  startDay?: string;
  endDay?: string;
}

export interface ConflictSideModel {
  /** `person` : 4 colonnes horaires (départ/coup d'envoi/fin/durée). `venue` : gymnase · date · créneau. */
  kind: "person" | "venue";
  sides: ConflictSideLine[];
  overlap: ConflictOverlapLine;
}

/** L'icône/lieu de base selon domicile/extérieur — TABLE, pas un décideur. */
const HOME_AWAY_KIND: Record<HomeAway, ConflictSideKind> = {
  HOME: "home",
  AWAY: "away",
};

/** L'heure murale « HH:MM » d'une borne ISO servie par le backend (sans offset, lue telle quelle). */
function wallClockTime(iso: string): string {
  return iso.slice(11, 16);
}

/** Minutes de recouvrement d'un segment `[start, end]` — arithmétique d'AFFICHAGE (`end − start`). */
function overlapMinutes(startIso: string, endIso: string): number {
  return Math.round((new Date(endIso).getTime() - new Date(startIso).getTime()) / 60000);
}

function roleWordOf(side: { role?: ConflictFixtureView["role"] }): string | undefined {
  return undefined !== side.role ? SIDE_ROLE_WORD[side.role] : undefined;
}

/** Le groupe « lieu » d'un côté MATCH : domicile, ou extérieur (avec le lieu connu, sinon « lieu inconnu »). */
function matchPlace(side: ConflictFixtureView): string {
  if ("AWAY" !== side.homeAway) {
    return "domicile";
  }
  const place = side.opponentPlace;
  return null != place && "" !== place ? `extérieur à ${place}` : "extérieur (lieu inconnu)";
}

/** Les 4 créneaux d'un côté MATCH — domicile (coup d'envoi, fin, durée) ou extérieur (départ, coup d'envoi, retour). */
function matchTimes(side: ConflictFixtureView): { times: ConflictSideTimes; travelUnknown?: boolean } {
  const duration = side.matchDurationMinutes;

  if ("AWAY" !== side.homeAway) {
    // Domicile : coup d'envoi RÉEL ; fin = kickoff + durée (arithmétique d'affichage) ; colonne durée.
    const kickoff = side.kickoffTime ?? "";
    const kickoffMin = parseTime(kickoff);
    return {
      times: {
        kickoff: { value: kickoff, estimated: false },
        end: null !== kickoffMin && undefined !== duration ? formatMinutes(kickoffMin + duration) : undefined,
        duration: undefined !== duration ? formatDurationMinutes(duration) : undefined,
      },
    };
  }

  // Extérieur : le coup d'envoi RÉEL, sinon l'ESTIMÉ emprunté à l'habitude.
  const estimated = true === side.estimatedKickoff;
  const kickoff = side.kickoffTime ?? side.estimatedKickoffTime ?? "";

  // Trajet non modélisé (null) → ni départ ni retour, « trajet inconnu » à la place.
  if (null == side.travelOneWayMinutes) {
    return { times: { kickoff: { value: kickoff, estimated } }, travelUnknown: true };
  }
  return {
    times: {
      departure: wallClockTime(side.windowStart),
      kickoff: { value: kickoff, estimated },
      end: wallClockTime(side.windowEnd), // le retour
    },
  };
}

function matchSide(side: ConflictFixtureView, teams: Map<string, Team>): ConflictSideLine {
  const { times, travelUnknown } = matchTimes(side);
  return {
    teamName: teams.get(side.teamId)?.name ?? "Équipe ?",
    roleWord: roleWordOf(side),
    kind: HOME_AWAY_KIND[side.homeAway],
    place: matchPlace(side),
    opponent: undefined !== side.opponentLabel && "" !== side.opponentLabel ? `vs ${side.opponentLabel}` : undefined,
    times,
    travelUnknown,
  };
}

/**
 * Un côté d'une collision de gymnase (VENUE_OVERLAP) : équipe, « vs adversaire », le
 * gymnase (dans `place`, répété sur chaque ligne), la date (répétée), et le créneau
 * coup d'envoi → fin. Les deux rencontres sont à domicile sur le même gymnase ; le
 * coup d'envoi RÉEL (sinon l'estimé emprunté à l'habitude), la fin = coup d'envoi +
 * durée (arithmétique d'affichage, comme le côté domicile d'un conflit de personne).
 */
function venueSide(side: ConflictFixtureView, teams: Map<string, Team>, venueName: string): ConflictSideLine {
  const estimated = true === side.estimatedKickoff;
  const kickoff = side.kickoffTime ?? side.estimatedKickoffTime ?? "";
  const kickoffMin = parseTime(kickoff);
  const duration = side.matchDurationMinutes;
  return {
    teamName: teams.get(side.teamId)?.name ?? "Équipe ?",
    kind: HOME_AWAY_KIND[side.homeAway],
    place: venueName,
    opponent: undefined !== side.opponentLabel && "" !== side.opponentLabel ? `vs ${side.opponentLabel}` : undefined,
    date: frDateShortNoYear(side.matchDate),
    times: {
      kickoff: { value: kickoff, estimated },
      end: null !== kickoffMin && undefined !== duration ? formatMinutes(kickoffMin + duration) : undefined,
    },
  };
}

function trainingSide(training: ConflictTrainingView, teams: Map<string, Team>, venues: Map<string, Venue>): ConflictSideLine {
  const venueName = venues.get(training.venueId)?.name ?? "Gymnase ?";
  return {
    teamName: teams.get(training.teamId)?.name ?? "Équipe ?",
    roleWord: roleWordOf(training),
    kind: "training",
    place: `Entraînement · ${venueName}`,
    // Entraînement : son début va en colonne coup d'envoi, sa fin en colonne fin/retour.
    times: { kickoff: { value: wallClockTime(training.windowStart), estimated: false }, end: wallClockTime(training.windowEnd) },
  };
}

function overlapLine(startIso: string, endIso: string): ConflictOverlapLine {
  const crossDay = startIso.slice(0, 10) !== endIso.slice(0, 10);
  return {
    start: wallClockTime(startIso),
    end: wallClockTime(endIso),
    minutes: overlapMinutes(startIso, endIso),
    crossDay,
    // La date n'est répétée que si le recouvrement franchit minuit (rare : match tardif).
    startDay: crossDay ? frDateShortNoYear(startIso.slice(0, 10)) : undefined,
    endDay: crossDay ? frDateShortNoYear(endIso.slice(0, 10)) : undefined,
  };
}

/** Un `fixture` de conflit est-il une vue MATCH complète (fenêtre), pas la variante VENUE_UNAVAILABLE ? */
function isFixtureView(fixture: Conflict["fixture"]): fixture is ConflictFixtureView {
  return undefined !== fixture && "windowStart" in fixture;
}

/**
 * Le modèle de lignes d'un conflit qui gagne à s'expliquer côté par côté, ou `null`
 * pour toute autre famille (le caller garde alors la ligne grise). Trois cas :
 * MATCH_MATCH (left/right) et MATCH_TRAINING (fixture + training) → variante `person` ;
 * VENUE_OVERLAP (left/right + venueId) → variante `venue`. Un VENUE_OVERLAP sans
 * `venueId` (donnée dégradée : on ne peut pas nommer le gymnase) retombe sur `null`.
 */
export function buildConflictSideLines(conflict: Conflict, teams: Map<string, Team>, venues: Map<string, Venue>): ConflictSideModel | null {
  if ("MATCH_MATCH" === conflict.type && undefined !== conflict.left && undefined !== conflict.right && undefined !== conflict.start && undefined !== conflict.end) {
    return {
      kind: "person",
      sides: [matchSide(conflict.left, teams), matchSide(conflict.right, teams)],
      overlap: overlapLine(conflict.start, conflict.end),
    };
  }
  if ("MATCH_TRAINING" === conflict.type && isFixtureView(conflict.fixture) && undefined !== conflict.training && undefined !== conflict.start && undefined !== conflict.end) {
    return {
      kind: "person",
      sides: [matchSide(conflict.fixture, teams), trainingSide(conflict.training, teams, venues)],
      overlap: overlapLine(conflict.start, conflict.end),
    };
  }
  if ("VENUE_OVERLAP" === conflict.type && undefined !== conflict.left && undefined !== conflict.right && undefined !== conflict.start && undefined !== conflict.end && undefined !== conflict.venueId) {
    const venueName = venues.get(conflict.venueId)?.name ?? "Gymnase ?";
    return {
      kind: "venue",
      sides: [venueSide(conflict.left, teams, venueName), venueSide(conflict.right, teams, venueName)],
      overlap: overlapLine(conflict.start, conflict.end),
    };
  }
  return null;
}
