<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubUser;
use App\Entity\SuperAdmin;
use App\Entity\User;
use App\Security\TotpService;
use App\Tests\TenantGucTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * NR P3-4 PR B (surface /api/admin — la plus sensible) : l'arbitrage superadmin
 * des demandes de création (« le superadmin peut valider si besoin » — y compris
 * EXPIRÉES, le lien public étant mort) et l'activation d'une adhésion pending
 * (« gestionnaire parti fâché, pas de passation » — décisions fondateur
 * 2026-08-05). Gardes : firewall admin (401 sans session), CSRF (403 sans
 * token), et l'approbation console PROVISIONNE comme la page publique.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminClubRequestTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private string $requestIp;

    private string $adminId;

    /** @var list<string> */
    private array $clubAras = [];

    /** @var list<string> */
    private array $userIds = [];

    public function testSurfaceIsUnreachableWithoutAnAdminSession(): void
    {
        $this->client->request('GET', '/api/admin/club-requests');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/admin/club-requests/' . Uuid::v4()->toRfc4122() . '/decision');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/admin/pending-memberships/' . Uuid::v4()->toRfc4122() . '/activate');
        self::assertResponseStatusCodeSame(401);
    }

    public function testExpiredRequestStaysActionableAndConsoleApprovalProvisions(): void
    {
        [$secret] = $this->createSuperAdmin('club-req@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('club-req@example.test', 'VeryStrongPassword!', $secret);

        $request = $this->seedRequest(status: ClubCreationRequest::STATUS_EXPIRED);

        // Sans CSRF → 403, rien exécuté.
        $this->client->request('POST', "/api/admin/club-requests/{$request->getId()}/decision", [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->requestIp], (string) json_encode(['decision' => 'approve']));
        self::assertResponseStatusCodeSame(403);

        // La liste sert pending ET expirées (la console garde la main après J+7).
        $this->client->request('GET', '/api/admin/club-requests', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['items'], 'id');
        self::assertContains($request->getId(), $ids);

        // Approbation console d'une demande EXPIRÉE → le club naît, provisionné.
        $this->client->request('POST', "/api/admin/club-requests/{$request->getId()}/decision", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => $this->requestIp], (string) json_encode(['decision' => 'approve']));
        self::assertResponseIsSuccessful();
        $clubId = json_decode((string) $this->client->getResponse()->getContent(), true)['clubId'];
        // Lectures sur la connexion PAR DÉFAUT : sous DAMA, l'écriture d'approbation
        // vit dans la transaction de test — la connexion admin ne la voit pas.
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM club WHERE id = :id', ['id' => $clubId]));
        $this->scopeGucToClub($clubId);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM season WHERE club_id = :id', ['id' => $clubId]), 'provisionné comme la page publique');

        // Déjà décidée → 404 (même anonymat que la page publique).
        $this->client->request('POST', "/api/admin/club-requests/{$request->getId()}/decision", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => $this->requestIp], (string) json_encode(['decision' => 'approve']));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPendingMembershipCanBeActivatedByTheSuperAdmin(): void
    {
        [$secret] = $this->createSuperAdmin('memb@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('memb@example.test', 'VeryStrongPassword!', $secret);

        // Un club + une adhésion pending, semés par la porte admin (cross-tenant).
        $clubId = Uuid::v4()->toRfc4122();
        $ara = 'ADH' . strtoupper(substr(md5(uniqid()), 0, 7));
        $this->clubAras[] = $ara;
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed, ffbb_club_code) VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :loc, FALSE, :ara)',
            ['id' => $clubId, 'name' => 'Club Adhésion', 'slug' => 'club-adh-' . strtolower($ara), 'tz' => 'Europe/Paris', 'loc' => 'fr', 'ara' => $ara],
        );
        // Utilisateur semé par la porte ADMIN : la liste lit par cette connexion,
        // et un user en transaction DAMA (EM) serait invisible à son JOIN.
        $userId = Uuid::v4()->toRfc4122();
        $this->userIds[] = $userId;
        $this->admin()->executeStatement(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (:id, 1, NOW(), NOW(), :email, :hash, :fn, :ln)',
            ['id' => $userId, 'email' => 'memb-' . strtolower(substr($ara, 0, 8)) . '@t.fr', 'hash' => 'x', 'fn' => 'Mem', 'ln' => 'Ber'],
        );
        $membershipId = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO club_user (id, version, created_at, updated_at, joined_at, club_id, user_id, role, is_active) VALUES (:id, 1, NOW(), NOW(), NOW(), :club, :user, :role, FALSE)',
            ['id' => $membershipId, 'club' => $clubId, 'user' => $userId, 'role' => 'admin'],
        );
        // Chantier Membre (PR 1) : le club a un gestionnaire actif — la console n'active pas
        // une adhésion sur un club SANS gestionnaire (testActivateIsRefusedOnAManagerlessClub).
        $this->seedActiveManager($clubId, $ara);

        // Listée…
        $this->client->request('GET', '/api/admin/pending-memberships', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['items'], 'id');
        self::assertContains($membershipId, $ids);

        // … activée (le déblocage sans passation), une seule fois.
        $this->client->request('POST', "/api/admin/pending-memberships/{$membershipId}/activate", [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        self::assertTrue((bool) $this->admin()->fetchOne('SELECT is_active FROM club_user WHERE id = :id', ['id' => $membershipId]));
        $this->client->request('POST', "/api/admin/pending-memberships/{$membershipId}/activate", [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseStatusCodeSame(404, 'déjà active → 404, pas un no-op silencieux');

        // P1-1 PR B : un membre DÉSACTIVÉ par son club (deactivated_at posé) n'est
        // ni listé ni « activable » par la console — la décision du club se respecte,
        // seul le geste club POST /api/memberships/{id}/reactivate le restaure.
        $this->admin()->executeStatement(
            'UPDATE club_user SET is_active = FALSE, deactivated_at = NOW() WHERE id = :id',
            ['id' => $membershipId],
        );
        $this->client->request('GET', '/api/admin/pending-memberships', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['items'], 'id');
        self::assertNotContains($membershipId, $ids, 'un désactivé ne re-rentre pas dans la file admin');
        $this->client->request('POST', "/api/admin/pending-memberships/{$membershipId}/activate", [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseStatusCodeSame(404, 'désactivé → la console ne contourne pas le geste club');
        self::assertFalse((bool) $this->admin()->fetchOne('SELECT is_active FROM club_user WHERE id = :id', ['id' => $membershipId]));
    }

    /**
     * La console n'« active » pas une adhésion sur un club SANS gestionnaire : ce serait reprendre
     * le club sans la preuve de l'adresse officielle. 409 nommé, la ligne reste en attente.
     */
    public function testActivateIsRefusedOnAManagerlessClub(): void
    {
        [$secret] = $this->createSuperAdmin('noman@example.test', 'VeryStrongPassword!');
        $csrf = $this->authenticate('noman@example.test', 'VeryStrongPassword!', $secret);

        $clubId = Uuid::v4()->toRfc4122();
        $ara = 'NMG' . strtoupper(substr(md5(uniqid()), 0, 7));
        $this->clubAras[] = $ara;
        $this->admin()->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed, ffbb_club_code) VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :loc, FALSE, :ara)',
            ['id' => $clubId, 'name' => 'Club Sans Gestionnaire', 'slug' => 'club-nmg-' . strtolower($ara), 'tz' => 'Europe/Paris', 'loc' => 'fr', 'ara' => $ara],
        );
        // Un Membre actif reste (le club n'est pas vide) : seul un GESTIONNAIRE compte.
        $this->seedMembership($clubId, 'member', true, $ara . 'm');
        $membershipId = $this->seedMembership($clubId, 'admin', false, $ara . 'p');

        $this->client->request('POST', "/api/admin/pending-memberships/{$membershipId}/activate", [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'La reprise d\'un club sans gestionnaire passe par l\'approbation du club.',
            json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null,
        );
        self::assertFalse((bool) $this->admin()->fetchOne('SELECT is_active FROM club_user WHERE id = :id', ['id' => $membershipId]), 'l\'adhésion reste en attente');
    }

    /**
     * Chantier Membre PR 3 (écart 8) : la console dit la NATURE de chaque demande — `create` (aucun
     * club réel sous ce code), `reprise` (club réel sans gestionnaire actif), `promotion` (club
     * géré, demandeur déjà membre actif : ses gestionnaires n'ont pas répondu). Aucun bouton
     * nouveau : la lecture seule change.
     */
    public function testConsoleListsTheKindOfEachRequest(): void
    {
        [$secret] = $this->createSuperAdmin('kind@example.test', 'VeryStrongPassword!');
        $this->authenticate('kind@example.test', 'VeryStrongPassword!', $secret);

        $create = $this->seedRequest(status: ClubCreationRequest::STATUS_PENDING);

        $managerless = $this->seedClubViaOrm('RPR');
        $repreneur = $this->seedUser();
        $this->seedMembershipViaOrm($managerless['clubId'], $repreneur->getId(), 'member');
        $reprise = $this->seedRequestFor($repreneur->getId(), $managerless['ara']);

        $managed = $this->seedClubViaOrm('PRM');
        $this->seedMembershipViaOrm($managed['clubId'], $this->seedUser()->getId(), 'admin');
        $member = $this->seedUser();
        $this->seedMembershipViaOrm($managed['clubId'], $member->getId(), 'member');
        $promotion = $this->seedRequestFor($member->getId(), $managed['ara']);

        $this->client->request('GET', '/api/admin/club-requests', [], [], ['REMOTE_ADDR' => $this->requestIp]);
        self::assertResponseIsSuccessful();
        $items = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['items'], null, 'id');
        foreach ([$create, $reprise, $promotion] as $request) {
            self::assertArrayHasKey($request->getId(), $items, 'témoin : la console liste les trois demandes');
        }
        $kinds = array_map(static fn (array $item): mixed => $item['kind'] ?? null, $items);
        self::assertSame('create', $kinds[$create->getId()] ?? null);
        self::assertSame('reprise', $kinds[$reprise->getId()] ?? null);
        self::assertSame('promotion', $kinds[$promotion->getId()] ?? null);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Throttle admin par IP (SA0) : une IP fraîche par test, sinon les suites
        // se polluent entre elles (gotcha connu du rate-limiter Redis).
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        foreach ($this->clubAras as $ara) {
            $clubId = $this->admin()->fetchOne('SELECT id FROM club WHERE ffbb_club_code = :ara', ['ara' => $ara]);
            if (\is_string($clubId)) {
                $this->admin()->executeStatement('DELETE FROM club WHERE id = :id', ['id' => $clubId]);
            }
        }
        foreach ($this->userIds as $userId) {
            $this->admin()->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $userId]);
        }
        if (isset($this->adminId)) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    /** @return array{clubId: string, ara: string} un club réel semé sur la connexion PAR DÉFAUT (celle que lit la console) */
    private function seedClubViaOrm(string $prefix): array
    {
        $ara = $prefix . strtoupper(substr(md5(uniqid()), 0, 7));
        $this->clubAras[] = $ara;
        $club = (new Club)->setName('Club ' . $ara)->setSlug('club-' . strtolower($ara))->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setFfbbClubCode($ara);
        $this->em->persist($club);
        $this->em->flush();

        return ['clubId' => $club->getId(), 'ara' => $ara];
    }

    private function seedMembershipViaOrm(string $clubId, string $userId, string $role): void
    {
        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($userId);
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();
        $this->clearGuc();
    }

    private function seedRequestFor(string $userId, string $ara): ClubCreationRequest
    {
        $request = new ClubCreationRequest;
        $request->setUserId($userId);
        $request->setAra($ara);
        $request->setClubName('Club ' . $ara);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function seedRequest(string $status): ClubCreationRequest
    {
        $user = $this->seedUser();
        $ara = 'REQ' . strtoupper(substr(md5(uniqid()), 0, 7));
        $this->clubAras[] = $ara;
        $request = new ClubCreationRequest;
        $request->setUserId($user->getId());
        $request->setAra($ara);
        $request->setClubName('Club Console');
        $request->setStatus($status);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function seedActiveManager(string $clubId, string $ara): void
    {
        $this->seedMembership($clubId, 'admin', true, $ara . 'g');
    }

    /** Un utilisateur + son adhésion, semés par la porte ADMIN (même connexion que la console). @return string l'id d'adhésion */
    private function seedMembership(string $clubId, string $role, bool $active, string $tag): string
    {
        $userId = Uuid::v4()->toRfc4122();
        $this->userIds[] = $userId;
        $this->admin()->executeStatement(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (:id, 1, NOW(), NOW(), :email, :hash, :fn, :ln)',
            ['id' => $userId, 'email' => 'seed-' . strtolower($tag) . '@t.fr', 'hash' => 'x', 'fn' => 'Seed', 'ln' => 'Member'],
        );
        $membershipId = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO club_user (id, version, created_at, updated_at, joined_at, club_id, user_id, role, is_active) VALUES (:id, 1, NOW(), NOW(), NOW(), :club, :user, :role, :active)',
            ['id' => $membershipId, 'club' => $clubId, 'user' => $userId, 'role' => $role, 'active' => $active],
            ['active' => ParameterType::BOOLEAN],
        );

        return $membershipId;
    }

    private function seedUser(): User
    {
        $uid = uniqid('', true);
        $user = new User;
        $user->setEmail('console-' . $uid . '@t.fr');
        $user->setFirstName('Co');
        $user->setLastName('Nsole');
        $user->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array{0: string} */
    private function createSuperAdmin(string $email, string $password): array
    {
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

        return [$secret];
    }

    private function authenticate(string $email, string $password, string $secret): string
    {
        $this->json('POST', '/api/admin/auth/password', ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
        $totp = self::getContainer()->get(TotpService::class);
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $csrfToken = \is_array($body) ? ($body['csrfToken'] ?? null) : null;
        self::assertIsString($csrfToken);

        return $csrfToken;
    }

    /** @param array<string, mixed> $payload */
    private function json(string $method, string $uri, array $payload): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->requestIp], (string) json_encode($payload));
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
