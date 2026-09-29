<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\User;
use App\Seed\BcclSeedProfile;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR BLOQUANT — axe auth & memberships. `app:bccl:seed-prod` pose le club BCCL RÉEL en PRODUCTION
 * avec des comptes gestionnaires 100 % pilotés par options/prompt (jamais de credentials en clair
 * dans le code). Ce que ce test garde, et pourquoi il GATE (`ci.yml` job `blocking-tests` +
 * `docs/testing/blocking-tests.md`) :
 *
 *  1. les deux gestionnaires (fondateur + Nicolas) naissent PRÉ-VÉRIFIÉS (le rail /register est
 *     mort sans e-mail sortant en prod : un compte non vérifié serait un club inaccessible) et
 *     rattachés au BON club (ARA0069036), jamais à la démo (ARA9999999, qui n'est même pas créée) ;
 *  2. les MOTS DE PASSE viennent des options : les mots de passe DEV (« maraboubccl »/« NicolasB »
 *     de {@see BcclSeedProfile::dev()}) n'authentifient PAS les comptes prod — sans quoi
 *     un mot de passe connu du dépôt ouvrirait le club de prod ;
 *  3. la commande REFUSE sans credentials (pas d'e-mails, pas de mot de passe en non-interactif) ou
 *     avec un mot de passe trop court (< 12) — et n'écrit alors RIEN ;
 *  4. create-only : un second run sur une base qui porte déjà le club NE FAIT RIEN (no-op).
 *
 * Sans ce gate, un régression sur la vérification d'e-mail, sur le rattachement au club, ou une
 * réintroduction de credentials en dur passerait tous les checks requis de `main`.
 *
 * Comme tout seed, la commande traverse la RLS et exige la connexion SUPERUSER : on bascule
 * `DATABASE_URL` sur l'URL admin AVANT de booter (patron {@see BcclSeedCommandTest}), d'où le
 * PROCESSUS ISOLÉ (DAMA épingle sa connexion statique au premier usager) et le ROLLBACK explicite.
 */
#[Group('phase1')]
#[Group('integration')]
final class BcclProdSeedCommandTest extends KernelTestCase
{
    private const string BCCL_FFBB_CODE = 'ARA0069036';
    private const string DEMO_FFBB_CODE = 'ARA9999999';
    private const string MANAGER_EMAIL = 'gestion@monclub.example';
    private const string CO_MANAGER_EMAIL = 'nicolas@monclub.example';
    private const string MANAGER_PASSWORD = 'gestion-motdepasse-solide';
    private const string CO_MANAGER_PASSWORD = 'nicolas-motdepasse-costaud';

    private EntityManagerInterface $em;

    private Connection $connection;

    private CommandTester $tester;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSeedsPreVerifiedManagersOnTheRealClubWithProvidedPasswords(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--co-email' => self::CO_MANAGER_EMAIL,
            '--password' => self::MANAGER_PASSWORD,
            '--co-password' => self::CO_MANAGER_PASSWORD,
        ]), $this->tester->getDisplay());

        $club = $this->connection->fetchAssociative('SELECT id, is_demo FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]);
        self::assertNotFalse($club, 'le club BCCL réel est posé');
        self::assertFalse((bool) $club['is_demo'], 'le club prod N\'est PAS un club de démonstration');
        $clubId = (string) $club['id'];

        // Le seed prod ne crée JAMAIS le club de démo (identités disjointes).
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::DEMO_FFBB_CODE]), 'le seed prod ne crée pas le club de démo');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([[self::MANAGER_EMAIL, self::MANAGER_PASSWORD], [self::CO_MANAGER_EMAIL, self::CO_MANAGER_PASSWORD]] as [$email, $password]) {
            $row = $this->connection->fetchAssociative('SELECT id, email_verified_at FROM app_user WHERE email = ?', [$email]);
            self::assertNotFalse($row, \sprintf('le compte %s existe', $email));
            self::assertNotNull($row['email_verified_at'], \sprintf('%s naît PRÉ-VÉRIFIÉ (le rail /register est mort en prod)', $email));

            // Rattaché au BON club, en gestionnaire (admin) actif.
            $membership = $this->connection->fetchAssociative(
                'SELECT role, is_active FROM club_user WHERE user_id = ? AND club_id = ?',
                [(string) $row['id'], $clubId],
            );
            self::assertNotFalse($membership, \sprintf('%s est rattaché au club BCCL réel', $email));
            self::assertSame('admin', (string) $membership['role'], \sprintf('%s est gestionnaire (admin)', $email));
            self::assertTrue((bool) $membership['is_active'], \sprintf('l\'adhésion de %s est active', $email));

            // Les mots de passe DEV du dépôt n'ouvrent PAS les comptes prod ; le mot de passe fourni, si.
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(User::class, $user);
            self::assertTrue($hasher->isPasswordValid($user, $password), \sprintf('le mot de passe fourni ouvre le compte %s', $email));
            self::assertFalse($hasher->isPasswordValid($user, 'maraboubccl'), \sprintf('le mot de passe dev « maraboubccl » n\'ouvre PAS %s', $email));
            self::assertFalse($hasher->isPasswordValid($user, 'NicolasB'), \sprintf('le mot de passe dev « NicolasB » n\'ouvre PAS %s', $email));
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesWithoutEmails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([], ['interactive' => false]));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est posé quand les e-mails manquent');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesWithoutPasswordInNonInteractiveMode(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--co-email' => self::CO_MANAGER_EMAIL,
        ], ['interactive' => false]));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est posé sans mot de passe');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesTooShortPassword(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--co-email' => self::CO_MANAGER_EMAIL,
            '--password' => 'court',
            '--co-password' => self::CO_MANAGER_PASSWORD,
        ], ['interactive' => false]));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est posé avec un mot de passe trop court');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSecondRunIsANoOpAndTouchesNothing(): void
    {
        $args = [
            '--email' => self::MANAGER_EMAIL,
            '--co-email' => self::CO_MANAGER_EMAIL,
            '--password' => self::MANAGER_PASSWORD,
            '--co-password' => self::CO_MANAGER_PASSWORD,
        ];
        self::assertSame(Command::SUCCESS, $this->tester->execute($args), $this->tester->getDisplay());
        $before = $this->counts();

        self::assertSame(Command::SUCCESS, $this->tester->execute($args), 'un second run RÉUSSIT sans rien faire (create-only)');
        self::assertStringContainsString('already present', $this->tester->getDisplay(), 'le no-op nomme la raison');

        self::assertSame($before, $this->counts(), 'le second run (no-op) n\'écrit RIEN : tous les comptes sont identiques');
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

        // Sans la connexion superuser, la commande échouerait tard sur le garde RLS du seeder.
        self::assertSame('amateo_owner', $this->connection->fetchOne('SELECT current_user'));

        $this->tester = new CommandTester(new Application(self::$kernel)->find('app:bccl:seed-prod'));

        // Filet de rollback : tout le seed vit dans cette transaction (la statique DAMA ne couvre
        // pas cette connexion admin reconstruite).
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
     * @return array{clubs:int, users:int, clubUsers:int, teams:int, slots:int, reservations:int}
     */
    private function counts(): array
    {
        return [
            'clubs' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM club'),
            'users' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_user'),
            'clubUsers' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM club_user'),
            'teams' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM team'),
            'slots' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_training_slot'),
            'reservations' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation'),
        ];
    }
}
