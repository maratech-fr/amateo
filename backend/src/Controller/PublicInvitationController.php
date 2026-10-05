<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\ClubInvitation;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Repository\ClubUserRepository;
use App\Security\JwtCookieFactory;
use App\Service\ClubInvitationManager;
use App\Service\ClubProvisioner;
use App\Service\OrphanAccountNotifier;
use App\Service\PasswordPolicy;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P4-299 — la page PUBLIQUE d'une invitation (patron ClubApprovalController /
 * PublicCoachWishController). Deux chemins d'acceptation :
 *  - SANS compte : création d'un compte VÉRIFIÉ (le lien reçu à l'adresse invitée
 *    PROUVE la possession, même preuve que la vérification d'e-mail — aucun second
 *    mail), adhésion ACTIVE au rôle invité, cookie JWT posé en sortie ;
 *  - AVEC compte (route AUTHENTIFIÉE) : acceptation en un clic — l'e-mail du compte
 *    connecté DOIT être l'adresse invitée (le jeton identifie l'ADRESSE, le login le
 *    COMPTE ; il faut les deux).
 *
 * Défenses (identiques au patron) : rate-limit par IP AVANT toute résolution ; forme du
 * jeton validée + 404 BYTE-IDENTIQUE pour un jeton inconnu, malformé, EXPIRÉ ou révoqué
 * (rien ne distingue une invitation morte d'un jeton inventé) ; `app.club_id` posé par le
 * contrôleur depuis le club du jeton, relâché en `finally` ; expiration mesurée sur
 * `app.clock.real` (jamais l'horloge simulée d'un club démo porté par un JWT).
 *
 * Une invitation ABSORBE une adhésion PENDING existante du même couple user+club : à
 * l'acceptation on ACTIVE la ligne (setRole + setIsActive) au lieu d'insérer — jamais deux
 * lignes club_user. L'acceptation CONSOMME le jeton (single-use) : la ligne disparaît.
 */
#[AsController]
final class PublicInvitationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubInvitationManager $invitationManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly ClubProvisioner $clubProvisioner,
        private readonly OrphanAccountNotifier $orphanAccountNotifier,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly JwtCookieFactory $jwtCookieFactory,
        // P4-304 — horloge RÉELLE : `emailVerifiedAt` et la mesure d'expiration sont des
        // horodatages de SÉCURITÉ ; un JWT de club démo porté sur cette route publique ne
        // doit pas les faire vivre à la date simulée.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactory $invitationPublicLimiter,
    ) {}

    #[Route('/api/invitations/public/{token}', name: 'api_invitations_public_show', methods: ['GET'])]
    public function show(string $token, Request $request): JsonResponse
    {
        if (!$this->invitationPublicLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return $this->tooManyRequests();
        }
        $invitation = $this->resolve($token);
        if (!$invitation instanceof ClubInvitation) {
            return $this->notFound();
        }

        return $this->json([
            'clubName' => $this->clubName((string) $invitation->getClubId()),
            'email' => $invitation->getEmail(),
            'role' => $invitation->getRole(),
            // Un compte VÉRIFIÉ existe-t-il pour cette adresse ? Pilote « se connecter » vs
            // « créer un compte ». Révélé seulement au porteur du lien envoyé à cette adresse.
            'hasAccount' => $this->hasVerifiedAccount($invitation->getEmail()),
        ]);
    }

    #[Route('/api/invitations/public/{token}/accept', name: 'api_invitations_public_accept', methods: ['POST'])]
    public function acceptWithoutAccount(string $token, Request $request): JsonResponse
    {
        if (!$this->invitationPublicLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return $this->tooManyRequests();
        }
        $invitation = $this->resolve($token);
        if (!$invitation instanceof ClubInvitation) {
            return $this->notFound();
        }

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }
        $firstName = isset($data['firstName']) && \is_string($data['firstName']) ? trim($data['firstName']) : '';
        $lastName = isset($data['lastName']) && \is_string($data['lastName']) ? trim($data['lastName']) : '';
        $password = isset($data['password']) && \is_string($data['password']) ? $data['password'] : '';
        $consent = true === ($data['consent'] ?? false);

        if ('' === $firstName || '' === $lastName) {
            return $this->json(['error' => 'Le prénom et le nom sont requis.'], 422);
        }
        if (null !== ($passwordError = $this->passwordPolicy->validate($password))) {
            return $this->json(['error' => $passwordError], 422);
        }
        // Création d'un compte → consentement RGPD requis (même gate que l'inscription).
        if (!$consent) {
            return $this->json(['error' => 'Vous devez accepter les CGU et la politique de confidentialité.'], 422);
        }

        // Un compte existe déjà pour l'adresse invitée → ce chemin ne doit pas en créer un
        // second (ni violer l'unicité) : on oriente vers l'acceptation connectée.
        if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $invitation->getEmail()])) {
            return $this->json(['error' => 'Un compte existe déjà pour cette adresse — connectez-vous pour accepter l\'invitation.'], 409);
        }

        $invitationId = $invitation->getId();
        $clubId = (string) $invitation->getClubId();
        $email = $invitation->getEmail();
        $role = ClubRole::from($invitation->getRole());

        $createdUserId = null;
        $consumed = false;
        try {
            $this->tenantConnectionContext->setClubId($clubId);
            $this->entityManager->wrapInTransaction(function () use ($invitationId, $clubId, $email, $firstName, $lastName, $password, $role, &$createdUserId, &$consumed): void {
                // Sérialise deux acceptations concurrentes du MÊME jeton (double-clic / deux
                // onglets) : le gagnant tient le verrou et consomme ; un perdant relit null.
                $locked = $this->entityManager->find(ClubInvitation::class, $invitationId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof ClubInvitation) {
                    $consumed = true;

                    return;
                }

                $user = new User;
                $user->setEmail($email);
                $user->setFirstName($firstName);
                $user->setLastName($lastName);
                $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
                // Le lien reçu À cette adresse prouve la possession → compte né VÉRIFIÉ.
                $user->setEmailVerifiedAt($this->clock->now());
                $user->setTermsAcceptedAt($this->clock->now());
                $user->setTermsVersion(AuthController::TERMS_VERSION);
                $this->entityManager->persist($user);

                $this->clubProvisioner->createMembership($clubId, $user->getId(), true, $role);
                $this->invitationManager->consume($locked);
                $createdUserId = $user->getId();
            });
        } finally {
            $this->tenantConnectionContext->clear();
        }

        if ($consumed || null === $createdUserId) {
            // Le jeton venait d'être consommé (course) → même 404 byte-identique.
            return $this->notFound();
        }

        $user = $this->entityManager->getRepository(User::class)->find($createdUserId);
        if (!$user instanceof User) {
            return $this->json(['error' => 'Acceptation impossible — réessayez.'], 500);
        }

        $response = $this->json(['membershipStatus' => 'active', 'user' => ['id' => $user->getId(), 'email' => $user->getEmail()]]);
        $response->headers->setCookie($this->jwtCookieFactory->create($this->jwtManager->create($user)));

        return $response;
    }

    #[Route('/api/invitations/{token}/accept', name: 'api_invitations_accept', methods: ['POST'])]
    public function acceptAsConnectedUser(string $token): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $invitation = $this->resolve($token);
        if (!$invitation instanceof ClubInvitation) {
            return $this->notFound();
        }

        // Le jeton identifie l'ADRESSE, le login le COMPTE — il faut les deux : un compte
        // connecté dont l'e-mail n'est pas l'adresse invitée ne peut pas accepter.
        if (strtolower($user->getEmail()) !== $invitation->getEmail()) {
            return $this->json(['error' => 'Cette invitation a été envoyée à une autre adresse.'], 403);
        }

        $invitationId = $invitation->getId();
        $clubId = (string) $invitation->getClubId();
        $userId = $user->getId();
        $role = ClubRole::from($invitation->getRole());

        $consumed = false;
        try {
            $this->tenantConnectionContext->setClubId($clubId);
            $this->entityManager->wrapInTransaction(function () use ($invitationId, $clubId, $userId, $role, &$consumed): void {
                $locked = $this->entityManager->find(ClubInvitation::class, $invitationId, LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof ClubInvitation) {
                    $consumed = true;

                    return;
                }
                $this->activateMembership($clubId, $userId, $role);
                $this->invitationManager->consume($locked);
            });
        } finally {
            $this->tenantConnectionContext->clear();
        }

        if ($consumed) {
            return $this->notFound();
        }

        return $this->json(['membershipStatus' => 'active', 'clubId' => $clubId]);
    }

    /**
     * Matérialise l'adhésion ACTIVE au rôle invité. Absorbe une ligne existante du couple
     * (user, club) — PENDING ou désactivée — au lieu d'en insérer une seconde (unicité
     * club_user). Tourne SOUS le GUC du club (posé par l'appelant).
     */
    private function activateMembership(string $clubId, string $userId, ClubRole $role): void
    {
        $existing = $this->clubUserRepository->findOneBy(['clubId' => $clubId, 'userId' => $userId]);
        if ($existing instanceof ClubUser) {
            $existing->setRole($role->value);
            $existing->setIsActive(true);
            $existing->setDeactivatedAt(null);
            // P4-301 — regagner une adhésion active annule le préavis « compte sans club ».
            $this->orphanAccountNotifier->cancelFor($userId);

            return;
        }
        // Naissance d'une adhésion active (createMembership appelle lui-même cancelFor).
        $this->clubProvisioner->createMembership($clubId, $userId, true, $role);
    }

    /** Forme (64 hex) + existence + non-expiration — 404 identique pour tout le reste. */
    private function resolve(string $token): ?ClubInvitation
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        return $this->invitationManager->resolve($token);
    }

    private function hasVerifiedAccount(string $email): bool
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        return $user instanceof User && $user->getEmailVerifiedAt() instanceof DateTimeImmutable;
    }

    private function clubName(string $clubId): string
    {
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);

        return $club instanceof Club ? $club->getName() : '';
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
    }

    private function tooManyRequests(): JsonResponse
    {
        return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
    }
}
