<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\Club;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Enum\OpponentTravelSource;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-54 RMM-9 PR-3 — computes the AUTO car travel time from a club's siège to
 * each of its AWAY opponents' venues, and upserts it into the tenant
 * `opponent_travel`. Patron {@see VenueTravelTimeAutofillService} (best-effort,
 * MANUAL never overwritten by AUTO).
 *
 * The location of an opponent is:
 *   - the MANUAL override on its `opponent_travel` row when the manager pinned a
 *     gym — but a MANUAL row is NEVER recomputed here (its travel was fixed when
 *     the manager set it) ;
 *   - otherwise the opponent's entry in the GLOBAL `opponent_directory` (public
 *     federal coordinates).
 *
 * Best-effort intégral : IGN en panne → `travelMinutes` null (jamais une erreur
 * bloquante) ; un adversaire sans lieu connu (ni override ni directory géolocalisé)
 * est laissé « non localisé », sans ligne AUTO.
 */
final class OpponentTravelResolver
{
    public const int MAX_OPPONENTS = 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IgnRoutingClient $routingClient,
        private readonly OpponentTravelRepository $travelRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly ClubRepository $clubRepository,
        private readonly FixtureRepository $fixtures,
    ) {}

    /**
     * The DISTINCT opponent organisme codes stamped on the season's AWAY fixtures
     * — the work set. Exposed so the catch-up route can enforce its hard cap
     * BEFORE any network call.
     *
     * @return list<string>
     */
    public function distinctOpponentCodes(string $seasonId): array
    {
        $codes = [];
        foreach ($this->fixtures->findAwayBySeason($seasonId) as $fixture) {
            $code = $fixture->getOpponentOrganismeCode();
            if (null !== $code && '' !== $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Resolve the AUTO travel for every AWAY opponent of the club+season. A MANUAL
     * row is left untouched. Best-effort per opponent.
     *
     * @return array{resolved: int, unresolved: list<string>, skippedManual: int}
     */
    public function resolve(string $clubId, string $seasonId): array
    {
        $club = $this->clubRepository->find($clubId);
        $clubLat = $club instanceof Club ? $club->getLatitude() : null;
        $clubLon = $club instanceof Club ? $club->getLongitude() : null;

        $codes = $this->distinctOpponentCodes($seasonId);
        $existing = $this->existingByCode($seasonId);

        $unresolved = [];
        $skippedManual = 0;
        /** @var list<array{code: string, lat: float, lon: float}> $geoTargets */
        $geoTargets = [];

        foreach ($codes as $code) {
            $row = $existing[$code] ?? null;
            if (null !== $row && OpponentTravelSource::MANUAL === $row->getSource()) {
                ++$skippedManual;

                continue;
            }

            $location = $this->directoryLocation($code);
            if (null === $location) {
                $unresolved[] = $code;

                continue;
            }
            $geoTargets[] = ['code' => $code, 'lat' => $location[0], 'lon' => $location[1]];
        }

        // No usable origin → nothing computable, every geolocated opponent is
        // unresolved (best-effort, no exception).
        if (null === $clubLat || null === $clubLon) {
            foreach ($geoTargets as $target) {
                $unresolved[] = $target['code'];
            }

            return ['resolved' => 0, 'unresolved' => $unresolved, 'skippedManual' => $skippedManual];
        }

        $batch = $this->routingClient->travelMinutesBatch(array_map(
            static fn (array $t): array => [
                'key' => $t['code'],
                'profile' => IgnRoutingClient::PROFILE_CAR,
                'startLat' => (float) $clubLat,
                'startLon' => (float) $clubLon,
                'endLat' => $t['lat'],
                'endLon' => $t['lon'],
            ],
            $geoTargets,
        ));
        $minutes = $batch['minutes'];
        $budgetExceeded = array_fill_keys($batch['budgetExceededKeys'], true);

        $resolved = 0;
        $wrote = false;
        foreach ($geoTargets as $target) {
            $code = $target['code'];
            // The budget stopped BEFORE this code was even tried: NOT the same as an
            // IGN-mute answer. Leave the row untouched — no write, no creation — so a
            // good AUTO value already in base survives, and a re-run resolves it. Only
            // a code that WAS tried and came back without a duration overwrites (below).
            if (isset($budgetExceeded[$code])) {
                $unresolved[] = $code;

                continue;
            }
            $value = $minutes[$code] ?? null;
            $row = $existing[$code] ?? $this->newRow($clubId, $seasonId, $code);
            // AUTO row: the location is the global directory's, no manual override.
            $row->setTravelMinutes($value)
                ->setSource(OpponentTravelSource::AUTO)
                ->setOverrideVenueExternalRef(null)
                ->setOverrideVenueLabel(null)
                ->setOverrideLatitude(null)
                ->setOverrideLongitude(null)
                ->setResolvedAt(new DateTimeImmutable);
            if (!isset($existing[$code])) {
                $this->entityManager->persist($row);
                $existing[$code] = $row;
            }
            $wrote = true;
            if (null !== $value) {
                ++$resolved;
            } else {
                $unresolved[] = $code; // located but IGN gave no duration
            }
        }

        if ($wrote) {
            $this->entityManager->flush();
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved, 'skippedManual' => $skippedManual];
    }

    /**
     * The manager pins a specific gym for an opponent (MANUAL override). The
     * travel is recomputed from that gym; a MANUAL row is never touched by the
     * AUTO pass afterwards. Best-effort: IGN muet → `travelMinutes` null.
     */
    public function applyManualOverride(string $clubId, string $seasonId, string $code, ?string $venueRef, string $venueLabel, float $lat, float $lon): OpponentTravel
    {
        $minutes = $this->carMinutesFromClub($clubId, $lat, $lon);
        $row = $this->travelRepository->findOneByCode($seasonId, $code) ?? $this->newRow($clubId, $seasonId, $code);
        $row->setOverrideVenueExternalRef($venueRef)
            ->setOverrideVenueLabel($venueLabel)
            ->setOverrideLatitude($lat)
            ->setOverrideLongitude($lon)
            ->setTravelMinutes($minutes)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setResolvedAt(new DateTimeImmutable);
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $row;
    }

    /**
     * Return an opponent to AUTO: drop the manual override and recompute the
     * travel from the GLOBAL directory location. Null when there is no row to
     * revert (nothing was overridden).
     */
    public function revertToAuto(string $clubId, string $seasonId, string $code): ?OpponentTravel
    {
        $row = $this->travelRepository->findOneByCode($seasonId, $code);
        if (!$row instanceof OpponentTravel) {
            return null;
        }
        $location = $this->directoryLocation($code);
        $minutes = null === $location ? null : $this->carMinutesFromClub($clubId, $location[0], $location[1]);
        $row->setOverrideVenueExternalRef(null)
            ->setOverrideVenueLabel(null)
            ->setOverrideLatitude(null)
            ->setOverrideLongitude(null)
            ->setTravelMinutes($minutes)
            ->setSource(OpponentTravelSource::AUTO)
            ->setResolvedAt(new DateTimeImmutable);
        $this->entityManager->flush();

        return $row;
    }

    /** Car minutes from the club siège to a point, or null (no club geo / IGN muet). */
    private function carMinutesFromClub(string $clubId, float $lat, float $lon): ?int
    {
        $club = $this->clubRepository->find($clubId);
        if (!$club instanceof Club || null === $club->getLatitude() || null === $club->getLongitude()) {
            return null;
        }

        return $this->routingClient->travelMinutes(IgnRoutingClient::PROFILE_CAR, (float) $club->getLatitude(), (float) $club->getLongitude(), $lat, $lon);
    }

    /**
     * The opponent's location from the GLOBAL directory (coordinates), or null
     * when unknown / not geolocated.
     *
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    private function directoryLocation(string $code): ?array
    {
        $entry = $this->directory->findOneByFfbbOrganismeCode($code);
        if (!$entry instanceof OpponentDirectoryEntry) {
            return null;
        }
        $lat = $entry->getLatitude();
        $lon = $entry->getLongitude();

        return null === $lat || null === $lon ? null : [$lat, $lon];
    }

    /**
     * @return array<string, OpponentTravel> keyed by opponent organisme code
     */
    private function existingByCode(string $seasonId): array
    {
        $map = [];
        foreach ($this->travelRepository->findBySeason($seasonId) as $row) {
            $map[$row->getOpponentOrganismeCode()] = $row;
        }

        return $map;
    }

    private function newRow(string $clubId, string $seasonId, string $code): OpponentTravel
    {
        return (new OpponentTravel)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setOpponentOrganismeCode($code);
    }
}
