<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\ClubInvitation;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Repository\ClubInvitationRepository;
use App\Repository\ClubUserRepository;
use App\Service\ClubInvitationMailer;
use App\Service\ClubInvitationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P4-299 — l'émission / le listing / le renvoi / la révocation d'invitations par un
 * gestionnaire ACTIF de SON club (gate `requireActiveAdmin`, patron MembershipController).
 * Les écritures sont club-scoped : le GUC tenant est posé par TenantFilterListener depuis
 * le club de la requête, la RLS borne d'elle-même la portée.
 *
 * Un gestionnaire d'un compte de DÉMONSTRATION ne peut pas inviter (geste de compte
 * interdit aux démos, SEC-28) ; une adresse de compte démo ne peut pas être invitée (un
 * compte démo ne gagne aucune adhésion réelle). Une adresse déjà membre ACTIF du club →
 * 422 clair (l'appelant est gestionnaire de SON club, le silence serait de l'ergonomie
 * perdue sans gain anti-énumération).
 */
final class ClubInvitationController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubInvitationRepository $invitations,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly ClubInvitationManager $invitationManager,
        private readonly ClubInvitationMailer $invitationMailer,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactory $invitationSendLimiter,
    ) {}

    #[Route('/api/invitations', name: 'api_invitations_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $admin = $this->requireActiveAdmin();
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->json(['invitations' => array_map(
            $this->serialize(...),
            $this->invitations->findForClub((string) $admin['clubId']),
        )]);
    }

    #[Route('/api/invitations', name: 'api_invitations_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireActiveAdmin();
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $actingUser = $admin['user'];

        // Geste de compte interdit aux comptes de démonstration (SEC-28) : un gestionnaire
        // démo ne peut pas inviter — aucune invitation ne naît donc sous horloge simulée.
        if ($actingUser->isDemo()) {
            return $this->json(['error' => 'Un compte de démonstration ne peut pas inviter.'], 403);
        }

        if (!$this->invitationSendLimiter->create($actingUser->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop d\'invitations envoyées — réessayez dans un moment.'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $email = isset($data['email']) && \is_string($data['email']) ? strtolower(trim($data['email'])) : '';
        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Une adresse e-mail valide est requise.'], 422);
        }

        $role = $this->readRole($data);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $clubId = (string) $admin['clubId'];

        // L'adresse appartient-elle à un compte ? Lookup GLOBAL (app_user hors tenant) : on
        // ne révèle jamais « a un compte ailleurs », seulement le lien avec CE club.
        $invitee = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($invitee instanceof User) {
            if ($invitee->isDemo()) {
                return $this->json(['error' => 'Cette adresse ne peut pas être invitée.'], 422);
            }
            $membership = $this->clubUserRepository->findActiveMembership($invitee->getId(), $clubId);
            if ($membership instanceof ClubUser) {
                return $this->json(['error' => 'Cette personne est déjà membre de votre club.'], 422);
            }
        }

        $raw = $this->invitationManager->issue($clubId, $email, $role, $actingUser->getId());
        $this->entityManager->flush();

        $invitation = $this->invitations->findOneByClubAndEmail($clubId, $email);
        if (!$invitation instanceof ClubInvitation) {
            return $this->json(['error' => 'Invitation introuvable — réessayez.'], 500);
        }

        $this->invitationMailer->send(
            $invitation,
            $raw,
            $actingUser->getFirstName(),
            $this->clubName($clubId),
            $request->getSchemeAndHttpHost(),
        );

        return $this->json($this->serialize($invitation), 201);
    }

    #[Route('/api/invitations/{id}/resend', name: 'api_invitations_resend', methods: ['POST'])]
    public function resend(string $id, Request $request): JsonResponse
    {
        $admin = $this->requireActiveAdmin();
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $actingUser = $admin['user'];
        if ($actingUser->isDemo()) {
            return $this->json(['error' => 'Un compte de démonstration ne peut pas inviter.'], 403);
        }

        $invitation = $this->resolveForClub($id, (string) $admin['clubId']);
        if (!$invitation instanceof ClubInvitation) {
            return $this->json(['error' => 'Invitation introuvable — rechargez la liste.'], 404);
        }

        if (!$this->invitationSendLimiter->create($actingUser->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop d\'invitations envoyées — réessayez dans un moment.'], 429);
        }

        $raw = $this->invitationManager->regenerate($invitation);
        $this->entityManager->flush();

        $this->invitationMailer->send(
            $invitation,
            $raw,
            $actingUser->getFirstName(),
            $this->clubName((string) $admin['clubId']),
            $request->getSchemeAndHttpHost(),
        );

        return $this->json($this->serialize($invitation));
    }

    #[Route('/api/invitations/{id}', name: 'api_invitations_revoke', methods: ['DELETE'])]
    public function revoke(string $id): JsonResponse
    {
        $admin = $this->requireActiveAdmin();
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $invitation = $this->resolveForClub($id, (string) $admin['clubId']);
        if (!$invitation instanceof ClubInvitation) {
            return $this->json(['error' => 'Invitation introuvable — rechargez la liste.'], 404);
        }

        $this->entityManager->remove($invitation);
        $this->entityManager->flush();

        return $this->json(null, 204);
    }

    /** @return array{id: string, email: string, role: string, expiresAt: string} */
    private function serialize(ClubInvitation $invitation): array
    {
        return [
            'id' => $invitation->getId(),
            'email' => $invitation->getEmail(),
            'role' => $invitation->getRole(),
            'expiresAt' => $invitation->getExpiresAt()->format('Y-m-d'),
        ];
    }

    private function resolveForClub(string $id, string $clubId): ?ClubInvitation
    {
        $invitation = $this->invitations->find($id);
        // Jamais de fuite cross-tenant : la cible doit appartenir au club du gestionnaire.
        if (!$invitation instanceof ClubInvitation || $invitation->getClubId() !== $clubId) {
            return null;
        }

        return $invitation;
    }

    /**
     * Lit le rôle visé du corps — défaut Membre (décision fondateur : inviter directement
     * au rôle Gestionnaire est possible). Une valeur hors enum → 422, jamais persistée.
     *
     * @param array<string, mixed> $data
     */
    private function readRole(array $data): ClubRole|JsonResponse
    {
        if (!\array_key_exists('role', $data)) {
            return ClubRole::MEMBER;
        }
        $role = \is_string($data['role']) ? ClubRole::tryFrom($data['role']) : null;
        if (!$role instanceof ClubRole) {
            return $this->json(['error' => 'Rôle invalide.'], 422);
        }

        return $role;
    }

    private function clubName(string $clubId): string
    {
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);

        return $club instanceof Club ? $club->getName() : '';
    }

    /**
     * L'adhésion management ACTIVE de l'appelant dans le club de la requête, ou une
     * réponse d'erreur. Patron MembershipController::requireActiveAdmin (copié, pas
     * extrait — zéro refactor hors scope).
     *
     * @return array{user: User, clubId: string}|JsonResponse
     */
    private function requireActiveAdmin(): array|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        $membership = $this->clubUserRepository->findActiveMembership($user->getId(), $clubId);
        if (!$membership instanceof ClubUser || !$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return ['user' => $user, 'clubId' => $clubId];
    }
}
