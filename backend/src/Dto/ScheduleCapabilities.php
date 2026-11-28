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
 *  - canDelete       ⇄ ScheduleStateProcessor::processDelete (choisie / en vol / seul planning de saison /
 *                       version chargée d'où repart une génération en cours)
 *  - canValidate     ⇄ ValidateScheduleController (terminée, aucune sœur en vol, version chargée ; la
 *                       version CHOISIE reste validable — re-pointage no-op accepté)
 *  - canLoad         ⇄ LoadVersionController (« Charger », tout plan) : version terminée, plan non validé,
 *                       aucune génération en cours dans la saison, chargeable (LoadFeasibility : copie
 *                       présente, niveau des équipes engagées intact) ET pas DÉJÀ la version chargée (le
 *                       geste ne changerait rien — on ne l'offre pas) ; loadBlockedReason dit le motif d'une
 *                       version terminée qui ne se charge pas, mot pour mot le `error` du refus
 *  - canEdit         ⇄ les neuf guichets de retouche (VersionEditability) ; editBlockedReason dit le code
 *
 * Et un compteur, lu au même endroit que les gardes, pour que le front annonce
 * l'effet avant le clic :
 *  - versionsDeletedOnValidate  : le nombre de versions sœurs supprimées si l'on valide
 * Les plannings de période qu'une validation supprime n'ont qu'une source : l'annonce
 * `GET /api/schedules/{id}/validate-impact` (lot 5).
 */
final class ScheduleCapabilities
{
    public function __construct(
        #[Groups(['read'])]
        public bool $canDelete,
        #[Groups(['read'])]
        public bool $canValidate,
        #[Groups(['read'])]
        public bool $canLoad,
        #[Groups(['read'])]
        public int $versionsDeletedOnValidate,
        #[Groups(['read'])]
        public bool $canEdit = true,
        #[Groups(['read'])]
        public ?string $editBlockedReason = null,
        #[Groups(['read'])]
        public ?string $loadBlockedReason = null,
    ) {}
}
