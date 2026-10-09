/** L'état tri-état d'une étape du rail : vert « fait », orange « alertes », rouge « blocage ». */
export type RailState = "done" | "warning" | "error";

/** Ce que `railState` LIT d'un verdict — structurel, pour accepter aussi bien un `StepValidation`
 *  complet que des notices allégées en test (seul `tone` est consulté). */
interface RailInput {
  errors: readonly string[];
  warnings: readonly string[];
  notices?: readonly { tone: string }[];
}

/**
 * D1 (lot 2) — le rail du wizard est TRI-ÉTAT. La règle vit ICI (fonction pure, falsifiable sans
 * monter `WizardLayout`, qui n'a pas de test aujourd'hui) et nourrit `StepRail` (présentation pure).
 *
 * Priorité : le BLOCAGE prime (rouge), puis les ALERTES non bloquantes (orange — warnings du
 * verdict OU une notice typée de ton avertissement, p.ex. un créneau partagé partiel), puis
 * « fait » (vert). Une notice d'INFORMATION grise n'est pas une alerte : elle ne vire pas au
 * orange. `done` est calculé par l'appelant (il porte les prérequis d'une étape OPTIONNELLE).
 */
export function railState(validation: RailInput, done: boolean): RailState | undefined {
  if (validation.errors.length > 0) {
    return "error";
  }
  if (validation.warnings.length > 0 || (validation.notices ?? []).some((n) => "warning" === n.tone)) {
    return "warning";
  }

  return done ? "done" : undefined;
}
