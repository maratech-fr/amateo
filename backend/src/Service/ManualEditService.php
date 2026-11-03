<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class ManualEditService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditTrail $auditTrail,
        private readonly Security $security,
    ) {}

    /**
     * @param array<string, mixed> $auditDetails détails AJOUTÉS à la trace (ids seulement, jamais de PII) — p. ex. la
     *                                           cause d'un retrait système `{cause: 'venue_closed', constraintId}`
     * @param bool                 $flush        false quand l'appelant retire un LOT de verrous et flushe une seule fois
     *                                           (retrait groupé d'une fermeture, {@see ClosurePinRemover})
     */
    public function applyLock(ScheduleSlotTemplate $slot, LockLevel $lockLevel, array $auditDetails = [], bool $flush = true): void
    {
        // Niveau LU avant le set : c'est l'« avant » que la trace d'audit doit porter.
        $before = $slot->getLockLevel();
        $slot->setLockLevel($lockLevel);
        // Le gestionnaire épingle (ou déverrouille) à la main : origine MANUAL tant qu'il
        // y a un verrou, NULL quand il le retire (NONE = pas de verrou, pas d'origine).
        $slot->setLockOrigin(LockLevel::NONE === $lockLevel ? null : LockOrigin::MANUAL);
        if ($flush) {
            $this->entityManager->flush();
        }

        // RGPD — foyer AU SERVICE : tout chemin qui pose/retire un verrou hérite de la trace.
        // Details sans PII : les deux niveaux (avant/après), l'id du créneau porté hors details.
        $actor = $this->security->getUser();
        $this->auditTrail->record(
            AuditAction::LOCK_CHANGED,
            $actor instanceof User ? $actor->getId() : null,
            $slot->getClubId(),
            'ScheduleSlotTemplate',
            $slot->getId(),
            ['before' => $before->value, 'after' => $lockLevel->value] + $auditDetails,
        );
    }
}
