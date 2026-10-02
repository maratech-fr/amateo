<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CompetitionResource;
use App\Dto\CompetitionInput;
use App\Entity\Competition;
use App\Enum\CompetitionType;
use DateTimeImmutable;
use LogicException;

/**
 * @extends AbstractStateProcessor<Competition, CompetitionInput, CompetitionResource>
 */
class CompetitionStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Competition::class;
    }

    /**
     * @param CompetitionInput $input
     */
    protected function createEntityFromInput(object $input): Competition
    {
        $entity = new Competition;
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->competitionType) {
            $entity->setCompetitionType(CompetitionType::from($input->competitionType));
        }
        $entity->setStartDate($this->parseDate($input->startDate));
        $entity->setEndDate($this->parseDate($input->endDate));

        return $entity;
    }

    /**
     * PUT retiré de cette ressource (nettoyage API) — l'abstraction impose seulement
     * que ce foyer existe ; il n'est plus atteignable par l'API.
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        throw new LogicException('La modification (PUT) n\'est pas exposée pour les compétitions.');
    }

    /**
     * @param Competition $entity
     */
    protected function mapEntityToOutput(object $entity): CompetitionResource
    {
        return CompetitionResource::fromEntity($entity);
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        return null === $value || '' === $value ? null : new DateTimeImmutable($value);
    }
}
