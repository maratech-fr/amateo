<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Doctrine SQL filter that scopes reads to the resolved season by appending
 * `season_id = ?` to every query on entities that own a `season_id` column.
 *
 * Same fail-secure, column-based idiom as TenantFilter: entities without a
 * season_id column (Season itself, Club, ClubUser, SportCategory…)
 * are untouched, so seasons stay listable for the season selector.
 *
 * Season isolation is an intra-club correctness boundary (a rolled-over club
 * must never mix N-1/N/N+1 rows), not the security tenant boundary — that one
 * stays club_id (TenantFilter + RLS). The filter is activated per-request by
 * TenantFilterListener, only when a season resolves.
 */
class SeasonFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Only apply to entities that actually have a season_id column.
        if (!$this->hasSeasonIdColumn($targetEntity)) {
            return '';
        }

        // getParameter() returns the SQL-escaped literal (e.g. '550e8400-…').
        return \sprintf('%s.season_id = %s', $targetTableAlias, $this->getParameter('season_id'));
    }

    /** @phpstan-ignore missingType.generics */
    private function hasSeasonIdColumn(ClassMetadata $targetEntity): bool
    {
        foreach ($targetEntity->getFieldNames() as $fieldName) {
            if ('season_id' === $targetEntity->getColumnName($fieldName)) {
                return true;
            }
        }

        return false;
    }
}
