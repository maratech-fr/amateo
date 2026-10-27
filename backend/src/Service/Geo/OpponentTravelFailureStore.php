<?php

declare(strict_types=1);

namespace App\Service\Geo;

use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * M14 (retours Nico, BCCL 2026-10-27) — mémorise la CAUSE d'échec du DERNIER calcul de
 * trajets, par club+saison, pour que le bandeau Adversaires distingue « en attente de
 * relance » (le budget worker s'est épuisé avant d'atteindre la paire — une relance
 * reprend) de « introuvable » (IGN muet / pas d'itinéraire / gymnase mal géolocalisé —
 * une relance n'y changera rien tant que la donnée ne bouge pas).
 *
 * État VOLATILE, pas une donnée métier : vit dans Redis (même connexion que
 * {@see TravelComputeLock}), sous UNE clé par club+saison, avec un TTL long — PAS de table.
 * La clé est écrasée à chaque run (la carte reflète le DERNIER calcul : un code résolu
 * depuis en disparaît). ⚠ Jamais de FLUSHALL : SET/GET/DEL ciblés sur la seule clé.
 *
 * La carte est keyée par CLÉ D'APPARIEMENT de l'adversaire (code fédéral, ou clé sentinelle
 * d'un sans-code — la même que {@see OpponentVenueLink::getOpponentOrganismeCode}), pour que
 * le contrôleur retrouve la cause d'une ligne de gymnase sans redériver quoi que ce soit.
 */
final class OpponentTravelFailureStore
{
    public const string REASON_AWAITING_RETRY = 'awaiting_retry';
    public const string REASON_UNROUTABLE = 'unroutable';

    private const string KEY_PREFIX = 'opponent_travel_failures:';

    /** Le budget worker s'épuise/l'IGN reste muet pour des semaines : TTL long (30 jours). */
    private const int TTL_SECONDS = 2_592_000;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
    ) {}

    /**
     * Enregistre la carte des causes du dernier run (clé d'appariement → cause). La carte VIDE
     * efface la clé (le run n'a laissé aucun échec à signaler). Écrase toujours la carte
     * précédente : elle reflète le DERNIER calcul, jamais un cumul.
     *
     * @param array<string, self::REASON_*> $reasons
     */
    public function record(string $clubId, string $seasonId, array $reasons): void
    {
        $redis = $this->connect();
        $key = $this->key($clubId, $seasonId);
        if ([] === $reasons) {
            $redis->del($key);

            return;
        }
        $redis->setex($key, self::TTL_SECONDS, (string) json_encode($reasons));
    }

    /**
     * La carte des causes du dernier run pour ce club+saison (clé d'appariement → cause), ou
     * un tableau vide si aucune n'est mémorisée (jamais calculé, expiré, ou tout résolu).
     *
     * @return array<string, string>
     */
    public function reasonsFor(string $clubId, string $seasonId): array
    {
        $raw = $this->connect()->get($this->key($clubId, $seasonId));
        if (!\is_string($raw) || '' === $raw) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }
        $reasons = [];
        /** @var mixed $reason */
        foreach ($decoded as $code => $reason) {
            if (\is_string($code) && \is_string($reason)) {
                $reasons[$code] = $reason;
            }
        }

        return $reasons;
    }

    private function key(string $clubId, string $seasonId): string
    {
        return self::KEY_PREFIX . $clubId . ':' . $seasonId;
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
