<?php

declare(strict_types=1);

namespace App\Service\Registration;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Enum\ClubRole;
use App\Repository\ClubUserRepository;
use App\Service\ClubProvisioner;
use App\Service\OrphanAccountNotifier;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reprise d'un club existant sans membre actif (workspace purgé après effacement,
 * seule la fiche FFBB survit).
 *
 * Décision fondateur (2026-10-03) : la reprise passe désormais par l'APPROBATION
 * P3-4 du contact officiel — la vérification d'e-mail ne reprend plus le club en
 * direct, elle ouvre une demande (club_pending). C'est {@see ClubApprovalService}
 * qui, à l'approbation d'un club existant SANS membre actif, appelle `reprise()` :
 * le repreneur devient Gestionnaire actif, l'effacement/rappel programmés sont
 * annulés, et le workspace re-seedé s'il avait été purgé. `isMemberless()` sépare
 * ce cas (→ reprise) de l'adhésion PENDING à un club encore peuplé.
 *
 * ⚠ RLS : l'appelant ouvre la transaction et relâche le GUC en `finally` ; ce
 * service pose le GUC du club repris avant ses écritures.
 */
final class ClubWinBackService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ClubProvisioner $clubProvisioner,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly OrphanAccountNotifier $orphanAccountNotifier,
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
     * actif d'office (l'approbation du contact officiel a fait la preuve). Annule
     * l'effacement/désinscription programmés ET le rappel J-7 (nouveau cycle propre),
     * et re-seede uniquement si le workspace a réellement été purgé (repreneur PENDANT
     * le délai de grâce → saisons intactes, un seed dupliquerait saison + catégories).
     *
     * L'appelant a déjà le GUC du club posé (ce service le repose par sécurité) et
     * possède la transaction ; aucun flush ici.
     */
    public function reprise(Club $existingClub, string $userId): void
    {
        $this->tenantConnectionContext->setClubId($existingClub->getId());
        // Le repreneur peut DÉJÀ porter une adhésion à ce club (ancien membre parti,
        // ligne désactivée ou pending) : la contrainte unique (club_id, user_id) interdit
        // un second `club_user`. On RÉACTIVE et PROMEUT la ligne existante en Gestionnaire
        // actif plutôt que d'en insérer une seconde ; à défaut, on en crée une.
        $existing = $this->clubUserRepository->findOneBy(['clubId' => $existingClub->getId(), 'userId' => $userId]);
        if ($existing instanceof ClubUser) {
            $existing->setRole(ClubRole::MANAGER->value);
            $existing->setIsActive(true);
            $existing->setDeactivatedAt(null);
            // P4-301 — reprise d'une ligne existante : retour d'un accès actif → annule le
            // préavis « compte sans club » (la branche `else` passe par createMembership,
            // qui l'annule déjà). Flushé par la transaction de l'appelant.
            $this->orphanAccountNotifier->cancelFor($userId);
        } else {
            $this->clubProvisioner->createMembership($existingClub->getId(), $userId, true, ClubRole::MANAGER);
        }
        $existingClub->setUnsubscribedAt(null);
        $existingClub->setErasureScheduledAt(null);
        $existingClub->setErasureReminderSentAt(null);
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
