import type { ConflictType } from "../api";
import type { ConsultTemporality } from "../store";
import { CONFLICT_FAMILY_LABEL, KIND_LABEL } from "./conflictLabels";
import type { Kind } from "./consultFilter";
import type { MatchFilterMode } from "./matchFilter";
import { monthLabel } from "./monthView";

/**
 * P4-356 — la COMPOSITION pure des repères de contexte du module Matchs, joints au
 * signalement (porte contextuelle) : les FILTRES actifs et la PLAGE affichée. Le front
 * n'invente aucune règle (🔴 `.claude/rules/frontend.md`) — il RÉSUME l'état d'affichage
 * du store en deux chaînes bornées que le support lit dans la console, sans avoir à
 * redemander au gestionnaire ce qu'il regardait.
 *
 * PRÉSENTATION pure : indexer une table clé→libellé (axe, type, famille), jamais décider
 * d'un comportement. Le CÂBLAGE (ces deux chaînes atteignent les deux `PageHeader` du
 * module) vit dans `MatchesLayout`.
 */

const AXIS_LABEL: Record<MatchFilterMode, string> = { equipe: "équipe", coach: "coach", gymnase: "gymnase" };

/** Longueur max alignée sur les bornes serveur du contexte (`FeedbackController`). */
const ACTIVE_FILTERS_MAX = 300;

const DISPLAYED_RANGE_MAX = 80;

interface ActiveFiltersInput {
  filterMode: MatchFilterMode;
  filterIds: string[];
  consultKinds: Kind[] | null;
  consultFamilies: ConflictType[] | null;
  consultAway: boolean;
}

/**
 * Les filtres NON-DÉFAUT en une ligne « · » bornée : l'axe + le nombre de ressources
 * cochées (« axe équipe (3) »), les types et familles cochés (en clair), et
 * « extérieurs masqués » quand les extérieurs sont cachés. Tout à son défaut (aucune
 * ressource cochée, types/familles = null, extérieurs affichés) → chaîne VIDE.
 */
export function composeActiveFilters({ filterMode, filterIds, consultKinds, consultFamilies, consultAway }: ActiveFiltersInput): string {
  const parts: string[] = [];
  if (filterIds.length > 0) {
    parts.push(`axe ${AXIS_LABEL[filterMode]} (${filterIds.length})`);
  }
  if (null !== consultKinds) {
    parts.push(`types : ${consultKinds.map((kind) => KIND_LABEL[kind]).join(", ")}`);
  }
  if (null !== consultFamilies) {
    parts.push(`familles : ${consultFamilies.map((family) => CONFLICT_FAMILY_LABEL[family]).join(", ")}`);
  }
  if (!consultAway) {
    parts.push("extérieurs masqués");
  }
  return parts.join(" · ").slice(0, ACTIVE_FILTERS_MAX);
}

interface DisplayedRangeInput {
  temporality: ConsultTemporality;
  weekendKey: string | null;
  month: string | null;
  phaseLabel: string | null;
}

/**
 * La plage affichée bornée, selon la temporalité : « week-end du JJ/MM » (semaine), le
 * mois en clair (mois), « Phase : <libellé> » (phase) — OMISE (chaîne vide) si le repère
 * de la temporalité courante est absent (auto non résolu, libellé de phase manquant).
 */
export function composeDisplayedRange({ temporality, weekendKey, month, phaseLabel }: DisplayedRangeInput): string {
  if ("semaine" === temporality) {
    if (null === weekendKey) {
      return "";
    }
    const [, mm, dd] = weekendKey.split("-");
    return `week-end du ${dd}/${mm}`.slice(0, DISPLAYED_RANGE_MAX);
  }
  if ("mois" === temporality) {
    return null === month ? "" : monthLabel(month).slice(0, DISPLAYED_RANGE_MAX);
  }
  // phase
  return null === phaseLabel || "" === phaseLabel ? "" : `Phase : ${phaseLabel}`.slice(0, DISPLAYED_RANGE_MAX);
}
