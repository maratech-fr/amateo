import { frDateShortNoYear } from "@/shared/lib/date";
import { formatDurationMinutes, formatMinutes, parseTime } from "@/shared/lib/time";

import type { Conflict, ConflictFixtureView, ConflictTrainingView, HomeAway, Team, Venue } from "../api";
import { SIDE_ROLE_WORD } from "./conflictLabels";

/**
 * P2-54 « détail par côté » — le BUILDER PUR du modèle de lignes d'un conflit de
 * PERSONNE (MATCH_MATCH / MATCH_TRAINING) : une ligne par côté (équipe, rôle, lieu,
 * adversaire, horaires) + une ligne de chevauchement. `ConflictLine` le rend ;
 * seules ces deux familles l'utilisent (les familles gymnase/passerelle gardent
 * leur ligne grise + pastille globale).
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
export type SegmentSeparator = "arrow" | "dot";

/** Un point horaire d'un côté (« coup d'envoi 15:00 », « fin 17:25 », « 18:00 »). */
export interface ConflictTimeSegment {
  /** Mot muet en tête (« départ », « retour », « fin », « durée estimée », « coup d'envoi »). */
  label?: string;
  /** L'heure « 15:00 » ou la durée « 1 h 55 ». */
  value: string;
  /** Le coup d'envoi — libellé + heure en gras. */
  emphasis?: boolean;
  /** Pastille « estimé » collée à ce segment (coup d'envoi emprunté à une habitude). */
  estimated?: boolean;
  /** Séparateur AVANT ce segment ; absent = premier segment, sans séparateur. */
  separator?: SegmentSeparator;
}

export interface ConflictSideLine {
  teamName: string;
  /** Le mot du rôle (`SIDE_ROLE_WORD`) — absent quand le côté ne porte pas de rôle. */
  roleWord?: string;
  kind: ConflictSideKind;
  /** Le texte du groupe « lieu » : « domicile » | « extérieur à X » | « extérieur (lieu inconnu) » | « Entraînement · Gymnase X ». */
  place: string;
  /** « vs <adversaire> » — côtés MATCH seulement (jamais un entraînement). */
  opponent?: string;
  segments: ConflictTimeSegment[];
  /** Extérieur sans trajet modélisé : pas de départ/retour, un « trajet inconnu » muet à la place. */
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

/** Les segments horaires d'un côté MATCH — domicile (coup d'envoi → fin · durée) ou extérieur (départ → coup d'envoi → retour). */
function matchSegments(side: ConflictFixtureView): { segments: ConflictTimeSegment[]; travelUnknown?: boolean } {
  const duration = side.matchDurationMinutes;

  if ("AWAY" !== side.homeAway) {
    // Domicile : coup d'envoi RÉEL → fin (kickoff + durée, arithmétique d'affichage) · durée estimée.
    const kickoff = side.kickoffTime ?? "";
    const segments: ConflictTimeSegment[] = [{ label: "coup d'envoi", value: kickoff, emphasis: true }];
    const kickoffMin = parseTime(kickoff);
    if (null !== kickoffMin && undefined !== duration) {
      segments.push({ label: "fin", value: formatMinutes(kickoffMin + duration), separator: "arrow" });
      segments.push({ label: "durée estimée", value: formatDurationMinutes(duration), separator: "dot" });
    }
    return { segments };
  }

  // Extérieur : le coup d'envoi RÉEL, sinon l'ESTIMÉ emprunté à l'habitude.
  const estimated = true === side.estimatedKickoff;
  const kickoff = side.kickoffTime ?? side.estimatedKickoffTime ?? "";
  const kickoffSegment: ConflictTimeSegment = { label: "coup d'envoi", value: kickoff, emphasis: true, estimated };

  // Trajet non modélisé (null) → pas de départ/retour, « trajet inconnu » à la place.
  if (null == side.travelOneWayMinutes) {
    return { segments: [kickoffSegment], travelUnknown: true };
  }
  return {
    segments: [
      { label: "départ", value: wallClockTime(side.windowStart) },
      { ...kickoffSegment, separator: "arrow" },
      { label: "retour", value: wallClockTime(side.windowEnd), separator: "arrow" },
    ],
  };
}

function matchSide(side: ConflictFixtureView, teams: Map<string, Team>): ConflictSideLine {
  const { segments, travelUnknown } = matchSegments(side);
  return {
    teamName: teams.get(side.teamId)?.name ?? "Équipe ?",
    roleWord: roleWordOf(side),
    kind: HOME_AWAY_KIND[side.homeAway],
    place: matchPlace(side),
    opponent: undefined !== side.opponentLabel && "" !== side.opponentLabel ? `vs ${side.opponentLabel}` : undefined,
    segments,
    travelUnknown,
  };
}

function trainingSide(training: ConflictTrainingView, teams: Map<string, Team>, venues: Map<string, Venue>): ConflictSideLine {
  const venueName = venues.get(training.venueId)?.name ?? "Gymnase ?";
  return {
    teamName: teams.get(training.teamId)?.name ?? "Équipe ?",
    roleWord: roleWordOf(training),
    kind: "training",
    place: `Entraînement · ${venueName}`,
    // Entraînement : la fenêtre servie telle quelle, aucun coup d'envoi.
    segments: [{ value: wallClockTime(training.windowStart) }, { value: wallClockTime(training.windowEnd), separator: "arrow" }],
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
 * Le modèle de lignes d'un conflit de PERSONNE, ou `null` pour toute autre famille
 * (le caller garde alors la ligne grise). Deux familles seulement : MATCH_MATCH
 * (left/right) et MATCH_TRAINING (fixture + training).
 */
export function buildConflictSideLines(conflict: Conflict, teams: Map<string, Team>, venues: Map<string, Venue>): ConflictSideModel | null {
  if ("MATCH_MATCH" === conflict.type && undefined !== conflict.left && undefined !== conflict.right && undefined !== conflict.start && undefined !== conflict.end) {
    return {
      sides: [matchSide(conflict.left, teams), matchSide(conflict.right, teams)],
      overlap: overlapLine(conflict.start, conflict.end),
    };
  }
  if ("MATCH_TRAINING" === conflict.type && isFixtureView(conflict.fixture) && undefined !== conflict.training && undefined !== conflict.start && undefined !== conflict.end) {
    return {
      sides: [matchSide(conflict.fixture, teams), trainingSide(conflict.training, teams, venues)],
      overlap: overlapLine(conflict.start, conflict.end),
    };
  }
  return null;
}
