<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\Service\ClosureWindowPlanner;
use App\Service\ManagementAccessGuard;
use DateTimeImmutable;
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
 * Prolonger une fermeture à travers les vacances (fondateur 2026-10-27, axe planning lifecycle) —
 * L'APERÇU des effets de la nouvelle fenêtre d'une fermeture : on annonce AVANT de confirmer.
 * LECTURE PURE (aucun persist, aucun flush), management (patron {@see RedatePreviewController}).
 *
 * Rend `effects` (liste FR chronologique — convert, copie validée/vide, pause de vacances, glissement/
 * disparition — dates en clair, aucun identifiant interne), `summary`, `futurePlansWarning` et un
 * `token` d'état que le PUT confirmera (recalcul sous verrous ; différent → 409). Le calcul vit dans
 * le foyer UNIQUE {@see ClosureWindowPlanner}, partagé avec l'apply — l'aperçu == l'appliqué.
 *
 * Tenant : 404 BYTE-IDENTIQUE pour un plan inconnu ET pour un plan d'un autre club (aucun oracle
 * d'existence, patron {@see SchedulePlanStructureHashController}).
 */
#[AsController]
final class ClosureWindowPreviewController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly ClosureWindowPlanner $planner,
    ) {}

    #[Route('/api/schedule_plans/{id}/window-preview', name: 'api_schedule_plan_window_preview', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        try {
            $context = $this->entityManager->getRepository(SchedulePlan::class)->find($id);
        } catch (Throwable) {
            $context = null; // id malformé → 22P02 : même 404, jamais un 500 Postgres.
        }
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$context instanceof SchedulePlan || (null !== $currentClubId && $context->getClubId() !== $currentClubId)) {
            return $this->json(['error' => 'Plan introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (SchedulePlanType::CLOSURE !== $context->getType()) {
            return $this->json(['error' => 'Seule une fermeture modifie sa fenêtre ainsi.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $newStart = $this->parseDate($body['startDate'] ?? null);
        $newEnd = $this->parseDate($body['endDate'] ?? null);
        if (!$newStart instanceof DateTimeImmutable || !$newEnd instanceof DateTimeImmutable) {
            return $this->json(['error' => 'Dates manquantes ou mal formées (AAAA-MM-JJ).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($newEnd->format('Y-m-d') < $newStart->format('Y-m-d')) {
            return $this->json(['error' => 'La date de fin doit être postérieure ou égale à la date de début.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $plan = $this->planner->plan($context, $newStart, $newEnd);

        return $this->json([
            'token' => $plan->token,
            'summary' => $plan->summary,
            'futurePlansWarning' => $plan->futurePlansWarning,
            'effects' => $plan->effects,
        ]);
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
