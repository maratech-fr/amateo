import { useMemo } from "react";

import type { CoachPlayerMembership, TeamCoach } from "@/features/planning/api";

import type { Coach, Competition, Conflict, ConflictType, Fixture, Team, Venue } from "../api";
import { CONFLICT_FAMILIES } from "./conflictLabels";
import { applyKindFilter, DEFAULT_KINDS } from "./consultFilter";
import type { Kind } from "./consultFilter";
import { applyMatchFilter } from "./matchFilter";
import type { MatchFilterMode } from "./matchFilter";

/**
 * La chaîne de filtrage du Calendrier, dans l'ordre : filtre PR-1 (équipe/coach/gymnase)
 * → types de compétition → interrupteur « Extérieurs ». Toutes les entrées déjà lues par
 * la page (store, normalisations, cartes byId) arrivent en paramètres INDIVIDUELS — jamais
 * un objet d'options, qui changerait d'identité à chaque rendu. Zéro règle métier nouvelle :
 * on ne fait que composer des libs pures déjà testées.
 */
export function useMatchFilterChain(
  filterMode: MatchFilterMode,
  filterIds: string[],
  allFixtures: Fixture[],
  allConflicts: Conflict[],
  teamCoaches: { data: TeamCoach[] | undefined },
  coachPlayers: { data: CoachPlayerMembership[] | undefined },
  coachesMap: Map<string, Coach>,
  venuesMap: Map<string, Venue>,
  teamsMap: Map<string, Team>,
  competitionsMap: Map<string, Competition>,
  consultKinds: Kind[] | null,
  consultFamilies: ConflictType[] | null,
  consultAway: boolean,
) {
  // ── Chaîne : PR-1 (équipe/coach/gymnase) → type de compétition ─────────────────
  const filtered = useMemo(
    () => applyMatchFilter({ mode: filterMode, ids: filterIds, fixtures: allFixtures, conflicts: allConflicts, teamCoaches: teamCoaches.data ?? [], coachPlayers: coachPlayers.data ?? [] }),
    [filterMode, filterIds, allFixtures, allConflicts, teamCoaches.data, coachPlayers.data],
  );
  const coachTeamRoles = filtered.coachTeamRoles ?? undefined;
  const filterActive = filterIds.length > 0;
  const filterLabel = useMemo(() => {
    if (!filterActive) {
      return "";
    }
    return filterIds
      .map((id) => {
        if ("coach" === filterMode) {
          const coach = coachesMap.get(id);
          return undefined === coach ? null : `${coach.firstName} ${coach.lastName}`.trim();
        }
        return ("gymnase" === filterMode ? venuesMap.get(id)?.name : teamsMap.get(id)?.name) ?? null;
      })
      .filter((name): name is string => null !== name)
      .join(", ");
  }, [filterActive, filterIds, filterMode, coachesMap, venuesMap, teamsMap]);

  const effectiveKinds = consultKinds ?? DEFAULT_KINDS;
  const effectiveFamilies = consultFamilies ?? CONFLICT_FAMILIES;
  const kindResult = useMemo(
    () => applyKindFilter(filtered.fixtures, filtered.conflicts, effectiveKinds, competitionsMap),
    [filtered.fixtures, filtered.conflicts, effectiveKinds, competitionsMap],
  );
  const kindFixtures = kindResult.fixtures;

  // Interrupteur « Extérieurs » : filtre d'AFFICHAGE (grille/bande/Mois/Phase). Les
  // compteurs, conflits, radar, complétude et enveloppe restent sur `kindFixtures`.
  const visibleFixtures = useMemo(
    () => (consultAway ? kindFixtures : kindFixtures.filter((f) => "HOME" === f.homeAway)),
    [consultAway, kindFixtures],
  );

  return { filtered, coachTeamRoles, filterActive, filterLabel, effectiveKinds, effectiveFamilies, kindResult, visibleFixtures };
}
