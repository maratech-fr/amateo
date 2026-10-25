<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Service\TenantConnectionContext;
use App\Tests\StartsFreshBrowserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D3 — NR d'axe « auth & memberships » (+ tenant isolation effleuré, §7.1) : UN COOKIE DE
 * SESSION MORT NE BLOQUE PLUS LES PAGES PUBLIQUES.
 *
 * Le piège : le firewall `api` (stateless, `jwt: ~`) fait tourner l'authentificateur lexik
 * dès qu'un jeton est PRÉSENT — même sur une route `PUBLIC_ACCESS`. Un jeton pourri (expiré,
 * signature invalide, compte supprimé) y échoue AVANT que `access_control` n'accorde l'accès
 * public : le handler d'échec lexik court-circuite la requête en 401. Conséquence vécue :
 * « Se déconnecter » sur une session expirée, la page publique d'invitation ouverte dans un
 * onglet où traîne un vieux cookie, l'inscription d'un navigateur qui rejoue un jeton mort —
 * tous bloqués par un cookie que le JS ne peut même pas effacer (httpOnly).
 *
 * Le correctif (NEO) tient en trois pièces, chacune protégée ici :
 *  - D-a/D-b `PublicApiCredentialNeutralizer` (`kernel.request` priority 9, AVANT le firewall) :
 *    une requête qui matche {@see \App\Security\PublicApiRouteCatalog} voit son cookie `BEARER`
 *    ET son en-tête `Authorization` RETIRÉS — INCONDITIONNELLEMENT, même un jeton VALIDE (aucune
 *    route publique ne lit l'identité, D-b). La réponse redevient byte-identique au cas sans jeton.
 *  - D-c `StaleJwtCookiePurgeListener` (événements lexik JWT_INVALID / JWT_EXPIRED /
 *    AUTHENTICATION_FAILURE, jamais JWT_NOT_FOUND) : sur une route PROTÉGÉE, un 401 dû à un cookie
 *    `BEARER` mort EFFACE ce cookie (aux attributs EXACTS de la pose) — SSI le cookie est présent
 *    ET aucun en-tête `Authorization` (un script Bearer gère son propre jeton, jamais purgé).
 *
 * Falsification (à rejouer quand la branche sera montée — Docker interdit en worktree) :
 * retirer le neutraliseur fait rougir les promesses byte-identiques et le GUC ; retirer le
 * purgeur fait rougir les deux promesses d'effacement.
 *
 * Patrons réutilisés : {@see JwtCookieContractTest} (attributs du cookie), {@see SlidingSessionTest}
 * (forge d'un jeton horodaté + lecture des Set-Cookie), {@see TenantIsolationTest} (lecture du GUC
 * `app.club_id`, lignes 128-143), {@see StartsFreshBrowserSession} (browser-kit rejoue le cookie).
 */
#[Group('phase1')]
#[Group('security')]
#[Group('integration')]
final class PublicRouteStaleJwtTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private const string PASSWORD = 'Str0ng!Passw0rd';

    /** Les messages d'échec lexik qui ne doivent JAMAIS ressortir d'une page publique. */
    private const array JWT_FAILURE_MARKERS = ['Expired JWT Token', 'Invalid JWT Token', 'JWT Token not found'];

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Une page publique (`GET /api/health`) répond EXACTEMENT comme sans jeton, quel que soit le
     * jeton pourri porté — expiré, signature invalide, compte supprimé — en COOKIE comme en Bearer,
     * et aucun message d'échec JWT ne fuit.
     */
    public function testPublicRoutesAnswerByteIdenticalWithARottenToken(): void
    {
        [$baselineStatus, $baselineBody] = $this->getHealthWithNoCredentials();
        self::assertSame(200, $baselineStatus, 'la page publique répond 200 sans aucun jeton');
        self::assertSame('{"status":"ok"}', $baselineBody);

        foreach ($this->rottenTokens() as $label => $jwt) {
            // Porté en COOKIE (le cas du navigateur).
            [$cookieStatus, $cookieBody] = $this->getHealthWithCookie($jwt);
            self::assertSame($baselineStatus, $cookieStatus, \sprintf('cookie pourri (%s) : un jeton mort ne doit plus bloquer la page publique', $label));
            self::assertSame($baselineBody, $cookieBody, \sprintf('cookie pourri (%s) : la réponse publique doit être byte-identique au cas sans jeton', $label));

            // Porté en en-tête Authorization (le cas d'un appel scripté).
            [$headerStatus, $headerBody] = $this->getHealthWithBearerHeader($jwt);
            self::assertSame($baselineStatus, $headerStatus, \sprintf('Bearer pourri (%s) : un jeton mort ne doit plus bloquer la page publique', $label));
            self::assertSame($baselineBody, $headerBody, \sprintf('Bearer pourri (%s) : la réponse publique doit être byte-identique au cas sans jeton', $label));

            foreach (self::JWT_FAILURE_MARKERS as $marker) {
                self::assertStringNotContainsString($marker, $cookieBody, \sprintf('aucun message d\'échec JWT (%s) ne doit fuir d\'une page publique', $label));
                self::assertStringNotContainsString($marker, $headerBody, \sprintf('aucun message d\'échec JWT (%s) ne doit fuir d\'une page publique', $label));
            }
        }
    }

    /**
     * Se déconnecter reste possible AVEC une session déjà morte : `POST /api/logout` répond 200 et
     * pose le cookie d'EFFACEMENT, aux attributs EXACTS de la pose (vide, expiré, path /api,
     * httpOnly, SameSite strict). Sans le neutraliseur, le cookie mort 401-ait avant le contrôleur —
     * une session expirée devenait indéconnectable, le cas où l'on en a le plus besoin.
     */
    public function testLogoutWithADeadCookieStillClearsItAtThePoseAttributes(): void
    {
        $dead = $this->forge($this->persistVerifiedUser('logout-dead'), time() - 7200, time() - 3600);

        $this->freshSession();
        $this->setBearerCookie($dead);
        $this->client->request('POST', '/api/logout', [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertResponseStatusCodeSame(200, 'un cookie mort ne doit plus empêcher la déconnexion');
        $this->assertErasureCookie($this->responseBearerCookie(), 'logout');
    }

    /**
     * Sur une route PROTÉGÉE (`GET /api/me`), un cookie `BEARER` mort reçoit 401 ET se fait EFFACER,
     * aux attributs de la pose — le navigateur ne traîne plus un jeton que le JS ne peut pas voir.
     */
    public function testADeadCookieOnAProtectedRouteIs401AndPurgesTheCookie(): void
    {
        $dead = $this->forge($this->persistVerifiedUser('me-dead'), time() - 7200, time() - 3600);

        $this->freshSession();
        $this->setBearerCookie($dead);
        $this->client->request('GET', '/api/me', [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertResponseStatusCodeSame(401, 'un cookie mort reste refusé sur une route protégée');
        $this->assertErasureCookie($this->responseBearerCookie(), 'purge /api/me');
    }

    /**
     * Même un jeton VALIDE (signature bonne, non expiré, compte vivant AVEC club) est IGNORÉ sur une
     * route publique (D-b) : aucune identité résolue, donc le GUC tenant `app.club_id` reste VIDE et
     * la session glissante ne ré-émet AUCUN cookie. Patron GUC : {@see TenantIsolationTest}:128-143.
     */
    public function testAForgedTokenNeverYieldsAnIdentityNorPosesTheTenantGuc(): void
    {
        // Jeton PARFAITEMENT valide, vieux de 10 min (sans le fix, la session glisserait + poserait le GUC).
        $valid = $this->forge($this->persistVerifiedUserWithClub('forged'), time() - 600, time() + 3000);

        $this->freshSession();
        $this->setBearerCookie($valid);
        $this->client->request('GET', '/api/health', [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertResponseIsSuccessful('la page publique répond, le jeton valide est ignoré');
        self::assertSame('', $this->tenantGuc(), 'une route publique ne pose JAMAIS le GUC tenant, même sous un jeton valide');
        self::assertNull($this->responseBearerCookie(), 'sans identité résolue, la session glissante ne ré-émet aucun cookie');
    }

    /**
     * Une page publique À TOKEN (`GET /api/club-approvals/{token}`) garde son 404 byte-identique sous
     * un cookie pourri : le neutraliseur retire le cookie, le contrôleur résout le token et rend son
     * 404 anti-oracle — jamais un 401 qui trahirait que « la page existe mais tu n'es pas connecté ».
     */
    public function testTokenPagesKeepTheirByteIdentical404UnderARottenCookie(): void
    {
        $unknownToken = 'tok-' . bin2hex(random_bytes(16));
        $path = '/api/club-approvals/' . $unknownToken;

        [$baselineStatus, $baselineBody] = $this->getWithNoCredentials($path);
        self::assertSame(404, $baselineStatus, 'un token inconnu rend 404 sans aucun jeton');

        $dead = $this->forge($this->persistVerifiedUser('token-page'), time() - 7200, time() - 3600);
        $this->freshSession();
        $this->setBearerCookie($dead);
        $this->client->request('GET', $path, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()]);

        self::assertSame($baselineStatus, $this->client->getResponse()->getStatusCode(), 'un cookie mort ne doit pas transformer le 404 d\'une page à token en 401');
        self::assertSame($baselineBody, (string) $this->client->getResponse()->getContent(), 'le 404 anti-oracle reste byte-identique sous un cookie pourri');
    }

    /**
     * Le neutraliseur n'ouvre AUCUNE route voisine : les routes PROTÉGÉES qui ne sont pas au catalogue
     * public exigent toujours un jeton valide (401 sans identité) — l'accept CONNECTÉ d'invitation,
     * `POST /api/me/email` (seul `/confirm` est public) et `GET /api/me`.
     */
    public function testProtectedNeighborsStillRequireAValidToken(): void
    {
        $neighbors = [
            ['POST', '/api/invitations/' . bin2hex(random_bytes(8)) . '/accept'],
            ['POST', '/api/me/email'],
            ['GET', '/api/me'],
        ];

        foreach ($neighbors as [$method, $path]) {
            $this->freshSession();
            $this->client->request($method, $path, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
            self::assertSame(401, $this->client->getResponse()->getStatusCode(), \sprintf('%s %s n\'est pas une route publique : sans jeton valide, 401', $method, $path));
        }
    }

    /**
     * La purge ne vise QUE le cookie du navigateur : un échec par en-tête `Authorization` (script,
     * Behat, helper e2e) n'efface JAMAIS de cookie — le script re-signe lui-même. Y compris cookie
     * mort + en-tête mort ensemble : la présence de l'en-tête suffit à suspendre la purge.
     */
    public function testTheCookiePurgeNeverFiresOnABearerFailure(): void
    {
        $dead = $this->forge($this->persistVerifiedUser('bearer-fail'), time() - 7200, time() - 3600);

        // (a) en-tête seul : 401, aucun Set-Cookie.
        $this->freshSession();
        $this->client->request('GET', '/api/me', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $dead]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->responseBearerCookie(), 'un échec Bearer en en-tête ne purge aucun cookie');

        // (b) cookie mort + en-tête mort : l'en-tête présent suspend la purge.
        $this->freshSession();
        $this->setBearerCookie($dead);
        $this->client->request('GET', '/api/me', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $dead]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->responseBearerCookie(), 'un en-tête Authorization présent suspend la purge, même avec un cookie mort');
    }

    /**
     * L'inscription suit le rail ANONYME quel que soit le cookie porté : un cookie mort ne la bloque
     * plus (sans le fix, 401 avant le contrôleur), et un cookie VALIDE d'un autre compte est ignoré
     * (D-b) — un NOUVEAU compte est créé pour l'adresse soumise, jamais rattaché au porteur du cookie.
     * Turnstile inerte sans secret → 202 nominal (cf. {@see RegisterTurnstileTest}).
     */
    public function testAnAnonymizedRegisterFollowsTheExactAnonymousRail(): void
    {
        // (a) cookie MORT : le rail anonyme reprend la main.
        $dead = $this->forge($this->persistVerifiedUser('reg-dead'), time() - 7200, time() - 3600);
        $deadEmail = 'reg-dead-' . uniqid('', true) . '@test.fr';
        $this->freshSession();
        $this->setBearerCookie($dead);
        $this->register($deadEmail, 'RDEAD1');
        self::assertSame(202, $this->client->getResponse()->getStatusCode(), 'un cookie mort ne doit plus bloquer l\'inscription');
        self::assertSame(1, $this->em->getRepository(User::class)->count(['email' => $deadEmail]), 'le compte est bien créé par le rail anonyme');

        // (b) cookie VALIDE d'un autre compte : ignoré, un compte NEUF naît pour l'adresse soumise.
        $valid = $this->forge($this->persistVerifiedUser('reg-valid'), time() - 60, time() + 3000);
        $freshEmail = 'reg-fresh-' . uniqid('', true) . '@test.fr';
        $this->freshSession();
        $this->setBearerCookie($valid);
        $this->register($freshEmail, 'RFRSH1');
        self::assertSame(202, $this->client->getResponse()->getStatusCode(), 'un cookie valide d\'un autre compte est ignoré : l\'inscription reste anonyme');
        self::assertSame(1, $this->em->getRepository(User::class)->count(['email' => $freshEmail]), 'le compte créé est celui de l\'adresse soumise, pas celui du cookie');
    }

    /**
     * Le firewall admin (`^/api/admin`, SA0, session séparée) ignore le neutraliseur : celui-ci est
     * borné aux routes publiques du firewall `api` (catalogue excluant nommément `^/api/admin/auth/*`
     * et `^/api/login`). Un jeton de CLUB ne franchit jamais la frontière admin — 401. Frontière SA0
     * complète prouvée par {@see AdminRequestBoundaryTest} ; exclusion nominative du catalogue par
     * {@see PublicRouteCatalogMatchesAccessControlTest}.
     */
    public function testTheAdminFirewallIgnoresTheNeutralizer(): void
    {
        $clubToken = $this->forge($this->persistVerifiedUserWithClub('admin-boundary'), time() - 60, time() + 3000);

        $this->freshSession();
        $this->setBearerCookie($clubToken);
        $this->client->request('GET', '/api/admin/health', [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode(), 'un jeton de club ne franchit jamais le firewall admin, que le neutraliseur tourne ou non');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: int, 1: string} [status, body] */
    private function getHealthWithNoCredentials(): array
    {
        return $this->getWithNoCredentials('/api/health');
    }

    /** @return array{0: int, 1: string} [status, body] */
    private function getWithNoCredentials(string $path): array
    {
        $this->freshSession();
        $this->client->request('GET', $path, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()]);

        return [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()];
    }

    /** @return array{0: int, 1: string} [status, body] */
    private function getHealthWithCookie(string $jwt): array
    {
        $this->freshSession();
        $this->setBearerCookie($jwt);
        $this->client->request('GET', '/api/health', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()]);

        return [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()];
    }

    /** @return array{0: int, 1: string} [status, body] */
    private function getHealthWithBearerHeader(string $jwt): array
    {
        $this->freshSession();
        $this->client->request('GET', '/api/health', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip(), 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);

        return [$this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent()];
    }

    /**
     * Les trois jetons pourris, GÉNÉRÉS au runtime (jamais de littéral à forte entropie : gitleaks
     * scanne l'historique). Chacun échoue à l'authentification, mais par un chemin lexik distinct.
     *
     * @return array<string, string>
     */
    private function rottenTokens(): array
    {
        $expired = $this->forge($this->persistVerifiedUser('rotten-expired'), time() - 7200, time() - 3600);
        $badSignature = $this->corruptSignature($this->forge($this->persistVerifiedUser('rotten-sig'), time() - 60, time() + 3000));

        // Compte supprimé : jeton par ailleurs valide, mais l'utilisateur n'existe plus → le provider
        // ne le charge pas (AUTHENTICATION_FAILURE, pas JWT_INVALID/EXPIRED).
        $doomed = $this->persistVerifiedUser('rotten-deleted');
        $deletedAccount = $this->forge($doomed, time() - 60, time() + 3000);
        $this->em->remove($doomed);
        $this->em->flush();

        return [
            'expiré' => $expired,
            'signature invalide' => $badSignature,
            'compte supprimé' => $deletedAccount,
        ];
    }

    /**
     * Part d'un VRAI jeton (identité + rôles + `auth_at` posés par `create`), réécrit iat/exp, re-signe.
     * Même technique que {@see SlidingSessionTest::forge} : le provider JWS de lexik respecte iat/exp.
     */
    private function forge(User $user, int $iat, int $exp): string
    {
        $manager = $this->jwtManager();
        $payload = $manager->parse($manager->create($user));
        $payload['iat'] = $iat;
        $payload['exp'] = $exp;

        return $manager->createFromPayload($user, $payload);
    }

    /**
     * Abîme le SEGMENT de signature (base64url, header.payload.signature) sans toucher aux claims
     * lisibles : la vérification de signature échoue (JWT_INVALID). Généré, jamais un littéral.
     */
    private function corruptSignature(string $jwt): string
    {
        $parts = explode('.', $jwt);
        $signature = $parts[2];
        $parts[2] = ('A' === $signature[0] ? 'B' : 'A') . substr($signature, 1);

        return implode('.', $parts);
    }

    private function setBearerCookie(string $jwt): void
    {
        // Mêmes nom/chemin que la pose serveur, sinon le navigateur simulé ne l'enverrait pas à /api.
        $this->client->getCookieJar()->set(new BrowserKitCookie('BEARER', $jwt, null, '/api', '', false, true));
    }

    private function freshSession(): void
    {
        $this->startFreshBrowserSession($this->client);
    }

    private function responseBearerCookie(): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('BEARER' === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    private function assertErasureCookie(?Cookie $cookie, string $context): void
    {
        self::assertNotNull($cookie, \sprintf('%s : la réponse doit poser le cookie BEARER d\'effacement', $context));
        self::assertSame('', (string) $cookie->getValue(), \sprintf('%s : le cookie d\'effacement est vide', $context));
        self::assertTrue($cookie->getExpiresTime() < time(), \sprintf('%s : le cookie d\'effacement est daté dans le passé', $context));
        self::assertSame('/api', $cookie->getPath(), \sprintf('%s : même path que la pose, sinon le navigateur en garde un second', $context));
        self::assertTrue($cookie->isHttpOnly(), \sprintf('%s : attribut httpOnly de la pose', $context));
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite(), \sprintf('%s : attribut SameSite=Strict de la pose', $context));
    }

    private function register(string $email, string $ara): void
    {
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip(),
        ], (string) json_encode([
            'email' => $email,
            'password' => 'Password123!',
            'firstName' => 'An',
            'lastName' => 'Onymous',
            'ara' => $ara,
            'club_name' => 'Club Anonyme',
            'consent' => true,
        ], \JSON_THROW_ON_ERROR));
    }

    private function tenantGuc(): string
    {
        return (string) self::getContainer()
            ->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');
    }

    /** Un utilisateur actif, VÉRIFIÉ, sans club — le sujet est l'authentification. */
    private function persistVerifiedUser(string $prefix): User
    {
        $user = new User;
        $user->setEmail($prefix . '-' . uniqid('', true) . '@test.fr');
        $user->setFirstName('Stale');
        $user->setLastName('Jwt');
        $user->setPasswordHash($this->hasher()->hashPassword($user, self::PASSWORD));
        // UserChecker refuse un compte non vérifié avec le MÊME message qu'un mauvais mot de passe.
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Un utilisateur VÉRIFIÉ membre ACTIF d'un club : un jeton valide pour lui résout un tenant. */
    private function persistVerifiedUserWithClub(string $prefix): User
    {
        $uid = substr(md5(uniqid('', true)), 0, 8);
        $club = new Club;
        $club->setName('Club ' . $prefix . ' ' . $uid);
        $club->setSlug('club-' . $prefix . '-' . $uid);
        $this->em->persist($club);

        $user = $this->persistVerifiedUser($prefix . '-club');

        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        \assert($tenant instanceof TenantConnectionContext);
        $tenant->setClubId($club->getId());
        try {
            $membership = new ClubUser;
            $membership->setClubId($club->getId());
            $membership->setUserId($user->getId());
            $membership->setRole('manager');
            $membership->setIsActive(true);
            $this->em->persist($membership);
            $this->em->flush();
        } finally {
            $tenant->clear();
        }

        return $user;
    }

    private function jwtManager(): JWTTokenManagerInterface
    {
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($manager instanceof JWTTokenManagerInterface);

        return $manager;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        return $hasher;
    }

    private function ip(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }
}
