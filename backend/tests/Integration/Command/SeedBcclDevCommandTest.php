<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Seed\BcclSeedProfile;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:seed:bccl-dev` — le raccourci CREATE-ONLY qui pose le club dev BCCL RÉEL
 * (identités réelles, sans flag démo) pour `make play`. Deux propriétés font sa
 * sûreté :
 *
 *  1. sur une base VIERGE, il seede le vrai BCCL (code FFBB ARA0069036), club
 *     NON démo, avec l'adhésion du gestionnaire principal et le plan SEASON
 *     pointant la transcription COMPLETED du planning réel ;
 *  2. sur une base qui le porte DÉJÀ, il REFUSE tout net (create-only, jamais
 *     reset) et n'écrit RIEN — la base de jeu du fondateur est son travail.
 *
 * Comme le seeder purge/insère à travers la RLS, il exige la connexion SUPERUSER :
 * on bascule `DATABASE_URL` sur l'URL admin AVANT de booter, exactement comme
 * `BcclSeederIdempotenceTest` (d'où le PROCESSUS ISOLÉ — DAMA épingle sa connexion
 * statique au premier usager de `default` — et le ROLLBACK explicite en tearDown :
 * la transaction statique de DAMA ne couvre pas nos écritures sur cette connexion
 * reconstruite, le seed massif ne doit laisser aucune trace).
 *
 * NON gatant (pas un axe §7.1) : tourne dans `unit-tests`, ni dans `ci.yml`
 * (job `blocking-tests`) ni dans `docs/testing/blocking-tests.md`. Ce qu'il garde :
 * le contrat create-only de la commande (seede si absent, refuse sinon, zéro
 * écriture au refus) — la propriété non destructrice sur laquelle `make play`
 * s'appuie.
 */
#[Group('integration')]
final class SeedBcclDevCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private Connection $connection;

    private CommandTester $tester;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFreshDatabaseSeedsTheRealBcclClub(): void
    {
        $profile = BcclSeedProfile::dev();

        self::assertSame(0, $this->tester->execute([]), $this->tester->getDisplay());

        $row = $this->connection->fetchAssociative(
            'SELECT id, is_demo FROM club WHERE ffbb_club_code = ?',
            [$profile->ffbbCode],
        );
        self::assertNotFalse($row, 'le club dev BCCL réel est présent après le seed');
        self::assertFalse((bool) $row['is_demo'], 'le club dev réel N\'est PAS un club de démonstration');
        $clubId = (string) $row['id'];

        $membership = $this->connection->fetchOne(
            'SELECT 1 FROM club_user cu JOIN app_user u ON u.id = cu.user_id WHERE u.email = ? AND cu.club_id = ?',
            [$profile->managerEmail, $clubId],
        );
        self::assertNotFalse($membership, 'le gestionnaire principal a bien son adhésion au club');

        // Le plan SEASON pointe la transcription COMPLETED du planning réel (90
        // créneaux) — même requête que BcclSeederIdempotenceTest : le club dev
        // ouvre sur le planning réel, sans appeler le solveur.
        $season = $this->connection->fetchAssociative(
            'SELECT s.status, s.solver_version, '
            . '(SELECT COUNT(*) FROM schedule_slot_template t WHERE t.schedule_id = s.id) AS slot_count '
            . 'FROM schedule_plan sp JOIN schedule s ON s.id = sp.chosen_schedule_id '
            . 'WHERE sp.season_id = (SELECT id FROM season WHERE club_id = ? AND name = \'2026-2027\') '
            . 'AND sp.type = \'SEASON\'',
            [$clubId],
        );
        self::assertNotFalse($season, 'le plan SEASON du club dev pointe une version choisie');
        self::assertSame('COMPLETED', (string) $season['status'], 'la version pointée est COMPLETED');
        self::assertSame('seed-transcription', (string) $season['solver_version'], 'la provenance est la transcription du seed');
        self::assertSame(90, (int) $season['slot_count'], 'la transcription pose exactement 90 créneaux');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSecondRunIsRefusedAndTouchesNothing(): void
    {
        self::assertSame(0, $this->tester->execute([]), $this->tester->getDisplay());

        $before = $this->counts();

        self::assertNotSame(0, $this->tester->execute([]), 'un second run doit ÉCHOUER (create-only, jamais reset)');
        self::assertStringContainsString('already exists', $this->tester->getDisplay(), 'le refus nomme la raison');
        self::assertStringContainsString('nothing touched', $this->tester->getDisplay(), 'le refus dit qu\'il n\'a rien touché');

        $after = $this->counts();
        self::assertSame($before, $after, 'le second run (refusé) n\'écrit RIEN : tous les comptes sont identiques');
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

        $this->tester = new CommandTester(new Application(self::$kernel)->find('app:seed:bccl-dev'));

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
