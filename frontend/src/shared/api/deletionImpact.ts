/**
 * P3-16 — ce qu'une suppression VA détruire, calculé par le SERVEUR.
 *
 * ⚑ Ces compteurs étaient dérivés côté écran, depuis le cache react-query : la modale
 * annonçait 2 ou 3 familles quand la cascade en emportait dix. L'écran ne pouvait pas dire
 * vrai — il n'a chargé ici ni les matchs, ni les contraintes, ni les séances des autres
 * plannings. Le front AFFICHE désormais ce que le backend a compté (règle 🔴 maison).
 *
 * Le TYPE vit dans `shared/api/` (descendu de `features/wizard/` le 2026-08-22, P4-123) :
 * la modale de confirmation partagée `shared/components/ui/delete-confirm` l'affiche, ce qui
 * faisait remonter `shared/ → features/`. Le geste (`fetchDeletionImpact`, `DeletableKind`)
 * reste chez le wizard, qui l'appelle.
 */
export interface DeletionImpactLine {
  key: string;
  count: number;
  /** Libellés PORTÉS PAR LE SERVEUR : une famille ajoutée au cascade s'affiche d'office. */
  one: string;
  many: string;
}

export interface DeletionImpact {
  /** Le serveur REFUSERA le geste (équipe engagée) : ne pas l'offrir. */
  blocked: boolean;
  reason: string | null;
  lines: DeletionImpactLine[];
  /** Séances touchées vivant dans une version EN VIGUEUR. */
  slotsInForce: number;
  /**
   * P4-270 : matchs déjà PLACÉS dans ce gymnase (PLACED/SUBMITTED/VALIDATED) qui redeviendront
   * « à placer » à sa disparition. `declaredFixtures` en est le sous-ensemble.
   */
  placedFixtures: number;
  /** DOC-2 : matchs déjà déclarés à la fédération qui perdront leur salle (sous-ensemble de placedFixtures). */
  declaredFixtures: number;
}
