<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared by the bulk-DQL services (SeasonDataPurger, EntityCascadeDeleter):
 * the tenant/season Doctrine filters alias the table
 * name, which is invalid SQL for the reserved-word `constraint` table, so a
 * bulk DELETE/UPDATE that could touch it must run with them off. Deliberately
 * NOT restored — Doctrine drops a filter's bound parameters when it is disabled
 * then re-enabled, and every caller either runs in a short-lived CLI/EM that is
 * torn down after, or in a DELETE request whose 204 needs no further scoped
 * read. RLS + the explicit clubId/seasonId scope keep every statement bounded.
 */
trait DisablesTenantFilters
{
    private function disableTenantFilters(EntityManagerInterface $entityManager): void
    {
        $filters = $entityManager->getFilters();
        foreach (['tenant_filter', 'season_filter'] as $filterName) {
            if ($filters->isEnabled($filterName)) {
                $filters->disable($filterName);
            }
        }
    }
}
