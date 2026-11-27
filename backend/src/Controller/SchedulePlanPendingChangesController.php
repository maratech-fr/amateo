<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Plans\PendingChanges;
use App\Service\SchedulePlanProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Ce qui attend la validation du planning de saison (lot 5) : `{pending, hasWorkVersion, changes}` lu par l'écran du
 * planning et le bandeau du cockpit ({@see PendingChanges}).
 *
 * Lecture ouverte au Membre, sans écriture (patron {@see SchedulePlanStructureHashController}) : un plan inconnu, d'un
 * autre club ou un identifiant malformé répondent le même 404.
 */
#[AsController]
#[ClubFunction(module: Module::PLANNING, audience: Audience::MEMBER)]
final class SchedulePlanPendingChangesController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PendingChanges $pendingChanges,
    ) {}

    #[Route('/api/schedule_plans/{id}/pending-changes', name: 'api_schedule_plan_pending_changes', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $context = $this->schedulePlanProvisioner->fetchPlanContext($id);
        } catch (Throwable) {
            $context = null; // id malformé → 22P02 : même 404, jamais un 500 Postgres.
        }

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $context || (null !== $currentClubId && $context['clubId'] !== $currentClubId)) {
            return $this->json(['error' => 'Plan introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->pendingChanges->of($id));
    }
}
