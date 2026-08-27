<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\SportCategoryResource;
use App\Entity\SportCategory;
use App\Service\MatchDurationResolver;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractStateProvider<SportCategory, SportCategoryResource>
 */
class SportCategoryStateProvider extends AbstractStateProvider
{
    protected MatchDurationResolver $matchDurationResolver;

    #[Required]
    public function setMatchDurationResolver(MatchDurationResolver $matchDurationResolver): void
    {
        $this->matchDurationResolver = $matchDurationResolver;
    }

    protected function getEntityClass(): string
    {
        return SportCategory::class;
    }

    /**
     * L'ordre des catégories est SERVI, il ne se devine pas.
     *
     * Sans ce tri (constaté le 2026-08-01, P4-36), la collection ne portait que l'ordre par
     * défaut du provider générique — `ORDER BY e.id`, or les ids sont des UUID v4
     * ALÉATOIRES. Le sélecteur de catégories du wizard sortait donc dans un ordre arbitraire,
     * DIFFÉRENT d'un club à l'autre, et la colonne `sortOrder` du catalogue ne servait à rien.
     *
     * Le tri vit ici plutôt que dans l'écran : le wizard, les exports et tout écran futur
     * lisent la même liste, dans le même ordre. Le `e.id ASC` que le parent ajoute derrière
     * reste le départage déterministe (deux catégories personnalisées peuvent partager un
     * `sortOrder`).
     */
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $qb->orderBy('e.sortOrder', 'ASC');

        return parent::applyRequestFilters($qb);
    }

    /**
     * @param SportCategory $entity
     */
    protected function mapEntityToOutput(object $entity): SportCategoryResource
    {
        return SportCategoryResource::fromEntity($entity, $this->matchDurationResolver->familyDefault($entity));
    }
}
