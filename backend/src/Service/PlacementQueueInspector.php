<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\PlaceMatchesMessage;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

/**
 * Lit, en LECTURE SEULE, la file Messenger du transport `async` telle que le transport Redis
 * l'écrit, et rend les runs de placement qui y attendent — pour que le rattrapage ne déclare
 * jamais « interrompu » un placement PENDING encore en file.
 *
 * Deux endroits : le stream (messages prêts ; `delete_after_ack` : il ne contient que des
 * messages non acquittés) et la file différée `{stream}__queue` (retries, `DelayStamp`, en JSON
 * brut). Ordre FIXÉ : file différée → stream → file différée, union des trois lectures : un
 * message qui migre de l'une à l'autre (retry : ZADD avant XDEL ; échéance : ZADD XX → XADD → ZREM)
 * est vu au moins une fois.
 *
 * Le client Redis est configuré par le transport lui-même (`Connection::fromDsn`, non paresseux :
 * sérialiseur PHP, auth, base, TLS) — l'inspecteur lit donc les octets comme le receveur du worker
 * (`RedisReceiver`). Il suppose que le transport `async` n'a ni `options:` ni DSN paresseux
 * (`PlacementQueueInspectorReadsTheAsyncTransportTest`).
 *
 * Règle : UNE entrée indécodable, ou un Redis injoignable = file NON lue ({@see PlacementQueueUnreadable}),
 * jamais une liste partielle — une liste partielle ferait échouer à tort les runs qu'elle aurait tus.
 * Le message d'erreur nomme l'entrée, jamais son contenu.
 */
class PlacementQueueInspector
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        #[Autowire(env: 'MESSENGER_TRANSPORT_DSN')]
        private readonly string $dsn,
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private readonly SerializerInterface $serializer,
    ) {}

    /** Le stream du transport, lu comme `Connection::fromDsn` : chemin, puis `?stream=`, puis `messages`. */
    public static function streamNameOf(string $dsn): string
    {
        $parts = parse_url($dsn);
        $parts = \is_array($parts) ? $parts : [];

        $pathParts = explode('/', rtrim($parts['path'] ?? '', '/'));
        if (isset($pathParts[1]) && '' !== $pathParts[1]) {
            return $pathParts[1];
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            if (\is_string($query['stream'] ?? null) && '' !== $query['stream']) {
                return $query['stream'];
            }
        }

        return 'messages';
    }

    /** @return list<string> les ids des runs de placement en file (sans doublon) */
    public function queuedRunIds(): array
    {
        $redis = $this->connect();
        $stream = self::streamNameOf($this->dsn);
        $queue = $stream . '__queue';

        $runIds = [];
        foreach ($this->readDelayedQueue($redis, $queue) as $runId) {
            $runIds[$runId] = true;
        }
        foreach ($this->readStream($redis, $stream) as $runId) {
            $runIds[$runId] = true;
        }
        foreach ($this->readDelayedQueue($redis, $queue) as $runId) {
            $runIds[$runId] = true;
        }

        return array_map(strval(...), array_keys($runIds));
    }

    private function connect(): Redis
    {
        try {
            $redis = new Redis;
            Connection::fromDsn($this->dsn, [], $redis);
        } catch (Throwable $exception) {
            throw new PlacementQueueUnreadable('Messenger queue not reachable: ' . $exception::class, 0, $exception);
        }

        return $redis;
    }

    /** @return list<string> */
    private function readDelayedQueue(Redis $redis, string $queue): array
    {
        try {
            $members = $redis->rawCommand('ZRANGE', $queue, '0', '-1');
        } catch (Throwable $exception) {
            throw new PlacementQueueUnreadable('Messenger delayed queue not readable: ' . $exception::class, 0, $exception);
        }
        if (!\is_array($members)) {
            throw new PlacementQueueUnreadable('Messenger delayed queue not readable.');
        }

        $runIds = [];
        foreach (array_values($members) as $index => $member) {
            $runId = $this->runIdOf(\is_string($member) ? $member : null, \sprintf('delayed queue entry #%d', $index));
            if (null !== $runId) {
                $runIds[] = $runId;
            }
        }

        return $runIds;
    }

    /** @return list<string> */
    private function readStream(Redis $redis, string $stream): array
    {
        $runIds = [];
        $start = '-';

        while (true) {
            try {
                $entries = $redis->xRange($stream, $start, '+', self::PAGE_SIZE);
            } catch (Throwable $exception) {
                throw new PlacementQueueUnreadable('Messenger stream not readable: ' . $exception::class, 0, $exception);
            }
            if (!\is_array($entries)) {
                throw new PlacementQueueUnreadable('Messenger stream not readable.');
            }

            $lastId = null;
            foreach ($entries as $id => $fields) {
                $lastId = (string) $id;
                $raw = \is_array($fields) && \is_string($fields['message'] ?? null) ? $fields['message'] : null;
                $runId = $this->runIdOf($raw, \sprintf('stream entry %s', $lastId));
                if (null !== $runId) {
                    $runIds[] = $runId;
                }
            }

            if (null === $lastId || \count($entries) < self::PAGE_SIZE) {
                return $runIds;
            }
            $start = '(' . $lastId;
        }
    }

    /**
     * Décode une entrée comme le receveur du worker (`RedisReceiver`) : JSON `{body, headers}` puis
     * le sérialiseur du transport. Rend l'id du run pour un `PlaceMatchesMessage`, `null` pour tout
     * autre message ; lève sur une entrée indécodable (jamais de repli).
     */
    private function runIdOf(?string $raw, string $entryName): ?string
    {
        $decoded = null !== $raw ? json_decode($raw, true) : null;
        if (!\is_array($decoded) || !\array_key_exists('body', $decoded) || !\array_key_exists('headers', $decoded) || !\is_array($decoded['headers'])) {
            throw new PlacementQueueUnreadable(\sprintf('Messenger queue not readable: %s is undecodable.', $entryName));
        }

        try {
            $envelope = $this->serializer->decode([
                'body' => \is_string($decoded['body']) ? $decoded['body'] : '',
                'headers' => $decoded['headers'],
            ]);
        } catch (Throwable $exception) {
            throw new PlacementQueueUnreadable(\sprintf('Messenger queue not readable: %s is undecodable.', $entryName), 0, $exception);
        }

        $message = $envelope->getMessage();

        return $message instanceof PlaceMatchesMessage ? $message->getRunId() : null;
    }
}
