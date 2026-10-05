<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Redis;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Suivi Redis de la réinitialisation ASYNCHRONE de la démo BCCL (BCK-35) — deux clés
 * sous le même Redis que les verrous de génération (patron {@see ClubGenerationLock} /
 * {@see MatchPlacementLock}) :
 *
 *  - un VERROU `demo_reset:bccl:lock` (SET NX EX, valeur = token) : sa présence = « un
 *    reset tourne ». Pris par le contrôleur à l'enfilage (anti-double-clic → 409 net),
 *    RENDU par le worker (compare-and-delete par token, idiome BCK-02). Son TTL (>
 *    durée max du seed, 600 s) garantit qu'un worker tué au SIGKILL ne fige jamais le
 *    verrou — il expire seul, et l'état « en cours » retombe sans intervention.
 *  - un STATUT `demo_reset:bccl:status` (JSON {state, at}) : l'issue du dernier reset
 *    (running au début, succeeded / failed en `finally`), posée par le worker et lue
 *    par la console. Son TTL est plus long pour que « fin / erreur » reste visible un
 *    moment après le run.
 *
 * L'état « en cours » servi par {@see self::snapshot()} se DÉRIVE du verrou, jamais d'un
 * statut `running` qui survivrait seul à un crash : un `running` sans verrou vivant est
 * traité comme périmé (null), ce qui referme la fenêtre d'un worker SIGKILL.
 */
final readonly class DemoResetTracker
{
    private const string LOCK_KEY = 'demo_reset:bccl:lock';

    private const string STATUS_KEY = 'demo_reset:bccl:status';

    /** TTL du verrou : AU-DESSUS du budget max du seed (`DemoResetRunner`, 600 s). */
    private const int LOCK_TTL_SECONDS = 900;

    /** TTL de l'issue terminale servie à la console. */
    private const int STATUS_TTL_SECONDS = 3600;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private string $redisUrl,
        // Horloge RÉELLE (comme AccountErasureService) : l'horodatage d'un reset est un
        // temps de MACHINE, jamais l'« aujourd'hui » simulé d'un club démo.
        #[Autowire(service: 'app.clock.real')]
        private ClockInterface $clock,
    ) {}

    /**
     * Tente d'ouvrir un run : SET NX du verrou. Null = un reset tourne déjà (le
     * contrôleur répond 409). Succès → statut `running` posé, token rendu au worker.
     */
    public function begin(): ?string
    {
        $redis = $this->connect();
        $token = bin2hex(random_bytes(16));

        $acquired = $redis->set(self::LOCK_KEY, $token, ['nx', 'ex' => self::LOCK_TTL_SECONDS]);
        if (!$acquired) {
            return null;
        }

        $redis->set(self::STATUS_KEY, $this->encodeStatus('running'), ['ex' => self::STATUS_TTL_SECONDS]);

        return $token;
    }

    /**
     * Pose l'issue TERMINALE (statut AVANT le relâchement : la console ne voit jamais «
     * ni en cours ni fini ») puis relâche le verrou par compare-and-delete du token
     * (seul le détenteur relâche — un token périmé est un no-op franc).
     */
    public function finish(string $token, bool $succeeded): void
    {
        $redis = $this->connect();
        $redis->set(self::STATUS_KEY, $this->encodeStatus($succeeded ? 'succeeded' : 'failed'), ['ex' => self::STATUS_TTL_SECONDS]);
        $redis->eval(
            'if redis.call(\'get\', KEYS[1]) == ARGV[1] then return redis.call(\'del\', KEYS[1]) else return 0 end',
            [self::LOCK_KEY, $token],
            1,
        );
    }

    /**
     * L'état du dernier reset, ou null si aucun : `{state: running|succeeded|failed, at}`.
     * `running` n'est servi que si le verrou vit ENCORE (un `running` orphelin — worker
     * SIGKILL, verrou expiré — est périmé et rendu comme null).
     *
     * @return array{state: string, at: string}|null
     */
    public function snapshot(): ?array
    {
        $redis = $this->connect();
        $raw = $redis->get(self::STATUS_KEY);
        if (!\is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return null;
        }
        $state = isset($decoded['state']) && \is_string($decoded['state']) ? $decoded['state'] : null;
        $at = isset($decoded['at']) && \is_string($decoded['at']) ? $decoded['at'] : null;
        if (null === $state || null === $at) {
            return null;
        }

        if ('running' === $state && !$redis->exists(self::LOCK_KEY)) {
            return null; // crash auto-réparé : plus de verrou vivant, le « en cours » ne vaut plus.
        }

        return ['state' => $state, 'at' => $at];
    }

    private function encodeStatus(string $state): string
    {
        return json_encode([
            'state' => $state,
            'at' => DateTimeImmutable::createFromInterface($this->clock->now())->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
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
