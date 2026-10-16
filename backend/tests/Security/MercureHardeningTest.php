<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-05/06 non-regression: the Mercure hub must stay hardened. Static guard on
 * the tracked docker-compose.yml — a live subscribe-without-JWT check would need
 * the hub running, but the regression we must catch is a config edit re-adding
 * `anonymous` or the wildcard CORS, which this reads directly.
 *
 * BCK-34 (axe *auth & memberships*) — le TTL du jeton de souscription est une DURÉE
 * DE SÉCURITÉ : il doit se caler sur l'horloge RÉELLE, jamais sur l'« aujourd'hui »
 * simulé d'un club démo (sinon un membre d'un club démo à date FUTURE aurait un jeton
 * quasi éternel, à date PASSÉE un jeton déjà expiré). Prouvé en bootant une vraie
 * requête pour un club démo à date simulée extrême et en lisant l'`iat` du jeton.
 *
 * ⚠ FALSIFICATION : réinjecter l'horloge DÉCORÉE (`clock`) dans MercureAuthController
 * cale l'`iat`/`exp` sur l'année simulée (2099) → l'assertion « iat ≈ maintenant »
 * rougit.
 *
 * Mercure 1.0 (lot 4 PR C) — la bascule est un CUTOVER DIRECT, dev = prod, sans mode compat :
 * le hub est épinglé à une version 1.x EXACTE (jamais `latest`, jamais une 0.x), et la MÊME
 * version dans `docker-compose.yml` et `docker-compose.prod.yml` — un jeton 1.0 (`authorization_
 * details`, `at+jwt`) parle au hub, un décalage de version casse le temps réel en silence.
 */
#[Group('phase1')]
#[Group('integration')]
final class MercureHardeningTest extends WebTestCase
{
    use TenantGucTrait;

    private string $compose;

    private string $composeProd;

