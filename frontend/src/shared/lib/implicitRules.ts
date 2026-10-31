/**
 * Les règles de bien-être que le moteur traite TOUJOURS en objectif, quel que soit le cran stocké :
 * l'écran n'offre donc aucun réglage sur elles et le planning aucun lien « Ajuster ».
 *
 * Présentation assumée d'un fait moteur (`.claude/rules/frontend.md`) : aucune décision métier ici,
 * le moteur reste seul juge. Le fait est prouvé côté moteur par
 * `engine/tests/semantic/test_fully_reserved_grid_two_employees.py::test_hard_and_preferred_give_the_same_schedule`.
 * Pas de garde cross-stack, par décision du 31/10.
 */
export const ALWAYS_OBJECTIVE_RULES: ReadonlySet<string> = new Set(["salarieDistribution"]);

export function isAlwaysObjective(ruleKey: string | null): boolean {
  return null !== ruleKey && ALWAYS_OBJECTIVE_RULES.has(ruleKey);
}
