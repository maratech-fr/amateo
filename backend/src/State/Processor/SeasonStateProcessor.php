<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SeasonResource;
use App\Dto\SeasonInput;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProcessor<Season, SeasonInput, SeasonResource>
 */
class SeasonStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return Season::class;
    }

    /**
     * ADR-0002 Lot A: the SEASON plan exists as soon as the season does — an
     * empty "espace de travail" with no version yet.
     *
     * @param SeasonInput $input
     *
     * @return SeasonResource
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        /** @var SeasonResource $output */
        $output = parent::processPost($input, $clubId, $seasonId);

        $season = $this->entityManager->getRepository(Season::class)->find($output->id);
        if ($season instanceof Season) {
            $this->schedulePlanProvisioner->ensureSeasonPlan($season);
            $this->entityManager->flush();
        }

        return $output;
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
