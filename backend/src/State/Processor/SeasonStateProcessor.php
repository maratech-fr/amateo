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
     * ADR-0002 Lot A: a season rename / date shift must re-sync the mirrored
     * SEASON plan so /api/schedule_plans never serves a stale name or period.
     *
     * @param SeasonInput          $input
     * @param array<string, mixed> $uriVariables
     *
     * @return SeasonResource
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        /** @var SeasonResource $output */
        $output = parent::processPut($input, $uriVariables, $clubId, $seasonId);

        $season = $this->entityManager->getRepository(Season::class)->find($output->id);
        if ($season instanceof Season) {
            $this->schedulePlanProvisioner->syncSeasonPlan($season);
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
     * @param Season      $entity
     * @param SeasonInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        // Partial PUT: absent dates keep the current values — a client updating
        // one field (e.g. planningName) must not echo (possibly stale) dates.
        if (null !== $input->startDate) {
            $entity->setStartDate($input->startDate);
        }
        if (null !== $input->endDate) {
            $entity->setEndDate($input->endDate);
        }
        if (null !== $input->status) {
            $entity->setStatus(SeasonStatus::from($input->status));
        }
    }

    /**
     * @param Season $entity
     */
    protected function mapEntityToOutput(object $entity): SeasonResource
    {
        return SeasonResource::fromEntity($entity);
    }
}
