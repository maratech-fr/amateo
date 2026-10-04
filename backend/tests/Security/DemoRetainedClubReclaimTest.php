<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\SuperAdmin;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Enum\SeasonStatus;
use App\Security\TotpService;
use App\Service\ClubApprovalService;
use App\Tests\Integration\Admin\AdminDemoResetTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * NR (axe *auth & memberships*) — P4-294 : conserver 14 jours le club démo d'un prospect, puis
 * le reprendre par l'approbation P3-4 du contact officiel. Trois promesses, chacune falsifiée
 * par un cas voisin qui NE doit PAS bouger :
 *
 *  (a) la console « Conserver » est refusée (409) tant que la fenêtre démo est OUVERTE (horloge
 *      réelle), acceptée fenêtre FERMÉE → l'animateur est DÉTACHÉ, l'horloge revient à null,
 *      l'échéance J+14 est posée ;
 *  (b) l'approbation d'un code FFBB dont le seul porteur est un club démo CONSERVÉ le REPREND —
 *      is_demo=false, crédits remis à 0, horloge & conservation effacées, gestionnaire ACTIF, nom
 *      conservé, JAMAIS un 2e club ; un club RÉEL du même code garde la priorité (démo intacte) ;
 *      deux conservés sur le même code → le PLUS RÉCENT est repris ;
 *  (c) un conservé NON expiré survit à la purge nocturne, un conservé expiré est détruit, un
 *      conservé REPRIS (membre actif) n'est jamais touché.
 *
 * (a) écrit par la connexion `admin` (hors transaction DAMA — cleanup manuel en tearDown) comme
 * {@see AdminDemoResetTest} ; (b)/(c) par l'EM sous GUC (DAMA roll-back).
 */
#[Group('phase1')]
#[Group('integration')]
final class DemoRetainedClubReclaimTest extends WebTestCase
{
    use TenantGucTrait;

    private const string PROSPECT_EMAIL = 'demo@amateo.fr';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private string $requestIp;

    private ?string $adminId = null;

    /** @var list<string> */
    private array $adminSeededUserIds = [];

    /** @var list<string> */
    private array $adminSeededClubIds = [];

    // ---------------------------------------------------------------------------------------
    // (a) Conserver : refusé fenêtre ouverte, accepté fenêtre fermée
    // ---------------------------------------------------------------------------------------

