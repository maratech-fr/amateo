<?php

declare(strict_types=1);

namespace App\Service;

use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Envoi des conflits aux coachs — anti double clic SIMULTANÉ, par club (patron
 * {@see MatchPlacementLock} : SET NX EX + compare-and-delete du jeton), sous son PROPRE préfixe.
 * La mémoire du dernier envoi est lue et écrite sous ce verrou. Il ne protège que deux envois
 * simultanés : deux clics à quelques secondes d'écart envoient deux fois la liste à jour.
 */
class ConflictMailingLock
{
    private const string KEY_PREFIX = 'conflict_mailing:club:';

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
    ) {}

    public function acquire(string $clubId, int $ttlSeconds): ?string
    {
        $redis = $this->connect();
        $token = bin2hex(random_bytes(16));

        $acquired = $redis->set(self::KEY_PREFIX . $clubId, $token, ['nx', 'ex' => max(1, $ttlSeconds)]);

        return $acquired ? $token : null;
    }

    public function release(string $clubId, string $token): void
    {
        $this->connect()->eval(
            'if redis.call(\'get\', KEYS[1]) == ARGV[1] then return redis.call(\'del\', KEYS[1]) else return 0 end',
            [self::KEY_PREFIX . $clubId, $token],
            1,
        );
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
