<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\TenantConnectionContext;

/**
 * Test seeding under RLS: direct EntityManager inserts into club-scoped tables
 * go through the amateo_app connection, whose WITH CHECK policies require the
 * app.club_id GUC to match each row. Seeds must scope the connection to the
 * club they are inserting for (and re-scope when switching clubs).
 *
 * dama wraps each test in a transaction, so a GUC set here is rolled back with
 * the test — no cross-test leakage.
 */
trait TenantGucTrait
{
    private function scopeGucToClub(string $clubId): void
    {
        static::getContainer()->get(TenantConnectionContext::class)->setClubId($clubId);
    }

    private function clearGuc(): void
    {
        static::getContainer()->get(TenantConnectionContext::class)->clear();
    }
}