    public function testRetainIsRefusedWhileTheWindowIsOpenThenDetachesAndSetsTheDeadline(): void
    {
        $animatorId = $this->adminSeedDemoUser(self::PROSPECT_EMAIL, new DateTimeImmutable('now')->modify('+2 hours'));
        $clubId = $this->adminSeedDemoClub($animatorId, simulatedToday: '2026-03-01');
        $csrf = $this->authenticateAsSuperAdmin('retain-a@example.test');

        // Fenêtre OUVERTE → 409, rien touché.
        $this->json('POST', '/api/admin/demos/prospect/retain', ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('2026-03-01', $this->admin()->fetchOne('SELECT simulated_today FROM club WHERE id = :id', ['id' => $clubId]), 'fenêtre ouverte : rien n\'est modifié');
        self::assertSame(1, (int) $this->admin()->fetchOne('SELECT COUNT(*) FROM club_user WHERE club_id = :id AND user_id = :u', ['id' => $clubId, 'u' => $animatorId]), 'fenêtre ouverte : l\'animateur reste rattaché');

        // Fenêtre FERMÉE → 200, geste atomique.
        $this->admin()->executeStatement('UPDATE app_user SET demo_active_until = NULL WHERE id = :id', ['id' => $animatorId]);
        $this->json('POST', '/api/admin/demos/prospect/retain', ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();

        // Échéance = aujourd'hui (Europe/Paris, horloge réelle) + 14 j, comme le contrôleur.
        $expected = new DateTimeImmutable('now')->setTimezone(new DateTimeZone('Europe/Paris'))->modify('+14 days')->format('Y-m-d');
        self::assertSame($expected, $this->responseBody()['retainedUntil']);
        self::assertSame($expected, $this->admin()->fetchOne('SELECT demo_retained_until FROM club WHERE id = :id', ['id' => $clubId]), 'échéance J+14 posée');
        self::assertNull($this->admin()->fetchOne('SELECT simulated_today FROM club WHERE id = :id', ['id' => $clubId]), 'l\'horloge revient à aujourd\'hui (null)');
        self::assertTrue((bool) $this->admin()->fetchOne('SELECT is_demo FROM club WHERE id = :id', ['id' => $clubId]), 'le club reste un club démo jusqu\'à la reprise');
        self::assertSame(0, (int) $this->admin()->fetchOne('SELECT COUNT(*) FROM club_user WHERE club_id = :id AND user_id = :u', ['id' => $clubId, 'u' => $animatorId]), 'l\'animateur est DÉTACHÉ du club conservé');
    }

    public function testRetainedClubsAreExposedByTheConsoleState(): void
    {
        $animatorId = $this->adminSeedDemoUser(self::PROSPECT_EMAIL, null);
        $clubId = $this->adminSeedDemoClub($animatorId, simulatedToday: null);
        $this->admin()->executeStatement(
            'UPDATE club SET demo_retained_until = :d WHERE id = :id',
            ['d' => new DateTimeImmutable('now')->modify('+10 days')->format('Y-m-d'), 'id' => $clubId],
        );
        $this->authenticateAsSuperAdmin('retain-state@example.test');

        $this->client->request('GET', '/api/admin/demos');
        self::assertResponseIsSuccessful();
        $retained = $this->responseBody()['retained'] ?? null;
        self::assertIsArray($retained);
        $names = array_column($retained, 'name');
        self::assertContains($this->admin()->fetchOne('SELECT name FROM club WHERE id = :id', ['id' => $clubId]), $names, 'le club conservé est listé par la table, pas par adhésion');
    }

    // ---------------------------------------------------------------------------------------
    // (b) L'approbation reprend le club conservé
    // ---------------------------------------------------------------------------------------

    public function testApprovalReclaimsTheRetainedDemoClubWithoutCreatingASecondOne(): void
    {
        $ara = $this->ara();
        $retainedId = $this->seedRetainedDemoViaEm($ara, 'Club Conservé', credits: 7);

        $userId = $this->seedUserViaEm('repreneur-' . uniqid() . '@test.fr');
        $request = $this->openRequestViaEm($userId, $ara, 'Nom Saisi Au Register');

        $club = $this->approval()->approve($request);

        self::assertSame($retainedId, $club->getId(), 'le club conservé est REPRIS — jamais un 2e club');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM club WHERE ffbb_club_code = :a', ['a' => $ara]), 'un seul club porte le code');
        $this->em->clear();
        $fresh = $this->em->getRepository(Club::class)->find($retainedId);
        self::assertInstanceOf(Club::class, $fresh);
        self::assertFalse($fresh->isDemo(), 'le club redevient un vrai club');
        self::assertNull($fresh->getDemoRetainedUntil(), 'l\'échéance de conservation est effacée');
        self::assertNull($fresh->getSimulatedToday(), 'l\'horloge simulée est effacée (CHECK is_demo)');
        self::assertSame(0, $fresh->getOutputCreditsUsed(), 'les crédits Découverte sont remis à zéro');
        self::assertSame('Club Conservé', $fresh->getName(), 'le club garde son nom (le nom saisi au register est ignoré)');

        $membership = $this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $retainedId, 'userId' => $userId]);
        self::assertInstanceOf(ClubUser::class, $membership);
        self::assertTrue($membership->getIsActive(), 'le repreneur est gestionnaire ACTIF');
        self::assertSame(ClubRole::MANAGER->value, $membership->getRole());
    }

