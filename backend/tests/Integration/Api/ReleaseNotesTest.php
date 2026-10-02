<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * P5-12 — le journal de nouveautés (côté membre) et son atelier superadmin.
 *
 * Le membre ne voit QUE les notes publiées (un brouillon semé reste invisible)
 * et son filigrane de lecture (`seenUpTo`) se pose au POST /seen. L'atelier SA
 * crée/édite/publie/supprime ; publier deux fois est un 409. La surface admin
 * refuse un JWT club.
 */
#[Group('integration')]
final class ReleaseNotesTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private string $requestIp;

    private ?string $adminId = null;

    public function testAnonymousCannotReadTheJournal(): void
    {
        $this->client->request('GET', '/api/release-notes');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMarkSeenIsSelfOnly(): void
    {
        // Aucune identité : marquer « vu » n'a personne à modifier — 401.
        $this->client->request('POST', '/api/release-notes/seen');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMemberSeesOnlyPublishedNotesAndSeenWatermarkMoves(): void
    {
        $token = $this->registerVerified('RN1');

        // L'atelier SA sème un brouillon (invisible) et une note publiée.
        $csrf = $this->authenticateSuperAdmin('release-notes@example.test');
        $this->createNote($csrf, 'Brouillon interne', 'Pas encore prêt', '2026-08-10');
        $publishedId = $this->createNote($csrf, 'Nouvelle vue planning', 'Un récapitulatif plus lisible.', '2026-08-13');
        $this->publishNote($csrf, $publishedId);

        // Le membre repart d'un navigateur neuf (le cookie de session admin ne doit
        // pas voyager) et lit via son JWT.
        $this->startFreshBrowserSession($this->client);
        $body = $this->memberGet($token);
        self::assertNull($body['seenUpTo']);
        $titles = array_column($body['items'], 'title');
        self::assertContains('Nouvelle vue planning', $titles);
        self::assertNotContains('Brouillon interne', $titles, 'un brouillon reste invisible');
        self::assertCount(1, $body['items']);
        self::assertSame('2026-08-13', $body['items'][0]['date']);
        self::assertNotNull($body['items'][0]['publishedAt']);

        // Marquer « vu » pose le filigrane.
        $this->client->request('POST', '/api/release-notes/seen', [], [], $this->bearer($token));
        self::assertResponseStatusCodeSame(204);

        $afterSeen = $this->memberGet($token);
        self::assertNotNull($afterSeen['seenUpTo']);
    }

    public function testSuperAdminCanCreatePublishAndDelete(): void
    {
        $csrf = $this->authenticateSuperAdmin('release-notes-crud@example.test');

        // Créer (brouillon) — publishedAt null.
        $id = $this->createNote($csrf, 'Titre initial', 'Corps initial', '2026-08-12');
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->responseBody()['publishedAt']);

        // Validation : titre vide → 400.
        $this->json('POST', '/api/admin/release-notes', ['title' => '  ', 'body' => 'x', 'noteDate' => '2026-08-12'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);

        // Publier — publishedAt posé.
        $this->client->request('POST', '/api/admin/release-notes/' . $id . '/publish', [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->responseBody()['publishedAt']);

        // Publier une seconde fois → 409.
        $this->client->request('POST', '/api/admin/release-notes/' . $id . '/publish', [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json']);
        self::assertResponseStatusCodeSame(409);

        // La liste admin voit la note (publiée).
        $this->client->request('GET', '/api/admin/release-notes');
        self::assertResponseIsSuccessful();
        self::assertContains($id, array_column($this->responseBody()['items'], 'id'));

        // Supprimer.
        $this->client->request('DELETE', '/api/admin/release-notes/' . $id, [], [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/admin/release-notes');
        self::assertNotContains($id, array_column($this->responseBody()['items'], 'id'));
    }

    public function testWritesRequireCsrf(): void
    {
        $this->authenticateSuperAdmin('release-notes-csrf@example.test');
        // Session admin valide mais sans jeton CSRF → 403.
        $this->json('POST', '/api/admin/release-notes', ['title' => 'x', 'body' => 'y', 'noteDate' => '2026-08-12']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testClubJwtCannotReachTheAdminWorkshop(): void
    {
        $token = $this->registerVerified('RN2');
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/admin/release-notes', [], [], $this->bearer($token));
        self::assertResponseStatusCodeSame(401);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if (null !== $this->adminId) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id OR super_admin_id IS NULL', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function memberGet(string $token): array
    {
        $this->client->request('GET', '/api/release-notes', [], [], $this->bearer($token));
        self::assertResponseIsSuccessful();

        return $this->responseBody();
    }

    /** @return array{HTTP_AUTHORIZATION: string, REMOTE_ADDR: string} */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => $this->requestIp];
    }

    private function registerVerified(string $ara): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email,
            'password' => 'Password123!',
            'firstName' => 'Membre',
            'lastName' => 'Nouveautés',
            'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara,
            'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        return $token;
    }

    private function createNote(string $csrf, string $title, string $body, string $noteDate): string
    {
        $this->json('POST', '/api/admin/release-notes', ['title' => $title, 'body' => $body, 'noteDate' => $noteDate], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);

        return $this->responseBody()['id'];
    }

    private function publishNote(string $csrf, string $id): void
    {
        $this->client->request('POST', '/api/admin/release-notes/' . $id . '/publish', [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();
    }

    private function authenticateSuperAdmin(string $email): string
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, 'VeryStrongPassword!'));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, TRUE, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret()],
        );

        $this->json('POST', '/api/admin/auth/password', ['email' => $email, 'password' => 'VeryStrongPassword!']);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();
        $csrf = $this->responseBody()['csrfToken'] ?? null;
        self::assertIsString($csrf);

        return $csrf;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private function json(string $method, string $uri, array $body, array $server = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
            ...$server,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
