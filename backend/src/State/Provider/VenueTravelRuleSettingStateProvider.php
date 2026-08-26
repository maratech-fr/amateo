<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\VenueTravelRuleSettingResource;
use App\Entity\VenueTravelRuleSetting;
use App\Enum\TeamLinkIntensity;
use App\Repository\VenueTravelRuleSettingRepository;
use App\Service\SeasonResolver;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sert le levier de trajet RÉSOLU (stocké OU défaut PREFERRED) du club+saison courant.
 *
 * @implements ProviderInterface<VenueTravelRuleSettingResource>
 */
final class VenueTravelRuleSettingStateProvider implements ProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly VenueTravelRuleSettingRepository $repository,
        private readonly SeasonResolver $seasonResolver,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?VenueTravelRuleSettingResource
    {
        // Revue sécurité 2026-08-26 (F-1) : la clé du chemin est VÉRIFIÉE — toute
        // autre chaîne fait 404 au lieu d'aliaser silencieusement l'unique réglage
        // (le piège du jour où une 2ᵉ clé existera, et l'OpenAPI cesse de mentir).
        if (VenueTravelRuleSettingResource::RULE_KEY !== ($uriVariables['ruleKey'] ?? null)) {
            return null;
        }
        [$clubId, $seasonId] = $this->resolveScope();
        if (null === $clubId || null === $seasonId) {
            return null;
        }

        $stored = $this->repository->findOneByClubSeason($clubId, $seasonId);

        return VenueTravelRuleSettingResource::from(
            $stored instanceof VenueTravelRuleSetting ? $stored->getIntensity() : TeamLinkIntensity::PREFERRED,
            !$stored instanceof VenueTravelRuleSetting,
        );
    }

    /**
     * Le club + la saison du contexte de la requête (stampés par le listener, ou saison courante).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveScope(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return [null, null];
        }

        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            $seasonId = $this->seasonResolver->currentSeason($clubId)?->getId();
        }

        return [$clubId, \is_string($seasonId) && '' !== $seasonId ? $seasonId : null];
    }
}
