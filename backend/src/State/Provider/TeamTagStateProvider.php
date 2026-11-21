<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TeamTagResource;
use App\Service\TeamTagService;
use ArrayIterator;

/**
 * Sert le catalogue fermé des étiquettes ({@see TeamTagService::catalog}) : un objet par nom,
 * identique pour tous les clubs, rien n'est lu en base. L'item `/api/team_tags/{nom}` rend le
 * groupe s'il est au catalogue, sinon 404.
 *
 * @implements ProviderInterface<TeamTagResource>
 */
final class TeamTagStateProvider implements ProviderInterface
{
    public function __construct(
        private readonly TeamTagService $tagService,
        private readonly Pagination $pagination,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return TeamTagResource|iterable<int, TeamTagResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $all = array_map(
            static fn (array $entry): TeamTagResource => TeamTagResource::fromCatalog($entry['name'], $entry['color'], $entry['axis']),
            $this->tagService->catalog(),
        );

        if (!$operation instanceof GetCollection) {
            $id = $uriVariables['id'] ?? null;
            foreach ($all as $tag) {
                if ($tag->id === $id) {
                    return $tag;
                }
            }

            return null;
        }

        $offset = $this->pagination->getOffset($operation, $context);
        $limit = $this->pagination->getLimit($operation, $context);
        $page = \array_slice($all, $offset, $limit > 0 ? $limit : null);
        $currentPage = $limit > 0 ? (int) floor($offset / $limit) + 1 : 1;

        return new TraversablePaginator(new ArrayIterator($page), $currentPage, $limit, \count($all));
    }
}
