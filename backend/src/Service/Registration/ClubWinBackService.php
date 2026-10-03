<?php

declare(strict_types=1);

namespace App\Service\Registration;

use App\Entity\Club;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Service\ClubProvisioner;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reprise RGPD d'un club existant sans membre actif — extrait VERBATIM de
 * AuthController::verifyEmail. Le workspace a pu être purgé après effacement
 * (seule la fiche FFBB survit) : un « pending » serait inapprouvable à jamais
 * (le gate d'approbation exige un manager actif) et l'ARA unique interdirait de
 * recréer le club → l'inscrit reprend le club directement.
 *
 * ⚠ RLS : l'appelant (EmailVerificationService) ouvre la transaction et relâche
 * le GUC en `finally` ; ce service pose le GUC du club repris avant ses écritures.
 */
final class ClubWinBackService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ClubProvisioner $clubProvisioner,
    ) {}

    /** Aucun membre actif, tous rôles confondus (raw DBAL — club_user se lit cross-tenant). */
    public function isMemberless(string $clubId): bool
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM club_user WHERE club_id = :cid AND is_active = true',
            ['cid' => $clubId],
        );

        return 0 === (int) $count;
    }

    /**
     * Reprise d'un club sans membre actif : le repreneur en devient Gestionnaire,
     * actif d'office (même confiance que la création — premier arrivé sur un ARA
     * sans propriétaire). Annule l'effacement/désinscription programmés et re-seede
     * uniquement si le workspace a réellement été purgé (repreneur PENDANT le délai
     * de grâce → saisons intactes, un seed dupliquerait saison + catégories).
     */
    public function reprise(Club $existingClub, User $user): void
    {
        $this->tenantConnectionContext->setClubId($existingClub->getId());
        // Reprise RGPD d'un club sans membre actif : le repreneur en
        // devient Gestionnaire, actif d'office (même confiance que la création).
        $this->clubProvisioner->createMembership($existingClub->getId(), $user->getId(), true, ClubRole::MANAGER);
        $existingClub->setUnsubscribedAt(null);
        $existingClub->setErasureScheduledAt(null);
        // Re-seed uniquement si le workspace a réellement été purgé
        // (repreneur PENDANT le délai de grâce → saisons intactes,
        // un seed dupliquerait saison + catégories).
        if ($this->clubHasNoSeason($existingClub->getId())) {
            $this->clubProvisioner->seedWorkspace($existingClub);
        }
    }

    /** Le GUC du club doit déjà être posé (season est RLS-gardée). */
    private function clubHasNoSeason(string $clubId): bool
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM season WHERE club_id = :cid',
            ['cid' => $clubId],
        );

        return 0 === (int) $count;
    }
}