    public function testNoAnonymousSubscribers(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/^\s*anonymous\s*$/m',
            $this->readCompose(),
            'Mercure hub must NOT allow anonymous subscribers (SEC-05).',
        );
    }

    public function testCorsOriginsNotWildcard(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/cors_origins\s+\*/',
            $this->readCompose(),
            'Mercure cors_origins must be an explicit allow-list, not "*" (SEC-05).',
        );
    }

    public function testNoWildcardPublishOrigins(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/publish_origins\s+\*/',
            $this->readCompose(),
            'Mercure publish_origins "*" must not be re-introduced (SEC-05).',
        );
    }

    public function testHubUsesDedicatedSecretNotJwtPassphrase(): void
    {
        // The hub keys must reference the dedicated MERCURE_JWT_SECRET, never the
        // lexik JWT_PASSPHRASE (SEC-06 — the two were the same value before).
        self::assertMatchesRegularExpression(
            '/MERCURE_(PUBLISHER|SUBSCRIBER)_JWT_KEY:\s*\$\{MERCURE_JWT_SECRET\}/',
            $this->readCompose(),
            'Mercure hub must sign with ${MERCURE_JWT_SECRET} (SEC-06).',
        );
        self::assertDoesNotMatchRegularExpression(
            '/MERCURE_(PUBLISHER|SUBSCRIBER)_JWT_KEY:\s*\$\{JWT_PASSPHRASE\}/',
            $this->readCompose(),
            'Mercure hub must not reuse ${JWT_PASSPHRASE} (SEC-06 collision).',
        );
    }

    public function testMercureJwtExpiryUsesTheRealClockUnderASimulatedDemoClock(): void
    {
        $client = self::createClient();
        // Club démo à date simulée EXTRÊME (2099) : si le contrôleur lisait l'horloge
        // décorée, l'iat/exp du jeton partirait en 2099.
        $token = $this->seedDemoManagerWithSimulatedClock('2099-01-01');

        $client->request('GET', '/api/mercure/auth', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        $cookie = null;
        foreach ($client->getResponse()->headers->getCookies() as $candidate) {
            // Mercure 1.0 (C1) : cookie `mercure_access_token` sans préfixe en test (JWT_COOKIE_SECURE
            // = false) — le préfixe `__Secure-` n'apparaît qu'en prod HTTPS (cookie Secure).
            if ('/.well-known/mercure' === $candidate->getPath()) {
                $cookie = $candidate;
                break;
            }
        }
        self::assertNotNull($cookie, 'le jeton de souscription est posé en cookie');

        $claims = $this->decodeJwtClaims((string) $cookie->getValue());
        $realNow = new DateTimeImmutable('now')->getTimestamp();
        // lcobucci sérialise iat/exp en numérique (float avec microsecondes).
        self::assertTrue(is_numeric($claims['iat'] ?? null), 'iat présent et numérique');
        self::assertTrue(is_numeric($claims['exp'] ?? null), 'exp présent et numérique');
        $iat = (float) $claims['iat'];
        $exp = (float) $claims['exp'];
        self::assertLessThan(300, abs($iat - $realNow), 'iat calé sur l\'horloge RÉELLE, jamais sur la date simulée du club (2099)');
        self::assertEqualsWithDelta(3600, $exp - $iat, 1.0, 'TTL d\'une heure, réel');
    }

    /**
     * Le hub de PROD n'ouvre pas non plus l'anonyme, et son CORS reste une liste explicite
     * (`${PUBLIC_BASE_URL}`), jamais le joker — même garde que le dev, sur l'autre fichier.
     */
    public function testProdHubIsHardenedToo(): void
    {
        self::assertDoesNotMatchRegularExpression('/^\s*anonymous\s*$/m', $this->composeProd, 'Le hub de prod ne doit JAMAIS autoriser les abonnés anonymes (SEC-05).');
        self::assertDoesNotMatchRegularExpression('/cors_origins\s+\*/', $this->composeProd, 'Le CORS du hub de prod doit rester une liste explicite, jamais "*" (SEC-05).');
    }

    /**
     * Mercure 1.0 — le hub est épinglé à une version 1.x EXACTE (MAJOR.MINOR.PATCH), jamais
     * `latest` (une `docker compose pull` de routine ne doit pas échanger la version du hub en
     * silence), jamais une 0.x (le protocole legacy est retiré au cutover).
     */
    public function testBothComposeFilesPinAnExactMercure1xTagNeverLatestNor0x(): void
    {
        foreach (['docker-compose.yml' => $this->compose, 'docker-compose.prod.yml' => $this->composeProd] as $file => $contents) {
            $tag = $this->mercureImageTag($contents);
            self::assertMatchesRegularExpression('/^v1\.\d+\.\d+$/', $tag, \sprintf('%s doit épingler dunglas/mercure à une 1.x exacte (vMAJOR.MINOR.PATCH), pas "%s".', $file, $tag));
            self::assertStringNotContainsString('latest', $tag, \sprintf('%s ne doit jamais utiliser le tag "latest" pour le hub.', $file));
        }
    }

    /**
     * Cutover DIRECT, dev = prod : les deux fichiers doivent épingler EXACTEMENT la même version
     * du hub — une génération réelle qui parle à un hub d'une autre version que celle testée en CI
     * casse le temps réel sans qu'aucun test ne le voie.
     */
    public function testDevAndProdPinTheSameMercureVersion(): void
    {
        self::assertSame(
            $this->mercureImageTag($this->compose),
            $this->mercureImageTag($this->composeProd),
            'docker-compose.yml et docker-compose.prod.yml doivent épingler la MÊME version du hub Mercure (cutover direct, dev = prod).',
        );
    }

    protected function setUp(): void
    {
        // Repo root is mounted at /app (./:/app); tests run in /app/backend.
        $this->compose = $this->readComposeFile(\dirname(__DIR__, 3) . '/docker-compose.yml');
        $this->composeProd = $this->readComposeFile(\dirname(__DIR__, 3) . '/docker-compose.prod.yml');
    }

    private function readComposeFile(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "compose file not found at {$path}");

        return $contents;
    }

    /** Le tag de l'image `dunglas/mercure:<tag>` déclarée dans un fichier compose. */
    private function mercureImageTag(string $compose): string
    {
        if (1 !== preg_match('#image:\s*dunglas/mercure:(\S+)#', $compose, $m)) {
            self::fail('un service dunglas/mercure doit déclarer une image épinglée');
        }

        return $m[1];
    }

    private function readCompose(): string
    {
        return $this->compose;
    }

    /** Club DÉMO (is_demo) à `simulated_today` posé + gestionnaire actif ; rend son JWT. */
    private function seedDemoManagerWithSimulatedClock(string $simulatedToday): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        $uid = substr(md5(uniqid('', true)), 0, 8);

        $club = new Club;
        $club->setName('Mercure Démo ' . $uid)->setSlug('mercure-demo-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo(true);
        $club->setSimulatedToday(new DateTimeImmutable($simulatedToday));
        $em->persist($club);

        $user = new User;
        $user->setEmail('mercure-' . $uid . '@test.fr')->setFirstName('N')->setLastName('Tester');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $em->persist($membership);
        $em->flush();
        $this->clearGuc();

        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($manager instanceof JWTTokenManagerInterface);

        return $manager->create($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'un JWT a trois segments');
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($payload);
        $claims = json_decode($payload, true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        return $claims;
    }
}
