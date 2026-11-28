<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Plans\ValidationImpact;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Ce que change « Valider ces modifications » (lot 5) : la forme de `validate-impact` (ce qui change famille par
 * famille, les plannings de période supprimés et gardés, les doléances conservées, le jeton de la liste vue), plus le
 * contrôle d'incohérences avec le planning en vigueur — règles cassées nommées avec leur séance, séances manquantes,
 * contrôle non concluant ({@see ValidationImpact::pendingBody}). Rien à dire sans version en vigueur.
 *
 * Lecture ouverte au Membre, sans écriture (patron {@see SchedulePlanPendingChangesController}) : un plan inconnu,
 * d'un autre club ou un identifiant malformé répondent le même 404. Le contrôle d'incohérences appelle le moteur : il
 * n'est fait que pour un gestionnaire ; un Membre lit l'annonce avec `checkPerformed` faux, sans appel au moteur.
 */
#[AsController]
#[ClubFunction(module: Module::PLANNING, audience: Audience::MEMBER)]
final class SchedulePlanPendingImpactController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ValidationImpact $validationImpact,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Route('/api/schedule_plans/{id}/pending-impact', name: 'api_schedule_plan_pending_impact', methods: ['GET'])]
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

        return $this->json($this->validationImpact->pendingBody($id, $context['clubId'], $context['seasonId'], $this->managementAccessGuard->isManager()));
    }
}
