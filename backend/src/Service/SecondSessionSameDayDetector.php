<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\LockLevel;
use App\Plans\PlanComposition;
use App\Support\WeeklySlot;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une équipe s'entraîne au plus « son plafond » de fois par jour (1 par défaut, réglé dans l'onglet
 * « Séances par jour »), et jamais sur deux créneaux qui se chevauchent. Réserver au-delà passe au geste,
 * le Récap de saison et ses guichets l'arrêtent : il faut au moins une réservation en cause, face à une
 * autre réservation ou à un verrou de la version CHARGÉE. Deux verrous seuls ne bloquent pas, et une
 * réservation posée sur le placement même d'un verrou est une seule séance. Les équipes aux mêmes cases
 * et au même plafond (un groupe mutualisé) partagent un seul motif.
 *
 * Deux causes, une seule par (équipe, jour) : le chevauchement (plafond ≥ 2, intervalles demi-ouverts,
 * deux cases qui se touchent ne se chevauchent pas) l'emporte sur le dépassement du plafond. Chaque
 * réservation bloquée porte sa cause : `reservationIds` (trop de séances ce jour-là) ou
 * `overlappingReservationIds` (chevauche une autre séance) — deux listes disjointes. Quand le chevauchement
 * parle, une réservation en trop qui ne chevauche rien n'est dans aucune des deux.
 *
 * @phpstan-type Place array{start: string, venue: string, text: string, from: int, to: int}
 * @phpstan-type Bucket array{day: int, cap: int, overlap: bool, teams: list<string>, reserved: array<string, Place>, locked: array<string, Place>, ids: list<string>}
 */
