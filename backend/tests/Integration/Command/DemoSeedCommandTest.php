<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:demo:seed` — le club de DÉMONSTRATION permanent anonymisé (code FFBB
 * ARA9999999, flag `is_demo`). Deux propriétés :
 *
 *  1. sur une base VIERGE (avec `--password`), il crée le club de démo et
 *     l'adhésion de son gestionnaire ;
 *  2. avec `--if-absent`, sur une base qui le porte DÉJÀ, il NE FAIT RIEN
 *     (no-op) : SUCCESS + « déjà présent, rien touché », zéro écriture — c'est le
 *     chemin non destructeur que `make play` emprunte (`IF_ABSENT=1 seed-demo`).
 *     Sans l'option, la commande PURGE le workspace avant de re-seeder (créer OU
 *     RESET) — testé ailleurs via le seeder ; ici on garde le contrat if-absent.
 *
 * Le seeder purge/insère à travers la RLS → connexion SUPERUSER exigée : on
 * bascule `DATABASE_URL` sur l'URL admin AVANT de booter (même patron que
 * `BcclSeedCommandTest` / `BcclSeederIdempotenceTest` : PROCESSUS ISOLÉ car DAMA
 * épingle sa connexion statique au premier usager de `default`, ROLLBACK explicite
 * en tearDown car la transaction statique de DAMA ne couvre pas cette connexion
 * admin reconstruite).
 *
 * NON gatant (pas un axe §7.1) : tourne dans `unit-tests`, ni dans `ci.yml`
 * (job `blocking-tests`) ni dans `docs/testing/blocking-tests.md`.
 */
#[Group('integration')]
final class DemoSeedCommandTest extends KernelTestCase
{
    private const string DEMO_FFBB_CODE = 'ARA9999999';

    private const string DEMO_PASSWORD = 'DemoSeedTest!2026';

    private EntityManagerInterface $em;

    private Connection $connection;

    private CommandTester $tester;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFreshDatabaseSeedsTheDemoClub(): void
    {
        self::assertSame(
            0,
            $this->tester->execute(['--password' => self::DEMO_PASSWORD]),
            $this->tester->getDisplay(),
        );

        $row = $this->connection->fetchAssociative(
            'SELECT id, is_demo FROM club WHERE ffbb_club_code = ?',
            [self::DEMO_FFBB_CODE],
        );
        self::assertNotFalse($row, 'le club de démo est présent après le seed');
        self::assertTrue((bool) $row['is_demo'], 'le club de démo porte bien le flag is_demo');

        $membership = $this->connection->fetchOne(
            'SELECT 1 FROM club_user cu JOIN app_user u ON u.id = cu.user_id WHERE u.email = ? AND cu.club_id = ?',
            ['demo-bccl@amateo.fr', (string) $row['id']],
        );
        self::assertNotFalse($membership, 'le gestionnaire de démo a bien son adhésion au club');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIfAbsentSecondRunIsANoOpAndTouchesNothing(): void
    {
        self::assertSame(
            0,
            $this->tester->execute(['--password' => self::DEMO_PASSWORD]),
            $this->tester->getDisplay(),
        );

        $before = $this->counts();

        self::assertSame(
            0,
            $this->tester->execute(['--if-absent' => true]),
            'un second run --if-absent doit RÉUSSIR sans rien faire',
        );
        self::assertStringContainsString('already present', $this->tester->getDisplay(), 'le no-op nomme la raison');
        self::assertStringContainsString('nothing touched', $this->tester->getDisplay(), 'le no-op dit qu\'il n\'a rien touché');

        $after = $this->counts();
        self::assertSame($before, $after, 'le second run --if-absent n\'écrit RIEN : tous les comptes sont identiques');
    }

    protected function setUp(): void
    {
        $adminUrl = $_SERVER['DATABASE_ADMIN_URL'] ?? getenv('DATABASE_ADMIN_URL');
        self::assertNotFalse($adminUrl, 'DATABASE_ADMIN_URL doit être défini pour seeder en superuser');
        $_SERVER['DATABASE_URL'] = $adminUrl;
        $_ENV['DATABASE_URL'] = $adminUrl;
        putenv('DATABASE_URL=' . $adminUrl);

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();

        // Sans la connexion superuser, la commande échouerait tard sur le garde RLS
        // du seeder — on l'affirme tôt (patron BcclSeederIdempotenceTest).
        self::assertSame('amateo_owner', $this->connection->fetchOne('SELECT current_user'));

        $this->tester = new CommandTester(new Application(self::$kernel)->find('app:demo:seed'));

        // Filet de rollback : tout le seed vit dans cette transaction (la statique
        // DAMA ne couvre pas cette connexion admin reconstruite).
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /**
     * @return array{clubs:int, teams:int, slots:int, reservations:int, clubUsers:int, schedules:int, calendarEntries:int}
     */
    private function counts(): array
    {
        return [
            'clubs' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM club'),
            'teams' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM team'),
            'slots' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_training_slot'),
            'reservations' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation'),
            'clubUsers' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM club_user'),
            'schedules' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM schedule'),
            'calendarEntries' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM calendar_entry'),
        ];
    }
}
