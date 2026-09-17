<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use App\Service\Basketball\VenueLabelNormalizer;

/**
 * Resolves WHERE an AWAY opponent plays, for the conflict radar's per-side
 * rendering (P2-54 conflict side details). Decorates the AWAY sides of
 * MATCH_MATCH / MATCH_TRAINING with a human place label (« extérieur à … »).
 *
 * Loads in BATCH — one query over the season's `opponent_travel` rows, one over
 * the global `opponent_directory` by organisme code — so decorating many
 * fixtures never fans out into an N+1. Pure resolution afterwards.
 *
 * Ordering (founder decision — MANUAL FIRST, then federal, then the raw FBI
 * label):
 *   1. {@see OpponentTravel::getOverrideVenueLabel()} — the manager's own gym
 *      choice for this opponent: the TEAM override (row keyed on the rencontre
 *      label NORMALIZED via {@see VenueLabelNormalizer}, the same normalization
 *      the controller uses for travel minutes) when present, else the CLUB row;
 *   2. {@see OpponentDirectoryEntry::getCity()} — the community-shared federal
 *      directory (GLOBAL, read-only), keyed on the opponent's FFBB organisme
 *      code (= {@see Fixture::getOpponentOrganismeCode()});
 *   3. {@see Fixture::getFbiVenueLabel()} — the salle label the FBI export
 *      carried for this fixture;
 *   4. null — nothing known (the UI says « lieu inconnu »).
 *
 * An empty/blank candidate at any tier is treated as absent and the next tier
 * is tried. All reads are scoped (tenant + season on `opponent_travel`) or over
 * the global reference (`opponent_directory` is read-only, no writes here).
 */
final class OpponentPlaceResolver
{
    public function __construct(
        private readonly OpponentTravelRepository $travelRepository,
        private readonly OpponentDirectoryEntryRepository $directoryRepository,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * @param list<Fixture> $awayFixtures the AWAY fixtures whose place to resolve (already scoped)
     *
     * @return array<string, string> fixtureId → resolved place label; a fixture with NO
     *                               resolvable place is ABSENT from the map (null place)
     */
    public function resolveByFixture(string $seasonId, array $awayFixtures): array
    {
        if ([] === $awayFixtures) {
            return [];
        }

        // Batch 1 — the manual overrides of this club/season, indexed by code with
        // the club default and the per-team overrides kept apart.
        /** @var array<string, array{club: string|null, teams: array<string, string|null>}> $travelByCode */
        $travelByCode = [];
        foreach ($this->travelRepository->findBySeason($seasonId) as $row) {
            $code = $row->getOpponentOrganismeCode();
            $travelByCode[$code] ??= ['club' => null, 'teams' => []];
            $teamKey = $row->getOpponentTeamKey();
            if (null === $teamKey) {
                $travelByCode[$code]['club'] = $row->getOverrideVenueLabel();
            } else {
                $travelByCode[$code]['teams'][$teamKey] = $row->getOverrideVenueLabel();
            }
        }

        // Batch 2 — the federal directory city for every organisme code in play.
        $codes = [];
        foreach ($awayFixtures as $fixture) {
            $code = $fixture->getOpponentOrganismeCode();
            if (null !== $code) {
                $codes[] = $code;
            }
        }
        $cityByCode = [];
        foreach ($this->directoryRepository->findByFfbbOrganismeCodes($codes) as $entry) {
            $cityByCode[$entry->getFfbbOrganismeCode()] = $entry->getCity();
        }

        $places = [];
        foreach ($awayFixtures as $fixture) {
            $place = $this->resolveOne($fixture, $travelByCode, $cityByCode);
            if (null !== $place) {
                $places[$fixture->getId()] = $place;
            }
        }

        return $places;
    }

    /**
     * @param array<string, array{club: string|null, teams: array<string, string|null>}> $travelByCode
     * @param array<string, string|null>                                                 $cityByCode
     */
    private function resolveOne(Fixture $fixture, array $travelByCode, array $cityByCode): ?string
    {
        $code = $fixture->getOpponentOrganismeCode();

        // 1. Manual override — the team row first (matched on the normalized label,
        // the same key the travel-minutes resolver uses), else the club row.
        if (null !== $code && isset($travelByCode[$code])) {
            $teamKey = $this->labelNormalizer->normalize(trim($fixture->getOpponentLabel()));
            $manual = $this->firstNonBlank([
                $travelByCode[$code]['teams'][$teamKey] ?? null,
                $travelByCode[$code]['club'],
            ]);
            if (null !== $manual) {
                return $manual;
            }
        }

        // 2. Federal directory city.
        if (null !== $code) {
            $city = $this->firstNonBlank([$cityByCode[$code] ?? null]);
            if (null !== $city) {
                return $city;
            }
        }

        // 3. The fixture's own FBI venue label.
        return $this->firstNonBlank([$fixture->getFbiVenueLabel()]);
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
