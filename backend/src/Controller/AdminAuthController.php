<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SuperAdmin;
use App\Security\AdminSessionCsrf;
use App\Security\SuperAdminProvider;
use App\Security\TotpService;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

#[Route('/api/admin/auth')]
final class AdminAuthController
{
    private const PENDING_ID = 'admin_pending_id';
    private const PENDING_AT = 'admin_pending_at';

    public function __construct(
        private readonly SuperAdminProvider $provider,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TotpService $totp,
        private readonly TokenStorageInterface $tokens,
        private readonly ManagerRegistry $registry,
        private readonly RateLimiterFactory $adminAuthLimiter,
        private readonly AdminSessionCsrf $csrf,
    ) {}

    #[Route('/password', methods: ['POST'])]
    public function password(Request $request): JsonResponse
    {
        if (!$this->adminAuthLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de tentatives. Patientez quelques instants.'], 429);
        }
        $this->clearPendingChallenge($request);
        $body = $this->body($request);
        try {
            $admin = $this->provider->loadUserByIdentifier((string) ($body['email'] ?? ''));
        } catch (UserNotFoundException) {
            return new JsonResponse(['error' => 'Identifiants invalides.'], 401);
        }
        if (!$admin->isEnabled() || !$this->passwordHasher->isPasswordValid($admin, (string) ($body['password'] ?? ''))) {
            return new JsonResponse(['error' => 'Identifiants invalides.'], 401);
        }
        $session = $request->getSession();
        $session->migrate(true);
        $session->set(self::PENDING_ID, $admin->getId());
        $session->set(self::PENDING_AT, time());

        return new JsonResponse(['mfaRequired' => true]);
    }

    #[Route('/totp', methods: ['POST'])]
    public function totp(Request $request): JsonResponse
    {
        if (!$this->adminAuthLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de tentatives. Patientez quelques instants.'], 429);
        }
        $session = $request->getSession();
        $id = $session->get(self::PENDING_ID);
        $startedAt = $session->get(self::PENDING_AT);
        if (!\is_string($id) || !\is_int($startedAt) || time() - $startedAt > 300) {
            $this->clearPendingChallenge($request);

            return new JsonResponse(['error' => 'Le délai de vérification a expiré — reconnectez-vous.'], 401);
        }
        try {
            $admin = $this->provider->loadById($id);
        } catch (UserNotFoundException) {
            $this->clearPendingChallenge($request);

            return new JsonResponse(['error' => 'Vérification invalide — reconnectez-vous.'], 401);
        }
        $body = $this->body($request);
        if (!$admin->isEnabled() || !$this->totp->verifyEncrypted($admin->getTotpSecret(), (string) ($body['code'] ?? ''))) {
            return new JsonResponse(['error' => 'Code de vérification invalide.'], 401);
        }
        $this->clearPendingChallenge($request);
        $session->migrate(true);
        $token = new UsernamePasswordToken($admin, 'admin', $admin->getRoles());
        $this->tokens->setToken($token);
        $adminConnection = $this->registry->getConnection('admin');
        \assert($adminConnection instanceof Connection);
        $adminConnection->executeStatement('UPDATE super_admin SET last_login_at = NOW() WHERE id = :id', ['id' => $admin->getId()]);

        return new JsonResponse([
            'authenticated' => true,
            'csrfToken' => $this->csrf->issue($request),
        ]);
    }

    #[Route('/me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof SuperAdmin) {
            return new JsonResponse(['error' => 'Unauthorized.'], 401);
        }

        $csrfToken = $this->csrf->current($request) ?? $this->csrf->issue($request);

        return new JsonResponse(['id' => $user->getId(), 'email' => $user->getEmail(), 'csrfToken' => $csrfToken]);
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }
        $user = $this->tokens->getToken()?->getUser();
        if ($user instanceof SuperAdmin) {
            $request->attributes->set('_admin_audit_actor_id', $user->getId());
        }
        $this->tokens->setToken(null);
        $request->getSession()->invalidate();

        return new JsonResponse(null, 204);
    }

    private function clearPendingChallenge(Request $request): void
    {
        $session = $request->getSession();
        $session->remove(self::PENDING_ID);
        $session->remove(self::PENDING_AT);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return \is_array($body) ? $body : [];
    }
}
