<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\LockLevel;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une équipe ne s'entraîne qu'une fois par jour. Réserver deux fois le même jour passe au geste, le Récap
 * de saison et ses guichets l'arrêtent : il faut au moins une réservation en cause, face à une autre
 * réservation ou à un verrou de la version CHARGÉE. Deux verrous seuls ne bloquent pas, et une réservation
 * posée sur le placement même d'un verrou est une seule séance. Les équipes aux mêmes cases (un groupe
 * mutualisé) partagent un seul motif.
 *
 * @phpstan-type Place array{start: string, venue: string, text: string}
 * @phpstan-type Bucket array{day: int, teams: list<string>, reserved: array<string, Place>, locked: array<string, Place>, ids: list<string>}
 */
final readonly class SecondSessionSameDayDetector
{
    private const array COUNT_WORDS = [2 => 'deux', 3 => 'trois'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * @param list<Reservation> $reservations le périmètre de saison qui partira au solveur
     *
     * @return array{blockers: list<string>, reservationIds: list<string>, teamDays: list<string>} `teamDays` : les « équipe|jour » couverts
     */
    public function detect(array $reservations): array
    {
        $reservedByTeamDay = [];
        $idsByTeamDay = [];
        $seasonId = null;
        $venueNames = [];
        foreach ($reservations as $reservation) {
            $teamId = $reservation->getTeamId();
            if (null === $teamId) {
                continue; // un créneau libre n'est la séance de personne
            }
            $seasonId = $reservation->getSeasonId();
            $key = $teamId . '|' . $reservation->getDayOfWeek();
            $place = $this->placeKey($reservation->getVenueId(), $reservation->getStartTime());
            $reservedByTeamDay[$key][$place] = $this->place($reservation->getVenueId(), $reservation->getStartTime(), $reservation->getDurationMinutes(), $venueNames);
            $idsByTeamDay[$key][] = $reservation->getId();
        }
        if (null === $seasonId) {
            return ['blockers' => [], 'reservationIds' => [], 'teamDays' => []];
        }

        $loadedVersionId = $this->schedulePlanProvisioner->loadedVersionIdOfSeason($seasonId);
        $lockedByTeamDay = [];
        foreach (null === $loadedVersionId ? [] : $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $loadedVersionId, 'lockLevel' => LockLevel::HARD]) as $slot) {
            $key = $slot->getTeamId() . '|' . $slot->getDayOfWeek();
            $place = $this->placeKey($slot->getVenueId(), $slot->getStartTime());
            if (isset($reservedByTeamDay[$key]) && !isset($reservedByTeamDay[$key][$place])) {
                $lockedByTeamDay[$key][$place] = $this->place($slot->getVenueId(), $slot->getStartTime(), $slot->getDurationMinutes(), $venueNames);
            }
        }

        /** @var array<string, Bucket> $buckets */
        $buckets = [];
        $teamDays = [];
        foreach ($reservedByTeamDay as $key => $reserved) {
            $locked = $lockedByTeamDay[$key] ?? [];
            if (\count($reserved) + \count($locked) < 2) {
                continue;
            }
            ksort($reserved);
            ksort($locked);
            $teamDays[] = $key;
            [$teamId, $day] = explode('|', $key);
            $bucketKey = $day . '#' . implode(',', array_keys($reserved)) . '#' . implode(',', array_keys($locked));
            $buckets[$bucketKey] ??= ['day' => (int) $day, 'teams' => [], 'reserved' => $reserved, 'locked' => $locked, 'ids' => []];
            $buckets[$bucketKey]['teams'][] = $this->entityManager->getRepository(Team::class)->find($teamId)?->getName() ?? 'Cette équipe';
            array_push($buckets[$bucketKey]['ids'], ...$idsByTeamDay[$key]);
        }
        foreach ($buckets as &$bucket) {
            sort($bucket['teams'], \SORT_NATURAL);
        }
        unset($bucket);
        uasort($buckets, static fn (array $a, array $b): int => [$a['day'], $a['teams']] <=> [$b['day'], $b['teams']]);

        $versionLabel = [] === $lockedByTeamDay || null === $loadedVersionId ? '' : $this->versionLabel($loadedVersionId);
        $blockers = [];
        $ids = [];
        foreach ($buckets as $bucket) {
            $blockers[] = [] === $bucket['locked'] ? $this->twoReservationsSentence($bucket) : $this->reservationAndLockSentence($bucket, $versionLabel);
            array_push($ids, ...$bucket['ids']);
        }

        return ['blockers' => $blockers, 'reservationIds' => $ids, 'teamDays' => $teamDays];
    }

    /** @param Bucket $bucket */
    private function twoReservationsSentence(array $bucket): string
    {
        $count = \count($bucket['reserved']);

        return \sprintf(
            '%s %s fois le %s : %s — une seule séance par jour. %s au récapitulatif.',
            1 === \count($bucket['teams']) ? $bucket['teams'][0] . ' est réservée' : $this->joined($bucket['teams']) . ' sont réservées',
            self::COUNT_WORDS[$count] ?? (string) $count,
            TeamCaseOccupancyGuard::DAY_LABELS[$bucket['day']] ?? '',
            $this->joined(array_map(static fn (array $place): string => $place['text'], $this->ordered($bucket['reserved']))),
            2 === $count ? 'Retirez l\'une des deux' : 'Retirez-en ' . ($count - 1),
        );
    }

    /** @param Bucket $bucket */
    private function reservationAndLockSentence(array $bucket, string $versionLabel): string
    {
        $count = \count($bucket['reserved']) + \count($bucket['locked']);
        $day = TeamCaseOccupancyGuard::DAY_LABELS[$bucket['day']] ?? '';
        $places = [
            ...array_map(static fn (array $place): string => $place['text'] . ' (réservation)', $this->ordered($bucket['reserved'])),
            ...array_map(static fn (array $place): string => \sprintf('%s (verrou de la version %s)', $place['text'], $versionLabel), $this->ordered($bucket['locked'])),
        ];

        return \sprintf(
            '%s %s séances le %s : %s — une seule séance par jour. Retirez %s au récapitulatif, ou déverrouillez %s du %s dans la version %s.',
            1 === \count($bucket['teams']) ? $bucket['teams'][0] . ' a' : $this->joined($bucket['teams']) . ' ont',
            self::COUNT_WORDS[$count] ?? (string) $count,
            $day,
            $this->joined($places),
            1 === \count($bucket['reserved']) ? 'la réservation' : 'les réservations',
            1 === \count($bucket['locked']) ? 'la séance' : 'les séances',
            $day,
            $versionLabel,
        );
    }

    /**
     * @param array<string, Place> $places
     *
     * @return list<Place>
     */
    private function ordered(array $places): array
    {
        $list = array_values($places);
        usort($list, static fn (array $a, array $b): int => [$a['start'], $a['venue']] <=> [$b['start'], $b['venue']]);

        return $list;
    }

    /** @param list<string> $parts */
    private function joined(array $parts): string
    {
        $last = array_pop($parts);

        return [] === $parts ? (string) $last : implode(', ', $parts) . ' et ' . $last;
    }

    private function placeKey(string $venueId, DateTimeInterface $start): string
    {
        return $venueId . '@' . $start->format('H:i');
    }

    /**
     * @param array<string, string> $venueNames cache des noms, le temps d'une détection
     *
     * @return Place
     */
    private function place(string $venueId, DateTimeInterface $start, int $durationMinutes, array &$venueNames): array
    {
        $venueNames[$venueId] ??= $this->entityManager->getRepository(Venue::class)->find($venueId)?->getName() ?? 'ce gymnase';
        $from = $start->format('H:i');
        $to = DateTimeImmutable::createFromInterface($start)->modify(\sprintf('+%d minutes', $durationMinutes))->format('H:i');

        return ['start' => $from, 'venue' => $venueNames[$venueId], 'text' => \sprintf('%s-%s à %s', $from, $to, $venueNames[$venueId])];
    }

    /** « V2 » : le même libellé que le reste de l'écran. */
    private function versionLabel(string $versionId): string
    {
        $repository = $this->entityManager->getRepository(Schedule::class);
        $version = $repository->find($versionId);
        if (!$version instanceof Schedule) {
            return '';
        }
        $versions = array_map(
            static fn (Schedule $schedule): array => ['id' => $schedule->getId(), 'createdAt' => $schedule->getCreatedAt()->format('Y-m-d\TH:i:s.uP')],
            $repository->findBy(['schedulePlanId' => $version->getSchedulePlanId()]),
        );

        return VersionLabel::of($versions, $versionId) ?? '';
    }
}
