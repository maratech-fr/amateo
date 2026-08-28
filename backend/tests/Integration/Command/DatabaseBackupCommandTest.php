<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Service\AdminDataFreshnessService;
use App\Service\BackupCoverage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Backups pilotés par l'ACTIVITÉ (décision fondateur 2026-07-18) : dump seulement
 * quand quelque chose a bougé depuis le dernier, rétention 14, et la preuve de
 * restauration (app:db:restore-check) — « un backup jamais restauré n'existe pas ».
 * La ligne « Sauvegarde » du board suit la même règle (base dormante = jamais rouge).
 */
#[Group('integration')]
final class DatabaseBackupCommandTest extends KernelTestCase
{
    private string $dir;

    /** @var list<string> */
    private array $clubIds = [];

    public function testActivityDrivenDumpSkipThenForceAndRetention(): void
    {
        $this->seedActivity();
        $application = new Application(self::$kernel);
        $backup = fn (): CommandTester => new CommandTester($application->find('app:db:backup'));

        // Activité présente, aucun dump → dump créé.
        $first = $backup();
        self::assertSame(Command::SUCCESS, $first->execute(['--dir' => $this->dir]), $first->getDisplay());
        self::assertCount(1, $this->dumps(), 'un dump est écrit quand il y a de l\'activité');

        // Re-run : le dump est plus récent que l'activité → SKIP (pas de 2e fichier).
        $second = $backup();
        self::assertSame(Command::SUCCESS, $second->execute(['--dir' => $this->dir], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        self::assertStringContainsString('skipping', $second->getDisplay());
        self::assertCount(1, $this->dumps(), 'sans activité nouvelle, aucun dump supplémentaire');

        // --force : dump même sans activité nouvelle.
        $forced = $backup();
        self::assertSame(Command::SUCCESS, $forced->execute(['--dir' => $this->dir, '--force' => true]), $forced->getDisplay());
        self::assertGreaterThanOrEqual(2, \count($this->dumps()));

        // Rétention : 15 fichiers factices ANTÉRIEURS + un run --force → il reste 14.
        for ($i = 1; $i <= 15; ++$i) {
            touch(\sprintf('%s/amateo-2020%02d01-000000.dump', $this->dir, ($i % 12) + 1));
        }
        $retention = $backup();
        self::assertSame(Command::SUCCESS, $retention->execute(['--dir' => $this->dir, '--force' => true]));
        self::assertCount(14, $this->dumps(), 'la rétention garde les 14 dumps les plus récents');
    }

    public function testBootstrapDumpsADataBearingBaseWithoutActivitySignal(): void
    {
        // Revue #258, finding 3 : une base qui CONTIENT des données mais sans aucun
        // signal d'activité (déploiement existant, audit purgé, jamais de login depuis
        // la colonne) doit recevoir un PREMIER dump — « aucun signal » ≠ « rien à protéger ».
        // Hermétique (round 2, finding 6) : neutraliser tout signal résiduel du process —
        // la précondition du bootstrap est « latestActivity() null sur TOUTE la base ».
        $this->admin()->executeStatement('UPDATE club SET last_activity_at = NULL');
        $this->admin()->executeStatement('DELETE FROM solver_metrics');
        $this->admin()->executeStatement('DELETE FROM audit_log');
        $this->seedActivity(withActivity: false);
        $application = new Application(self::$kernel);

        $bootstrap = new CommandTester($application->find('app:db:backup'));
        self::assertSame(Command::SUCCESS, $bootstrap->execute(['--dir' => $this->dir]), $bootstrap->getDisplay());
        self::assertStringContainsString('bootstrap', $bootstrap->getDisplay());
        self::assertCount(1, $this->dumps(), 'le bootstrap protège une base à données sans signal');

        // Un dump existe désormais et toujours aucun signal → skip normal.
        $second = new CommandTester($application->find('app:db:backup'));
        self::assertSame(Command::SUCCESS, $second->execute(['--dir' => $this->dir]));
        self::assertCount(1, $this->dumps());
    }

    public function testRestoreCheckProvesTheLatestDumpIsRestorable(): void
    {
        $this->seedActivity();
        $application = new Application(self::$kernel);
        $backup = new CommandTester($application->find('app:db:backup'));
        self::assertSame(Command::SUCCESS, $backup->execute(['--dir' => $this->dir, '--force' => true]), $backup->getDisplay());

        $check = new CommandTester($application->find('app:db:restore-check'));
        self::assertSame(Command::SUCCESS, $check->execute(['--dir' => $this->dir]), $check->getDisplay());
        self::assertStringContainsString('The backup is real', $check->getDisplay());

        // Aucune base jetable ne survit au check.
        $leftovers = $this->admin()->fetchFirstColumn('SELECT datname FROM pg_database WHERE datname LIKE \'amateo_restore_%\'');
        self::assertSame([], $leftovers, 'la base jetable est détruite après le check');
    }

    public function testFreshnessBackupRowFollowsActivityNotTheCalendar(): void
    {
        $this->seedActivity();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        $clock = self::getContainer()->get(ClockInterface::class);
        $coverage = self::getContainer()->get(BackupCoverage::class);
        \assert($registry instanceof ManagerRegistry && $clock instanceof ClockInterface && $coverage instanceof BackupCoverage);
        $service = new AdminDataFreshnessService($registry, $clock, $coverage, $this->dir);

        // Activité mais AUCUN dump → périmé (fail-visible).
        self::assertTrue($this->backupRow($service)['stale'], 'de l\'activité jamais sauvegardée doit être rouge');

        // Dump frais (à l'instant) → couvert, vert.
        touch($this->dir . '/amateo-20990101-000000.dump');
        self::assertFalse($this->backupRow($service)['stale'], 'un dump frais couvre l\'activité');

        // Dump VIEUX (> 26 h) avec activité plus récente → rouge.
        touch($this->dir . '/amateo-20990101-000000.dump', time() - 60 * 60 * 48);
        self::assertTrue($this->backupRow($service)['stale'], 'activité non couverte depuis > 26 h = rouge');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir() . '/backup-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->dumps() as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        if ([] !== $this->clubIds) {
            $this->admin()->executeStatement('DELETE FROM club WHERE id IN (:ids)', ['ids' => $this->clubIds], ['ids' => ArrayParameterType::STRING]);
        }
        parent::tearDown();
    }

    /** @return array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool} */
    private function backupRow(AdminDataFreshnessService $service): array
    {
        foreach ($service->referentials() as $row) {
            if ('db-backup' === $row['key']) {
                return $row;
            }
        }
        self::fail('missing db-backup row');
    }

    /** @return list<string> */
    private function dumps(): array
    {
        return glob($this->dir . '/amateo-*.dump') ?: [];
    }

    /** L'activité = un club avec last_activity_at récent ; withActivity: false = club à
     *  données SANS signal (bootstrap). */
    private function seedActivity(bool $withActivity = true): void
    {
        $clubId = Uuid::v4()->toRfc4122();
        $this->clubIds[] = $clubId;
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed, last_activity_at) VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :locale, FALSE, ' . ($withActivity ? 'NOW()' : 'NULL') . ')',
            ['id' => $clubId, 'name' => 'Club backup', 'slug' => 'bkp-' . strtolower(substr(md5(uniqid('', true)), 0, 10)), 'tz' => 'Europe/Paris', 'locale' => 'fr'],
        );
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
