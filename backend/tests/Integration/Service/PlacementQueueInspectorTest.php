<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Message\GenerateScheduleMessage;
use App\Message\PlaceMatchesMessage;
use App\Service\PlacementQueueInspector;
use App\Service\PlacementQueueUnreadable;
use PHPUnit\Framework\Attributes\Group;
use Redis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * NR d'axe *generation pipeline* (§7.1) — le rattrapage ne déclare « interrompu » un placement
 * PENDING que s'il est VRAIMENT absent de la file Messenger. L'inspecteur lit la file du
 * transport `async` telle que le VRAI transport Redis l'écrit (enfilage par `RedisTransport`,
 * jamais par un format recopié) :
 *
 *  - le stream (messages prêts) ET la file différée `{stream}__queue` (retries, `DelayStamp`),
 *    sans doublon, pages comprises ;
 *  - seuls les `PlaceMatchesMessage` comptent ;
 *  - UNE entrée indécodable, ou un Redis injoignable = file NON LUE (exception, jamais une liste
 *    partielle — une liste partielle ferait échouer à tort les runs qu'elle aurait tus) ; le
 *    message d'erreur nomme l'entrée, jamais son contenu ;
 *  - ordre file différée → stream → file différée : un message qui migre de l'une à l'autre
 *    (retry : ZADD avant XDEL ; échéance : ZADD XX → XADD → ZREM) est vu au moins une fois.
 *
 * Stream DÉDIÉ au test sur le Redis réel, supprimé (DEL ciblé de ses deux clés) en fin de test —
 * jamais FLUSHALL. Garde structurelle jumelle (le transport `async` reste lisible par
 * l'inspecteur) : `Unit/Documentation/PlacementQueueInspectorReadsTheAsyncTransportTest`.
 */
#[Group('phase1')]
#[Group('integration')]
final class PlacementQueueInspectorTest extends KernelTestCase
{
    private const string STREAM = 'messages_placement_inspector_test';

    public function testItListsThePlacementRunsWaitingInTheStreamAndTheDelayedQueue(): void
    {
        $transport = $this->transport();
        $transport->send(new Envelope($this->placement('run-ready')));
        $transport->send(new Envelope($this->placement('run-retry'), [new DelayStamp(600000)]));
        $transport->send(new Envelope(new GenerateScheduleMessage(scheduleId: 'schedule-x', clubId: 'club-x')));

        $ids = $this->inspector()->queuedRunIds();

        sort($ids);
        self::assertSame(['run-ready', 'run-retry'], $ids, 'prêt (stream) ET différé (retry) comptent ; un autre message ne compte pas ; aucun doublon');
    }

    public function testEveryPageOfTheStreamIsRead(): void
    {
        $transport = $this->transport();
        $expected = [];
        for ($i = 0; $i < 150; ++$i) {
            $expected[] = 'run-' . $i;
            $transport->send(new Envelope($this->placement('run-' . $i)));
        }

        $ids = $this->inspector()->queuedRunIds();

        sort($ids);
        sort($expected);
        self::assertSame($expected, $ids, 'au-delà d\'une page, aucun run en file n\'est oublié');
    }

    public function testAnUndecodableStreamEntryMakesTheWholeQueueUnreadable(): void
    {
        $this->transport()->send(new Envelope($this->placement('run-ok')));
        $garbageId = $this->rawRedis()->xAdd(self::STREAM, '*', ['message' => 'garbage-secret']);
        self::assertIsString($garbageId);

        try {
            $this->inspector()->queuedRunIds();
            self::fail('une entrée indécodable doit rendre la file illisible, jamais une liste partielle');
        } catch (PlacementQueueUnreadable $e) {
            self::assertStringContainsString($garbageId, $e->getMessage(), 'le message nomme l\'entrée en cause');
            self::assertStringNotContainsString('garbage-secret', $e->getMessage(), 'jamais le contenu de l\'entrée');
        }
    }

    public function testAnUndecodableDelayedEntryMakesTheWholeQueueUnreadable(): void
    {
        $this->transport()->send(new Envelope($this->placement('run-ok')));
        $this->rawRedis()->rawCommand('ZADD', self::STREAM . '__queue', '1', 'garbage-secret');

        $this->expectException(PlacementQueueUnreadable::class);
        $this->inspector()->queuedRunIds();
    }

    public function testAnUnreachableRedisMakesTheQueueUnreadable(): void
    {
        $inspector = new PlacementQueueInspector('redis://127.0.0.1:1/' . self::STREAM, $this->serializer());

        $this->expectException(PlacementQueueUnreadable::class);
        $inspector->queuedRunIds();
    }

    public function testTheStreamNameIsTheOneTheTransportUses(): void
    {
        self::assertSame('messages', PlacementQueueInspector::streamNameOf('redis://redis:6379/messages'));
        self::assertSame('messages', PlacementQueueInspector::streamNameOf('redis://h/'), 'sans chemin : le stream par défaut du transport');
        self::assertSame('x', PlacementQueueInspector::streamNameOf('redis://h/?stream=x'));
    }

    /**
     * L'ordre de lecture, observé sur le Redis réel par son SLOWLOG (seuil 0 le temps du test,
     * restauré ensuite) : la file différée est lue AVANT le stream et à nouveau APRÈS.
     */
    public function testTheDelayedQueueIsReadBeforeAndAfterTheStream(): void
    {
        $this->transport()->send(new Envelope($this->placement('run-ready')));
        $redis = $this->rawRedis();
        $config = $redis->config('GET', 'slowlog-log-slower-than');
        $previous = \is_array($config) ? (string) ($config['slowlog-log-slower-than'] ?? '10000') : '10000';
        $redis->config('SET', 'slowlog-log-slower-than', '0');
        $redis->slowlog('reset');

        try {
            $this->inspector()->queuedRunIds();
            $entries = $redis->slowlog('get', 1000);
        } finally {
            $redis->config('SET', 'slowlog-log-slower-than', $previous);
        }

        self::assertIsArray($entries);
        // SLOWLOG rend le plus récent d'abord : on remet dans l'ordre d'exécution.
        $sequence = [];
        foreach (array_reverse($entries) as $entry) {
            $args = array_map('strval', (array) ($entry[3] ?? []));
            $command = strtoupper($args[0] ?? '');
            $key = $args[1] ?? '';
            if (str_starts_with($command, 'Z') && self::STREAM . '__queue' === $key) {
                $sequence[] = 'queue';
            } elseif (str_starts_with($command, 'X') && self::STREAM === $key) {
                $sequence[] = 'stream';
            }
        }
        $collapsed = array_values(array_filter($sequence, static fn (string $s, int $i): bool => 0 === $i || $sequence[$i - 1] !== $s, \ARRAY_FILTER_USE_BOTH));

        self::assertSame(['queue', 'stream', 'queue'], $collapsed, 'file différée, puis stream, puis file différée à nouveau');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function placement(string $runId): PlaceMatchesMessage
    {
        return new PlaceMatchesMessage(runId: $runId, clubId: 'club-' . $runId, seasonId: null, weeksCount: 1, lockToken: 'jeton');
    }

    private function inspector(): PlacementQueueInspector
    {
        return new PlacementQueueInspector($this->dsn(), $this->serializer());
    }

    /** L'enfilage par le VRAI transport Redis (patron `AdminMessengerFailedControllerTest`). */
    private function transport(): RedisTransport
    {
        return new RedisTransport(Connection::fromDsn($this->dsn(), ['auto_setup' => false]), $this->serializer());
    }

    private function serializer(): SerializerInterface
    {
        $serializer = self::getContainer()->get('messenger.transport.native_php_serializer');
        \assert($serializer instanceof SerializerInterface);

        return $serializer;
    }

    private function dsn(): string
    {
        $parts = parse_url($this->redisUrl());
        \assert(\is_array($parts) && isset($parts['host']));

        return \sprintf('redis://%s:%d/%s', $parts['host'], (int) ($parts['port'] ?? 6379), self::STREAM);
    }

    /** Client NU (sans sérialiseur) : il écrit et lit les octets tels quels. */
    private function rawRedis(): Redis
    {
        $parts = parse_url($this->redisUrl());
        \assert(\is_array($parts) && isset($parts['host']));
        $redis = new Redis;
        $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379));
        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }

        return $redis;
    }

    private function redisUrl(): string
    {
        return (string) ($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? 'redis://redis:6379/0');
    }

    private function cleanup(): void
    {
        // DEL ciblé des deux clés du stream de test — jamais FLUSHALL.
        $this->rawRedis()->del(self::STREAM, self::STREAM . '__queue');
    }
}
