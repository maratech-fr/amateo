<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\StartsFreshBrowserSession;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * NR d'axe « auth & memberships » (§7.1, surface anonyme adjacente au register) — P4-298 :
 * le lookup public du NOM de club à l'inscription (`GET /api/register/club-lookup`). AFFICHAGE
 * SEUL — la réponse ne pré-remplit jamais `club_name` et n'expose jamais le mail.
 *
 * Ce que ce garde tient (dont les abus nommés du cadrage) :
 *  - found : la réponse ne porte QUE {status, name, city} — jamais `mail`/`email` (assertion
 *    stricte sur les CLÉS) ;
 *  - unknown franc : un code au bon format mais inconnu de la fédération → {status:"unknown"} ;
 *  - ABUS — code MALFORMÉ → AUCUN appel sortant : avec la panne transport ARMÉE, un code hors
 *    format rend quand même `unknown` (jamais `unavailable`) — preuve qu'il n'a pas touché la FFBB ;
 *  - FFBB muette → `unavailable`, jamais 500 ;
 *  - gating : le flag off (régime dev/test/Behat/e2e) rend `unavailable` SANS appel sortant,
 *    panne transport armée comprise ;
 *  - ABUS — le limiteur IP dédié `register_club_lookup` épuisé → 429 (anti-énumération de codes).
 *
 * ⚠ Redis des limiteurs : on DRAINE une IP dédiée (`$limiter->reset()` puis `consume`),
 * JAMAIS de FLUSHALL (incident coder 2026-09-04).
 */
#[Group('phase1')]
#[Group('integration')]
final class RegisterClubLookupTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private static int $ipCounter = 0;

    public function testFoundResponseContainsOnlyNameAndCityNeverMail(): void
    {
        $this->setFlag('true');
        $client = self::createClient();
        [$status, $body] = $this->lookup($client, FfbbHttpClientStub::CLUB_CODE);

        self::assertSame(200, $status);
        self::assertSame('found', $body['status'] ?? null);
        self::assertSame(FfbbHttpClientStub::CLUB_NAME, $body['name'] ?? null);
        self::assertSame(FfbbHttpClientStub::CLUB_CITY, $body['city'] ?? null);
        // La réponse n'expose QUE le strict nécessaire : jamais le mail institutionnel.
        self::assertSame(['status', 'name', 'city'], array_keys($body), 'found ne porte QUE status/name/city');
        self::assertArrayNotHasKey('mail', $body);
        self::assertArrayNotHasKey('email', $body);
    }

    public function testKnownFormatButUnknownCodeYieldsUnknown(): void
    {
        $this->setFlag('true');
        $client = self::createClient();
        [$status, $body] = $this->lookup($client, 'ARA9999998');

        self::assertSame(200, $status);
        self::assertSame('unknown', $body['status'] ?? null);
        self::assertSame(['status'], array_keys($body), 'unknown ne porte QUE son statut');
    }

    /** ABUS — un code MALFORMÉ ne déclenche AUCUN appel sortant (panne armée → resterait `unknown`). */
    public function testMalformedCodeMakesNoOutboundCall(): void
    {
        $this->setFlag('true');
        FfbbHttpClientStub::$failSearch = true; // toute recherche jetterait → unavailable
        $client = self::createClient();
        $this->clearCacheFor('NOTAFFBBCODE');
        [$status, $body] = $this->lookup($client, 'NOTAFFBBCODE');

        self::assertSame(200, $status);
        // S'il avait appelé la FFBB, la panne armée l'aurait rendu `unavailable`.
        self::assertSame('unknown', $body['status'] ?? null, 'un code malformé ne doit pas sortir vers la FFBB');
    }

    /** FFBB muette (transport en échec) → `unavailable`, jamais une 500. */
    public function testFfbbSilenceYieldsUnavailableNot500(): void
    {
        $this->setFlag('true');
        FfbbHttpClientStub::$failSearch = true;
        $client = self::createClient();
        $this->clearCacheFor('ARA9999997');
        [$status, $body] = $this->lookup($client, 'ARA9999997');

        self::assertSame(200, $status, 'une FFBB muette ne doit pas devenir une 500');
        self::assertSame('unavailable', $body['status'] ?? null);
    }

    /** Gating : flag off (régime dev/test/Behat/e2e) → `unavailable` SANS appel sortant. */
    public function testFeatureFlagOffYieldsUnavailableWithoutOutboundCall(): void
    {
        // Flag EXPLICITEMENT off (régime dev/test) ; panne armée : un appel se verrait.
        $this->setFlag('false');
        FfbbHttpClientStub::$failSearch = true;
        $client = self::createClient();
        [$status, $body] = $this->lookup($client, FfbbHttpClientStub::CLUB_CODE);

        self::assertSame(200, $status);
        self::assertSame('unavailable', $body['status'] ?? null, 'flag off → unavailable sans jamais sortir');
    }

    /** ABUS — le limiteur IP dédié épuisé répond 429 (anti-énumération de codes). */
    public function testLimiterReturns429WhenExhausted(): void
    {
        $client = self::createClient();

        $ip = '203.0.113.42';
        $factory = self::getContainer()->get('limiter.register_club_lookup');
        \assert($factory instanceof RateLimiterFactory);
        $limiter = $factory->create($ip);
        $limiter->reset();
        // Draine le budget de CETTE IP (DEL ciblé via reset + consommation, jamais FLUSHALL).
        while ($limiter->consume(1)->isAccepted()) {
            // vidange
        }

        $this->startFreshBrowserSession($client);
        $client->request('GET', '/api/register/club-lookup?code=' . FfbbHttpClientStub::CLUB_CODE, [], [], [
            'REMOTE_ADDR' => $ip,
        ]);

        self::assertSame(429, $client->getResponse()->getStatusCode(), 'le limiteur IP dédié doit répondre 429 une fois épuisé');
    }

    protected function setUp(): void
    {
        FfbbHttpClientStub::$failSearch = false;
    }

    protected function tearDown(): void
    {
        FfbbHttpClientStub::$failSearch = false;
        $this->clearFlag();
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: array<string, mixed>} [status, decoded body]
     */
    private function lookup(KernelBrowser $client, string $code): array
    {
        $this->startFreshBrowserSession($client);
        $client->request('GET', '/api/register/club-lookup?code=' . rawurlencode($code), [], [], [
            'REMOTE_ADDR' => $this->nextIp(),
        ]);

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        return [$client->getResponse()->getStatusCode(), \is_array($decoded) ? $decoded : []];
    }

    /** Supprime l'entrée de cache d'un code (déterminisme cross-run des cas panne/malformé). */
    private function clearCacheFor(string $code): void
    {
        $pool = self::getContainer()->get('cache.app');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->deleteItem('register_club_lookup.' . sha1($code));
    }

    private function nextIp(): string
    {
        $n = self::$ipCounter++;

        return \sprintf('10.45.%d.%d', intdiv($n, 254), $n % 254 + 1);
    }

    private function setFlag(string $value): void
    {
        $_SERVER['FFBB_REGISTER_EXISTENCE_CHECK'] = $value;
        $_ENV['FFBB_REGISTER_EXISTENCE_CHECK'] = $value;
        putenv('FFBB_REGISTER_EXISTENCE_CHECK=' . $value);
    }

    private function clearFlag(): void
    {
        // RESTAURE le régime de test (`false`), ne PAS unset : le défaut de conteneur est
        // `true` (fail-closed prod), un unset le ferait voir aux tests suivants (fuite).
        $this->setFlag('false');
    }
}
