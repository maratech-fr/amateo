<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\Service\ClosureWindowApplier;
use App\Service\ClosureWindowPlanner;
use App\Service\ManagementAccessGuard;
use App\Service\PeriodWindowUniquenessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\WriteTargetSeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Prolonger une fermeture à travers les vacances (fondateur 2026-10-27, axe planning lifecycle) —
 * L'APPLICATION de la nouvelle fenêtre, après aperçu confirmé.
 *
 * Jeton d'aperçu OBLIGATOIRE (absent → 422) ; RECALCULÉ sous `lockClubWindows` puis `lockPlanScope`
 * (le décor a-t-il bougé depuis l'aperçu ?) → 409 si périmé/forgé. Plancher « le passé ne bouge
 * pas » (début figé d'une fermeture commencée, fin jamais sous aujourd'hui) → 422. Conversion non
 * destructive racine-bloc → mère découpée, portée par {@see ClosureWindowApplier} ; l'aperçu ==
 * l'appliqué (même {@see ClosureWindowPlanner}). SeasonScopedWriteInterface → 409 saison archivée.
 */
#[AsController]
final class ClosureWindowApplyController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ClosureWindowPlanner $planner,
        private readonly ClosureWindowApplier $applier,
        private readonly PeriodWindowUniquenessGuard $windowUniquenessGuard,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    // SEC-13 — la cible est le plan nommé dans l'URL ; sa saison gouverne le garde « archivée ».
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedulePlan($id) : null;
    }

    #[Route('/api/schedule_plans/{id}/window', name: 'api_schedule_plan_window', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $previewToken = $body['previewToken'] ?? null;
        if (!\is_string($previewToken) || '' === $previewToken) {
            return $this->json(['error' => 'Demandez d\'abord l\'aperçu des effets, puis confirmez.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $newStart = $this->parseDate($body['startDate'] ?? null);
        $newEnd = $this->parseDate($body['endDate'] ?? null);
        if (!$newStart instanceof DateTimeImmutable || !$newEnd instanceof DateTimeImmutable || $newEnd->format('Y-m-d') < $newStart->format('Y-m-d')) {
            return $this->json(['error' => 'Dates manquantes ou mal formées (AAAA-MM-JJ).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $plan = $this->entityManager->getRepository(SchedulePlan::class)->find($id);
        } catch (Throwable) {
            $plan = null;
        }
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$plan instanceof SchedulePlan || (null !== $currentClubId && $plan->getClubId() !== $currentClubId)) {
            return $this->json(['error' => 'Plan introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (SchedulePlanType::CLOSURE !== $plan->getType()) {
            return $this->json(['error' => 'Seule une fermeture modifie sa fenêtre ainsi.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Tout ce qui DÉCIDE et écrit vit sous les verrous et dans la transaction : club+saison
        // d'abord (unicité de fenêtre du club entier), plan ensuite — ordre uniforme, pas d'ABBA.
        $this->entityManager->wrapInTransaction(function () use ($plan, $newStart, $newEnd, $previewToken): void {
            $this->schedulePlanProvisioner->lockClubWindows((string) $plan->getClubId(), $plan->getSeasonId());
            $entryId = $plan->getCalendarEntryId();
            if (\is_string($entryId)) {
                $this->schedulePlanProvisioner->lockPlanScope($entryId);
            }

            // Jeton recalculé SOUS les verrous : un décor qui a bougé depuis l'aperçu → 409.
            $computed = $this->planner->plan($plan, $newStart, $newEnd);
            if ($computed->token !== $previewToken) {
                throw new ConflictHttpException('La fermeture a changé depuis l\'aperçu : redemandez l\'aperçu des effets avant de confirmer.');
            }

            // Plancher « le passé ne bouge pas ».
            $floor = $this->planner->floorViolation($plan, $newStart, $newEnd);
            if (null !== $floor) {
                throw new UnprocessableEntityHttpException($floor);
            }

            // « Les vacances ont la main » : le conflit de fenêtre ne joue QU'ENTRE FERMETURES.
            $this->windowUniquenessGuard->assertWindowFree(
                (string) $plan->getClubId(),
                $plan->getSeasonId(),
                $computed->rootEntryId,
                $computed->newStart,
                $computed->newEnd,
                closuresOnly: true,
            );

            $this->applier->apply($plan, $computed);
        });

        return $this->json(['id' => $plan->getId()], Response::HTTP_OK);
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
