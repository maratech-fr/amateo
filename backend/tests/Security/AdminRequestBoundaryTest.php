<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\SuperAdmin;
use App\Security\TotpService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Les deux FRONTIÈRES d'une requête `/api/admin` : jeton CSRF exigé (SEC-18) et
 * AUCUN tenant posé (SEC-17).
 *
 * SEC-18 — TOUTE écriture admin exige le jeton CSRF. Sans exception non déclarée.
 *
 * La console super-admin s'authentifie par session : autorité ambiante, donc
 * exposée au CSRF. La protection existait mais était **opt-in** — chaque
 * contrôleur appelait `AdminSessionCsrf::isValid()` à la main. Au moment d'écrire
 * ce test, les quatre routes d'écriture l'appelaient toutes : il n'y avait pas de
 * trou, il y avait un PIÈGE. Le premier `POST /api/admin/…` ajouté sans copier la
 * ligne naissait sans protection, en silence.
 *
 * Ce test ne lit pas une liste écrite à la main : il **ÉNUMÈRE le routeur**. Toute
 * route sous `/api/admin` déclarant une méthode non sûre doit, une fois
 * authentifiée mais SANS jeton, rendre 403 — ou figurer dans la liste d'exemptions
 * explicite du listener (les deux portes de connexion, qui précèdent toute
 * session). Une route ajoutée demain est couverte le jour où elle est ajoutée.
 *
 * Axe *auth & memberships* (§7.1) → phase1.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminRequestBoundaryTest extends WebTestCase
{
    private const string PASSWORD = 'Sup3r-Admin-Passw0rd!';

    /** Les seules routes d'écriture admin légitimement sans jeton : les portes de connexion. */
    private const array EXPECTED_EXEMPTIONS = [
        '/api/admin/auth/password',
        '/api/admin/auth/totp',
    ];

    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    private KernelBrowser $client;

    private string $adminId;

    private string $requestIp;

    public function testEveryAdminWriteRouteRejectsAMissingCsrfToken(): void
    {
        $secret = $this->createSuperAdmin();
        $this->authenticate($secret);

        $checked = [];
        foreach ($this->adminWriteRoutes() as [$method, $path]) {
            if (\in_array($path, self::EXPECTED_EXEMPTIONS, true)) {
                continue;
            }
            $checked[] = $method . ' ' . $path;

            // Authentifié (la session vit dans le client), mais AUCUN X-CSRF-Token.
            $this->client->request($method, $path, [], [], [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => $this->requestIp,
            ], '{}');

            self::assertSame(
                403,
                $this->client->getResponse()->getStatusCode(),
                \sprintf(
                    '%s %s accepte une écriture SANS jeton CSRF. La console admin s\'authentifie '
                    . 'par session (autorité ambiante) : un site tiers peut donc déclencher cette '
                    . 'requête depuis le navigateur d\'un admin connecté. Le contrôle est central '
                    . '(AdminCsrfListener) — si cette route y échappe, c\'est que son chemin sort '
                    . 'du préfixe /api/admin ou qu\'elle a été ajoutée aux exemptions.',
                    $method,
                    $path,
                ),
            );
        }

        self::assertNotEmpty($checked, 'le routeur doit exposer au moins une écriture admin — sinon ce test ne garde rien');
    }

    public function testTheExemptionListIsExactlyTheTwoLoginDoors(): void
    {
        // Les exemptions sont le seul endroit où la protection peut être retirée.
        // Les épingler ici rend tout ajout VISIBLE en revue : c'est le geste qu'on
        // veut conscient, pas la ligne qu'on veut interdire.
        $exemptions = [];
        foreach ($this->adminWriteRoutes() as [, $path]) {
            if (\in_array($path, self::EXPECTED_EXEMPTIONS, true)) {
                $exemptions[$path] = true;
            }
        }

        self::assertSame(self::EXPECTED_EXEMPTIONS, array_keys($exemptions));
    }

    public function testAValidTokenLetsTheWriteThrough(): void
    {
        // Témoin : sans lui, un listener qui rendrait 403 à TOUT ferait passer le
        // test précédent au vert en cassant la console.
        $secret = $this->createSuperAdmin();
        $csrf = $this->authenticate($secret);

        $this->client->request('POST', '/api/admin/jobs/unknown-job/run', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
            'REMOTE_ADDR' => $this->requestIp,
        ], '{}');

        self::assertNotSame(
            403,
            $this->client->getResponse()->getStatusCode(),
            'avec un jeton valide, le contrôle CSRF doit laisser passer (ici la route répond sur le fond — 404 job inconnu)',
        );
    }

    /**
     * SEC-17 — une requête admin ne pose JAMAIS le tenant, même si elle tente de
     * réclamer un club dans un en-tête.
     *
     * Le contrat SA0 dit « la session admin ne pose jamais `app.club_id` ».
     * L'early-return `/api/admin` du listener tenant le garantit : il sort avant
     * toute résolution de club. (Depuis AUD-SEC-25, le club ne vient de toute
     * façon plus que de l'adhésion d'un `User` authentifié — un `SuperAdmin` n'en
     * a aucune, et l'en-tête `X-Club-Id` n'est plus lu nulle part ; l'en-tête
     * porté ici est donc inerte, mais le test épingle que même une telle tentative
     * ne pose aucun GUC.) Ce test est le seul filet de l'early-return (vérifié :
     * le retirer laissait toute la suite verte).
     */
    public function testAnAdminRequestNeverSetsTheTenantGuc(): void
    {
        $secret = $this->createSuperAdmin();
        $this->authenticate($secret);

        $this->client->request('GET', '/api/admin/health', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Club-Id' => '710a2907-20ce-40ff-a67f-c2674ca0dbe7',
            'REMOTE_ADDR' => $this->requestIp,
        ]);
        self::assertResponseIsSuccessful();

        // Le GUC est posé sur la connexion APPLICATIVE (celle que RLS lit).
        $guc = self::getContainer()->get(ManagerRegistry::class)->getConnection()
            ->fetchOne('SELECT current_setting(\'app.club_id\', true)');

        self::assertTrue(
            null === $guc || '' === $guc,
            \sprintf('une requête admin a posé app.club_id = %s — le contrat SA0 dit l\'inverse', var_export($guc, true)),
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->requestIp = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    protected function tearDown(): void
    {
        if (isset($this->adminId)) {
            $this->admin()->executeStatement('DELETE FROM admin_audit_log WHERE super_admin_id = :id', ['id' => $this->adminId]);
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    /**
     * Les écritures admin, LUES DU ROUTEUR — jamais d'une liste tenue à la main.
     * Les routes à paramètre reçoivent une valeur inerte : le sujet est le contrôle
     * CSRF, qui s'applique avant que la route ne regarde ses arguments.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function adminWriteRoutes(): array
    {
        $routes = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/admin')) {
                continue;
            }
            $methods = array_diff($route->getMethods(), self::SAFE_METHODS);
            if ([] === $methods) {
                continue;
            }
            $concrete = preg_replace('/\{[^}]+\}/', '00000000-0000-4000-8000-000000000000', $path);
            foreach ($methods as $method) {
                $routes[] = [$method, (string) $concrete];
            }
        }

        return $routes;
    }

    private function admin(): Connection
    {
        return self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
    }

    private function createSuperAdmin(): string
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $totp = self::getContainer()->get(TotpService::class);
        $secret = $totp->generateSecret();
        $email = 'csrf-coverage-' . substr($this->adminId, 0, 8) . '@test.com';
        $identity = new SuperAdmin($this->adminId, $email, '', $totp->encrypt($secret));
        $identity->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($identity, self::PASSWORD));
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, :enabled, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'password' => $identity->getPassword(), 'secret' => $identity->getTotpSecret(), 'enabled' => true],
            ['enabled' => ParameterType::BOOLEAN],
        );

        return $secret;
    }

    /** @return string le jeton CSRF émis par la connexion réussie */
    private function authenticate(string $secret): string
    {
        $email = 'csrf-coverage-' . substr($this->adminId, 0, 8) . '@test.com';
        $this->json('POST', '/api/admin/auth/password', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        $totp = self::getContainer()->get(TotpService::class);
        $this->json('POST', '/api/admin/auth/totp', ['code' => $totp->code($secret, time())]);
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsString($body['csrfToken'] ?? null);

        return $body['csrfToken'];
    }

    /** @param array<string, mixed> $body */
    private function json(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->requestIp,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
