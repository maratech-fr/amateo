<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ImplicitRuleSettingResource;
use App\Enum\ImplicitRuleKey;
use App\Repository\ImplicitRuleSettingRepository;
use App\Service\ImplicitRuleResolver;
use App\Service\SeasonResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sert les 4 règles implicites RÉSOLUES (stocké OU défaut).
 *
 * @implements ProviderInterface<ImplicitRuleSettingResource>
 */
final class ImplicitRuleSettingStateProvider implements ProviderInterface
{
    use ReadsUuidQueryParamTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ImplicitRuleResolver $resolver,
        private readonly ImplicitRuleSettingRepository $repository,
        private readonly SeasonResolver $seasonResolver,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        [$clubId, $seasonId] = $this->resolveScope();
        if (null === $clubId || null === $seasonId) {
            return $operation instanceof CollectionOperationInterface ? [] : null;
        }

        // ADR-0002 inv. 5 — la PORTÉE : `?schedulePlanId=` cible un plan de période (sa copie),
        // son absence la portée SAISON. Un uuid mal formé rend 400 (jamais un 500 de cast pg).
        $request = $this->requestStack->getCurrentRequest();
        $schedulePlanId = $request instanceof Request ? $this->uuidQueryParam($request, 'schedulePlanId') : null;

        [$resolved, $stored] = null === $schedulePlanId
            ? [$this->resolver->resolve($clubId, $seasonId), $this->repository->findByClubSeasonIndexed($clubId, $seasonId)]
            : [$this->resolver->resolveForPlan($clubId, $seasonId, $schedulePlanId), $this->repository->findByPlanIndexed($clubId, $seasonId, $schedulePlanId)];

        if ($operation instanceof CollectionOperationInterface) {
            $out = [];
            foreach (ImplicitRuleKey::cases() as $ruleKey) {
                $out[] = ImplicitRuleSettingResource::fromResolved(
                    $ruleKey,
                    // Une règle opt-in non réglée n'est PAS dans le bloc résolu (elle ne part
                    // pas au moteur) — mais elle doit rester visible et activable (P2-42).
                    $resolved[$ruleKey->value] ?? ImplicitRuleResolver::inactiveEntry($ruleKey),
                    !isset($stored[$ruleKey->value]),
                );
            }

            return $out;
        }

        $ruleKey = ImplicitRuleKey::tryFrom((string) ($uriVariables['ruleKey'] ?? ''));
        if (null === $ruleKey) {
            return null;
        }

        return ImplicitRuleSettingResource::fromResolved(
            $ruleKey,
            $resolved[$ruleKey->value] ?? ImplicitRuleResolver::inactiveEntry($ruleKey),
            !isset($stored[$ruleKey->value]),
        );
    }

    /**
     * Le club + la saison du contexte de la requête. La saison est celle stampée par le
     * listener (X-Season-Id ou saison courante) ; à défaut, on retombe sur la saison courante
     * du club, comme AbstractStateProcessor.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveScope(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return [null, null];
        }

        $seasonId = $request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            $seasonId = $this->seasonResolver->currentSeason($clubId)?->getId();
        }

        return [$clubId, \is_string($seasonId) && '' !== $seasonId ? $seasonId : null];
    }
}
