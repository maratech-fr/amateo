<?php

declare(strict_types=1);

namespace App\Service;

use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verrou par club du calcul ASYNCHRONE des trajets (C6) — patron exact de
 * {@see MatchPlacementLock} (SETEX NX + compare-and-delete par token), sous son PROPRE
 * préfixe. Un seul calcul de trajets à la fois par club (un second dispatch attend).
 *
 * Sa clé fait DOUBLE emploi (C5) : tant qu'elle est posée, un calcul est EN COURS pour
 * ce club, ce que le contrôleur lit via {@see isHeld} pour servir `travelStatus: pending`
 * sur `GET /api/opponents/travel`. En C5, personne ne l'acquiert encore (le calcul reste
 * synchrone) → `isHeld` répond toujours faux → `pending` ne se produit jamais ; C6 la
 * rend vivante en l'acquérant dans le handler.
 */
class TravelComputeLock
{
    private const KEY_PREFIX = 'travel_compute:club:';

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
    ) {}

    public function acquire(string $clubId, int $ttlSeconds): ?string
    {
        $redis = $this->connect();
        $token = bin2hex(random_bytes(16));
        $ttlSeconds = max(1, $ttlSeconds);

        $acquired = $redis->set(self::KEY_PREFIX . $clubId, $token, ['nx', 'ex' => $ttlSeconds]);

        return $acquired ? $token : null;
    }

    public function release(string $clubId, string $token): void
    {
        $redis = $this->connect();
        $key = self::KEY_PREFIX . $clubId;

        // Atomic compare-and-delete: only the token holder may delete.
        $redis->eval(
            'if redis.call(\'get\', KEYS[1]) == ARGV[1] then return redis.call(\'del\', KEYS[1]) else return 0 end',
            [$key, $token],
            1,
        );
    }

    /** True while a travel computation is in flight for this club (feeds `travelStatus: pending`). */
    public function isHeld(string $clubId): bool
    {
        return (bool) $this->connect()->exists(self::KEY_PREFIX . $clubId);
    }

    private function connect(): Redis
    {
        $parts = parse_url($this->redisUrl);
        if (!\is_array($parts) || !isset($parts['host'])) {
            throw new RuntimeException('REDIS_URL is invalid.');
        }

        $redis = new Redis;
        $redis->connect($parts['host'], (int) ($parts['port'] ?? 6379));

        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        if (isset($parts['path']) && '' !== $parts['path'] && '/' !== $parts['path']) {
            $database = ltrim($parts['path'], '/');
            if (ctype_digit($database)) {
                $redis->select((int) $database);
            }
        }

        return $redis;
    }
}
