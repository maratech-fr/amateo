<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Geo;

use App\Service\Geo\IgnRoutingClient;
use App\Tests\Double\RecordingLogger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * C3 — le client IGN pacé. Le quota mesuré (`x-ratelimit-limit-second: 1`, 429 après
 * ~9 appels) impose UNE requête par seconde : les appels sont sérialisés et espacés
 * sur l'horloge injectée, un 429 est réessayé (Retry-After, sinon 1 s) jusqu'à 3 fois.
 * L'horloge (`MockClock`) est la couture : les `sleep` avancent le temps simulé, donc
 * le pacing et les réessais se prouvent sans aucune attente réelle.
 */
#[Group('unit')]
final class IgnRoutingClientTest extends TestCase
{
    public function testCallsArePacedAtOnePerSecondOnTheInjectedClock(): void
    {
        $clock = new MockClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new IgnRoutingClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => 600]))),
            $clock,
        );

        $start = $clock->now();
        // Trois appels d'affilée : le 1er part tout de suite, les deux suivants
        // attendent 1 s chacun → l'horloge a avancé de 2 s, sans aucune vraie attente.
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(10, $client->travelMinutes(IgnRoutingClient::PROFILE_CAR, 45.7, 4.8, 45.8, 4.9));
        }
        self::assertSame(2, $clock->now()->getTimestamp() - $start->getTimestamp(), 'deux battements de pacing entre trois appels');
    }

    public function testA429IsRetriedThenSucceeds(): void
    {
        $clock = new MockClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $logger = new RecordingLogger;
        // 1er appel 429 (Retry-After 2 s), 2e appel 200 → la valeur passe.
        $client = new IgnRoutingClient(
            new MockHttpClient([
                new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '2']]),
                new MockResponse((string) json_encode(['duration' => 600])),
            ]),
            $clock,
            $logger,
        );

        $start = $clock->now();
        self::assertSame(10, $client->travelMinutes(IgnRoutingClient::PROFILE_CAR, 45.7, 4.8, 45.8, 4.9));
        self::assertSame(2, $clock->now()->getTimestamp() - $start->getTimestamp(), 'le Retry-After de 2 s a été respecté sur l\'horloge');
        self::assertNotEmpty($logger->records, 'un 429 est journalisé');
        self::assertStringContainsString('429', $logger->records[0]['message']);
    }

    public function testThreeConsecutive429sGiveUpToNullAndWarnEachTime(): void
    {
        $clock = new MockClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $logger = new RecordingLogger;
        $client = new IgnRoutingClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 429])),
            $clock,
            $logger,
        );

        self::assertNull($client->travelMinutes(IgnRoutingClient::PROFILE_CAR, 45.7, 4.8, 45.8, 4.9), 'un 429 persistant abandonne en null');
        $warned429 = array_filter($logger->records, static fn (array $r): bool => str_contains($r['message'], '429'));
        self::assertCount(3, $warned429, 'une alerte par tentative (3 essais max)');
    }

    public function testABatchIsPacedSeriallyAndReturnsMinutes(): void
    {
        $clock = new MockClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $client = new IgnRoutingClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => 900]))),
            $clock,
        );

        $jobs = [
            ['key' => 'a', 'profile' => IgnRoutingClient::PROFILE_CAR, 'startLat' => 45.7, 'startLon' => 4.8, 'endLat' => 45.8, 'endLon' => 4.9],
            ['key' => 'b', 'profile' => IgnRoutingClient::PROFILE_PEDESTRIAN, 'startLat' => 45.7, 'startLon' => 4.8, 'endLat' => 45.8, 'endLon' => 4.9],
        ];
        $start = $clock->now();
        $result = $client->travelMinutesBatch($jobs);

        self::assertSame(['a' => 15, 'b' => 15], $result['minutes'], 'chaque job résout ses minutes (900 s → 15 min)');
        self::assertSame([], $result['budgetExceededKeys'], 'budget large : rien de sauté');
        self::assertSame(1, $clock->now()->getTimestamp() - $start->getTimestamp(), 'un battement de pacing entre les deux jobs');
    }
}
