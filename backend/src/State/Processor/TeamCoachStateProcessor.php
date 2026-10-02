<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamCoachResource;
use App\Dto\TeamCoachInput;
use App\Entity\TeamCoach;
use App\Enum\TeamCoachRole;
use LogicException;

/**
 * @extends AbstractStateProcessor<TeamCoach, TeamCoachInput, TeamCoachResource>
 */
class TeamCoachStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return TeamCoach::class;
    }

    /**
     * @param TeamCoachInput $input
     */
    protected function createEntityFromInput(object $input): TeamCoach
    {
        $entity = new TeamCoach;
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->role) {
            $role = TeamCoachRole::tryFrom($input->role);
            if (null !== $role) {
                $entity->setRole($role);
            }
        }
        if (null !== $input->isRequired) {
            $entity->setIsRequired($input->isRequired);
        }

        return $entity;
    }

    /**
     * PUT retiré de cette ressource (nettoyage API) — l'abstraction impose seulement
     * que ce foyer existe ; il n'est plus atteignable par l'API.
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        throw new LogicException('La modification (PUT) n\'est pas exposée pour les liaisons coach-équipe.');
    }

    /**
     * @param TeamCoach $entity
     */
    protected function mapEntityToOutput(object $entity): TeamCoachResource
    {
        return TeamCoachResource::fromEntity($entity);
    }
}
