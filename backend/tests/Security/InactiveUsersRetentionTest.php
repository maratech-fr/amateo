<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\User;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * RGPD PR-3 non-regression (axe auth & memberships) : rétention des comptes.
 *
 * (a) inactif > 23 mois → préavis email + inactivityWarnedAt ;
 * (b) inactif > 24 mois + préavis ≥ 1 MOIS (la promesse de l'email) → anonymisé (routine erase : club
 *     orphelin programmé) ; JAMAIS anonymisé sans préavis suffisant ;
 * (c) un login réussi remet lastLoginAt et annule le préavis ;
 * (d) --dry-run n'écrit rien et n'envoie rien ;
 * (e) P4-304 — un compte de DÉMONSTRATION (is_demo) est HORS rétention : il vit à une
 *     horloge simulée (souvent passée) qui le ferait paraître inactif depuis « 25 mois »
 *     dès sa création. Falsifiable : retirer `->andWhere('u.isDemo = false')` de warn()
 *     fait prévenir le démo-candidat ; de erase() fait anonymiser le démo déjà prévenu.
 */
#[Group('phase1')]
#[Group('integration')]
final class InactiveUsersRetentionTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testInactiveUserIsWarnedThenAnonymized(): void
    {
        [, $userId] = $this->registerVerified('INAA');
        $em = $this->em();

        // 25 mois d'inactivité d'emblée : le MÊME run doit warner SANS anonymiser
        // (garde same-run). last_login_at aussi : l'authenticator JWT trace
        // l'activité à chaque requête (le register l'a posée à maintenant).
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\' WHERE id = :id',
            ['id' => $userId],
        );

        // Étage 1 : préavis — et PAS d'anonymisation dans le même run.
        $tester = $this->commandTester();
        self::assertSame(0, $tester->execute([]));
        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getInactivityWarnedAt(), 'préavis posé');
        self::assertNull($user->getAnonymizedAt(), 'same-run : jamais warn PUIS erase dans la même exécution');

        // Étage 2 : préavis vieux de 5 semaines (> 1 mois promis) → anonymisation.
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET inactivity_warned_at = NOW() - INTERVAL \'5 weeks\' WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(0, $this->commandTester()->execute([]));
        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getAnonymizedAt(), 'anonymisé : 24 mois passés + préavis ≥ 1 mois');
        self::assertStringEndsWith('@anonymized.invalid', $user->getEmail());

        // La routine erase a programmé la purge du club orphelin.
        $clubId = $em->getConnection()->fetchOne('SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1', ['uid' => $userId]);
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNotNull($club->getErasureScheduledAt(), 'club orphelin programmé (+30 j)');
    }

    public function testFreshWarningBlocksAnonymizationEvenPast24Months(): void
    {
        [, $userId] = $this->registerVerified('INAB');
        $em = $this->em();

        // 25 mois d'inactivité mais préavis d'il y a 15 jours : l'email promet
        // UN MOIS — le compte garde son délai complet (cron down puis relancé).
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\', inactivity_warned_at = NOW() - INTERVAL \'15 days\' WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(0, $this->commandTester()->execute([]));
        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->getAnonymizedAt(), 'préavis < 1 mois → pas d\'anonymisation');
    }

    public function testSuccessfulLoginResetsInactivityTracking(): void
    {
        [, $userId, $email] = $this->registerVerified('INAC');
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET inactivity_warned_at = NOW() - INTERVAL \'5 days\' WHERE id = :id',
            ['id' => $userId],
        );

        $this->client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => 'Password123!'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getLastLoginAt(), 'login trace lastLoginAt');
        self::assertNull($user->getInactivityWarnedAt(), 'login annule le préavis');
    }

    public function testDryRunWritesNothing(): void
    {
        [, $userId] = $this->registerVerified('INAD');
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\' WHERE id = :id',
            ['id' => $userId],
        );

        self::assertSame(0, $this->commandTester()->execute(['--dry-run' => true]));
        $em->clear();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->getInactivityWarnedAt(), 'dry-run : pas de préavis posé');
        self::assertNull($user->getAnonymizedAt(), 'dry-run : pas d\'anonymisation');
    }

    public function testDemoAccountsAreNeverPurgedForInactivity(): void
    {
        // Témoin NON démo, 25 mois d'inactivité + préavis vieux de 2 mois → DOIT être anonymisé.
        [, $controlId] = $this->registerVerified('INAE');
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\', inactivity_warned_at = NOW() - INTERVAL \'2 months\' WHERE id = :id',
            ['id' => $controlId],
        );

        // Démo candidat au PRÉAVIS (sans stamp) et démo candidat à l'ANONYMISATION
        // (préavis de 2 mois) : les deux étages du cron doivent les écarter.
        $demoToWarnId = $this->seedDemoUser(withWarning: false);
        $demoToEraseId = $this->seedDemoUser(withWarning: true);

        self::assertSame(0, $this->commandTester()->execute([]));
        $em->clear();

        $control = $em->getRepository(User::class)->find($controlId);
        self::assertInstanceOf(User::class, $control);
        self::assertNotNull($control->getAnonymizedAt(), 'le témoin non-démo est bien anonymisé (le cron fait son travail)');

        $demoToWarn = $em->getRepository(User::class)->find($demoToWarnId);
        self::assertInstanceOf(User::class, $demoToWarn);
        self::assertNull($demoToWarn->getInactivityWarnedAt(), 'un compte démo n\'est JAMAIS prévenu pour inactivité');
        self::assertNull($demoToWarn->getAnonymizedAt(), 'un compte démo n\'est jamais anonymisé');

        $demoToErase = $em->getRepository(User::class)->find($demoToEraseId);
        self::assertInstanceOf(User::class, $demoToErase);
        self::assertNull($demoToErase->getAnonymizedAt(), 'un compte démo déjà prévenu n\'est JAMAIS anonymisé');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** Compte de démonstration inactif depuis 25 mois (hydraté puis rétro-daté en SQL). */
    private function seedDemoUser(bool $withWarning): string
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        $uid = substr(md5(uniqid('', true)), 0, 8);

        $user = new User;
        $user->setEmail('demo-inactif-' . $uid . '@test.fr');
        $user->setFirstName('Démo');
        $user->setLastName('Inactif');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $user->setIsDemo(true);
        $em->persist($user);
        $em->flush();
        $id = $user->getId();

        $warning = $withWarning ? ', inactivity_warned_at = NOW() - INTERVAL \'2 months\'' : '';
        $em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\'' . $warning . ' WHERE id = :id',
            ['id' => $id],
        );
        $em->clear();

        return $id;
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:users:purge-inactive'));
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, email]
     */
    private function registerVerified(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'In', 'lastName' => 'Actif', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $email];
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
