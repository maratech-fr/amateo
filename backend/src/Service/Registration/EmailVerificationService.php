<?php

declare(strict_types=1);

namespace App\Service\Registration;

use App\Entity\ClubCreationRequest;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Repository\ClubRepository;
use App\Repository\ClubUserRepository;
use App\Security\JwtCookieFactory;
use App\Service\ClubApprovalService;
use App\Service\ClubProvisioner;
use App\Service\EmailVerifier;
use App\Service\TenantConnectionContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * `/api/register/verify` — extrait VERBATIM de AuthController::verifyEmail.
 * Matérialise le tenant (club + seed) qu'une inscription vérifiée mérite :
 * reprise d'un club sans membre (ClubWinBackService), adhésion PENDING à un club
 * existant, ou demande de création en attente d'approbation (ClubApprovalService).
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly JwtCookieFactory $jwtCookieFactory,
        private readonly ClubRepository $clubRepository,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ClockInterface $clock,
        private readonly EmailVerifier $emailVerifier,
        private readonly RateLimiterFactory $authRegisterVerifyLimiter,
        private readonly ClubApprovalService $clubApprovalService,
        private readonly ClubProvisioner $clubProvisioner,
        private readonly ClubWinBackService $clubWinBackService,
        private readonly SerializerInterface $serializer,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        if (!$this->authRegisterVerifyLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }

        $data = json_decode((string) $request->getContent(), true);
        $rawToken = \is_array($data) && isset($data['token']) && \is_string($data['token']) ? $data['token'] : '';

        $token = $this->emailVerifier->resolve($rawToken);
        if (!$token instanceof EmailVerificationToken) {
            return $this->json(['error' => 'Lien de vérification invalide ou expiré.'], 400);
        }

        $tokenId = (int) $token->getId();
        $userId = $token->getUser()->getId();
        $ara = $token->getAra();
        // Non-null club name ⟺ this token was a CREATE (new ARA at register). A join
        // stores null; if its target club has since vanished, do NOT silently create a
        // club and make the user its admin — that would escalate above the join intent.
        $intentClubName = $token->getClubName();
        if (null === $intentClubName && null === $this->clubRepository->findOneBy(['ffbbClubCode' => $ara])) {
            return $this->json(['error' => 'Le club que vous vouliez rejoindre n\'existe plus.'], 409);
        }

        // Materialise the tenant now. Club-scoped inserts need the RLS GUC; set it once
        // the club id is known, always clear afterwards (finally).
        $status = 'pending';
        $pendingRequestId = null;
        try {
            $this->entityManager->wrapInTransaction(function () use ($tokenId, $userId, $ara, $intentClubName, &$status, &$pendingRequestId): void {
                // Serialize concurrent verifies of the SAME token (double-click / retry /
                // two tabs): the winner holds the write lock and consumes the row; a loser
                // then re-reads null and only resolves the (already-created) status — no
                // duplicate club/membership.
                $token = $this->entityManager->find(EmailVerificationToken::class, $tokenId, LockMode::PESSIMISTIC_WRITE);
                if (null === $token) {
                    $membership = $this->clubUserRepository->findOneBy(['userId' => $userId]);
                    $status = null !== $membership && $membership->getIsActive() ? 'active' : 'pending';

                    return;
                }

                $user = $token->getUser();
                $user->setEmailVerifiedAt($this->clock->now());
                // Re-resolve under the lock: the ARA may have been created since the outer read.
                $existingClub = $this->clubRepository->findOneBy(['ffbbClubCode' => $ara]);
                if (null !== $existingClub && $this->clubWinBackService->isMemberless($existingClub->getId())) {
                    // RGPD win-back : le club existe mais n'a PLUS AUCUN membre
                    // actif (workspace purgé après effacement, seule la fiche
                    // FFBB a survécu). Un "pending" serait inapprouvable à
                    // jamais (le gate d'approbation exige un manager actif) et
                    // l'ARA unique interdirait de recréer le club → l'inscrit
                    // reprend le club directement (même confiance que la
                    // création : premier arrivé sur un ARA sans propriétaire).
                    $this->clubWinBackService->reprise($existingClub, $user);
                    $status = 'active';
                } elseif (null !== $existingClub) {
                    $this->tenantConnectionContext->setClubId($existingClub->getId());
                    // Adhésion à un club existant : PENDING au moindre privilège
                    // (Membre) — un gestionnaire du club l'approuvera.
                    $this->clubProvisioner->createMembership($existingClub->getId(), $user->getId(), false, ClubRole::MEMBER);
                    $status = 'pending';
                } else {
                    // P3-4 (décision fondateur 2026-08-05) — anti-squatting : un ARA
                    // inconnu ne crée PLUS le club ici. La demande attend l'approbation
                    // du CLUB (mail institutionnel FFBB) ou du superadmin ; c'est
                    // ClubApprovalService::approve qui matérialisera (ClubProvisioner).
                    $request = $this->clubApprovalService->openRequest($user, $ara, (string) $intentClubName);
                    $pendingRequestId = $request->getId();
                    $status = 'club_pending';
                }
                $this->emailVerifier->consume($token);
            });
        } finally {
            $this->tenantConnectionContext->clear();
        }

        // P3-4 (le populate FFBB async — lot C — vit désormais dans
        // ClubApprovalService::approve, au moment où le club naît réellement.)
        // P3-4 : le mail « Approuver / Refuser » part APRÈS commit — le lien ne
        // doit jamais référencer une demande non persistée. Best-effort (service).
        if (null !== $pendingRequestId) {
            $request = $this->entityManager->getRepository(ClubCreationRequest::class)->find($pendingRequestId);
            $requester = $this->entityManager->getRepository(User::class)->find($userId);
            if (null !== $request && null !== $requester) {
                $this->clubApprovalService->sendApprovalEmail($request, $requester);
            }
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (null === $user) {
            return $this->json(['error' => 'Verification failed'], 500);
        }

        // SEC-16 (audit) : même sortie que `/api/login` — le jeton part en COOKIE
        // httpOnly, jamais dans le corps. Ce chemin crée le jeton à la main (il
        // n'y a pas de handler lexik ici), d'où la fabrique partagée : deux
        // recettes d'attributs finiraient par diverger.
        $response = $this->json([
            'membershipStatus' => $status,
            'user' => ['id' => $user->getId(), 'email' => $user->getEmail()],
        ]);
        $response->headers->setCookie($this->jwtCookieFactory->create($this->jwtManager->create($user)));

        return $response;
    }

    /**
     * Réplique byte-identique de AbstractController::json (le serializer est
     * toujours présent dans cette application) — démembrement VERBATIM oblige.
     *
     * @param array<string, mixed> $headers
     */
    private function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        $json = $this->serializer->serialize($data, 'json', [
            'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS,
        ]);

        return new JsonResponse($json, $status, $headers, true);
    }
}
