import type { ImplicitRuleKey } from "../api";

export interface ImplicitRule {
  id: string;
  title: string;
  detail: string;
}

/**
 * Les règles POSÉES D'OFFICE, lecture seule. L'ordre compte : du plus visible sur la grille au
 * plus subtil, puis les deux garanties de saisie honorée.
 *
 * ⚠ Le texte est un CONTRAT avec le moteur (`engine/app/solver/constraints.py`). Deux
 * formulations corrigées en le rédigeant : un coach PEUT encadrer deux équipes à la fois DANS LE
 * MÊME gymnase (D-14), et « une séance par jour » n'a AUCUNE exception (P4-79).
 */
export const PRODUCT_RULES: ImplicitRule[] = [
  {
    id: "venue-capacity",
    title: "Un gymnase ne dépasse jamais sa capacité",
    detail: "Sur un même créneau, un gymnase accueille au plus le nombre d'équipes que vous lui avez donné. Cette capacité se règle sur l'écran Gymnases, créneau par créneau.",
  },
  {
    id: "coach-two-venues",
    title: "Un coach n'est jamais dans deux gymnases à la fois",
    detail:
      "Deux équipes au même moment dans deux gymnases différents, c'est physiquement impossible : le solveur ne le proposera pas. En revanche, deux équipes au même moment dans le MÊME gymnase sont autorisées — le coach est présent une fois et surveille deux groupes.",
  },
  {
    id: "coach-player",
    title: "Une personne ne peut pas encadrer et jouer en même temps",
    detail: "Quand un coach est aussi joueur dans une autre équipe, ses deux rôles ne peuvent pas tomber sur le même créneau.",
  },
  {
    id: "team-overlap",
    title: "Une équipe n'a jamais deux séances en même temps",
    detail: "Une même équipe ne peut pas être placée sur deux créneaux qui se chevauchent.",
  },
  {
    id: "one-session-per-day",
    title: "Au plus une séance par jour et par équipe",
    detail: "Deux créneaux le même jour pour la même équipe ne sont jamais proposés.",
  },
  {
    id: "reservations-honored",
    title: "Vos réservations, indisponibilités et gymnases imposés sont toujours honorés",
    detail: "Ce que vous avez fixé vous-même — un créneau réservé, un coach indisponible, un gymnase imposé — n'est jamais remis en cause par le solveur.",
  },
  {
    id: "team-minimum-target",
    title: "Chaque équipe vise son minimum de séances",
    detail: "Le solveur cherche à donner à chaque équipe son nombre de séances par semaine. C'est une cible, pas une loi : quand le gymnase manque, une séance peut sauter — et le planning vous le dit.",
  },
];

/**
 * La PRÉSENTATION des 4 règles réglables (libellés humains + description). La clé EST celle du
 * contrat moteur ; ici on ne fait que la nommer pour un non-technicien.
 */
export const WELLBEING_RULES: { ruleKey: ImplicitRuleKey; title: string; detail: string }[] = [
  { ruleKey: "coachRestDay", title: "Chaque coach garde un jour de repos", detail: "Au moins le nombre de jours choisi sans séance entre le lundi et le vendredi (les week-ends ne comptent pas)." },
  { ruleKey: "salarieDistribution", title: "Au moins un salarié présent chaque jour ouvré", detail: "Sur chaque jour de la semaine où le club tourne, au moins un coach salarié encadre une séance." },
  { ruleKey: "maxConsecutiveSessions", title: "Jamais trop de créneaux dos-à-dos", detail: "Un même coach n'enchaîne pas plus que le nombre choisi de créneaux d'affilée — qu'il les encadre ou qu'il y joue." },
  {
    ruleKey: "maxConsecutiveDays",
    title: "Jamais plusieurs jours d'affilée",
    detail:
      "Une même équipe ne s'entraîne pas le nombre de jours choisi à la suite. Attention : demander du repos peut coûter une séance à une équipe dont les créneaux disponibles se suivent.",
  },
  { ruleKey: "ageAscending", title: "Les jeunes avant les grands", detail: "Sur un même gymnase et un même jour, les catégories d'âge se placent du plus jeune au plus âgé." },
];

export function isWellbeingKey(ruleKey: string | null): ruleKey is ImplicitRuleKey {
  return null !== ruleKey && WELLBEING_RULES.some((r) => r.ruleKey === ruleKey);
}
