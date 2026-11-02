<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamCoachResource;
use App\Dto\TeamCoachInput;
use App\Entity\TeamCoach;
use App\Enum\TeamCoachRole;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProcessor<TeamCoach, TeamCoachInput, TeamCoachResource>
 */
class TeamCoachStateProcessor extends AbstractStateProcessor
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

        return $entity;
    }

    /** Un lien posé peut changer qui répond pour l'équipe dans les collectes ouvertes. */
    protected function afterPersist(object $entity): void
    {
        if ($entity instanceof TeamCoach) {
            $this->tokenSync->syncSeason((string) $entity->getClubId(), (string) $entity->getSeasonId());
        }
    }

    /** @param array<string, mixed> $uriVariables */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $link = $this->entityManager->find(TeamCoach::class, $uriVariables['id'] ?? null);
        $linkClubId = $link?->getClubId();
        $seasonId = $link?->getSeasonId();

        parent::processDelete($uriVariables, $clubId);

        if (null !== $linkClubId && null !== $seasonId) {
            $this->tokenSync->syncSeason($linkClubId, $seasonId);
        }
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
