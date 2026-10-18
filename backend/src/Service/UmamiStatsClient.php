<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * La COUTURE sortante de l'onglet « Statistiques » (console superadmin) : tout ce qui part vers
 * l'instance Umami INTERNE qui mesure l'audience de la vitrine. Lecture seule, best-effort.
 *
 * Durcissement (même posture que {@see GitHubDeployClient}) :
 *  - l'HÔTE est une CONSTANTE ({@see self::API_HOST}), jamais dérivé d'une config ni d'une entrée ;
 *  - la clé API voyage UNIQUEMENT en en-tête `Authorization: Bearer …` — jamais dans l'URL, la
 *    query ni un corps — et {@see UmamiStatsException} est expurgée par construction ;
 *  - redirections coupées (`max_redirects` 0, SSRF-safe), timeout serré (3 s) ;
 *  - clé OU identifiant de site absent → le client est INERTE ({@see self::isConfigured()} faux)
 *    et n'émet aucune requête.
 *
 * L'API Umami répond `{pageviews, visitors, visits, bounces, totaltime, comparison:{…}}` sur
 * `/api/websites/{id}/stats` (compteurs du courant + période de comparaison), et une LISTE
 * `[{x, y}]` sur `/api/websites/{id}/metrics` (top pages / sources). Le client parse en compteurs
 * typés et en lignes `{value, count}`.
 */
final class UmamiStatsClient
{
    public const string API_HOST = 'http://umami:3000';

    private const float TIMEOUT = 3.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $websiteId,
    ) {}

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey && '' !== $this->websiteId;
    }

    /**
     * Compteurs d'une fenêtre (epoch ms), plus la période de comparaison (`compare=prev`) qui
     * porte la tendance.
     *
     * @return array{visitors: int, visits: int, pageviews: int, comparison: array{visitors: int, visits: int, pageviews: int}}
     */
    public function stats(int $startAtMs, int $endAtMs): array
    {
        $payload = $this->fetch('/api/websites/' . $this->websiteId . '/stats', [
            'startAt' => $startAtMs,
            'endAt' => $endAtMs,
            'compare' => 'prev',
        ]);

        $comparison = $payload['comparison'] ?? null;
        $comparison = \is_array($comparison) ? $comparison : [];

        return [
            'visitors' => $this->counter($payload, 'visitors'),
            'visits' => $this->counter($payload, 'visits'),
            'pageviews' => $this->counter($payload, 'pageviews'),
            'comparison' => [
                'visitors' => $this->counter($comparison, 'visitors'),
                'visits' => $this->counter($comparison, 'visits'),
                'pageviews' => $this->counter($comparison, 'pageviews'),
            ],
        ];
    }

    /**
     * Top métriques d'un `$type` (`path` = pages, `referrer` = sources) : lignes `{x, y}` d'Umami
     * mappées en `{value, count}`, bornées à `$limit` (le cap est aussi demandé à Umami).
     *
     * @return list<array{value: string, count: int}>
     */
    public function topMetrics(string $type, int $startAtMs, int $endAtMs, int $limit): array
    {
        $rows = $this->fetch('/api/websites/' . $this->websiteId . '/metrics', [
            'type' => $type,
            'startAt' => $startAtMs,
            'endAt' => $endAtMs,
            'limit' => $limit,
        ]);

        $mapped = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['x'])) {
                continue;
            }
            $mapped[] = [
                'value' => (string) $row['x'],
                'count' => $this->counter($row, 'y'),
            ];
        }

        return \array_slice($mapped, 0, $limit);
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return array<mixed>
     */
    private function fetch(string $path, array $query): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_HOST . $path, [
                'query' => $query,
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
            ]);
            // getStatusCode() ne lève pas sur 4xx/5xx (contrairement à toArray()) : on vérifie
            // nous-mêmes et on lève notre propre exception EXPURGÉE, sans jamais chaîner une
            // exception du transport porteuse des options (donc de la clé).
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            throw UmamiStatsException::unreachable('GET', $path);
        }

        if ($status < 200 || $status >= 300) {
            throw UmamiStatsException::unexpectedStatus('GET', $path, $status);
        }

        try {
            return $response->toArray();
        } catch (ExceptionInterface) {
            throw UmamiStatsException::malformed('GET', $path);
        }
    }

    /** @param array<string, mixed> $row */
    private function counter(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