final readonly class SecondSessionSameDayDetector
{
    private const array COUNT_WORDS = [2 => 'deux', 3 => 'trois', 4 => 'quatre'];

    /** Le plafond le plus haut réglable : au-delà, le motif ne propose plus de l'autoriser. */
    private const int MAX_DAILY_CAP = 4;

    private const string WHERE = '(étape Équipes, bouton Liens de l\'équipe, onglet « Séances par jour »)';

    private const string WHERE_PLURAL = '(étape Équipes, bouton Liens de chaque équipe, onglet « Séances par jour »)';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SchedulePlanProvisioner $schedulePlanProvisioner,
        private PlanComposition $planComposition,
    ) {}

    /**
     * @param list<Reservation> $reservations le périmètre de saison qui partira au solveur
     *
     * @return array{blockers: list<string>, reservationIds: list<string>, overlappingReservationIds: list<string>, teamDays: list<string>} `teamDays` : les « équipe|jour » couverts
     */
    public function detect(array $reservations): array
    {
        $reservedByTeamDay = [];
        $idsByTeamDayPlace = [];
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
            $idsByTeamDayPlace[$key][$place][] = $reservation->getId();
        }
        if (null === $seasonId) {
            return ['blockers' => [], 'reservationIds' => [], 'overlappingReservationIds' => [], 'teamDays' => []];
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
        $usedLocks = false;
        foreach ($reservedByTeamDay as $key => $reserved) {
            $locked = $lockedByTeamDay[$key] ?? [];
            [$teamId, $day] = explode('|', $key);
            $team = $this->entityManager->getRepository(Team::class)->find($teamId);
            // Le plafond de saison : la ligne de l'équipe au socle (lot 4, PR 3a).
            $cap = $team instanceof Team ? $this->planComposition->socleLinesOf([$teamId])[$teamId]->getMaxSessionsPerDay() : 1;

            // Le chevauchement d'abord, à plafond ≥ 2 : une réservation qui chevauche une autre séance du jour.
            $overlapping = $cap >= 2 ? $this->overlappingReservedPlaces($reserved, $locked) : [];
            if ([] !== $overlapping) {
                $reserved = array_intersect_key($reserved, $overlapping);
                $locked = array_filter($locked, fn (array $lock): bool => $this->overlapsAny($lock, $reserved));
                $isOverlap = true;
            } elseif (\count($reserved) + \count($locked) > $cap) {
                $isOverlap = false;
            } else {
                continue;
            }
            ksort($reserved);
            ksort($locked);
            $usedLocks = $usedLocks || [] !== $locked;
            $teamDays[] = $key;
            $bucketKey = $day . '#' . $cap . '#' . ($isOverlap ? 'o' : 'x') . '#' . implode(',', array_keys($reserved)) . '#' . implode(',', array_keys($locked));
            $buckets[$bucketKey] ??= ['day' => (int) $day, 'cap' => $cap, 'overlap' => $isOverlap, 'teams' => [], 'reserved' => $reserved, 'locked' => $locked, 'ids' => []];
            $buckets[$bucketKey]['teams'][] = $team?->getName() ?? 'Cette équipe';
            foreach (array_keys($reserved) as $place) {
                array_push($buckets[$bucketKey]['ids'], ...$idsByTeamDayPlace[$key][$place]);
            }
        }
        foreach ($buckets as &$bucket) {
            sort($bucket['teams'], \SORT_NATURAL);
        }
        unset($bucket);
        uasort($buckets, static fn (array $a, array $b): int => [$a['day'], $a['teams']] <=> [$b['day'], $b['teams']]);

        $versionLabel = !$usedLocks || null === $loadedVersionId ? '' : $this->versionLabel($loadedVersionId);
        $blockers = [];
        $ids = [];
        $overlappingIds = [];
        foreach ($buckets as $bucket) {
            if ($bucket['overlap']) {
                $blockers[] = $this->overlapSentence($bucket, $versionLabel);
                array_push($overlappingIds, ...$bucket['ids']);
                continue;
            }
            $blockers[] = [] === $bucket['locked'] ? $this->twoReservationsSentence($bucket) : $this->reservationAndLockSentence($bucket, $versionLabel);
            array_push($ids, ...$bucket['ids']);
        }

        return ['blockers' => $blockers, 'reservationIds' => $ids, 'overlappingReservationIds' => $overlappingIds, 'teamDays' => $teamDays];
    }

    /**
     * Les réservations qui chevauchent une autre séance du jour (réservation ou verrou).
     *
     * @param array<string, Place> $reserved
     * @param array<string, Place> $locked
     *
     * @return array<string, true>
     */
    private function overlappingReservedPlaces(array $reserved, array $locked): array
    {
        $found = [];
        foreach ($reserved as $key => $place) {
            $others = [...array_diff_key($reserved, [$key => true]), ...array_values($locked)];
            if ($this->overlapsAny($place, $others)) {
                $found[$key] = true;
            }
        }

        return $found;
    }

    /**
     * @param Place                    $place
     * @param array<int|string, Place> $others
     */
    private function overlapsAny(array $place, array $others): bool
    {
        foreach ($others as $other) {
            if (WeeklySlot::intervalsOverlap($place['from'], $place['to'], $other['from'], $other['to'])) {
                return true;
            }
        }

        return false;
    }

    /** @param Bucket $bucket */
    private function twoReservationsSentence(array $bucket): string
    {
        $count = \count($bucket['reserved']);
        $single = 1 === \count($bucket['teams']);

        return \sprintf(
            '%s %s fois le %s : %s — %s. %s au récapitulatif%s.',
            $single ? $bucket['teams'][0] . ' est réservée' : $this->joined($bucket['teams']) . ' sont réservées',
            self::COUNT_WORDS[$count] ?? (string) $count,
            TeamCaseOccupancyGuard::DAY_LABELS[$bucket['day']] ?? '',
            $this->joined(array_map(static fn (array $place): string => $place['text'], $this->ordered($bucket['reserved']))),
            $this->capPhrase($bucket['cap'], $single),
            $this->removeHowMany($count, $bucket['cap']),
            $this->allowMore($bucket, $count, $single),
        );
    }

    /** @param Bucket $bucket */
    private function reservationAndLockSentence(array $bucket, string $versionLabel): string
    {
        $count = \count($bucket['reserved']) + \count($bucket['locked']);
        $day = TeamCaseOccupancyGuard::DAY_LABELS[$bucket['day']] ?? '';
        $single = 1 === \count($bucket['teams']);
        $allow = $this->allowMore($bucket, $count, $single);

        return \sprintf(
            '%s %s séances le %s : %s — %s. Retirez %s au récapitulatif, %sdéverrouillez %s du %s dans la version %s%s.',
            $single ? $bucket['teams'][0] . ' a' : $this->joined($bucket['teams']) . ' ont',
            self::COUNT_WORDS[$count] ?? (string) $count,
            $day,
            $this->joined($this->placesWithOrigin($bucket, $versionLabel)),
            $this->capPhrase($bucket['cap'], $single),
            1 === \count($bucket['reserved']) ? 'la réservation' : 'les réservations',
            '' === $allow ? 'ou ' : '',
            1 === \count($bucket['locked']) ? 'la séance' : 'les séances',
            $day,
            $versionLabel,
            $allow,
        );
    }

    /**
     * Des cases qui se chevauchent : relever le plafond n'y changerait rien, aucune proposition.
     *
     * @param Bucket $bucket
     */
    private function overlapSentence(array $bucket, string $versionLabel): string
    {
        $day = TeamCaseOccupancyGuard::DAY_LABELS[$bucket['day']] ?? '';
        $single = 1 === \count($bucket['teams']);
        $count = \count($bucket['reserved']) + \count($bucket['locked']);
        $countWord = self::COUNT_WORDS[$count] ?? (string) $count;
        if ([] === $bucket['locked']) {
            return \sprintf(
                '%s %s fois le %s sur des créneaux qui se chevauchent : %s. Une équipe ne peut pas être à deux séances en même temps : %s.',
                $single ? $bucket['teams'][0] . ' est réservée' : $this->joined($bucket['teams']) . ' sont réservées',
                $countWord,
                $day,
                $this->joined(array_map(static fn (array $place): string => $place['text'], $this->ordered($bucket['reserved']))),
                2 === $count ? 'retirez l\'une des deux au récapitulatif' : 'retirez-en au récapitulatif jusqu\'à ce qu\'aucune ne chevauche une autre',
            );
        }

        return \sprintf(
            '%s %s séances le %s sur des créneaux qui se chevauchent : %s. Une équipe ne peut pas être à deux séances en même temps : retirez %s au récapitulatif, ou déverrouillez %s du %s dans la version %s.',
            $single ? $bucket['teams'][0] . ' a' : $this->joined($bucket['teams']) . ' ont',
            $countWord,
            $day,
            $this->joined($this->placesWithOrigin($bucket, $versionLabel)),
            1 === \count($bucket['reserved']) ? 'la réservation' : 'les réservations',
            1 === \count($bucket['locked']) ? 'la séance' : 'les séances',
            $day,
            $versionLabel,
        );
    }

    /**
     * @param Bucket $bucket
     *
     * @return list<string>
     */
    private function placesWithOrigin(array $bucket, string $versionLabel): array
    {
        return [
            ...array_map(static fn (array $place): string => $place['text'] . ' (réservation)', $this->ordered($bucket['reserved'])),
            ...array_map(static fn (array $place): string => \sprintf('%s (verrou de la version %s)', $place['text'], $versionLabel), $this->ordered($bucket['locked'])),
        ];
    }

    private function capPhrase(int $cap, bool $single): string
    {
        return 1 === $cap ? 'une seule séance par jour' : \sprintf('%d séances par jour au plus pour %s', $cap, $single ? 'cette équipe' : 'ces équipes');
    }

    private function removeHowMany(int $count, int $cap): string
    {
        $excess = $count - $cap;
        if (2 === $count && 1 === $excess) {
            return 'Retirez l\'une des deux';
        }

        return 1 === $excess ? 'Retirez-en une' : 'Retirez-en ' . $excess;
    }

    /**
     * « , ou autorisez N séances par jour pour cette équipe [R] » — seulement quand N est réglable et que les cases
     * sont deux à deux disjointes (sinon relever le plafond ne réglerait rien).
     *
     * @param Bucket $bucket
     */
    private function allowMore(array $bucket, int $count, bool $single): string
    {
        if ($count > self::MAX_DAILY_CAP) {
            return '';
        }
        $places = [...array_values($bucket['reserved']), ...array_values($bucket['locked'])];
        foreach ($places as $i => $place) {
            if ($this->overlapsAny($place, \array_slice($places, $i + 1))) {
                return '';
            }
        }

        return \sprintf(', ou autorisez %d séances par jour pour %s %s', $count, $single ? 'cette équipe' : 'ces équipes', $single ? self::WHERE : self::WHERE_PLURAL);
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

        $minutes = WeeklySlot::minutesOf($start);

        return ['start' => $from, 'venue' => $venueNames[$venueId], 'text' => \sprintf('%s-%s à %s', $from, $to, $venueNames[$venueId]), 'from' => $minutes, 'to' => $minutes + $durationMinutes];
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
