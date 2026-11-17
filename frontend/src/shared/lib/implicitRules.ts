/**
 * Les règles de bien-être qui ne peuvent jamais être obligatoires : l'écran ne leur offre que
 * « Souhaitée » et « Ignorée », et le serveur refuse le cran HARD (422).
 *
 * Présentation assumée d'un fait serveur (`.claude/rules/frontend.md`) : aucune décision métier ici.
 * Le refus vit dans `ImplicitRuleSettingStateProcessor` ; le moteur traite HARD et PREFERRED en un
 * même objectif (`engine/tests/semantic/test_fully_reserved_grid_two_employees.py::test_hard_and_preferred_give_the_same_schedule`).
 * Pas de garde cross-stack, par décision du 31/10.
 */
export const NEVER_MANDATORY_RULES: ReadonlySet<string> = new Set(["salarieDistribution"]);

export function isNeverMandatory(ruleKey: string | null): boolean {
  return null !== ruleKey && NEVER_MANDATORY_RULES.has(ruleKey);
}
