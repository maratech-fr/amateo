<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mesure d'audience de la vitrine (onglet « Statistiques », P4-307), lue d'Umami.
 *
 * TROIS états, et la route répond TOUJOURS 200 (un tiers lent ou absent ne fait jamais tomber la
 * console) :
 *  - clé / identifiant de site non configuré → `{configured:false}` ({@see UmamiStatsClient} inerte) ;
 *  - Umami injoignable ou en erreur → `{configured:true, available:false}` ;
 *  - sinon → les deux fenêtres (7 j / 30 j) avec leur tendance, plus le top pages et le top sources.
 *
 * La lecture est MISE EN CACHE (`cache.app`) pour ne pas marteler Umami ni ralentir la console :
 * {@see self::SUCCESS_TTL} sur un succès, {@see self::FAILURE_TTL} sur un échec (plus court : on
 * retente plus vite une fois Umami revenu). La clé de cache est stable.
 *
 * @phpstan-type AudienceWindow array{visitors: int, visits: int, pageviews: int, trend: int}
 * @phpstan-type AudienceData array{configured: true, available: true, windows: array<string, AudienceWindow>, topPages: list<array{value: string, count: int}>, topSources: list<array{value: string, count: int}>}
 * @phpstan-type AudienceComputed array{configured: true, available: false}|AudienceData
 * @phpstan-type AudiencePayload array{configured: false}|AudienceComputed
 */
final class AdminAudienceService
{
    private const int TOP_LIMIT = 5;

    private const int SUCCESS_TTL = 300;

    private const int FAILURE_TTL = 60;

    private const string CACHE_KEY = 'admin.audience.v1';

    public function __construct(
        private readonly UmamiStatsClient $umami,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /**
     * @return AudiencePayload
     */
    public function audience(): array
    {
        if (!$this->umami->isConfigured()) {
            return ['configured' => false];
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            /** @var AudienceComputed $cached */
            $cached = $item->get();

            return $cached;
        }

        $result = $this->compute();
        $item->set($result);
        $item->expiresAfter($result['available'] ? self::SUCCESS_TTL : self::FAILURE_TTL);
        $this->cache->save($item);

        return $result;
    }

    /**
     * @return AudienceComputed
     */
    private function compute(): array
    {
        $now = $this->clock->now();
        $endMs = $now->getTimestamp() * 1000;

        try {
            $windows = [
                'd7' => $this->window($now, 7, $endMs),
                'd30' => $this->window($now, 30, $endMs),
            ];
            $topPages = $this->umami->topMetrics('path', $this->startMs($now, 30), $endMs, self::TOP_LIMIT);
            $topSources = $this->umami->topMetrics('referrer', $this->startMs($now, 30), $endMs, self::TOP_LIMIT);
        } catch (UmamiStatsException) {
            return ['configured' => true, 'available' => false];
        }

        return [
            'configured' => true,
            'available' => true,
            'windows' => $windows,
            'topPages' => $topPages,
            'topSources' => $topSources,
        ];
    }

    /** @return AudienceWindow */
    private function window(DateTimeImmutable $now, int $days, int $endMs): array
    {
        $stats = $this->umami->stats($this->startMs($now, $days), $endMs);

        return [
            'visitors' => $stats['visitors'],
            'visits' => $stats['visits'],
            'pageviews' => $stats['pageviews'],
            'trend' => $this->trend($stats['visitors'], $stats['comparison']['visitors']),
        ];
    }

    private function startMs(DateTimeImmutable $now, int $days): int
    {
        return $now->modify('-' . $days . ' days')->getTimestamp() * 1000;
    }

    /** Variation en % des visiteurs face à la période précédente (positive = en hausse). */
    private function trend(int $current, int $previous): int
    {
        if (0 === $previous) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }
}
