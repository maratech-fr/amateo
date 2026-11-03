<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubCreationRequest;
use App\Entity\User;
use App\Repository\ClubCreationRequestRepository;
use App\Repository\ClubRepository;
use App\Service\ApprovalOutcome;
use App\Service\ClubApprovalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only (P3-4) : approuve la demande de création de club de l'appelant —
 * le relais e2e/dev du clic « Approuver » (mail institutionnel FFBB) ou du
 * geste superadmin (PR B), sans lequel aucun parcours d'inscription ne
 * matérialiserait de club en environnement de test. Même patron que
 * DevClockController : gardé par kernel.debug, 404 en prod.
 */
#[AsController]
final class DevClubApprovalController extends AbstractController
{
    public function __construct(
        private readonly ClubCreationRequestRepository $requests,
        private readonly ClubApprovalService $approvalService,
        private readonly ClubRepository $clubs,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {}

    #[Route('/api/dev/approve-club-request', name: 'dev_approve_club_request', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        if (!$this->debug) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $request = $this->requests->findPendingByUser($user->getId());
        if (!$request instanceof ClubCreationRequest) {
            return $this->json(['error' => 'No pending club creation request.'], 404);
        }

        if (ApprovalOutcome::NoOp === $this->approvalService->approve($request)) {
            return $this->json(['error' => 'No pending club creation request.'], 404);
        }
        $club = $this->clubs->findRealByFfbbCode($request->getAra());

        return $this->json(['clubId' => $club?->getId(), 'status' => 'approved']);
    }
}
