<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SeasonResource;
use App\Dto\SeasonInput;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use LogicException;

/**
 * @extends AbstractStateProcessor<Season, SeasonInput, SeasonResource>
 */
class SeasonStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Season::class;
    }

    /**
     * @param SeasonInput $input
     */
    protected function createEntityFromInput(object $input): Season
    {
        $entity = new Season;
        if (null === $input->startDate || null === $input->endDate) {
            $this->refuse('Une saison requiert une date de début et une date de fin.');
        }
        // Nom absent/blanc → défaut « 2026-2027 » dérivé de la fenêtre (jamais une
        // saison sans nom ni un nom mono-année — décision fondateur 2026-07-24).
        $name = null !== $input->name ? trim($input->name) : '';
        $entity->setName('' !== $name ? $name : SeasonResolver::defaultSeasonName($input->startDate));
        $entity->setStartDate($input->startDate);
        $entity->setEndDate($input->endDate);
        if (null !== $input->status) {
            $entity->setStatus(SeasonStatus::from($input->status));
        }

        return $entity;
    }

    /**
     * PUT retiré de cette ressource (nettoyage API) — l'abstraction impose seulement
     * que ce foyer existe ; il n'est plus atteignable par l'API.
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        throw new LogicException('La modification (PUT) n\'est pas exposée pour les saisons.');
    }

    /**
     * @param Season $entity
     */
    protected function mapEntityToOutput(object $entity): SeasonResource
    {
        return SeasonResource::fromEntity($entity);
    }
}
