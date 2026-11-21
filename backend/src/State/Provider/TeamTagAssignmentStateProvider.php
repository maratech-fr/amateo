<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TeamTagAssignmentResource;
use App\Entity\Season;
use App\Service\SeasonResolver;
use App\Service\TeamTagResolver;
use ArrayIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sert les rattachements CALCULÉS des équipes de la saison de la requête (club depuis
 * `_club_id`, saison sélectionnée sinon courante) : une ligne par équipe et par groupe qu'elle
 * porte, triées par équipe puis nom, 30 par page. Le calcul est celui des règles de groupe
 * ({@see TeamTagResolver::teamIdsByTag}) : l'écran voit exactement ce que le moteur reçoit.
 *
 * @implements ProviderInterface<TeamTagAssignmentResource>
 */
final class TeamTagAssignmentStateProvider implements ProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly TeamTagResolver $tagResolver,
        private readonly Pagination $pagination,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<int, TeamTagAssignmentResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $all = $this->assignments();

        $offset = $this->pagination->getOffset($operation, $context);
        $limit = $this->pagination->getLimit($operation, $context);
        $page = \array_slice($all, $offset, $limit > 0 ? $limit : null);
        $currentPage = $limit > 0 ? (int) floor($offset / $limit) + 1 : 1;

        return new TraversablePaginator(new ArrayIterator($page), $currentPage, $limit, \count($all));
    }

    /** @return list<TeamTagAssignmentResource> */
    private function assignments(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return [];
        }
        $clubId = $request->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return [];
        }
        $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if (!$season instanceof Season || $season->getClubId() !== $clubId) {
            return [];
        }
        $seasonId = $season->getId();

        $pairs = [];
        foreach ($this->tagResolver->teamIdsByTag($clubId, $seasonId) as $name => $teamIds) {
            foreach ($teamIds as $teamId) {
                $pairs[] = [$teamId, (string) $name];
            }
        }
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return array_map(
            static fn (array $pair): TeamTagAssignmentResource => TeamTagAssignmentResource::of($pair[0], $pair[1], $seasonId),
            $pairs,
        );
    }
}
