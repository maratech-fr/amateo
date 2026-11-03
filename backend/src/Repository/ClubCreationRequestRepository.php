<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubCreationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubCreationRequest>
 */
final class ClubCreationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubCreationRequest::class);
    }

    public function findPendingByToken(string $token): ?ClubCreationRequest
    {
        return $this->findOneBy(['token' => $token, 'status' => ClubCreationRequest::STATUS_PENDING]);
    }

    /** La demande VIVANTE d'un utilisateur (au plus une : le register la réutilise). */
    public function findPendingByUser(string $userId): ?ClubCreationRequest
    {
        return $this->findOneBy(['userId' => $userId, 'status' => ClubCreationRequest::STATUS_PENDING]);
    }

    /** @return list<ClubCreationRequest> */
    public function findPendingByAra(string $ara): array
    {
        return $this->findBy(['ara' => $ara, 'status' => ClubCreationRequest::STATUS_PENDING]);
    }

    /**
     * Chantier Membre (PR 3) — les demandes VIVANTES (`pending` ou `expired`) d'un utilisateur sur
     * CE code : une demande de club ouverte sur un autre code n'est jamais la demande de ce club.
     *
     * @return list<ClubCreationRequest>
     */
    public function findLiveByUserAndAra(string $userId, string $ara): array
    {
        return $this->findBy(['userId' => $userId, 'ara' => $ara, 'status' => [ClubCreationRequest::STATUS_PENDING, ClubCreationRequest::STATUS_EXPIRED]], ['createdAt' => 'ASC']);
    }

    public function findPendingByUserAndAra(string $userId, string $ara): ?ClubCreationRequest
    {
        return $this->findOneBy(['userId' => $userId, 'ara' => $ara, 'status' => ClubCreationRequest::STATUS_PENDING], ['createdAt' => 'DESC']);
    }

    /** La plus récente demande en attente, expirée ou refusée de cet utilisateur sur ce code (état servi par `/api/me`). */
    public function findLatestShownByUserAndAra(string $userId, string $ara): ?ClubCreationRequest
    {
        return $this->findOneBy(
            ['userId' => $userId, 'ara' => $ara, 'status' => [ClubCreationRequest::STATUS_PENDING, ClubCreationRequest::STATUS_EXPIRED, ClubCreationRequest::STATUS_REFUSED]],
            ['createdAt' => 'DESC'],
        );
    }
}
