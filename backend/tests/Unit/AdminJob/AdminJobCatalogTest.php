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

        // Jobs déjà passés à leur créneau précédent : chacun rend son créneau de CE dimanche
        // (lecture indépendante de la règle « jamais lancé », couverte par AdminJobScheduleTest).
        $sundayMorning = new DateTimeImmutable('2026-07-19T05:00:00+02:00');
        $restoreDue = $restoreCheck->schedule->nextDueAt($sundayMorning, new DateTimeImmutable('2026-07-12T04:00:00+02:00'));
        self::assertSame('2026-07-19T04:00:00+02:00', $restoreDue->format(DateTimeInterface::ATOM));
        self::assertSame('Sunday', $restoreDue->format('l'));

        // Après le dump nocturne (db-backup, quotidien 01:00) : même dimanche, 01:00 < 04:00.
        $backup = $catalog->find('db-backup');
        self::assertNotNull($backup);
        self::assertTrue(
            $backup->schedule->nextDueAt($sundayMorning, new DateTimeImmutable('2026-07-18T01:00:00+02:00')) < $restoreDue,
            'restore-check must run after the nightly backup.',
        );

        // Après la dernière purge nocturne (purge-exports, quotidien 03:45) : 03:45 < 04:00.
        $lastPurge = $catalog->find('purge-exports');
        self::assertNotNull($lastPurge);
        self::assertTrue(
            $lastPurge->schedule->nextDueAt($sundayMorning, new DateTimeImmutable('2026-07-18T03:45:00+02:00')) < $restoreDue,
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

        // Passé la veille à 00:15 : à 00:20 l'échéance est 00:15 du jour même.
        $due = $job->schedule->nextDueAt(new DateTimeImmutable('2026-12-28T00:20:00+01:00'), new DateTimeImmutable('2026-12-27T00:15:00+01:00'));
        self::assertSame('2026-12-28T00:15:00+01:00', $due->format(DateTimeInterface::ATOM));
    }
}
