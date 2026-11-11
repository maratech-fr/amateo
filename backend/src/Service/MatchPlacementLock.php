<?php

declare(strict_types=1);

namespace App\Service;

use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Anti-double-clic of the synchronous match placement (P1-4 PR D) — the exact
 * ClubGenerationLock pattern (SETEX NX + token compare-and-delete) under its
 * OWN prefix: generating a weekly plan and placing matches touch disjoint
 * data (nothing of the match module enters the weekly payload), so neither
 * gesture must ever block the other.
 */
class MatchPlacementLock
{
    private const KEY_PREFIX = 'match_placement:club:';

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

        // Atomic compare-and-delete (BCK-02 idiom): only the token holder may
        // delete, check + delete in one Redis operation.
        $redis->eval(
            'if redis.call(\'get\', KEYS[1]) == ARGV[1] then return redis.call(\'del\', KEYS[1]) else return 0 end',
            [$key, $token],
            1,
        );
    }

    /**
     * Réarme le verrou au budget du run qui DÉMARRE — seulement pour son détenteur (compare-and-
     * expire atomique). `false` : la clé a expiré (TTL de l'enfilage fondu dans la file) ou un autre
     * jeton la tient ; l'appelant la reprend ou s'efface.
     */
    public function extend(string $clubId, string $token, int $ttlSeconds): bool
    {
        $redis = $this->connect();

        $extended = $redis->eval(
            'if redis.call(\'get\', KEYS[1]) == ARGV[1] then return redis.call(\'expire\', KEYS[1], ARGV[2]) else return 0 end',
            [self::KEY_PREFIX . $clubId, $token, (string) max(1, $ttlSeconds)],
            1,
        );

        return 1 === $extended;
    }

    /**
     * Délai restant du verrou, en secondes arrondies au SUPÉRIEUR (le « au plus » du 409) ;
     * `null` si aucune clé (ou clé sans expiration).
     */
    public function remainingSeconds(string $clubId): ?int
    {
        $milliseconds = $this->connect()->pttl(self::KEY_PREFIX . $clubId);
        if (!\is_int($milliseconds) || $milliseconds < 0) {
            return null;
        }

        return (int) ceil($milliseconds / 1000);
    }

    /** Le verrou du club est-il tenu (par qui que ce soit) ? */
    public function isHeld(string $clubId): bool
    {
        $exists = $this->connect()->exists(self::KEY_PREFIX . $clubId);

        return \is_int($exists) && $exists > 0;
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
