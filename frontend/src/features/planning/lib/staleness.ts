/**
 * Un planning « périmé » : le message UNIFIÉ de sa (ses) cause(s).
 *
 * Deux déclencheurs, jamais re-dérivés ici (le backend DIT, le front AFFICHE) :
 *  - il a été retouché à la main depuis sa génération (le score ne décrit plus le placement) ;
 *  - sa STRUCTURE a changé depuis sa génération : l'empreinte de structure servie par plan
 *    (`GET /api/schedule_plans/{id}/structure-hash`) diverge du `snapshotHash` figé de la version.
 *    Une seule cause pour TOUT ce qui nourrit le solveur (contraintes, gymnases, coachs, créneaux,
 *    équipes, rattachements…) — le hash les recouvre toutes (P4-266).
 *
 * ⚠ Une SEULE bannière, jamais deux empilées. Le mot est choisi : périmé, PAS faux. Le planning
 * décrit un état antérieur des données ; on régénère pour SAVOIR s'il tient encore.
 *
 * Un planning VALIDÉ / en vigueur (lecture seule) ne porte AUCUN signal « à régénérer » (P4-266,
 * décision fondateur) : c'est le calendrier qui fait foi, on le rouvre avant de régénérer — la
 * bannière ne crie pas sur lui. `readOnly` ⇒ `null`.
 *
 * Retourne `null` quand rien n'est périmé (aucune bannière).
 */
export function stalenessMessage(opts: {
  manuallyEdited: boolean;
  structureChanged: boolean;
  readOnly: boolean;
}): string | null {
  // Un planning validé (en vigueur) est muet : pas de signal « à régénérer » dessus.
  if (opts.readOnly) {
    return null;
  }

  const causes: string[] = [];
  if (opts.manuallyEdited) {
    causes.push("il a été modifié à la main");
  }
  if (opts.structureChanged) {
    causes.push("vos données ont changé");
  }
  if (0 === causes.length) {
    return null;
  }

  return `Depuis la génération de ce planning, ${joinCauses(causes)} : il est périmé — pas forcément faux, mais il décrit un état antérieur de vos données. Régénérez pour savoir s'il tient encore.`;
}

/** « A » ou « A et B » — l'énumération française des causes. */
function joinCauses(causes: string[]): string {
  if (1 === causes.length) {
    return causes[0];
  }
  return `${causes.slice(0, -1).join(", ")} et ${causes[causes.length - 1]}`;
}
