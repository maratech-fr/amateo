<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachPlayerMembershipResource;
use App\Dto\CoachPlayerMembershipInput;
use App\Entity\CoachPlayerMembership;
use LogicException;

/**
 * @extends AbstractStateProcessor<CoachPlayerMembership, CoachPlayerMembershipInput, CoachPlayerMembershipResource>
 */
class CoachPlayerMembershipStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return CoachPlayerMembership::class;
    }

    /**
     * @param CoachPlayerMembershipInput $input
     */
    protected function createEntityFromInput(object $input): CoachPlayerMembership
    {
        $entity = new CoachPlayerMembership;
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->position) {
            $entity->setPosition($input->position);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }

        return $entity;
    }

    /**
     * PUT retiré de cette ressource (nettoyage API) — l'abstraction impose seulement
     * que ce foyer existe ; il n'est plus atteignable par l'API.
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        throw new LogicException('La modification (PUT) n\'est pas exposée pour les liaisons coach-joueur.');
    }

    /**
     * @param CoachPlayerMembership $entity
     */
    protected function mapEntityToOutput(object $entity): CoachPlayerMembershipResource
    {
        return CoachPlayerMembershipResource::fromEntity($entity);
    }
}
