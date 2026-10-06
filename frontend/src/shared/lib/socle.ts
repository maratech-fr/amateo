import { useMe } from "@/shared/session/queries";

/**
 * « Socle validé » = le plan de la SAISON pointe une version (chosenScheduleId).
 * Tant qu'il ne l'est pas, on ne peut pas créer/générer un planning secondaire
 * (#5). Source UNIQUE partagée par le cockpit (radar, modale jour, bannière) —
 * évite de retripler la dérivation et le libellé (revue B1 F6).
 *
 * ⚑ D-25/D-28 — elle ÉTAIT retriplée, hors du cockpit : `AppLayout`, `CockpitPage` et
 * `MatchesPage` réécrivaient le prédicat inline. Si la notion changeait (un socle validé
 * mais périmé), l'entrée de menu s'ouvrirait pendant que la page Matchs afficherait
 * « verrouillé » — un lien qui mène à un cadenas. D'où la remontée en `shared` : deux de
 * ses trois consommateurs ne sont pas dans le cockpit.
 *
 * ⚠ `ClubPage` et `PlanningPage` lisent l'ID (`chosenScheduleId`), pas le prédicat — ce
 * n'est pas la même chose et ils restent tels quels.
 */
export function useSocleValidated(): boolean {
  const { data: me } = useMe();
  return null != me?.seasonPlan?.chosenScheduleId;
}

/** Bulle d'info d'un bouton d'ajustement bloqué faute de socle validé. */
export const SEASON_LOCK_TITLE = "Le planning de saison n'est pas encore validé — validez-le pour ajuster.";

/** `undefined` si le socle est validé (pas de bulle), sinon le message de blocage. */
export function seasonLockTitle(socleValidated: boolean): string | undefined {
  return socleValidated ? undefined : SEASON_LOCK_TITLE;
}
