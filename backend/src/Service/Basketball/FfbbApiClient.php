<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * SSRF-safe client for the FFBB public API (lot C). TWO fixed hosts only:
 *  - api.ffbb.com (Directus) → public Meilisearch token from /items/configuration
 *  - meilisearch-prod.ffbb.app → organisme search (index ffbbserver_organismes)
 *
 * See backend/docs/ffbb-api.md. Hardening: hosts are hard-coded (never derived
 * from input); the club code is format-validated (isValidClubCode) before any
 * call; redirects are disabled (max_redirects=0) so a compromised endpoint
 * cannot bounce us to an internal address; a tight timeout bounds each call.
 * The public token is cached in-memory and refetched once on a 401 (rotation).
 */
final class FfbbApiClient
{
    private const CONFIG_URL = 'https://api.ffbb.com/items/configuration';
    private const SEARCH_URL = 'https://meilisearch-prod.ffbb.app/multi-search';
    private const ORIGIN = 'https://competitions.ffbb.com';
    private const INDEX = 'ffbbserver_organismes';
    private const TIMEOUT = 8.0;

    /** FFBB club code: league prefix (2-4 letters) + 7 digits, e.g. ARA0069036. */
    private const CLUB_CODE_RE = '/^[A-Z]{2,4}\d{7}$/';

