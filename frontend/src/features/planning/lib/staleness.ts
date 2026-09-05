/**
 * Un planning « périmé » : le message UNIFIÉ de sa (ses) cause(s).
 *
 * Plusieurs déclencheurs distincts rendent un planning obsolète sans le rendre FAUX :
 *  - il a été retouché à la main depuis sa génération (le score ne décrit plus le placement) ;
 *  - une contrainte a changé depuis sa génération (il décrit un état antérieur des règles) ;
 *  - une DONNÉE DU CLUB a changé (gymnase, coach, créneau, grille de période, réservation, tag,
 *    calendrier — P4-87) : il décrit un état antérieur des données ;
 *  - des équipes ont été ajoutées ou retirées depuis (structureDiverged — comparaison
 *    d'instantané, cf. PlanningPage ; fusionnée ICI plutôt qu'empilée en bandeau séparé).
 *
 * ⚠ Une SEULE bannière, jamais deux empilées : le gestionnaire finirait par les ignorer
 * toutes. Elle NOMME sa ou ses causes, et l'action proposée dépend de l'état du planning :
 *  - un planning modifiable → « Régénérez » ;
 *  - un planning VALIDÉ / en vigueur est en lecture seule (le rail refuse l'écriture) →
 *    proposer « Régénérer » l'enverrait dans un mur. On dit alors « Rouvrez… puis régénérez »,
 *    l'enchaînement réel du cycle de vie (ADR-0002 : valider / rouvrir).
 *
 * Le mot est choisi : périmé, PAS faux. Le planning décrit un état antérieur des données ;
 * on régénère pour SAVOIR s'il tient encore, pas parce qu'il est invalide.
 *
 * Retourne `null` quand rien n'est périmé (aucune bannière).
 */
export function stalenessMessage(opts: {
  manuallyEdited: boolean;
  constraintsChanged: boolean;
  resourcesChanged: boolean;
  structureDiverged: boolean;
  readOnly: boolean;
}): string | null {
  const causes: string[] = [];
  if (opts.manuallyEdited) {
    causes.push("il a été modifié à la main");
  }
  if (opts.constraintsChanged) {
    causes.push("une contrainte a changé");
  }
  if (opts.resourcesChanged) {
    causes.push("les données du club ont changé (gymnases, coachs, créneaux…)");
  }
  if (opts.structureDiverged) {
    causes.push("des équipes ont été ajoutées ou retirées");
  }
  if (0 === causes.length) {
    return null;
  }

  const action = opts.readOnly ? "Rouvrez ce planning, puis régénérez" : "Régénérez";
  return `Depuis la génération de ce planning, ${joinCauses(causes)} : il est périmé — pas forcément faux, mais il décrit un état antérieur de vos données. ${action} pour savoir s'il tient encore.`;
}

/**
 * La FORME COURTE de la péremption, pour une pastille du cockpit : « À régénérer — <cause(s)> ».
 *
 * Mêmes causes que `stalenessMessage`, en libellés brefs, SANS `structureDiverged` (hors scope du
 * cockpit, P4-173) et sans l'action longue (chaque surface porte déjà son propre CTA — « Voir le
 * planning », « Consulter »… ; la pastille est informative, non cliquable). `null` quand rien n'est
 * périmé : pas de pastille. Le TEXTE COMPLET est la valeur de la pastille — jamais en `title`.
 */
export function stalenessBadge(opts: {
  manuallyEdited: boolean;
  constraintsChanged: boolean;
  resourcesChanged: boolean;
}): string | null {
  const causes: string[] = [];
  if (opts.manuallyEdited) {
    causes.push("modifié à la main");
  }
  if (opts.constraintsChanged) {
    causes.push("une contrainte a changé");
  }
  if (opts.resourcesChanged) {
    causes.push("les données du club ont changé");
  }
  if (0 === causes.length) {
    return null;
  }

  return `À régénérer — ${joinCauses(causes)}`;
}

/** « A », « A et B », « A, B et C » — l'énumération française des causes. */
function joinCauses(causes: string[]): string {
  if (1 === causes.length) {
    return causes[0];
  }
  return `${causes.slice(0, -1).join(", ")} et ${causes[causes.length - 1]}`;
}
