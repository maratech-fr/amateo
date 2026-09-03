<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TeamSoloBudgetResource;
use App\Entity\SchedulePlan;
use App\Service\SeasonResolver;
use App\Service\SoloBudget;
use App\Service\SoloReservationBudget;
use App\State\Processor\AssertsSchedulePlanExistsTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Sert {@see TeamSoloBudgetResource} en GetCollection : le budget solo de chaque équipe du
 * club+saison courants, sur la portée demandée (``?schedulePlanId=`` — absent/NULL = socle,
 * un UUID = plan de période, jamais d'union). Calcul délégué à {@see SoloReservationBudget}
 * (MAISON UNIQUE de R). Pagination désactivée : la collection est bornée à une portée.
 *
 * ``?schedulePlanId=`` malformé → 400 ({@see ReadsUuidQueryParamTrait}) ; plan inexistant ou d'un
 * AUTRE club → 422 (même logique que {@see AssertsSchedulePlanExistsTrait} :
 * on ne révèle pas qu'il existe ailleurs).
 *
 * @implements ProviderInterface<TeamSoloBudgetResource>
 */
final class TeamSoloBudgetStateProvider implements ProviderInterface
{
    use ReadsUuidQueryParamTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly SoloReservationBudget $soloReservationBudget,
        private readonly SeasonResolver $seasonResolver,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<TeamSoloBudgetResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return [];
        }

        $clubIdRaw = $request->attributes->get('_club_id') ?? $request->headers->get('X-Club-Id');
        $clubId = \is_string($clubIdRaw) ? $clubIdRaw : null;
        if (null === $clubId) {
            return [];
        }

        $seasonId = $this->resolveSeasonId($request, $clubId);
        if (null === $seasonId) {
            return [];
        }

        $planId = $this->uuidQueryParam($request, 'schedulePlanId');
        if (null !== $planId) {
            $plan = $this->entityManager->getRepository(SchedulePlan::class)->find($planId);
            if (!$plan instanceof SchedulePlan || $plan->getClubId() !== $clubId) {
                throw new UnprocessableEntityHttpException('Unknown schedule plan.');
            }
        }

        return array_map(
            static fn (SoloBudget $budget): TeamSoloBudgetResource => TeamSoloBudgetResource::from($budget, $planId),
            $this->soloReservationBudget->forScope($clubId, $seasonId, $planId),
        );
    }

    private function resolveSeasonId(Request $request, string $clubId): ?string
    {
        $seasonIdRaw = $request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id');
        if (\is_string($seasonIdRaw)) {
            return $seasonIdRaw;
        }

        return $this->seasonResolver->currentSeason($clubId)?->getId();
    }
}
