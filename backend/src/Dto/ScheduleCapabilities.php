<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * P2-8 (PR A) — le bloc de permissions d'une version, calculé SERVEUR par le MÊME
 * code que les gardes d'écriture (ScheduleCapabilityResolver). But : « capacité
 * affichée == verdict du refus » — le front n'a plus à re-dériver les règles que le
 * serveur possède déjà (motif des 40 défauts d'ADR-0002).
 *
 * Chaque booléen reflète EXACTEMENT le garde de son geste :
 *  - canDelete       ⇄ ScheduleStateProcessor::processDelete (choisie / en vol / seul planning de saison)
 *  - canValidate     ⇄ ValidateScheduleController (terminée, aucune sœur en vol ; la version CHOISIE
 *                       reste validable — re-pointage no-op accepté)
 *  - canRegenerateFrom ⇄ RegenerateFromVersionController (socle terminé non-choisi, aucune génération
 *                       en cours dans la saison, photo de structure présente)
 *  - canLoadLocks    ⇄ LoadLocksController (V1bis, « Charger » pour une période) : version de PÉRIODE
 *                       terminée, plan non validé, aucune génération en cours ET pas DÉJÀ la source
 *                       des verrous (le geste serait un no-op — on ne l'offre pas)
 *
 * Et deux compteurs, lus au même endroit que les gardes, pour que le front annonce
 * l'effet avant le clic :
 *  - versionsDeletedOnValidate  : le nombre de versions sœurs supprimées si l'on valide
 *  - overlaysDroppedOnValidate  : le nombre de plannings de période à venir détruits si l'on valide
 *                                 (le count IDENTIQUE à celui du 409 `overlays_exist`)
 */
final class ScheduleCapabilities
{
    public function __construct(
        #[Groups(['read'])]
        public bool $canDelete,
        #[Groups(['read'])]
        public bool $canValidate,
        #[Groups(['read'])]
        public bool $canRegenerateFrom,
        #[Groups(['read'])]
        public bool $canLoadLocks,
        #[Groups(['read'])]
        public int $versionsDeletedOnValidate,
        #[Groups(['read'])]
        public int $overlaysDroppedOnValidate,
    ) {}
}