    public function testARealClubOfTheSameCodeKeepsPriorityOverARetainedDemo(): void
    {
        $ara = $this->ara();
        // Un vrai club PEUPLÉ (gestionnaire actif) + un club démo conservé, même code.
        $realId = $this->seedRealClubViaEm($ara, 'Vrai Club', $this->seedUserViaEm('mgr-' . uniqid() . '@test.fr'));
        $retainedId = $this->seedRetainedDemoViaEm($ara, 'Démo Conservé', credits: 3);

        $userId = $this->seedUserViaEm('prospect-' . uniqid() . '@test.fr');
        $request = $this->openRequestViaEm($userId, $ara, 'Nom Register');

        $club = $this->approval()->approve($request);

        self::assertSame($realId, $club->getId(), 'le club RÉEL garde la priorité');
        $this->em->clear();
        // La demande devient une adhésion pending sur le VRAI club, jamais sur la démo.
        $onReal = $this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $realId, 'userId' => $userId]);
        self::assertInstanceOf(ClubUser::class, $onReal);
        self::assertFalse($onReal->getIsActive(), 'adhésion pending sur le vrai club');
        self::assertNull($this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $retainedId, 'userId' => $userId]), 'aucune adhésion sur la démo');
        $retained = $this->em->getRepository(Club::class)->find($retainedId);
        self::assertInstanceOf(Club::class, $retained);
        self::assertTrue($retained->isDemo(), 'le club démo conservé reste intact');
        self::assertNotNull($retained->getDemoRetainedUntil(), 'son échéance de conservation survit');
    }

    public function testTwoRetainedClubsOnTheSameCodeReclaimTheMostRecent(): void
    {
        $ara = $this->ara();
        $older = $this->seedRetainedDemoViaEm($ara, 'Démo Ancien', credits: 0, createdAt: new DateTimeImmutable('now')->modify('-5 days'));
        $newer = $this->seedRetainedDemoViaEm($ara, 'Démo Récent', credits: 0, createdAt: new DateTimeImmutable('now')->modify('-1 day'));

        $userId = $this->seedUserViaEm('recent-' . uniqid() . '@test.fr');
        $request = $this->openRequestViaEm($userId, $ara, 'Nom Register');

        $club = $this->approval()->approve($request);

        self::assertSame($newer, $club->getId(), 'le conservé le PLUS RÉCENT est repris');
        $this->em->clear();
        self::assertFalse($this->em->getRepository(Club::class)->find($newer)?->isDemo(), 'le récent redevient réel');
        self::assertTrue($this->em->getRepository(Club::class)->find($older)?->isDemo(), 'l\'ancien reste démo (il expirera à son échéance)');
    }

    // ---------------------------------------------------------------------------------------
    // (c) Purge nocturne des conservés expirés
    // ---------------------------------------------------------------------------------------

    public function testNightlyPurgeDestroysExpiredRetainedClubsAndSparesTheRest(): void
    {
        $notExpired = $this->seedRetainedDemoViaEm($this->ara(), 'Conservé vivant', credits: 0, retainedUntil: new DateTimeImmutable('now')->modify('+5 days'));
        $expired = $this->seedRetainedDemoViaEm($this->ara(), 'Conservé expiré', credits: 0, retainedUntil: new DateTimeImmutable('now')->modify('-1 day'));
        // Conservé expiré MAIS avec un membre actif (repris entre la sélection et la purge) → épargné.
        $reclaimed = $this->seedRetainedDemoViaEm($this->ara(), 'Conservé repris', credits: 0, retainedUntil: new DateTimeImmutable('now')->modify('-1 day'));
        $this->attachActiveMemberViaEm($reclaimed, $this->seedUserViaEm('back-' . uniqid() . '@test.fr'));

        $tester = new CommandTester(new Application(self::$kernel)->find('app:demo:purge-stale'));
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Club::class)->find($notExpired), 'un conservé non expiré survit');
        self::assertNull($this->em->getRepository(Club::class)->find($expired), 'un conservé expiré est détruit');
        self::assertNotNull($this->em->getRepository(Club::class)->find($reclaimed), 'un conservé repris (membre actif) n\'est jamais touché');
    }

    // ---------------------------------------------------------------------------------------
    // Setup / teardown
    // ---------------------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $this->admin()->executeStatement('DELETE FROM club_user WHERE user_id IN (SELECT id FROM app_user WHERE email = :e)', ['e' => self::PROSPECT_EMAIL]);
        $this->admin()->executeStatement('DELETE FROM app_user WHERE email = :e', ['e' => self::PROSPECT_EMAIL]);
    }

    protected function tearDown(): void
    {
        // (a) seeds via la connexion admin — hors transaction DAMA, nettoyage manuel.
        if ([] !== $this->adminSeededClubIds) {
            $this->admin()->executeStatement('DELETE FROM club_user WHERE club_id IN (:ids)', ['ids' => $this->adminSeededClubIds], ['ids' => ArrayParameterType::STRING]);
            $this->admin()->executeStatement('DELETE FROM club WHERE id IN (:ids)', ['ids' => $this->adminSeededClubIds], ['ids' => ArrayParameterType::STRING]);
        }
        if ([] !== $this->adminSeededUserIds) {
            $this->admin()->executeStatement('DELETE FROM app_user WHERE id IN (:ids)', ['ids' => $this->adminSeededUserIds], ['ids' => ArrayParameterType::STRING]);
        }
        if (null !== $this->adminId) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    private function approval(): ClubApprovalService
    {
        $service = self::getContainer()->get(ClubApprovalService::class);
        \assert($service instanceof ClubApprovalService);

        return $service;
    }

    // --- (b)/(c) EM seeding (DAMA roll-back) ---------------------------------------------

    private function seedUserViaEm(string $email): string
    {
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Re');
        $user->setLastName('Preneur');
        $user->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user->getId();
    }

    private function seedRetainedDemoViaEm(string $ffbb, string $name, int $credits, ?DateTimeImmutable $retainedUntil = null, ?DateTimeImmutable $createdAt = null): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = new Club;
        $club->setName($name)->setSlug('ret-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setFfbbClubCode($ffbb);
        $club->setIsDemo(true);
        $club->setDemoRetainedUntil($retainedUntil ?? new DateTimeImmutable('now')->modify('+14 days'));
        $club->setOutputCreditsUsed($credits);
        $this->em->persist($club);
        $this->em->flush();
        if ($createdAt instanceof DateTimeImmutable) {
            $club->setCreatedAt($createdAt);
            $this->em->flush();
        }
        $clubId = $club->getId();
        // Une saison pour que la reprise ne re-seede pas (les données de démo sont déjà là).
        $this->seedSeasonViaEm($clubId);

        return $clubId;
    }

    private function seedRealClubViaEm(string $ffbb, string $name, string $managerId): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = new Club;
        $club->setName($name)->setSlug('real-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setFfbbClubCode($ffbb);
        $club->setIsDemo(false);
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();
        $this->attachActiveMemberViaEm($clubId, $managerId);

        return $clubId;
    }

    private function attachActiveMemberViaEm(string $clubId, string $userId): void
    {
        $this->scopeGucToClub($clubId);
        $membership = new ClubUser()->setClubId($clubId)->setUserId($userId)->setIsActive(true);
        $membership->setRole(ClubRole::MANAGER->value);
        $this->em->persist($membership);
        $this->em->flush();
        $this->clearGuc();
    }

    private function seedSeasonViaEm(string $clubId): void
    {
        $this->scopeGucToClub($clubId);
        $season = new Season()->setClubId($clubId)->setName('Saison démo')
            ->setStartDate(new DateTimeImmutable(date('Y') . '-07-16'))
            ->setEndDate(new DateTimeImmutable((date('Y') + 1) . '-07-14'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        $this->clearGuc();
    }

    private function openRequestViaEm(string $userId, string $ara, string $clubName): ClubCreationRequest
    {
        $request = new ClubCreationRequest;
        $request->setUserId($userId);
        $request->setAra($ara);
        $request->setClubName($clubName);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    // --- (a) admin-connection seeding (manual cleanup) -----------------------------------

    private function adminSeedDemoUser(string $email, ?DateTimeImmutable $activeUntil): string
    {
        $userId = Uuid::v4()->toRfc4122();
        $this->adminSeededUserIds[] = $userId;
        $this->admin()->executeStatement(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name, demo_active_until, is_demo) VALUES (:id, 1, NOW(), NOW(), :email, :hash, :fn, :ln, :until, TRUE)',
            ['id' => $userId, 'email' => $email, 'hash' => 'x', 'fn' => 'Démo', 'ln' => 'Amateo', 'until' => $activeUntil?->format(\DATE_ATOM)],
        );

        return $userId;
    }

    private function adminSeedDemoClub(string $ownerUserId, ?string $simulatedToday): string
    {
        $clubId = Uuid::v4()->toRfc4122();
        $this->adminSeededClubIds[] = $clubId;
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed, is_demo, simulated_today)'
            . ' VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :locale, FALSE, TRUE, :today)',
            ['id' => $clubId, 'name' => 'Démo ' . substr($clubId, 0, 8), 'slug' => 'demo-' . substr($clubId, 0, 8), 'tz' => 'Europe/Paris', 'locale' => 'fr', 'today' => $simulatedToday],
        );
        $this->admin()->executeStatement(
            'INSERT INTO club_user (id, version, created_at, updated_at, joined_at, club_id, user_id, role, is_active) VALUES (:id, 1, NOW(), NOW(), NOW(), :club, :user, :role, TRUE)',
            ['id' => Uuid::v4()->toRfc4122(), 'club' => $clubId, 'user' => $ownerUserId, 'role' => 'manager'],
        );

        return $clubId;
    }

    private function authenticateAsSuperAdmin(string $email): string
    {
        $password = 'VeryStrongPassword!';
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, $password));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, :enabled, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret(), 'enabled' => true],
            ['enabled' => ParameterType::BOOLEAN],
        );

        $this->jsonBody('POST', '/api/admin/auth/password', ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
        $this->jsonBody('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();
        $csrf = $this->responseBody()['csrfToken'] ?? null;
        self::assertIsString($csrf);

        return $csrf;
    }

    /** @param array<string, string> $server */
    private function json(string $method, string $uri, array $server = []): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->requestIp, ...$server], '{}');
    }

    /** @param array<string, mixed> $body */
    private function jsonBody(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->requestIp], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /** Un code FFBB syntaxiquement valide et unique par test (3 lettres + 7 chiffres). */
    private function ara(): string
    {
        return 'ZZZ' . str_pad((string) random_int(0, 9_999_999), 7, '0', \STR_PAD_LEFT);
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
