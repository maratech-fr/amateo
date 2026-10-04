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
 */
#[Group('phase1')]
#[Group('integration')]
final class MercureHardeningTest extends WebTestCase
{
    use TenantGucTrait;

    private string $compose;

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
            if ('mercureAuthorization' === $candidate->getName()) {
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

    protected function setUp(): void
    {
        // Repo root is mounted at /app (./:/app); tests run in /app/backend.
        $path = \dirname(__DIR__, 3) . '/docker-compose.yml';
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "docker-compose.yml not found at {$path}");
        $this->compose = $contents;
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
