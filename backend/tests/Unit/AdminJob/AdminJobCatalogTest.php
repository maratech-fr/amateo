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
}
