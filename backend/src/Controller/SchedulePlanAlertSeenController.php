<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\SchedulePlan;
use App\Service\ClubDay;
use App\Service\ManagementAccessGuard;
use App\Service\PeriodPlanAlertResolver;
use App\Service\SchedulePlanProvisioner;
use App\Service\WriteTargetSeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * « J'ai vu » sur l'alerte d'un planning de période au radar (validé automatiquement / commencé
 * sans planning) : la vue vaut pour TOUT le club, datée du jour du club. Réservé aux gestionnaires
 * (403) ; un plan d'un autre club est introuvable (filtre tenant + RLS, 404) ; un type d'alerte
 * inconnu est refusé (422). SeasonScopedWriteInterface → 409 sur une saison archivée.
 */
#[AsController]
final class SchedulePlanAlertSeenController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
        private readonly ClubDay $clubDay,
    ) {}

    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedulePlan($id) : null;
    }

    #[Route('/api/schedule_plans/{id}/alert-seen', name: 'api_schedule_plan_alert_seen', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager();

        try {
            $plan = $this->entityManager->getRepository(SchedulePlan::class)->find($id);
        } catch (Throwable) {
            $plan = null;
        }
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $club = null === $clubId ? null : $this->entityManager->find(Club::class, $clubId);
        if (!$plan instanceof SchedulePlan || !$club instanceof Club || $plan->getClubId() !== $club->getId()) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode((string) $request->getContent(), true);
        $kind = \is_array($body) ? ($body['kind'] ?? null) : null;
        if (!\is_string($kind) || !\in_array($kind, PeriodPlanAlertResolver::KINDS, true)) {
            return $this->json(['error' => 'Type d\'alerte inconnu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $today = $this->clubDay->todayFor($club);
        if (!$this->schedulePlanProvisioner->markAlertSeen($plan->getId(), $kind, $today)) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['id' => $plan->getId(), 'alertSeenDate' => $today->format('Y-m-d')], Response::HTTP_OK);
    }
}
