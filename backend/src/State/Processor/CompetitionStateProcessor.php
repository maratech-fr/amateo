<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CompetitionResource;
use App\Dto\CompetitionInput;
use App\Entity\Competition;
use App\Enum\CompetitionType;
use App\Matches\CompetitionRegistry;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProcessor<Competition, CompetitionInput, CompetitionResource>
 */
class CompetitionStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly CompetitionRegistry $competitionRegistry,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

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
            $this->competitionRegistry->engage($entity, $input->teamId);
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
        return CompetitionResource::fromEntity($entity, $this->competitionRegistry->engagementsOf($entity));
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        return null === $value || '' === $value ? null : new DateTimeImmutable($value);
    }
}
