<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\VenueLabelNormalizer;

/**
 * Resolves WHERE an AWAY opponent plays — a CITY — for the conflict radar's
 * per-side rendering (P2-54 conflict side details). Decorates the AWAY sides of
 * MATCH_MATCH / MATCH_TRAINING with a human place (« extérieur à … »).
 *
 * Loads in BATCH — one query over the season's `opponent_travel` rows, one over
 * the global `opponent_venue_suggestion` and one over the global
 * `opponent_directory`, all keyed on the organisme code — so decorating many
 * fixtures never fans out into an N+1 (three queries per batch). Pure resolution
 * afterwards.
 *
 * Ordering (founder decision 2026-09-17 — the manager's CHOSEN gym decides, and
 * we serve its CITY, never a gym label):
 *   1. The EFFECTIVE {@see OpponentTravel} override row — the TEAM row (keyed on
 *      the rencontre label NORMALIZED via {@see VenueLabelNormalizer}, the same
 *      key the travel-minutes resolver uses) when present, else the CLUB row. If
 *      that row carries an {@see OpponentTravel::getOverrideVenueExternalRef()}
 *      (a FFBB salle number), the city of the matching federal SUGGESTION
 *      `(ffbbOrganismeCode, venueExternalRef)` = {@see OpponentVenueSuggestion::getCity()};
 *   2. otherwise (no ref, a blank/absent suggestion city, or no override at all)
 *      {@see OpponentDirectoryEntry::getCity()} — the community-shared federal
 *      directory (GLOBAL, read-only), keyed on the opponent's FFBB organisme
 *      code (= {@see Fixture::getOpponentOrganismeCode()});
 *   3. null — nothing known (the key is not set → the UI says « lieu inconnu »).
 *
 * `overrideVenueLabel` and `fbiVenueLabel` are NEVER served (they are gym labels,
 * not cities). The city is served AS-IS (no case transformation). All reads are
 * scoped (tenant + season on `opponent_travel`) or over global references
 * (`opponent_venue_suggestion` / `opponent_directory` are read-only, no writes
 * here).
 */
final class OpponentPlaceResolver
{
    public function __construct(
        private readonly OpponentTravelRepository $travelRepository,
        private readonly OpponentVenueSuggestionRepository $suggestionRepository,
        private readonly OpponentDirectoryEntryRepository $directoryRepository,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * @param list<Fixture> $awayFixtures the AWAY fixtures whose place to resolve (already scoped)
     *
     * @return array<string, string> fixtureId → resolved city; a fixture with NO
     *                               resolvable city is ABSENT from the map (null place)
     */
    public function resolveByFixture(string $seasonId, array $awayFixtures): array
    {
        if ([] === $awayFixtures) {
            return [];
        }

        // Batch 1 — the manual override rows of this club/season, indexed by code
        // with the club default and the per-team overrides kept apart. We only
        // keep the CHOSEN gym's FFBB salle ref (the label is never served).
        /** @var array<string, array{club: string|null, teams: array<string, string|null>}> $refByCode */
        $refByCode = [];
        foreach ($this->travelRepository->findBySeason($seasonId) as $row) {
            $code = $row->getOpponentOrganismeCode();
            $refByCode[$code] ??= ['club' => null, 'teams' => []];
            $teamKey = $row->getOpponentTeamKey();
            if (null === $teamKey) {
                $refByCode[$code]['club'] = $row->getOverrideVenueExternalRef();
            } else {
                $refByCode[$code]['teams'][$teamKey] = $row->getOverrideVenueExternalRef();
            }
        }

        $codes = [];
        foreach ($awayFixtures as $fixture) {
            $code = $fixture->getOpponentOrganismeCode();
            if (null !== $code) {
                $codes[] = $code;
            }
        }

        // Batch 2 — the federal SUGGESTION city for every gym in play, indexed
        // code → (venueExternalRef → city). FFBB_API rows (null ref) can never
        // match an override ref, so they are skipped.
        /** @var array<string, array<string, string|null>> $suggestionCityByCodeRef */
        $suggestionCityByCodeRef = [];
        foreach ($this->suggestionRepository->findByFfbbOrganismeCodes($codes) as $suggestion) {
            $ref = $suggestion->getVenueExternalRef();
            if (null === $ref) {
                continue;
            }
            $suggestionCityByCodeRef[$suggestion->getFfbbOrganismeCode()][$ref] = $suggestion->getCity();
        }

        // Batch 3 — the federal directory city for every organisme code in play.
        $cityByCode = [];
        foreach ($this->directoryRepository->findByFfbbOrganismeCodes($codes) as $entry) {
            $cityByCode[$entry->getFfbbOrganismeCode()] = $entry->getCity();
        }

        $places = [];
        foreach ($awayFixtures as $fixture) {
            $place = $this->resolveOne($fixture, $refByCode, $suggestionCityByCodeRef, $cityByCode);
            if (null !== $place) {
                $places[$fixture->getId()] = $place;
            }
        }

        return $places;
    }

    /**
     * @param array<string, array{club: string|null, teams: array<string, string|null>}> $refByCode
     * @param array<string, array<string, string|null>>                                  $suggestionCityByCodeRef
     * @param array<string, string|null>                                                 $cityByCode
     */
    private function resolveOne(Fixture $fixture, array $refByCode, array $suggestionCityByCodeRef, array $cityByCode): ?string
    {
        $code = $fixture->getOpponentOrganismeCode();
        if (null === $code) {
            // No organisme code → nothing to key a suggestion or the directory on,
            // and the raw FBI label is no longer served → lieu inconnu.
            return null;
        }

        // 1. The EFFECTIVE override row (team then club) → the CITY of its chosen
        // gym, read from the federal suggestion by (code, salle ref).
        if (isset($refByCode[$code])) {
            $teamKey = $this->labelNormalizer->normalize(trim($fixture->getOpponentLabel()));
            $ref = $refByCode[$code]['teams'][$teamKey] ?? $refByCode[$code]['club'];
            if (null !== $ref) {
                $city = $this->firstNonBlank([$suggestionCityByCodeRef[$code][$ref] ?? null]);
                if (null !== $city) {
                    return $city;
                }
            }
        }

        // 2. Federal directory city.
        return $this->firstNonBlank([$cityByCode[$code] ?? null]);
    }

    /**
     * @param list<string|null> $candidates
     */
    private function firstNonBlank(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (null !== $candidate && '' !== trim($candidate)) {
                return trim($candidate);
            }
        }

        return null;
    }
}
