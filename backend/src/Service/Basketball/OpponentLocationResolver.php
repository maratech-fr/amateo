<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Service\FbiFixtureImporter;
use App\Service\Geo\BanGeocodingClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves WHERE an FFBB opponent plays and writes it to the GLOBAL
 * `opponent_directory` (P2-54 RMM-9). Fed best-effort after an FBI import or an
 * FFBB-API apply, and by the catch-up route — never on the solve path, never a
 * broken import/apply: every network step is guarded, an exception is logged and
 * the batch continues.
 *
 * The KEY is always the opponent's FFBB organisme code (public, federal — jamais
 * du texte libre). The location escalates, most precise first:
 *   1. the salle carried by the API rencontre hit — exact coordinates, FREE (no
 *      extra call). ⚠ Sondé 2026-08-27 : le hit rencontres porte
 *      `salle.cartographie.coordonnees.coordinates = [lng, lat]` + ville + code
 *      postal, donc l'étage 1 est un VENUE gratuit ({@see FfbbRencontreReader::readAwayOpponents}) ;
 *   2. a CITY fallback — the opponent organisme (by known code, else strict
 *      name match) → its `_geo`, else its commune/CP geocoded via the BAN → CITY ;
 *   3. rien ne résout → PAS de ligne (l'adversaire reste non localisé).
 *
 * ⚠ Le VENUE label-matché (l'ancien étage « appariement franc par nom de salle »)
 * a été RETIRÉ (revue sécurité 2026-08-28) — un label de salle xlsx est fourni par
 * le club, il ne peut pas établir une précision VENUE dans une table PARTAGÉE entre
 * tous les clubs (empoisonnement permanent) ; seul le hit API autoritatif le peut.
 *
 * A more precise resolution replaces a less precise one (VENUE > CITY), never the
 * reverse (repository upsert). An opponent already known at VENUE precision is
 * skipped — for the API channel (code en main) sans AUCUN appel réseau ; pour le
 * canal nom, le seul appel dépensé est la résolution d'organisme qui produit la
 * clé (les appels salle coûteux, eux, sont sautés).
 *
 * ⚠ Les idiomes de normalisation sont RÉUTILISÉS depuis {@see FbiFixtureImporter}
 * (`normalizeLabel`) — jamais recopiés ni déplacés (cadrage PR-2).
 */
final class OpponentLocationResolver
{
    /** Meilisearch est typo-tolérant : on élargit avant de filtrer STRICT. */
    private const int ORGANISME_SEARCH_LIMIT = 10;

    public function __construct(
        private readonly FfbbRencontreReader $reader,
        private readonly FfbbApiClient $apiClient,
        private readonly BanGeocodingClient $geocoder,
        private readonly FbiFixtureImporter $importer,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The API channel (post-apply hook): re-reads the club's away rencontres and
     * resolves each distinct opponent — code and exact salle carried by the hit.
     *
     * @return array{resolved: int, unresolved: list<string>, skipped: int}
     */
    public function resolveFromApiChannel(string $clubCode, int $seasonYear): array
    {
        try {
            $observations = $this->reader->readAwayOpponents($clubCode, $seasonYear);
        } catch (Throwable $e) {
            $this->logger->warning('Opponent directory: away rencontres fetch failed', ['error' => $e->getMessage()]);

            return ['resolved' => 0, 'unresolved' => [], 'skipped' => 0];
        }

        return $this->resolveObservations($observations);
    }

    /**
     * The FBI (xlsx) channel (post-import hook) and the catch-up route: resolves
     * the DISTINCT away opponents of a set of fixtures — an opponent NAME and its
     * away venue label only (no code, no direct venue → étages 2/3).
     *
     * @param iterable<Fixture> $fixtures
     *
     * @return array{resolved: int, unresolved: list<string>, skipped: int}
     */
    public function resolveFromFixtures(iterable $fixtures): array
    {
        return $this->resolveObservations($this->buildFixtureObservations($fixtures));
    }

    /**
     * The DISTINCT away opponents of a set of fixtures, as resolver observations
     * (name only). Exposed so the catch-up route can enforce its hard cap on
     * distinct opponents BEFORE any network call.
     *
     * The xlsx channel carries NO authoritative venue: `directVenue` stays null and
     * the resolution can only reach CITY precision (the free-text salle label is
     * club-supplied — il ne peut établir un VENUE dans la table partagée).
     *
     * @param iterable<Fixture> $fixtures
     *
     * @return list<array{organismeCode: string|null, name: string, directVenue: array{libelle: string, city: string|null, postalCode: string|null, latitude: float, longitude: float}|null}>
     */
    public function buildFixtureObservations(iterable $fixtures): array
    {
        $byKey = [];
        foreach ($fixtures as $fixture) {
            if (FixtureHomeAway::AWAY !== $fixture->getHomeAway()) {
                continue; // only an away match locates the opponent's own gym
            }
            $label = trim($fixture->getOpponentLabel());
            $key = $this->importer->normalizeLabel($label);
            if ('' === $label || '' === $key) {
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'organismeCode' => null,
                    'name' => mb_substr($label, 0, 180),
                    'directVenue' => null,
                ];
            }
        }

        return array_values($byKey);
    }

    /**
     * Resolve a batch of DISTINCT opponent observations, best-effort. Deduplicated
     * by key (organisme code when known, else normalized name) so a network call is
     * never spent twice on the same opponent.
     *
     * @param list<array{organismeCode: string|null, name: string, directVenue: array{libelle: string, city: string|null, postalCode: string|null, latitude: float, longitude: float}|null}> $observations
     *
     * @return array{resolved: int, unresolved: list<string>, skipped: int}
     */
    public function resolveObservations(array $observations): array
    {
        $resolved = 0;
        $skipped = 0;
        $unresolved = [];
        $seen = [];
        $wrote = false;

        foreach ($observations as $observation) {
            $dedupKey = $observation['organismeCode'] ?? $this->importer->normalizeLabel($observation['name']);
            if ('' === $dedupKey || isset($seen[$dedupKey])) {
                continue;
            }
            $seen[$dedupKey] = true;

            try {
                $outcome = $this->resolveOne($observation);
            } catch (Throwable $e) {
                // Best-effort intégral : une panne réseau (ou une donnée FFBB
                // inattendue) sur UN adversaire n'interrompt jamais la boucle.
                $this->logger->warning('Opponent directory: resolution failed', ['name' => $observation['name'], 'error' => $e->getMessage()]);
                $unresolved[] = $observation['name'];
                continue;
            }

            if ('resolved' === $outcome) {
                ++$resolved;
                $wrote = true;
            } elseif ('skipped' === $outcome) {
                ++$skipped;
            } else {
                $unresolved[] = $observation['name'];
            }
        }

        if ($wrote) {
            try {
                $this->entityManager->flush();
            } catch (Throwable $e) {
                // Une écriture concurrente sur le même code (unicité) — l'autre
                // requête a gagné, la donnée partagée est là : on log, jamais un 500.
                $this->logger->warning('Opponent directory: flush failed', ['error' => $e->getMessage()]);
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved, 'skipped' => $skipped];
    }

    /**
     * @param array{organismeCode: string|null, name: string, directVenue: array{libelle: string, city: string|null, postalCode: string|null, latitude: float, longitude: float}|null} $observation
     *
     * @return 'resolved'|'skipped'|'unresolved'
     */
    private function resolveOne(array $observation): string
    {
        $name = trim($observation['name']);
        if ('' === $name) {
            return 'unresolved';
        }

        // Step 1 — the KEY (organisme code). Known on the API channel; else resolved
        // by a STRICT name match against the organismes index.
        $organismeHit = null;
        $code = $this->trimToNull($observation['organismeCode']);
        if (null === $code) {
            $organismeHit = $this->resolveOrganismeByName($name);
            $code = null === $organismeHit ? null : $this->trimToNull($this->str($organismeHit['code'] ?? null));
            if (null === $code) {
                return 'unresolved';
            }
        }

        // Step 2 — dedup: an opponent already known at VENUE precision is left as-is
        // (no geo calls). A CITY row may still be upgraded to VENUE by the API channel.
        $existing = $this->directory->findOneByFfbbOrganismeCode($code);
        if ($existing instanceof OpponentDirectoryEntry && OpponentLocationPrecision::VENUE === $existing->getPrecision()) {
            return 'skipped';
        }

        // Step 3 — the best location, most precise first. VENUE is reserved to the
        // AUTHORITATIVE API channel (directVenue); the xlsx/catch-up channel caps at CITY.
        $location = $this->locateVenueFromDirect($name, $observation['directVenue'])
            ?? $this->locateCity($name, $code, $organismeHit);

        if (null === $location) {
            return 'unresolved';
        }

        $this->directory->upsert($code, $location['precision'], [
            'name' => $location['name'],
            'city' => $location['city'],
            'postalCode' => $location['postalCode'],
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'venueLabel' => $location['venueLabel'],
        ]);

        return 'resolved';
    }

    /**
     * Étage 1 — the exact venue carried by the API rencontre hit.
     *
     * @param array{libelle: string, city: string|null, postalCode: string|null, latitude: float, longitude: float}|null $directVenue
     *
     * @return array{precision: OpponentLocationPrecision, name: string, city: string|null, postalCode: string|null, latitude: float|null, longitude: float|null, venueLabel: string|null}|null
     */
    private function locateVenueFromDirect(string $name, ?array $directVenue): ?array
    {
        if (null === $directVenue) {
            return null;
        }

        return $this->venue($name, $directVenue['libelle'], $directVenue['city'], $directVenue['postalCode'], $directVenue['latitude'], $directVenue['longitude']);
    }

    /**
     * Étage 2 — the opponent organisme's commune (its `_geo`, else its commune/CP
     * geocoded via the BAN).
     *
     * @param array<string, mixed>|null $organismeHit already resolved by name (name channel), else null
     *
     * @return array{precision: OpponentLocationPrecision, name: string, city: string|null, postalCode: string|null, latitude: float|null, longitude: float|null, venueLabel: string|null}|null
     */
    private function locateCity(string $name, string $code, ?array $organismeHit): ?array
    {
        $hit = $organismeHit ?? $this->resolveOrganismeByCode($code);
        if (null === $hit) {
            return null;
        }

        $city = $this->cityOf($hit);
        $postalCode = $this->postalOf($hit);
        [$latitude, $longitude] = $this->geoOf($hit);
        if (null === $latitude && (null !== $city || null !== $postalCode)) {
            [$latitude, $longitude] = $this->geocodeCommune($city, $postalCode);
        }
        if (null === $city && null === $postalCode && null === $latitude) {
            return null; // organisme found but nothing locatable
        }

        return [
            'precision' => OpponentLocationPrecision::CITY,
            'name' => mb_substr($this->str($hit['nom'] ?? null) ?? $name, 0, 180),
            'city' => $city,
            'postalCode' => $postalCode,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'venueLabel' => null,
        ];
    }

    /**
     * @return array{precision: OpponentLocationPrecision, name: string, city: string|null, postalCode: string|null, latitude: float|null, longitude: float|null, venueLabel: string|null}
     */
    private function venue(string $name, string $venueLabel, ?string $city, ?string $postalCode, ?float $latitude, ?float $longitude): array
    {
        return [
            'precision' => OpponentLocationPrecision::VENUE,
            'name' => mb_substr($name, 0, 180),
            'city' => null === $city ? null : mb_substr($city, 0, 180),
            'postalCode' => null === $postalCode ? null : mb_substr($postalCode, 0, 16),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'venueLabel' => mb_substr($venueLabel, 0, 180),
        ];
    }

    /**
     * A STRICT organisme match by name: among the typo-tolerant hits, exactly ONE
     * must normalize-equal the query — 0 or ≥2 is ambiguous and yields no key.
     *
     * @return array<string, mixed>|null
     */
    private function resolveOrganismeByName(string $name): ?array
    {
        $needle = $this->importer->normalizeLabel($name);
        if ('' === $needle) {
            return null;
        }

        $matches = [];
        foreach ($this->apiClient->search($name, self::ORGANISME_SEARCH_LIMIT) as $hit) {
            if (null !== $this->trimToNull($this->str($hit['code'] ?? null))
                && $this->importer->normalizeLabel($this->str($hit['nom'] ?? null) ?? '') === $needle) {
                $matches[] = $hit;
            }
        }

        return 1 === \count($matches) ? $matches[0] : null;
    }

    /**
     * The organisme carrying this exact code (case-insensitive) — never an
     * arbitrary typo-tolerant neighbour (patron {@see FfbbClubPopulator}).
     *
     * @return array<string, mixed>|null
     */
    private function resolveOrganismeByCode(string $code): ?array
    {
        foreach ($this->apiClient->search($code, self::ORGANISME_SEARCH_LIMIT) as $hit) {
            if (0 === strcasecmp($this->str($hit['code'] ?? null) ?? '', $code)) {
                return $hit;
            }
        }

        return null;
    }

    /** @return array{0: float|null, 1: float|null} [latitude, longitude] from a salle/organisme `_geo`. */
    private function geoOf(mixed $hit): array
    {
        $geo = \is_array($hit) && \is_array($hit['_geo'] ?? null) ? $hit['_geo'] : [];
        if (isset($geo['lat'], $geo['lng']) && is_numeric($geo['lat']) && is_numeric($geo['lng'])) {
            return [(float) $geo['lat'], (float) $geo['lng']];
        }

        return [null, null];
    }

    private function cityOf(mixed $hit): ?string
    {
        $commune = \is_array($hit) && \is_array($hit['commune'] ?? null) ? $hit['commune'] : [];
        $carto = \is_array($hit) && \is_array($hit['cartographie'] ?? null) ? $hit['cartographie'] : [];
        $city = $this->str($commune['libelle'] ?? null) ?? $this->str($carto['ville'] ?? null);

        return null === $city ? null : mb_substr($city, 0, 180);
    }

    private function postalOf(mixed $hit): ?string
    {
        $commune = \is_array($hit) && \is_array($hit['commune'] ?? null) ? $hit['commune'] : [];
        $carto = \is_array($hit) && \is_array($hit['cartographie'] ?? null) ? $hit['cartographie'] : [];
        $postal = $this->str($commune['codePostal'] ?? null) ?? $this->str($carto['codePostal'] ?? null);

        return null === $postal ? null : mb_substr($postal, 0, 16);
    }

    /** @return array{0: float|null, 1: float|null} [latitude, longitude] geocoded from commune/CP via the BAN. */
    private function geocodeCommune(?string $city, ?string $postalCode): array
    {
        $query = trim(($postalCode ?? '') . ' ' . ($city ?? ''));
        if (!BanGeocodingClient::isValidQuery($query)) {
            return [null, null];
        }
        $candidates = $this->geocoder->geocode($query, 1);
        if ([] === $candidates) {
            return [null, null];
        }

        return [$candidates[0]['latitude'], $candidates[0]['longitude']];
    }

    private function str(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function trimToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
