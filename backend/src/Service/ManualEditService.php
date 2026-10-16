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

    public function applyLock(ScheduleSlotTemplate $slot, LockLevel $lockLevel): void
    {
        // Niveau LU avant le set : c'est l'« avant » que la trace d'audit doit porter.
        $before = $slot->getLockLevel();
        $slot->setLockLevel($lockLevel);
        // Le gestionnaire épingle (ou déverrouille) à la main : origine MANUAL tant qu'il
        // y a un verrou, NULL quand il le retire (NONE = pas de verrou, pas d'origine).
        $slot->setLockOrigin(LockLevel::NONE === $lockLevel ? null : LockOrigin::MANUAL);
        $this->entityManager->flush();

        // RGPD — foyer AU SERVICE : tout chemin qui pose/retire un verrou hérite de la trace.
        // Details sans PII : les deux niveaux (avant/après), l'id du créneau porté hors details.
        $actor = $this->security->getUser();
        $this->auditTrail->record(
            AuditAction::LOCK_CHANGED,
            $actor instanceof User ? $actor->getId() : null,
            $slot->getClubId(),
            'ScheduleSlotTemplate',
            $slot->getId(),
            ['before' => $before->value, 'after' => $lockLevel->value],
        );
    }
}
