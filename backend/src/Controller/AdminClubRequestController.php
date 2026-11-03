<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubCreationRequest;
use App\Entity\SuperAdmin;
use App\Entity\User;
use App\Repository\ClubCreationRequestRepository;
use App\Repository\ClubRepository;
use App\Security\AdminSessionCsrf;
use App\Service\ApprovalOutcome;
use App\Service\ClubApprovalService;
use App\Service\TenantConnectionContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * P3-4 PR B — le volet console des demandes de création de club et des
 * adhésions en attente. Deux décisions fondateur (2026-08-05) :
 *  - « le superadmin peut valider si besoin » : une demande SANS mail FFBB, ou
 *    EXPIRÉE (le lien public est mort), reste approuvable/refusable ici ;
 *  - « activer une adhésion » : l'ancien gestionnaire parti fâché ne fait pas
 *    la passation — le superadmin active le membership pending directement.
 *
 * Gardes = patron AdminClubActionController : firewall admin (session), CSRF,
 * identité SuperAdmin, contexte d'audit posé AVANT toute garde (une tentative
 * refusée doit tracer sa cible). Lectures cross-tenant par la connexion admin
 * (club_user est tenant-scopé en écriture ; l'activation passe par la porte
 * superadmin, tracée par l'audit SA0).
 */
#[Route('/api/admin')]
final readonly class AdminClubRequestController
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(
        private ClubCreationRequestRepository $requests,
        private ClubApprovalService $approvalService,
        private EntityManagerInterface $entityManager,
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ManagerRegistry $managerRegistry,
        private TenantConnectionContext $tenantContext,
        private ClubRepository $clubs,
    ) {}

    #[Route('/club-requests', methods: ['GET'])]
    public function list(): JsonResponse
    {
        // pending + expired : « le superadmin peut valider si besoin » — une
        // demande expirée sort du lien public, pas de la console.
        $rows = $this->requests->findBy(['status' => [ClubCreationRequest::STATUS_PENDING, ClubCreationRequest::STATUS_EXPIRED]], ['createdAt' => 'ASC']);
        $items = [];
        foreach ($rows as $request) {
            $requester = $this->entityManager->getRepository(User::class)->find($request->getUserId());
            $items[] = [
                'id' => $request->getId(),
                'clubName' => $request->getClubName(),
                'ara' => $request->getAra(),
                'clubEmail' => $request->getClubEmail(),
                'status' => $request->getStatus(),
                'requesterName' => $requester instanceof User ? trim($requester->getFirstName() . ' ' . $requester->getLastName()) : '',
                'requesterEmail' => $requester instanceof User ? $requester->getEmail() : '',
                'createdAt' => $request->getCreatedAt()->format('Y-m-d'),
                'expiresAt' => $request->getExpiresAt()->format('Y-m-d'),
                // Chantier Membre (PR 3) — la nature de la demande : create | reprise | promotion.
                'kind' => $this->approvalService->kindOf($request),
            ];
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/club-requests/{id}/decision', methods: ['POST'])]
    public function decide(string $id, Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['clubRequestId' => $id]);
        $guard = $this->guard($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }
        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            return new JsonResponse(['error' => 'Request not found.'], 404);
        }
        $creationRequest = $this->requests->find($id);
        if (!$creationRequest instanceof ClubCreationRequest
            || !\in_array($creationRequest->getStatus(), [ClubCreationRequest::STATUS_PENDING, ClubCreationRequest::STATUS_EXPIRED], true)) {
            return new JsonResponse(['error' => 'Request not found.'], 404);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $decision = \is_array($payload) ? ($payload['decision'] ?? null) : null;
        if (!\in_array($decision, ['approve', 'refuse'], true)) {
            return new JsonResponse(['error' => 'decision doit valoir "approve" ou "refuse".'], 422);
        }

        try {
            if ('approve' === $decision) {
                if (ApprovalOutcome::NoOp === $this->approvalService->approve($creationRequest, 'console')) {
                    return new JsonResponse(['error' => 'Request not found.'], 404);
                }
                $club = $this->clubs->findRealByFfbbCode($creationRequest->getAra());

                return new JsonResponse(['status' => 'approved', 'clubId' => $club?->getId()]);
            }
            if (!$this->approvalService->refuse($creationRequest)) {
                return new JsonResponse(['error' => 'Request not found.'], 404);
            }

            return new JsonResponse(['status' => 'refused']);
        } finally {
            // Ceinture SA0 (patron AdminClubActionController) : le service scope le
            // GUC tenant et le relâche lui-même, mais la requête ADMIN ne doit
            // JAMAIS continuer tenant-scopée si une exception a sauté son finally.
            $this->tenantContext->clear();
        }
    }

    /** Les adhésions en attente, tous clubs (lecture par la porte admin). */
    #[Route('/pending-memberships', methods: ['GET'])]
    public function pendingMemberships(): JsonResponse
    {
        $rows = $this->admin()->fetchAllAssociative(<<<'SQL'
            SELECT cu.id, c.name AS club_name, c.ffbb_club_code AS ara,
                   u.email AS user_email, u.first_name, u.last_name, cu.created_at
            FROM club_user cu
            JOIN club c ON c.id = cu.club_id
            JOIN app_user u ON u.id = cu.user_id
            WHERE cu.is_active = false AND cu.deactivated_at IS NULL
            ORDER BY cu.created_at ASC
            SQL);

        return new JsonResponse(['items' => array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'clubName' => $row['club_name'],
            'ara' => $row['ara'],
            'userName' => trim($row['first_name'] . ' ' . $row['last_name']),
            'userEmail' => $row['user_email'],
            'createdAt' => substr((string) $row['created_at'], 0, 10),
        ], $rows)]);
    }

    /**
     * « Activer une adhésion » — le déblocage quand la passation n'a pas lieu
     * (décision fondateur). Porte admin (club_user est tenant-scopé en écriture),
     * audité SA0 comme toute la surface /api/admin.
     */
    #[Route('/pending-memberships/{id}/activate', methods: ['POST'])]
    public function activateMembership(string $id, Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['membershipId' => $id]);
        $guard = $this->guard($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }
        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            return new JsonResponse(['error' => 'Membership not found.'], 404);
        }

        // P1-1 PR B : cette porte n'active que les adhésions EN ATTENTE — un membre
        // DÉSACTIVÉ par son gestionnaire (deactivated_at posé) ne se « réactive »
        // que par le geste club dédié (POST /api/memberships/{id}/reactivate),
        // sinon la console contournerait la décision du club.
        $clubId = $this->admin()->fetchOne(
            'SELECT club_id FROM club_user WHERE id = :id AND is_active = false AND deactivated_at IS NULL',
            ['id' => $id],
        );
        if (!\is_string($clubId)) {
            return new JsonResponse(['error' => 'Membership not found.'], 404);
        }
        // Chantier Membre : un club SANS gestionnaire actif ne se reprend que par l'approbation de
        // son adresse officielle — activer ici ferait entrer quelqu'un dans un club sans la preuve.
        // Lu par la porte ADMIN (même connexion que l'écriture) — prédicat de
        // ClubUserRepository::hasActiveManager (rôles de gestion owner/admin).
        $hasActiveManager = (int) $this->admin()->fetchOne(
            'SELECT COUNT(*) FROM club_user WHERE club_id = :club AND is_active = true AND role IN (\'owner\', \'admin\')',
            ['club' => $clubId],
        ) > 0;
        if (!$hasActiveManager) {
            return new JsonResponse(['error' => 'La reprise d\'un club sans gestionnaire passe par l\'approbation du club.'], 409);
        }
        $updated = $this->admin()->executeStatement(
            'UPDATE club_user SET is_active = true, updated_at = NOW() WHERE id = :id AND is_active = false AND deactivated_at IS NULL',
            ['id' => $id],
        );
        if (0 === $updated) {
            return new JsonResponse(['error' => 'Membership not found.'], 404);
        }

        return new JsonResponse(['status' => 'activated']);
    }

    /** CSRF + identité SuperAdmin — l'audit contexte est posé par l'appelant AVANT. */
    private function guard(Request $request): ?JsonResponse
    {
        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }
        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof SuperAdmin) {
            return new JsonResponse(['error' => 'Unauthorized.'], 401);
        }
        $request->attributes->set('_admin_audit_actor_id', $admin->getId());

        return null;
    }

    private function admin(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
