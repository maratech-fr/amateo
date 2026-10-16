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
use Symfony\Component\Yaml\Yaml;

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
 *
 * Mercure 1.0 — AUDIENCE DU JETON PUBLIEUR (axe *generation pipeline*). Le hub 1.0 ENFORCE
 * l'audience (`resource_identifier`, RFC 9068 `aud`) : TOUT service qui exécute le code backend
 * (image/build `docker/php/Dockerfile`) publie sur le hub — php-fpm en synchrone, messenger-worker
 * la progression de génération et les trajets, cron-runner le placement — et son jeton publieur
 * porte en `aud` la valeur de `MERCURE_PUBLIC_URL`. Si un seul de ces services ne reçoit pas
 * `MERCURE_PUBLIC_URL` aligné sur le `resource_identifier` du hub, il retombe sur le fallback de
 * `backend/.env` (`http://localhost:3000/…`), le hub rejette (« invalid JWT: token has invalid
 * audience ») et sa publication est perdue EN SILENCE — invisible en CI (port 3000 par défaut),
 * cassé sur le poste du fondateur (`MERCURE_PORT=13009`) et en PROD. Gardé par
 * `testEveryBackendServiceCarriesTheHubAudienceAsMercurePublicUrl`.
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

    /**
     * TOUT service backend (image/build `docker/php/Dockerfile`, hors cible nginx) reçoit
     * `MERCURE_PUBLIC_URL` — l'`aud` de son jeton publieur — avec la MÊME URL que le
     * `resource_identifier` du hub. Rouge aujourd'hui pour messenger-worker et cron-runner, dans les
     * DEUX compose : sans cette ligne, ces publieurs sont rejetés par le hub 1.0 et le temps réel
     * (progression de génération, trajets, placement) meurt en silence hors CI et en prod.
     *
     * ⚠ FALSIFICATION : retirer la ligne `MERCURE_PUBLIC_URL` d'un service backend d'un des deux
     * fichiers → ce test rougit en NOMMANT le service fautif et son fichier.
     */
    public function testEveryBackendServiceCarriesTheHubAudienceAsMercurePublicUrl(): void
    {
        foreach (['docker-compose.yml' => $this->compose, 'docker-compose.prod.yml' => $this->composeProd] as $file => $contents) {
            $services = $this->parseServices($contents);
            $hubAudience = $this->normalizeInterpolation($this->mercureResourceIdentifier($services, $file));

            $backendServices = $this->backendServiceNames($services);
            self::assertNotEmpty($backendServices, \sprintf('%s doit déclarer au moins un service backend (image/build docker/php/Dockerfile).', $file));

            foreach ($backendServices as $name) {
                $publicUrl = $this->serviceEnv($services, $name, 'MERCURE_PUBLIC_URL');
                self::assertNotNull(
                    $publicUrl,
                    \sprintf(
                        '%s : le service « %s » exécute le code backend et PUBLIE sur Mercure, mais ne reçoit pas MERCURE_PUBLIC_URL — son jeton publieur retombe sur le fallback backend/.env et le hub 1.0 rejette l\'audience (temps réel perdu en silence).',
                        $file,
                        $name,
                    ),
                );
                self::assertSame(
                    $hubAudience,
                    $this->normalizeInterpolation($publicUrl),
                    \sprintf(
                        '%s : MERCURE_PUBLIC_URL de « %s » doit valoir l\'URL du resource_identifier du hub (même audience RFC 9068, même expression d\'interpolation).',
                        $file,
                        $name,
                    ),
                );
            }
        }
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

    /**
     * @return array<string, mixed> les services déclarés dans un fichier compose, par nom
     */
    private function parseServices(string $contents): array
    {
        $parsed = Yaml::parse($contents);
        self::assertIsArray($parsed);
        $services = $parsed['services'] ?? null;
        self::assertIsArray($services, 'le fichier compose doit déclarer des services');

        return $services;
    }

    /**
     * Services qui exécutent le code backend : image/build `docker/php/Dockerfile`, hors cible
     * `nginx*` (le serveur web partage le Dockerfile mais ne lance aucun code PHP → il ne publie
     * jamais sur le hub). Attrape donc php-fpm, messenger-worker, cron-runner, et tout futur service
     * du même image/build.
     *
     * @param array<string, mixed> $services
     *
     * @return list<string>
     */
    private function backendServiceNames(array $services): array
    {
        $names = [];
        foreach ($services as $name => $svc) {
            if (!\is_array($svc)) {
                continue;
            }
            $build = $svc['build'] ?? null;
            if (!\is_array($build)) {
                continue;
            }
            $dockerfile = $build['dockerfile'] ?? null;
            if (!\is_string($dockerfile) || !str_ends_with($dockerfile, 'docker/php/Dockerfile')) {
                continue;
            }
            $target = $build['target'] ?? null;
            if (\is_string($target) && str_contains($target, 'nginx')) {
                continue;
            }
            $names[] = (string) $name;
        }

        return $names;
    }

    /**
     * L'URL (expression d'interpolation) du `resource_identifier` déclaré par le hub mercure — c'est
     * l'audience RFC 9068 que le hub EXIGE de chaque jeton publieur.
     *
     * @param array<string, mixed> $services
     */
    private function mercureResourceIdentifier(array $services, string $file): string
    {
        $directives = $this->serviceEnv($services, 'mercure', 'MERCURE_EXTRA_DIRECTIVES');
        if (null === $directives || 1 !== preg_match('/resource_identifier\s+(\S+)/', $directives, $m)) {
            self::fail(\sprintf('%s : le hub mercure doit déclarer un resource_identifier (audience RFC 9068).', $file));
        }

        return $m[1];
    }

    /**
     * La valeur (chaîne) d'une variable d'environnement d'un service, ou null si absente.
     *
     * @param array<string, mixed> $services
     */
    private function serviceEnv(array $services, string $service, string $key): ?string
    {
        $svc = $services[$service] ?? null;
        if (!\is_array($svc)) {
            return null;
        }
        $env = $svc['environment'] ?? null;
        if (!\is_array($env)) {
            return null;
        }
        $value = $env[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * Efface l'annotation de MESSAGE d'erreur d'interpolation `${VAR:?msg}` → `${VAR}` : le message
     * est cosmétique, la valeur résolue est identique. Permet de comparer l'URL du publieur et celle
     * du hub même quand l'un porte `${PUBLIC_BASE_URL:?set …}` et l'autre `${PUBLIC_BASE_URL:?}`.
     */
    private function normalizeInterpolation(string $expr): string
    {
        return preg_replace('/(\$\{[A-Za-z_][A-Za-z0-9_]*):\?[^}]*\}/', '$1}', $expr) ?? $expr;
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