    private ?string $token = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $ffbbMeilisearchToken = '',
    ) {
        // Optional prod override; else the public token is fetched at runtime.
        if ('' !== $this->ffbbMeilisearchToken) {
            $this->token = $this->ffbbMeilisearchToken;
        }
    }

    public static function isValidClubCode(string $code): bool
    {
        return 1 === preg_match(self::CLUB_CODE_RE, $code);
    }

    /** The `code` of a FFBB organisme sub-object, or null when absent/malformed. */
    private static function organismeCode(mixed $organisme): ?string
    {
        if (!\is_array($organisme)) {
            return null;
        }
        $code = $organisme['code'] ?? null;

        return \is_string($code) && '' !== $code ? $code : null;
    }

    /**
     * Search organismes by free text (club code, or committee/league name to
     * resolve a parent). Returns the raw hit list (possibly empty). Transport
     * failures propagate — callers (FfbbClubPopulator) treat them best-effort.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 3): array
    {
        return $this->query(['indexUid' => self::INDEX, 'q' => $query, 'limit' => $limit]);
    }

    /**
     * The club's engagements (P1-4 PR F). ⚠ Sondé le 2026-08-03 : `codeClub`
     * n'est PAS filtrable et `idOrganisme` (filtrable) est NULL dans les
     * données — le filtre Meilisearch est donc inutilisable ici. Repli mesuré :
     * recherche plein texte sur le code (283 hits) puis filtre STRICT serveur
     * sur le champ `codeClub` (→ les 14 vrais). Le code est re-validé avant
     * appel (SSRF/format).
     *
     * @return list<array<string, mixed>>
     */
    public function searchEngagements(string $clubCode): array
    {
        if (!self::isValidClubCode($clubCode)) {
            return [];
        }
        $hits = $this->query(['indexUid' => 'ffbbserver_engagements', 'q' => $clubCode, 'limit' => 300]);

        return array_values(array_filter($hits, static fn (array $hit): bool => ($hit['codeClub'] ?? null) === $clubCode));
    }

    /**
     * The club's rencontres (RMM-4 PR-3, canal API FFBB). Same measured pitfall
     * as the engagements: the free-text search rains noise (an « AMICAL PNM » hit
     * that does NOT concern the club, mesuré 2026-08-24), so a STRICT server-side
     * filter keeps only the hits where the club code appears on ONE of the two
     * organismes — `idOrganismeEquipe1.code` OR `idOrganismeEquipe2.code`. The
     * season is filtered downstream by {@see FfbbRencontreReader} (it reads
     * `saison.code` on the hit). Code re-validated before the call (SSRF/format).
     *
     * @return list<array<string, mixed>>
     */
    public function searchRencontres(string $clubCode): array
    {
        if (!self::isValidClubCode($clubCode)) {
            return [];
        }
        $hits = $this->query(['indexUid' => 'ffbbserver_rencontres', 'q' => $clubCode, 'limit' => 300]);

        return array_values(array_filter(
            $hits,
            static fn (array $hit): bool => self::organismeCode($hit['idOrganismeEquipe1'] ?? null) === $clubCode
                || self::organismeCode($hit['idOrganismeEquipe2'] ?? null) === $clubCode,
        ));
    }

    /**
     * Competitions by CODE + season (P1-4 PR F). `id` n'est pas filtrable
     * (sondé) mais `code` et `saison.code` le sont — le code (« PRM ») est
     * national, l'appelant discrimine ensuite par `id` (porté par
     * l'engagement). Les deux valeurs sont échappées par liste blanche stricte.
     *
     * @return list<array<string, mixed>>
     */
    public function searchCompetitionsByCode(string $competitionCode, string $seasonCode): array
    {
        if (1 !== preg_match('/^[A-Z0-9]{1,12}$/', $competitionCode) || 1 !== preg_match('/^\d{2}-\d{2}$/', $seasonCode)) {
            return [];
        }

        return $this->query([
            'indexUid' => 'ffbbserver_competitions',
            'q' => '',
            'filter' => \sprintf('code = \'%s\' AND saison.code = \'%s\'', $competitionCode, $seasonCode),
            'limit' => 50,
        ]);
    }

    /**
     * Les salles PROCHES d'un point (P2-21 lot D — « cochez vos gymnases parmi
     * ceux d'à côté », §6.9, validé 9/9 sur BCCL). `_geoRadius` + tri
     * `_geoPoint` marchent avec la clé search-only (mesuré). Le rayon vient de
     * l'appelant (paliers 3/5/10/20 km, auto-élargi côté contrôleur) ; lat/lng
     * sont des floats FORMATÉS ici — jamais une chaîne d'entrée (anti-injection,
     * même règle que le CP).
     *
     * @return list<array<string, mixed>>
     */
    public function searchSallesNearby(float $latitude, float $longitude, int $radiusMeters): array
    {
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0 || $radiusMeters < 100 || $radiusMeters > 50_000) {
            return [];
        }
        $point = \sprintf('%.6F, %.6F', $latitude, $longitude);

        return $this->query([
            'indexUid' => 'ffbbserver_salles',
            'q' => '',
            'filter' => \sprintf('_geoRadius(%s, %d)', $point, $radiusMeters),
            'sort' => [\sprintf('_geoPoint(%s):asc', $point)],
            'limit' => 60,
        ]);
    }

    /**
     * Les salles d'une COMMUNE (P2-20, autocomplétion des gymnases du wizard).
     * L'index `ffbbserver_salles` n'est pas relié aux clubs — seulement aux
     * communes : `commune.codePostal` est le seul filtre utile (mesuré,
     * cadrage api-ffbb-completion-club §3). Le CP est validé par format avant
     * toute interpolation dans le filtre (même règle que les autres search*).
     *
     * @return list<array<string, mixed>>
     */
    public function searchSalles(string $postalCode): array
    {
        if (1 !== preg_match('/^\d{5}$/', $postalCode)) {
            return [];
        }

        return $this->query([
            'indexUid' => 'ffbbserver_salles',
            'q' => '',
            'filter' => \sprintf('commune.codePostal = \'%s\'', $postalCode),
            'limit' => 50,
        ]);
    }

    /**
     * Salles by free-text NAME (P2-54 RMM-9 — localiser la salle d'un adversaire à
     * partir de son libellé). Plein-texte `q` sur `ffbbserver_salles` (le même index
     * que {@see searchSalles}, mais sans filtre commune) : le hit porte `libelle`,
     * `_geo` {lat,lng} et `commune` {libelle, codePostal} (sondé 2026-08-27).
     * L'appelant retient un hit SEULEMENT si son libellé normalisé concorde
     * exactement — Meilisearch étant typo-tolérant. Le nom est borné en longueur
     * avant l'appel (garde-fou, jamais une chaîne non bornée en `q`) ; limit petit.
     *
     * @return list<array<string, mixed>>
     */
    public function searchSallesByName(string $name, int $limit = 5): array
    {
        $trimmed = trim($name);
        $length = mb_strlen($trimmed);
        if ($length < 2 || $length > 180) {
            return [];
        }

        return $this->query(['indexUid' => 'ffbbserver_salles', 'q' => $trimmed, 'limit' => $limit]);
    }

    /**
     * @param array<string, mixed> $searchQuery
     *
     * @return list<array<string, mixed>>
     */
    private function query(array $searchQuery): array
    {
        $response = $this->postSearch($searchQuery);
        if (401 === $response->getStatusCode()) {
            // Token rotated (or the env override is stale): drop it and refetch
            // the public token from the config endpoint, then retry once.
            $this->token = null;
            $response = $this->postSearch($searchQuery);
        }

        $data = $response->toArray(false);
        $hits = $data['results'][0]['hits'] ?? null;

        return \is_array($hits) ? array_values(array_filter($hits, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $searchQuery
     */
    private function postSearch(array $searchQuery): ResponseInterface
    {
        return $this->httpClient->request('POST', self::SEARCH_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Content-Type' => 'application/json',
            ],
            'json' => ['queries' => [$searchQuery]],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'max_redirects' => 0,
        ]);
    }

    private function token(): string
    {
        if (null !== $this->token && '' !== $this->token) {
            return $this->token;
        }

        $data = $this->httpClient->request('GET', self::CONFIG_URL, [
            'headers' => [
                'Origin' => self::ORIGIN,
                'Referer' => self::ORIGIN . '/',
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'max_redirects' => 0,
        ])->toArray(false);

        $key = $data['data']['key_ms'] ?? null;
        if (!\is_string($key) || '' === $key) {
            throw new RuntimeException('FFBB config endpoint did not return a Meilisearch token.');
        }

        return $this->token = $key;
    }
}
