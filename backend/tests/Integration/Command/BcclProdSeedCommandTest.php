<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\User;
use App\Seed\BcclSeedProfile;
use DateTimeImmutable;
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
 * avec un gestionnaire 100 % piloté par options/prompt (AUD-SEC-29 : aucun credential ni nom en
 * clair dans le code). Ce que ce test garde, et pourquoi il GATE (`ci.yml` job `blocking-tests` +
 * `docs/testing/blocking-tests.md`) :
 *
 *  1. le gestionnaire (fondateur) naît PRÉ-VÉRIFIÉ (le rail /register est mort sans e-mail sortant
 *     en prod : un compte non vérifié serait un club inaccessible) et rattaché au BON club
 *     (ARA0069036), jamais à la démo (ARA9999999, qui n'est même pas créée) ;
 *  2. le MOT DE PASSE vient de l'option : le mot de passe DEV fictif commité
 *     (`charge-load-test-pwd` de {@see BcclSeedProfile::dev()}) n'authentifie PAS le compte prod —
 *     sans quoi un mot de passe connu du dépôt ouvrirait le club de prod ;
 *  3. la commande REFUSE sans credentials (pas d'e-mail, pas de prénom, pas de mot de passe en
 *     non-interactif) ou avec un mot de passe trop court (< 12) — et n'écrit alors RIEN ;
 *  4. create-only : un second run sur une base qui porte déjà le club NE FAIT RIEN (no-op).
 *
 * Sans ce gate, une régression sur la vérification d'e-mail, sur le rattachement au club, ou une
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
    private const string MANAGER_FIRST_NAME = 'Gestion';
    private const string MANAGER_LAST_NAME = 'Naire';
    private const string MANAGER_PASSWORD = 'gestion-motdepasse-solide';
    /** Le mot de passe dev fictif commité ({@see BcclSeedProfile::dev()}) : il ne doit PAS ouvrir la prod. */
    private const string COMMITTED_DEV_PASSWORD = 'charge-load-test-pwd';

    private EntityManagerInterface $em;

    private Connection $connection;

    private CommandTester $tester;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSeedsAPreVerifiedManagerOnTheRealClubWithTheProvidedPassword(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--first-name' => self::MANAGER_FIRST_NAME,
            '--last-name' => self::MANAGER_LAST_NAME,
            '--password' => self::MANAGER_PASSWORD,
        ]), $this->tester->getDisplay());

        $club = $this->connection->fetchAssociative('SELECT id, is_demo FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]);
        self::assertNotFalse($club, 'le club BCCL réel est posé');
        self::assertFalse((bool) $club['is_demo'], 'le club prod N\'est PAS un club de démonstration');
        $clubId = (string) $club['id'];

        // Le seed prod ne crée JAMAIS le club de démo (identités disjointes).
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::DEMO_FFBB_CODE]), 'le seed prod ne crée pas le club de démo');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $row = $this->connection->fetchAssociative('SELECT id, email_verified_at FROM app_user WHERE email = ?', [self::MANAGER_EMAIL]);
        self::assertNotFalse($row, 'le compte gestionnaire existe');
        self::assertNotNull($row['email_verified_at'], 'le gestionnaire naît PRÉ-VÉRIFIÉ (le rail /register est mort en prod)');

        // Rattaché au BON club, en gestionnaire (admin) actif.
        $membership = $this->connection->fetchAssociative(
            'SELECT role, is_active FROM club_user WHERE user_id = ? AND club_id = ?',
            [(string) $row['id'], $clubId],
        );
        self::assertNotFalse($membership, 'le gestionnaire est rattaché au club BCCL réel');
        self::assertSame('admin', (string) $membership['role'], 'le gestionnaire est admin');
        self::assertTrue((bool) $membership['is_active'], 'l\'adhésion du gestionnaire est active');

        // Le mot de passe DEV fictif du dépôt n'ouvre PAS le compte prod ; le mot de passe fourni, si.
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($hasher->isPasswordValid($user, self::MANAGER_PASSWORD), 'le mot de passe fourni ouvre le compte gestionnaire');
        self::assertFalse($hasher->isPasswordValid($user, self::COMMITTED_DEV_PASSWORD), 'le mot de passe dev fictif commité n\'ouvre PAS le compte prod');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesWithoutEmail(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([], ['interactive' => false]));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est posé quand l\'e-mail manque');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesWithoutAFirstName(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--password' => self::MANAGER_PASSWORD,
        ], ['interactive' => false]));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est posé sans prénom (aucun nom en dur)');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesWithoutPasswordInNonInteractiveMode(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--first-name' => self::MANAGER_FIRST_NAME,
        ], ['interactive' => false]));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est posé sans mot de passe');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesTooShortPassword(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--first-name' => self::MANAGER_FIRST_NAME,
            '--password' => 'court',
        ], ['interactive' => false]));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est posé avec un mot de passe trop court');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSecondRunIsANoOpAndTouchesNothing(): void
    {
        $args = [
            '--email' => self::MANAGER_EMAIL,
            '--first-name' => self::MANAGER_FIRST_NAME,
            '--last-name' => self::MANAGER_LAST_NAME,
            '--password' => self::MANAGER_PASSWORD,
        ];
        self::assertSame(Command::SUCCESS, $this->tester->execute($args), $this->tester->getDisplay());
        $before = $this->counts();

        self::assertSame(Command::SUCCESS, $this->tester->execute($args), 'un second run RÉUSSIT sans rien faire (create-only)');
        self::assertStringContainsString('already present', $this->tester->getDisplay(), 'le no-op nomme la raison');

        self::assertSame($before, $this->counts(), 'le second run (no-op) n\'écrit RIEN : tous les comptes sont identiques');
    }

    /**
     * Anti-usurpation — un compte NON vérifié qu'un tiers aurait créé via /api/register public avec
     * l'e-mail du fondateur (et SON mot de passe) entre le déploiement et le seed : le seeder
     * l'adopterait en silence (mot de passe fourni ignoré), en ferait un gestionnaire du BCCL, et
     * le lien de vérification cliqué par le fondateur activerait le compte de l'attaquant. La
     * commande doit REFUSER, ne rien créer, et ne pas toucher le compte existant.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesWhenAnUnverifiedAccountAlreadyExistsForTheManagerEmail(): void
    {
        $userId = $this->persistUser(self::MANAGER_EMAIL, false, 'mot-de-passe-attaquant');

        self::assertSame(Command::FAILURE, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--first-name' => self::MANAGER_FIRST_NAME,
            '--password' => self::MANAGER_PASSWORD,
        ], ['interactive' => false]));
        self::assertStringContainsString('already exists', $this->tester->getDisplay(), 'le refus nomme le compte préexistant');

        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est créé');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM club_user WHERE user_id = ?', [$userId]), 'aucune adhésion gestionnaire n\'est créée pour le compte adopté');

        // Le compte préexistant n'est PAS touché : toujours non vérifié, mot de passe inchangé.
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($this->connection->fetchOne('SELECT email_verified_at FROM app_user WHERE id = ?', [$userId]), 'le compte reste NON vérifié');
        self::assertTrue($hasher->isPasswordValid($user, 'mot-de-passe-attaquant'), 'le mot de passe du compte préexistant est inchangé (rien n\'a été adopté)');
        self::assertFalse($hasher->isPasswordValid($user, self::MANAGER_PASSWORD), 'le mot de passe fourni au seed n\'a PAS été appliqué');
    }

    /**
     * Anti-usurpation, variante compte DÉJÀ vérifié pour l'e-mail du gestionnaire : même refus,
     * rien créé — le garde ne dépend pas de l'état de vérification.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesWhenAVerifiedAccountAlreadyExistsForTheManagerEmail(): void
    {
        $userId = $this->persistUser(self::MANAGER_EMAIL, true, 'mot-de-passe-tiers');

        self::assertSame(Command::FAILURE, $this->tester->execute([
            '--email' => self::MANAGER_EMAIL,
            '--first-name' => self::MANAGER_FIRST_NAME,
            '--password' => self::MANAGER_PASSWORD,
        ], ['interactive' => false]));
        self::assertStringContainsString('already exists', $this->tester->getDisplay());

        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM club WHERE ffbb_club_code = ?', [self::BCCL_FFBB_CODE]), 'aucun club n\'est créé');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM club_user WHERE user_id = ?', [$userId]), 'aucune adhésion n\'est créée pour le compte préexistant');
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
     * Pose un User global (hors tenant) à l'e-mail donné, comme le ferait un /api/register — avec
     * un mot de passe « choisi par un tiers » et l'état de vérification voulu.
     */
    private function persistUser(string $email, bool $verified, string $plainPassword): string
    {
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Tiers');
        $user->setLastName('Inconnu');
        $user->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $plainPassword));
        if ($verified) {
            $user->setEmailVerifiedAt(new DateTimeImmutable);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user->getId();
    }

    /**
     * @return array{clubs: int, users: int, clubUsers: int, teams: int, slots: int, reservations: int}
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
