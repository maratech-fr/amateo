<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Clock\DevClockStore;
use App\Service\SeasonResolver;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Non-regression for the dev-clock → season-resolution threading (structuring
 * axis): with two seasons, pinning the simulated clock into the next season's
 * year makes /api/me report THAT season as current — i.e. the July-15 pivot is
 * driven by the clock, not real wall-time. If a resolution path ever reverts to
 * `new DateTimeImmutable('now')`, this test goes red.
 */
#[Group('integration')]
final class SeasonClockThreadingTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testPinnedClockShiftsCurrentSeason(): void
    {
        $token = $this->register();

        // Register seeds one season (current-year). Transition it → an N+1 draft.
        $seasons = $this->me($token);
        self::assertCount(1, $seasons, 'a fresh club starts with exactly one season');
        $sourceId = $seasons[0]['id'];

        // P1-5 : la bascule est réservée aux saisons payées — relais dev du geste
        // support (ce test couvre l'horloge, pas le gate, qui a ses propres NR).
        $this->request('POST', '/api/dev/mark-season-paid', $token);
        self::assertResponseStatusCodeSame(200);

        // La saison suivante ne se prépare qu'à partir du 1er mai : on se place au 15 mai de la
        // saison en cours pour la préparer (sans cela, rouge de novembre à avril).
        $this->pin(new DateTimeImmutable(\sprintf('%d-05-15T12:00:00Z', SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1)));
        $this->request('POST', '/api/seasons/' . $sourceId . '/transition', $token);
        self::assertResponseStatusCodeSame(201);

        $seasons = $this->me($token);
        self::assertCount(2, $seasons);
        usort($seasons, static fn (array $a, array $b): int => $a['startDate'] <=> $b['startDate']);
        [$older, $next] = $seasons;

        // Pin the clock into the NEXT season's year → it must become current, and
        // the older one read-only. (Under real time it would not have started.)
        $this->pin(new DateTimeImmutable($next['startDate'] . 'T12:00:00Z'));
        $after = $this->byId($this->me($token));
        self::assertTrue($after[$next['id']]['isCurrent'], 'pinned into N+1 year → N+1 is current');
        self::assertFalse($after[$older['id']]['isCurrent']);
        self::assertTrue($after[$older['id']]['isReadonly'], 'the previous season becomes read-only');

        // Pin back into the older season's year → it is current again.
        $this->pin(new DateTimeImmutable($older['startDate'] . 'T12:00:00Z'));
        $back = $this->byId($this->me($token));
        self::assertTrue($back[$older['id']]['isCurrent'], 'pinned into N year → N is current again');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        // Never leak a pinned clock into other tests.
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    private function pin(DateTimeImmutable $at): void
    {
        self::getContainer()->get(DevClockStore::class)->set($at);
    }

    /**
     * @param list<array<string, mixed>> $seasons
     *
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $seasons): array
    {
        $out = [];
        foreach ($seasons as $s) {
            $out[(string) $s['id']] = $s;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function me(string $token): array
    {
        $this->request('GET', '/api/me', $token);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $seasons = \is_array($body) && \is_array($body['seasons'] ?? null) ? $body['seasons'] : [];

        return array_values(array_filter($seasons, 'is_array'));
    }

    private function request(string $method, string $uri, string $token): void
    {
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    private function register(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'clk' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'C', 'lastName' => 'Clock', 'ara' => strtoupper($suffix), 'club_name' => 'Club Clock', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        return $token;
    }
}
