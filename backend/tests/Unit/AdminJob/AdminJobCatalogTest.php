<?php

declare(strict_types=1);

namespace App\Tests\Unit\AdminJob;

use App\AdminJob\AdminJobCatalog;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

final class AdminJobCatalogTest extends TestCase
{
    public function testRestoreCheckRunsWeeklyOnSundayAfterTheNightlyBackupAndPurges(): void
    {
        // Promesse : le catalogue fermé planifie `app:db:restore-check` en hebdomadaire,
        // dimanche 04:00 Europe/Paris — après la sauvegarde nocturne (01:00) et les purges.
        $catalog = new AdminJobCatalog;
        $restoreCheck = $catalog->find('db-restore-check');

        self::assertNotNull($restoreCheck, 'The closed catalog must schedule the db-restore-check job.');
        self::assertSame('app:db:restore-check', $restoreCheck->command);

        $sundayMorning = new DateTimeImmutable('2026-07-19T05:00:00+02:00');
        $restoreDue = $restoreCheck->schedule->nextDueAt($sundayMorning, null);
        self::assertSame('2026-07-19T04:00:00+02:00', $restoreDue->format(DateTimeInterface::ATOM));
        self::assertSame('Sunday', $restoreDue->format('l'));

        // Après le dump nocturne (db-backup, quotidien 01:00) : même dimanche, 01:00 < 04:00.
        $backup = $catalog->find('db-backup');
        self::assertNotNull($backup);
        self::assertTrue(
            $backup->schedule->nextDueAt($sundayMorning, null) < $restoreDue,
            'restore-check must run after the nightly backup.',
        );

        // Après la dernière purge nocturne (purge-exports, quotidien 03:45) : 03:45 < 04:00.
        $lastPurge = $catalog->find('purge-exports');
        self::assertNotNull($lastPurge);
        self::assertTrue(
            $lastPurge->schedule->nextDueAt($sundayMorning, null) < $restoreDue,
            'restore-check must run after the nightly purges.',
        );
    }

    public function testPeriodPlanAutoValidationRunsDailyAtQuarterPastMidnightParis(): void
    {
        // Promesse (P4-503) : le catalogue fermé planifie la validation automatique des plannings
        // de période chaque jour à 00:15 Europe/Paris — juste après la bascule du jour en métropole.
        $job = new AdminJobCatalog()->find('period-plan-auto-validation');

        self::assertNotNull($job, 'The closed catalog must schedule the period-plan-auto-validation job.');
        self::assertSame('app:period-plans:auto-validate', $job->command);

        // Même lecture que restore-check : sans dernier passage, l'échéance rendue est la plus
        // récente déjà atteinte (rattrapage) — à 00:20 c'est 00:15 du jour même.
        $due = $job->schedule->nextDueAt(new DateTimeImmutable('2026-12-28T00:20:00+01:00'), null);
        self::assertSame('2026-12-28T00:15:00+01:00', $due->format(DateTimeInterface::ATOM));
    }
}
