<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Service\TenantConnectionContext;
use App\Tests\StartsFreshBrowserSession;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR d'axe « auth & memberships » (§7.1) — P4-291 : la SESSION GLISSANTE du JWT de club
 * ({@see \App\EventListener\JwtSlidingSessionListener}).
 *
 * Promesse : le cookie httpOnly du navigateur GLISSE tant qu'on s'en sert (ré-émission
 * d'un TTL frais à chaque requête utile), mais sous DEUX bornes infranchissables —
 * 1 h d'inactivité (le TTL hérité) ET 12 h absolues depuis l'authentification initiale.
 *
 * Trois abus nommés du cadrage, chacun falsifiable :
 *   (1) un jeton rejoué en continu MEURT EXACTEMENT à 12 h (`exp` plafonné à `auth_at+12h`,
 *       puis plus aucune ré-émission, puis 401) — sans le plafond la session serait éternelle ;
 *   (2) un jeton EXPIRÉ reçoit 401 et AUCUN Set-Cookie — le glissement ne ressuscite rien ;
 *   (3) le flux Bearer (en-tête `Authorization`) ne glisse JAMAIS — un script re-signe lui-même.
 *
 * `auth_at` est posé à la création et reporté VERBATIM, jamais réinitialisé.
 */
#[Group('phase1')]
#[Group('integration')]
final class SlidingSessionTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private const int TTL = 3600;
    private const int TWELVE_HOURS = 43200;

    private KernelBrowser $client;
    private string $managerEmail = '';

    public function testCookieRequestOlderThanFiveMinutesGetsReissuedCookie(): void
    {
        $now = time();
        // Jeton de navigateur vieux de 10 min, encore valide, authentifié initialement il y a 10 min.
        $forged = $this->forge($now - 600, $now - 600, $now + (self::TTL - 600));
        $original = $this->jwtManager()->parse($forged);

        $this->sendWithCookie('GET', '/api/me', $forged);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $reissued = $this->reissuedBearer();
        self::assertNotNull($reissued, 'une requête navigateur de plus de 5 min ré-émet le cookie');

        $new = $this->jwtManager()->parse($reissued);

        // TTL rafraîchi : le nouvel `exp` dépasse l'ancien.
        self::assertGreaterThan($original['exp'], $new['exp'], 'le TTL a glissé');
        self::assertLessThanOrEqual($now + self::TTL + 5, $new['exp'], 'le nouvel exp reste borné par le TTL');

        // `auth_at` reporté verbatim, tous les autres claims (identité, rôles) conservés —
        // seuls iat/exp bougent. Couvre la conservation des claims (jetons démo compris).
        self::assertSame($original['auth_at'], $new['auth_at'], 'auth_at est reporté verbatim');
        unset($original['iat'], $original['exp'], $new['iat'], $new['exp']);
        ksort($original);
        ksort($new);
        self::assertSame($original, $new, 'tous les autres claims sont conservés à l\'identique');
    }

    public function testFreshTokenIsNotReissued(): void
    {
        $now = time();
        // Jeton frais (émis il y a 1 min) : on ne re-signe pas à chaque requête.
        $forged = $this->forge($now - 60, $now - 60, $now + (self::TTL - 60));

        $this->sendWithCookie('GET', '/api/me', $forged);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reissuedBearer(), 'un jeton de moins de 5 min n\'est pas ré-émis');
    }

    public function testReplayedTokenDiesAtTwelveHours(): void
    {
        $now = time();

        // (A) Près de la borne : authentifié il y a 12 h moins 2 min → l'exp est PLAFONNÉ
        // à `auth_at + 12 h`, jamais now+1 h.
        $authAtNearDeadline = $now - (self::TWELVE_HOURS - 120);
        $deadline = $authAtNearDeadline + self::TWELVE_HOURS; // ≈ now + 120
        $nearDeadline = $this->forge($now - 600, $authAtNearDeadline, $now + 1800);

        $this->sendWithCookie('GET', '/api/me', $nearDeadline);
        $reissued = $this->reissuedBearer();
        self::assertNotNull($reissued, 'tant que la borne 12 h n\'est pas atteinte, le jeton glisse encore');
        $capped = $this->jwtManager()->parse($reissued);
        self::assertSame($deadline, $capped['exp'], 'l\'exp est plafonné à auth_at + 12 h (mort exacte à 12 h)');
        self::assertLessThan($now + self::TTL, $capped['exp'], 'le plafond gagne sur le TTL d\'1 h');

        // (B) Au-delà de la borne : authentifié il y a plus de 12 h, jeton encore valide
        // (exp futur) → PLUS AUCUNE ré-émission.
        $pastDeadline = $this->forge($now - 600, $now - (self::TWELVE_HOURS + 60), $now + 1800);
        $this->sendWithCookie('GET', '/api/me', $pastDeadline);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reissuedBearer(), 'passé 12 h, le glissement s\'arrête — aucun Set-Cookie');

        // (C) Puis le jeton plafonné finit par expirer → 401, toujours sans ré-émission.
        $expired = $this->forge($now - (self::TTL + 600), $now - self::TWELVE_HOURS, $now - 60);
        $this->sendWithCookie('GET', '/api/me', $expired);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reissuedBearer());
    }

    public function testExpiredTokenGets401AndNoSetCookie(): void
    {
        $now = time();
        // Jeton expiré depuis 1 h : le firewall rend 401, aucune identité → zéro ré-émission.
        $expired = $this->forge($now - 7200, $now - 7200, $now - 3600);

        $this->sendWithCookie('GET', '/api/me', $expired);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reissuedBearer(), 'un jeton expiré ne ré-émet jamais de cookie');
    }

    public function testBearerHeaderNeverSlides(): void
    {
        $now = time();
        // Même jeton « glissable » (vieux de 10 min), mais présenté en en-tête Authorization :
        // un script/Behat re-signe lui-même, il ne doit JAMAIS glisser.
        $forged = $this->forge($now - 600, $now - 600, $now + (self::TTL - 600));

        $ip = $this->ip();
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/me', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $forged,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reissuedBearer(), 'le flux Bearer ne glisse jamais (aucun Set-Cookie)');
    }

    public function testAuthAtIsCarriedNeverReset(): void
    {
        $now = time();
        $authAt = $now - 600;
        $forged = $this->forge($now - 600, $authAt, $now + (self::TTL - 600));

        $this->sendWithCookie('GET', '/api/me', $forged);

        $reissued = $this->reissuedBearer();
        self::assertNotNull($reissued);
        $new = $this->jwtManager()->parse($reissued);
        self::assertSame($authAt, $new['auth_at'], 'auth_at traverse la ré-émission sans jamais être réinitialisé');
        self::assertGreaterThanOrEqual($now, $new['iat'], 'iat est rafraîchi, auth_at non');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->managerEmail = $this->seedManager();
    }

    /**
     * Forge un jeton de la bonne identité avec `iat`, `auth_at` et `exp` arbitraires.
     * On part d'un vrai jeton (`create` pose l'identité + les rôles + `auth_at`), on
     * en réécrit les trois horodatages, puis on re-signe : le JWS provider de lexik
     * respecte iat/exp présents dans le payload. (`createFromPayload` seul n'ajoute
     * PAS le claim d'identité — il faut le reprendre du jeton de base.)
     */
    private function forge(int $iat, int $authAt, int $exp): string
    {
        $manager = $this->manager();
        $payload = $this->jwtManager()->parse($this->jwtManager()->create($manager));
        $payload['iat'] = $iat;
        $payload['auth_at'] = $authAt;
        $payload['exp'] = $exp;

        return $this->jwtManager()->createFromPayload($manager, $payload);
    }

    private function sendWithCookie(string $method, string $uri, string $jwt): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->getCookieJar()->set(new BrowserKitCookie('BEARER', $jwt, null, '/api', '', false, true));
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip(),
        ]);
    }

    private function reissuedBearer(): ?string
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('BEARER' === $cookie->getName() && '' !== (string) $cookie->getValue()) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    private function seedManager(): string
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        $uid = substr(md5(uniqid('', true)), 0, 8);

        $club = new Club;
        $club->setName('Club glissant ' . $uid);
        $club->setSlug('club-glissant-' . $uid);
        $em->persist($club);

        $manager = new User;
        $manager->setEmail('slide-mgr-' . $uid . '@test.fr');
        $manager->setFirstName('Glis');
        $manager->setLastName('Sant');
        $manager->setPasswordHash($hasher->hashPassword($manager, 'Password123!'));
        $em->persist($manager);
        $em->flush();

        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        \assert($tenant instanceof TenantConnectionContext);
        $tenant->setClubId($club->getId());
        try {
            $membership = new ClubUser;
            $membership->setClubId($club->getId());
            $membership->setUserId($manager->getId());
            $membership->setRole('manager');
            $membership->setIsActive(true);
            $em->persist($membership);
            $em->flush();
        } finally {
            $tenant->clear();
        }

        $email = $manager->getEmail();
        $em->clear();

        return $email;
    }

    private function manager(): User
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->managerEmail]);
        \assert($user instanceof User);

        return $user;
    }

    private function ip(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    private function jwtManager(): JWTTokenManagerInterface
    {
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($manager instanceof JWTTokenManagerInterface);

        return $manager;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
