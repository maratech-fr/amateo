<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\MatchPlacementLock;
use Redis;

/**
 * Sonde du verrou de placement d'un club (`match_placement:club:{id}`), lue EN DIRECT dans Redis
 * — indépendante de l'API de {@see MatchPlacementLock} : un test qui vérifie que le verrou n'a
 * PAS été touché ne doit pas dépendre du code qu'il surveille.
 *
 * Les clés posées par le test sont notées et supprimées par DEL CIBLÉ en fin de test
 * (`releasePlacementLockKeys()` à appeler dans `tearDown`) — jamais FLUSHALL.
 */
trait PlacementLockProbeTrait
{
    /** @var list<string> */
    private array $placementLockKeys = [];

    /** Prend le verrou du club comme le ferait le contrôleur ; rend le jeton. */
    private function holdPlacementLock(string $clubId, int $ttlSeconds): string
    {
        $this->notePlacementLockKey($clubId);
        $lock = static::getContainer()->get(MatchPlacementLock::class);
        \assert($lock instanceof MatchPlacementLock);
        $token = $lock->acquire($clubId, $ttlSeconds);
        self::assertIsString($token, 'décor : le verrou du club doit être libre');

        return $token;
    }

    private function notePlacementLockKey(string $clubId): void
    {
        $this->placementLockKeys[] = 'match_placement:club:' . $clubId;
    }

    private function placementLockValue(string $clubId): ?string
    {
        $value = $this->placementRedis()->get('match_placement:club:' . $clubId);

        return \is_string($value) ? $value : null;
    }

    private function placementLockTtl(string $clubId): int
    {
        return (int) $this->placementRedis()->ttl('match_placement:club:' . $clubId);
    }

    private function releasePlacementLockKeys(): void
    {
        if ([] === $this->placementLockKeys) {
            return;
        }
        $redis = $this->placementRedis();
        foreach ($this->placementLockKeys as $key) {
            $redis->del($key); // DEL ciblé de nos seules clés — jamais FLUSHALL
        }
        $this->placementLockKeys = [];
    }

    private function placementRedis(): Redis
    {
        $url = (string) ($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? 'redis://redis:6379/0');
        $parts = parse_url($url);
        \assert(\is_array($parts) && isset($parts['host']));
        $redis = new Redis;
        $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379));
        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        $database = ltrim((string) ($parts['path'] ?? ''), '/');
        if (ctype_digit($database)) {
            $redis->select((int) $database);
        }

        return $redis;
    }
}
