<?php

declare(strict_types=1);

namespace App\Service\Registration;

use App\Entity\Club;
use App\Enum\AuditAction;
use App\Enum\ClubRole;
use App\Repository\ClubUserRepository;
use App\Service\AuditTrail;
use App\Service\ClubProvisioner;
use App\Service\OrphanAccountNotifier;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Reprise d'un club existant sans GESTIONNAIRE actif (fermeture programmée, ou workspace purgé
 * après effacement — seule la fiche FFBB survit).
 *
 * Décision fondateur (2026-10-03) : la reprise passe désormais par l'APPROBATION
 * P3-4 du contact officiel — la vérification d'e-mail ne reprend plus le club en
 * direct, elle ouvre une demande (club_pending). C'est {@see ClubApprovalService}
 * qui, à l'approbation d'un club existant SANS gestionnaire actif, appelle `reprise()` :
 * le repreneur devient Gestionnaire actif, l'effacement/rappel programmés sont
 * annulés, et le workspace re-seedé s'il avait été purgé. `isManagerless()` sépare
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
        private readonly AuditTrail $auditTrail,
    ) {}

    /**
     * Aucun GESTIONNAIRE actif (chantier Membre : un club sans gestionnaire ferme, même si des
     * Membres restent — un Membre seul ne retient pas le club et ne le reprend pas).
     */
    public function isManagerless(string $clubId): bool
    {
        return !$this->clubUserRepository->hasActiveManager($clubId);
    }

    /**
     * Maison UNIQUE de l'annulation d'une fermeture : échéance d'effacement, rappel J-7 et
     * désinscription remis à null, audit `CLUB_CLOSURE_CANCELLED` (ids seulement) quand une
     * fermeture était réellement en cours. Appelée par la reprise, l'acceptation d'une invitation
     * gestionnaire et la purge qui trouve un gestionnaire revenu à l'échéance.
     *
     * Aucun flush ici (l'appelant possède sa transaction) ; l'audit exige le GUC du club posé.
     */
    public function cancelClosure(Club $club, ?string $actorUserId, string $source): void
    {
        $wasClosing = $club->getErasureScheduledAt() instanceof DateTimeImmutable
            || $club->getUnsubscribedAt() instanceof DateTimeImmutable;
        $club->setUnsubscribedAt(null);
        $club->setErasureScheduledAt(null);
        $club->setErasureReminderSentAt(null);
        // Chantier Membre (PR 3) — la retenue de purge d'une demande de reprise meurt avec la fermeture.
        $club->setPurgeHoldUntil(null);
        if ($wasClosing) {
            $this->auditTrail->record(AuditAction::CLUB_CLOSURE_CANCELLED, $actorUserId, $club->getId(), 'Club', $club->getId(), ['source' => $source]);
        }
    }

    /**
     * Reprise d'un club sans gestionnaire actif : le repreneur en devient Gestionnaire,
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
        // ligne désactivée ou pending, Membre resté) : la contrainte unique (club_id, user_id)
        // interdit un second `club_user`. On RÉACTIVE et PROMEUT la ligne existante en
        // Gestionnaire actif plutôt que d'en insérer une seconde ; à défaut, on en crée une.
        //
        // Chantier Membre (PR 3) — lecture et écriture en SQL BRUT sous le GUC du club repris :
        // le lien d'approbation peut être ouvert par un visiteur CONNECTÉ à un autre club, dont le
        // filtre tenant Doctrine masquerait la ligne (on en créerait alors une seconde). La demande
        // de rôle éventuelle (stamps) meurt avec la promotion.
        $connection = $this->entityManager->getConnection();
        $existingId = $connection->fetchOne(
            'SELECT id FROM club_user WHERE club_id = :cid AND user_id = :uid',
            ['cid' => $existingClub->getId(), 'uid' => $userId],
        );
        if (\is_string($existingId)) {
            $updated = $connection->executeStatement(
                'UPDATE club_user SET role = :role, is_active = true, deactivated_at = NULL, manager_requested_at = NULL, '
                . 'manager_request_escalated_at = NULL, updated_at = NOW() WHERE club_id = :cid AND user_id = :uid',
                ['role' => ClubRole::MANAGER->value, 'cid' => $existingClub->getId(), 'uid' => $userId],
            );
            if (1 !== $updated) {
                throw new LogicException('Reprise: exactly one membership row must be promoted.');
            }
            // P4-301 — reprise d'une ligne existante : retour d'un accès actif → annule le
            // préavis « compte sans club » (la branche `else` passe par createMembership,
            // qui l'annule déjà). Flushé par la transaction de l'appelant.
            $this->orphanAccountNotifier->cancelFor($userId);
        } else {
            $this->clubProvisioner->createMembership($existingClub->getId(), $userId, true, ClubRole::MANAGER);
        }
        $this->cancelClosure($existingClub, null, 'official_address');
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
