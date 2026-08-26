<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\Venue;
use App\Entity\VenueTravelTime;
use App\Enum\VenueTravelTimeSource;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-53 RMM-8 (PR-1) — l'autofill de la matrice de trajet. Le serveur relit
 * venues+géos EN BASE (jamais le client), forme les paires non ordonnées des
 * gymnases, et remplit AUTO le temps voiture + le temps à pied via l'itinéraire
 * IGN.
 *
 * ⚠ LE cœur de la feature : une colonne MANUAL n'est JAMAIS touchée (« le 15 min
 * métro A survit à tous les re-calculs »). On n'écrit AUTO que sur une colonne
 * AUTO ou nulle. Best-effort par paire : un gymnase sans géo ou un échec de
 * transport → la paire est listée `unresolved`, jamais un échec global. Cap dur
 * pour ne pas noyer l'IGN d'appels.
 */
final class VenueTravelTimeAutofillService
{
    public const MAX_AUTOFILL_PAIRS = 120;

    private const REASON_MISSING_GEO = 'missing_geo';
    private const REASON_ROUTING_FAILED = 'routing_failed';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IgnRoutingClient $routingClient,
    ) {}

    /**
     * @throws AutofillCapExceededException       when the geolocated-pair count exceeds the cap
     * @throws UniqueConstraintViolationException au flush, quand un
     *                                            autofill concurrent (ou un POST manuel) a créé le même couple entre le pré-read
     *                                            et l'écriture — l'appelant la nomme en 409 rejouable (idiome P4-67)
     *
     * @return array{filled: int, unresolved: list<array{venueAId: string, venueBId: string, reason: string}>, skippedManual: int}
     */
    public function autofill(string $clubId, string $seasonId): array
    {
        // RLS + the Doctrine tenant/season filters already scope this, but the
        // explicit clubId/seasonId is defence in depth and makes the query intent plain.
        /** @var list<Venue> $venues */
        $venues = $this->entityManager->getRepository(Venue::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        // Deterministic pairing order (venueAId < venueBId is derived from this).
        usort($venues, static fn (Venue $a, Venue $b): int => strcasecmp($a->getId(), $b->getId()));

        $existing = $this->existingByCouple($clubId, $seasonId);

        $unresolved = [];
        /** @var list<array{a: Venue, b: Venue, drivingNeeded: bool, walkingNeeded: bool, hadManual: bool}> $geoPairs */
        $geoPairs = [];

        $count = \count($venues);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $a = $venues[$i];
                $b = $venues[$j];

                if (!$this->isGeolocated($a) || !$this->isGeolocated($b)) {
                    $unresolved[] = ['venueAId' => $a->getId(), 'venueBId' => $b->getId(), 'reason' => self::REASON_MISSING_GEO];

                    continue;
                }

                $row = $existing[$a->getId() . '|' . $b->getId()] ?? null;
                $drivingManual = null !== $row && VenueTravelTimeSource::MANUAL === $row->getDrivingSource();
                $walkingManual = null !== $row && VenueTravelTimeSource::MANUAL === $row->getWalkingSource();

                $geoPairs[] = [
                    'a' => $a,
                    'b' => $b,
                    'drivingNeeded' => !$drivingManual,
                    'walkingNeeded' => !$walkingManual,
                    'hadManual' => $drivingManual || $walkingManual,
                ];
            }
        }

        if (\count($geoPairs) > self::MAX_AUTOFILL_PAIRS) {
            throw new AutofillCapExceededException(\count($geoPairs), self::MAX_AUTOFILL_PAIRS);
        }

        $minutes = $this->routingClient->travelMinutesBatch($this->buildJobs($geoPairs));

        $filled = 0;
        $skippedManual = 0;
        foreach ($geoPairs as $pair) {
            $a = $pair['a'];
            $b = $pair['b'];
            $key = $a->getId() . '|' . $b->getId();

            if ($pair['hadManual']) {
                ++$skippedManual;
            }

            $drivingValue = $pair['drivingNeeded'] ? ($minutes[$key . '|' . IgnRoutingClient::PROFILE_CAR] ?? null) : null;
            $walkingValue = $pair['walkingNeeded'] ? ($minutes[$key . '|' . IgnRoutingClient::PROFILE_PEDESTRIAN] ?? null) : null;

            $wrote = false;
            $row = $existing[$key] ?? null;

            if ($pair['drivingNeeded'] && null !== $drivingValue) {
                $row ??= $this->newRow($clubId, $seasonId, $a->getId(), $b->getId());
                $row->setDrivingMinutes($drivingValue)->setDrivingSource(VenueTravelTimeSource::AUTO);
                $wrote = true;
            }
            if ($pair['walkingNeeded'] && null !== $walkingValue) {
                $row ??= $this->newRow($clubId, $seasonId, $a->getId(), $b->getId());
                $row->setWalkingMinutes($walkingValue)->setWalkingSource(VenueTravelTimeSource::AUTO);
                $wrote = true;
            }

            // A mode we needed but the routing could not resolve → the pair is
            // unresolved (routing_failed), even if the OTHER mode was written.
            $drivingFailed = $pair['drivingNeeded'] && null === $drivingValue;
            $walkingFailed = $pair['walkingNeeded'] && null === $walkingValue;
            if ($drivingFailed || $walkingFailed) {
                $unresolved[] = ['venueAId' => $a->getId(), 'venueBId' => $b->getId(), 'reason' => self::REASON_ROUTING_FAILED];
            }

            if ($wrote && null !== $row) {
                // A freshly created row (absent from the pre-read map) is persisted;
                // a managed row flushes on its own.
                if (!isset($existing[$key])) {
                    $this->entityManager->persist($row);
                    $existing[$key] = $row;
                }
                ++$filled;
            }
        }

        $this->entityManager->flush();

        return ['filled' => $filled, 'unresolved' => $unresolved, 'skippedManual' => $skippedManual];
    }

    /**
     * @param list<array{a: Venue, b: Venue, drivingNeeded: bool, walkingNeeded: bool, hadManual: bool}> $geoPairs
     *
     * @return list<array{key: string, profile: string, startLat: float, startLon: float, endLat: float, endLon: float}>
     */
    private function buildJobs(array $geoPairs): array
    {
        $jobs = [];
        foreach ($geoPairs as $pair) {
            $a = $pair['a'];
            $b = $pair['b'];
            $key = $a->getId() . '|' . $b->getId();
            $startLat = (float) $a->getLatitude();
            $startLon = (float) $a->getLongitude();
            $endLat = (float) $b->getLatitude();
            $endLon = (float) $b->getLongitude();

            if ($pair['drivingNeeded']) {
                $jobs[] = ['key' => $key . '|' . IgnRoutingClient::PROFILE_CAR, 'profile' => IgnRoutingClient::PROFILE_CAR, 'startLat' => $startLat, 'startLon' => $startLon, 'endLat' => $endLat, 'endLon' => $endLon];
            }
            if ($pair['walkingNeeded']) {
                $jobs[] = ['key' => $key . '|' . IgnRoutingClient::PROFILE_PEDESTRIAN, 'profile' => IgnRoutingClient::PROFILE_PEDESTRIAN, 'startLat' => $startLat, 'startLon' => $startLon, 'endLat' => $endLat, 'endLon' => $endLon];
            }
        }

        return $jobs;
    }

    /**
     * @return array<string, VenueTravelTime> keyed by "venueAId|venueBId"
     */
    private function existingByCouple(string $clubId, string $seasonId): array
    {
        $map = [];
        /** @var list<VenueTravelTime> $rows */
        $rows = $this->entityManager->getRepository(VenueTravelTime::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]);
        foreach ($rows as $row) {
            $map[$row->getVenueAId() . '|' . $row->getVenueBId()] = $row;
        }

        return $map;
    }

    private function newRow(string $clubId, string $seasonId, string $venueAId, string $venueBId): VenueTravelTime
    {
        return (new VenueTravelTime)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setVenueAId($venueAId)
            ->setVenueBId($venueBId);
    }

    private function isGeolocated(Venue $venue): bool
    {
        return null !== $venue->getLatitude() && null !== $venue->getLongitude();
    }
}
