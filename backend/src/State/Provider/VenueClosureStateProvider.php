<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\VenueClosureResource;
use App\Service\PlanVenueClosures;
use App\Service\SeasonResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P4-300 — sert {@see VenueClosureResource} en GetCollection : les fermetures `venue_closed`
 * du club+saison courants, lues comme FAIT brut via {@see PlanVenueClosures::closureIntervals}
 * (config, repli legacy — jamais la composition `VenuePeriodOverride`). Club résolu depuis
 * l'attribut `_club_id` posé par le listener tenant (comme {@see ClubLeagueWindowStateProvider}),
 * saison depuis `_season_id` (repli en-tête puis saison courante). Pagination désactivée : la
 * collection est bornée au club+saison. Un autre club ne voit rien (closureIntervals filtre par
 * clubId en SQL, RLS en couche DB).
 *
 * @implements ProviderInterface<VenueClosureResource>
 */
final class VenueClosureStateProvider implements ProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly PlanVenueClosures $planVenueClosures,
        private readonly SeasonResolver $seasonResolver,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<VenueClosureResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return [];
        }

        $clubIdRaw = $request->attributes->get('_club_id');
        $clubId = \is_string($clubIdRaw) ? $clubIdRaw : null;
        if (null === $clubId) {
            return [];
        }

        $seasonId = $this->resolveSeasonId($request, $clubId);
        if (null === $seasonId) {
            return [];
        }

        return array_map(
            static fn (array $closure): VenueClosureResource => VenueClosureResource::from($closure),
            $this->planVenueClosures->closureIntervals($clubId, $seasonId),
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
