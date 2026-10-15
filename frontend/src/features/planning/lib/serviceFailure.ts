/**
 * Miroir DÉCLARÉ (régime 2 de `.claude/rules/frontend.md`) — la liste des types de diagnostic qui
 * signalent une PANNE DU SERVICE de calcul, par opposition à un planning INFAISABLE. C'est ce qui
 * permet à l'écran de génération de dire « le service ne répond pas » (rien à corriger côté
 * utilisateur) au lieu de « votre planning est infaisable » (une faute de saisie à reprendre).
 *
 * Source de vérité backend : les types que `GenerateScheduleHandler` ENREGISTRE LUI-MÊME quand le
 * rail de génération échoue AVANT ou PENDANT l'appel moteur (pas ce que le MOTEUR répond) :
 *   · internal_error  — backend/src/MessageHandler/GenerateScheduleHandler.php:174
 *   · engine_timeout  — :288-290
 *   · engine_error    — :297-298 → failSchedule → :512-513
 *   · engine_status   — :458
 *
 * ⚠ `engine_failed` en est EXCLU À DESSEIN : il signifie que le MOTEUR A RÉPONDU « failed »
 *   (backend/src/Service/ScheduleDiagnosticsRecorder.php:63-66,75,85) — un planning INFAISABLE, pas
 *   une panne. Sont de même exclus, car « réessayez dans quelques minutes » ne les corrige pas :
 *   `overlay_entry_missing` (échec DOMAINE — période supprimée), `club_mismatch` (garde de défense
 *   en profondeur, injoignable sous RLS) et `engine_rejected` (refus de CONTRAT du moteur — un 422
 *   Pydantic sur un champ du payload hors contrat : déterministe, il route vers l'affichage du
 *   champ en cause, pas vers l'écran de panne de service, P4-340). Ces exclusions sont NOMMÉES et
 *   gardées côté handler par le test de parité.
 *
 * Parité gardée DANS LES DEUX SENS par `backend/tests/CrossStack/EngineFailureTypeMirrorTest.php`
 * (garde de frontière statique dédiée) : un type ajouté au handler et absent d'ici rougit, un type
 * d'ici disparu du handler rougit.
 */
export const SERVICE_FAILURE_TYPES = ["engine_timeout", "engine_error", "internal_error", "engine_status"] as const;

type ServiceFailureType = (typeof SERVICE_FAILURE_TYPES)[number];

const isServiceFailureType = (type: string): type is ServiceFailureType => (SERVICE_FAILURE_TYPES as readonly string[]).includes(type);

/**
 * Vrai SSI les diagnostics ERROR du run sont NON VIDES et TOUS dans `SERVICE_FAILURE_TYPES` —
 * c.-à-d. le service de calcul est en panne, et non « votre planning est infaisable » (au moins un
 * diagnostic métier suffit à disculper le service). Une liste vide, ou sans aucun ERROR, rend faux :
 * l'écran de panne ne s'affiche jamais par défaut.
 */
export function isServiceDown(diagnostics: readonly { type: string; severity: string }[]): boolean {
  const errors = diagnostics.filter((d) => "ERROR" === d.severity);
  return errors.length > 0 && errors.every((d) => isServiceFailureType(d.type));
}
