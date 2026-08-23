<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ConstraintPeriodOverrideResource;
use App\Dto\ConstraintPeriodOverrideInput;
use App\Entity\ConstraintPeriodOverride;

/**
 * @extends AbstractStateProcessor<ConstraintPeriodOverride, ConstraintPeriodOverrideInput, ConstraintPeriodOverrideResource>
 */
class ConstraintPeriodOverrideStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    protected function getEntityClass(): string
    {
        return ConstraintPeriodOverride::class;
    }

    /**
     * P4-34 — filet de la COURSE (voir {@see AssertsSchedulePlanExistsTrait}) : le
     * contrôle d'existence de l'ancre est un check-then-insert ; entre lui et le
     * flush le plan peut disparaître (suppression de période, reprise du socle), et
     * la FK remonterait alors une violation que personne n'attrape → 500 opaque.
     * On rend le MÊME 422 que si le plan avait déjà disparu au contrôle.
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
    }

    /**
     * @param ConstraintPeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): ConstraintPeriodOverride
    {
        // One override per (period, constraint) — the DB unique index would otherwise
        // surface as a 500 on a double-submit; give a clean 422 instead (edit via PUT).
        if (!\in_array(null, [$input->schedulePlanId, $input->constraintId, $this->entityManager->getRepository(ConstraintPeriodOverride::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'constraintId' => $input->constraintId])], true)) {
            $this->refuse('Cette contrainte a déjà un réglage pour cette période — modifiez-le.');
        }

        $entity = new ConstraintPeriodOverride;
        if (null !== $input->schedulePlanId) {
            // P4-34 — l'ANCRE doit exister : sans ce contrôle, un `schedulePlanId`
            // inventé écrivait une ligne 201 rattachée à une période inexistante —
            // invisible à l'écran, jamais lue par une génération, impossible à
            // supprimer par l'UI. Même garde que `Reservation`/`VenueTrainingSlot` (P4-30).
            $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
            $entity->setSchedulePlanId($input->schedulePlanId);
        }
        if (null !== $input->constraintId) {
            $entity->setConstraintId($input->constraintId);
        }
        $entity->setIsActive($input->isActive);

        return $entity;
    }

    /**
     * @param ConstraintPeriodOverride      $entity
     * @param ConstraintPeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // schedulePlanId + constraintId identify the row — not remapped on edit.
        $entity->setIsActive($input->isActive);
    }

    /**
     * @param ConstraintPeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): ConstraintPeriodOverrideResource
    {
        return ConstraintPeriodOverrideResource::fromEntity($entity);
    }
}
