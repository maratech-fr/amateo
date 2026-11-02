<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachResource;
use App\Dto\CoachInput;
use App\Entity\Coach;
use App\Enum\CoachGender;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProcessor<Coach, CoachInput, CoachResource>
 */
class CoachStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly CoachWishCampaignTokenSync $tokenSync,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return Coach::class;
    }

    /** Le coach parti, le suivant répond pour ses équipes dans les collectes ouvertes. */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $coach = $this->entityManager->find(Coach::class, $uriVariables['id'] ?? null);
        $coachClubId = $coach?->getClubId();
        $seasonId = $coach?->getSeasonId();

        parent::processDelete($uriVariables, $clubId);

        if (null !== $coachClubId && null !== $seasonId) {
            $this->tokenSync->syncSeason($coachClubId, $seasonId);
        }
    }

    protected function cascadeBeforeDelete(object $entity): void
    {
        if ($entity instanceof Coach) {
            $this->cascadeDeleter?->purgeChildrenOfCoach($entity);
        }
    }

    /**
     * @param CoachInput $input
     */
    protected function createEntityFromInput(object $input): Coach
    {
        $entity = new Coach;
        if (null !== $input->firstName) {
            $entity->setFirstName($input->firstName);
        }
        // last_name is NOT NULL in the schema; a coach may have only a first name.
        $entity->setLastName($input->lastName ?? '');
        if (null !== $input->email) {
            $entity->setEmail($input->email);
        }
        if (null !== $input->phone) {
            $entity->setPhone($input->phone);
        }
        if (null !== $input->maxDaysOverride) {
            // P4-51 — 0 = « retirer le plafond ». Le PUT étant partiel (null = inchangé),
            // null ne peut pas porter le retrait : sans ce cas, un champ vidé à l'écran
            // laisserait le plafond en base pour toujours.
            $entity->setMaxDaysOverride(0 === $input->maxDaysOverride ? null : $input->maxDaysOverride);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->isEmployee) {
            $entity->setIsEmployee($input->isEmployee);
        }
        if (null !== $input->isVehicled) {
            $entity->setIsVehicled($input->isVehicled);
        }
        if (null !== $input->gender) {
            $entity->setGender(CoachGender::from($input->gender));
        }
        if (null !== $input->parentCoachId) {
            $entity->setParentCoachId($input->parentCoachId);
        }

        return $entity;
    }

    /**
     * @param Coach      $entity
     * @param CoachInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->firstName) {
            $entity->setFirstName($input->firstName);
        }
        if (null !== $input->lastName) {
            $entity->setLastName($input->lastName);
        }
        if (null !== $input->email) {
            $entity->setEmail($input->email);
        }
        if (null !== $input->phone) {
            $entity->setPhone($input->phone);
        }
        if (null !== $input->maxDaysOverride) {
            // P4-51 — 0 = « retirer le plafond ». Le PUT étant partiel (null = inchangé),
            // null ne peut pas porter le retrait : sans ce cas, un champ vidé à l'écran
            // laisserait le plafond en base pour toujours.
            $entity->setMaxDaysOverride(0 === $input->maxDaysOverride ? null : $input->maxDaysOverride);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->isEmployee) {
            $entity->setIsEmployee($input->isEmployee);
        }
        if (null !== $input->isVehicled) {
            $entity->setIsVehicled($input->isVehicled);
        }
        if (null !== $input->gender) {
            $entity->setGender(CoachGender::from($input->gender));
        }
        if (null !== $input->parentCoachId) {
            $entity->setParentCoachId($input->parentCoachId);
        }
    }

    /**
     * @param Coach $entity
     */
    protected function mapEntityToOutput(object $entity): CoachResource
    {
        return CoachResource::fromEntity($entity);
    }
}
