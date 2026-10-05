<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Repository\ClubCreationRequestRepository;
use App\Service\ClubApprovalService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P3-4 — la page PUBLIQUE d'approbation d'une demande de création de club,
 * ouverte par le destinataire du mail institutionnel FFBB (pas de compte, pas
 * de JWT : le token EST l'identité — patron PublicCoachWishController).
 *
 * Défenses (mêmes que coach-wish) :
 *  - rate-limit par IP AVANT toute résolution ;
 *  - forme du token validée + 404 BYTE-IDENTIQUE pour inconnu, malformé ou
 *    déjà décidé (une demande close ne doit pas se distinguer d'un token
 *    inventé) ;
 *  - expirée → 410 (le superadmin reste la voie de secours, PR B) ;
 *  - la décision est UNIQUE : le premier POST gagne, les suivants revoient 404.
 */
#[AsController]
final class ClubApprovalController extends AbstractController
{
    public function __construct(
        private readonly ClubCreationRequestRepository $requests,
        private readonly ClubApprovalService $approvalService,
        private readonly EntityManagerInterface $entityManager,
        // P4-304 — horloge RÉELLE : l'expiration (+7 j) de la demande est une durée
        // de SÉCURITÉ. Cette page est publique mais un JWT (ex. un gestionnaire de
        // club démo) la ferait porter le `_club_id` du démo et l'horloge décorée
        // simulerait sa date ; la péremption doit se mesurer à l'instant réel.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactory $clubApprovalPublicLimiter,
    ) {}

    #[Route('/api/club-approvals/{token}', name: 'club_approval_get', methods: ['GET'])]
    public function show(string $token, Request $request): JsonResponse
    {
        if (!$this->clubApprovalPublicLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }
        $creationRequest = $this->resolveToken($token);
        if (!$creationRequest instanceof ClubCreationRequest) {
            return $this->notFound();
        }
        if ($this->isExpired($creationRequest)) {
            return $this->json(['error' => 'Cette demande a expiré.'], Response::HTTP_GONE);
        }

        $requester = $this->entityManager->getRepository(User::class)->find($creationRequest->getUserId());

        return $this->json([
            'clubName' => $creationRequest->getClubName(),
            'ara' => $creationRequest->getAra(),
            'requesterName' => $requester instanceof User ? trim($requester->getFirstName() . ' ' . $requester->getLastName()) : '',
            'expiresAt' => $creationRequest->getExpiresAt()->format('Y-m-d'),
        ]);
    }

    #[Route('/api/club-approvals/{token}', name: 'club_approval_decide', methods: ['POST'])]
    public function decide(string $token, Request $request): JsonResponse
    {
        if (!$this->clubApprovalPublicLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }
        $creationRequest = $this->resolveToken($token);
        if (!$creationRequest instanceof ClubCreationRequest) {
            return $this->notFound();
        }
        if ($this->isExpired($creationRequest)) {
            return $this->json(['error' => 'Cette demande a expiré.'], Response::HTTP_GONE);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $decision = \is_array($payload) ? ($payload['decision'] ?? null) : null;
        if (!\in_array($decision, ['approve', 'refuse'], true)) {
            return $this->json(['error' => 'decision doit valoir "approve" ou "refuse".'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ('approve' === $decision) {
            $this->approvalService->approve($creationRequest);

            return $this->json(['status' => 'approved']);
        }
        $this->approvalService->refuse($creationRequest);

        return $this->json(['status' => 'refused']);
    }

    /** Forme + existence + statut PENDING — 404 identique pour tout le reste. */
    private function resolveToken(string $token): ?ClubCreationRequest
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        return $this->requests->findPendingByToken($token);
    }

    private function isExpired(ClubCreationRequest $request): bool
    {
        return $this->clock->now()->format('Y-m-d') > $request->getExpiresAt()->format('Y-m-d');
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
    }
}
