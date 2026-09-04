<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SchoolHolidayPeriodRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * LA MAISON UNIQUE (côté serveur) des SEGMENTS début·milieu·fin d'une indisponibilité (mère
 * CLOSURE) — décision fondateur 2026-09-05.
 *
 * Reproduit ce que le cockpit calcule pour offrir le picker d'une fermeture (`closureWeeksOffer`
 * puis `subtractPlannedWeeks`, `date.ts`), à partir de ce que le SERVEUR SAIT :
 *  1. les semaines PLEINES lun→dim couvrant la mère, clampées à la saison, dont il reste des jours
 *     devant (les semaines révolues à l'horloge serveur en tête sont OMISES sans refus — c'est la
 *     tolérance `isActionableWeek` du front, endDate >= today) ;
 *  2. moins les semaines DE VACANCES (lundi→vendredi couvert, {@see HolidayWorkweekRule}) — vacances
 *     scolaires de la zone du club ∪ entrées HOLIDAY non ignorées ;
 *  3. moins les semaines qu'un AUTRE plan de période gouverne déjà
 *     ({@see PeriodWindowUniquenessGuard::governingWindows}, famille de la mère exclue).
 *
 * Le découpage lui-même est délégué au miroir pur {@see WeekSegmentationRule::segments}. Deux
 * appelants : {@see CalendarEntryStateProcessor::assertValidWeekChild} (l'enfant doit être
 * EXACTEMENT un segment) et {@see SchedulePlanStateProcessor} (« d'un bloc » permis ssi la
 * décomposition compte un seul segment).
 */
final class ClosureSegmentation
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolHolidayPeriodRepository $schoolHolidayRepository,
        private readonly PeriodWindowUniquenessGuard $windowUniquenessGuard,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Les segments d'une mère CLOSURE. Bornes en Y-m-d (exactes au jour, sans ambiguïté de fuseau).
     *
     * @param string $rootEntryId l'ancêtre racine de la mère (elle-même : une mère est toujours racine) — sa famille est exclue des fenêtres gouvernantes
     *
     * @return list<array{monday: string, startDate: string, endDate: string, kind: 'start'|'middle'|'end', weeks: list<string>}>
     */
    public function segments(
        string $clubId,
        string $seasonId,
        string $rootEntryId,
        string $motherStart,
        string $motherEnd,
        ?string $seasonStart,
        ?string $seasonEnd,
    ): array {
        $today = $this->clock->now()->format('Y-m-d');
        $offered = $this->offeredWeeks($motherStart, $motherEnd, $seasonStart, $seasonEnd, $today);
        if ([] === $offered) {
            return [];
        }

        $offered = $this->dropHolidayWeeks($offered, $clubId, $seasonId, $seasonStart, $seasonEnd);
        $offered = $this->dropPlannedWeeks($offered, $clubId, $seasonId, $rootEntryId);

        return WeekSegmentationRule::segments($offered, $motherStart, $motherEnd);
    }

    /**
     * Les semaines PLEINES lun→dim couvrant [motherStart, motherEnd], chacune clampée à la saison
     * (une semaine entièrement hors saison est omise) et gardée seulement s'il lui reste un jour
     * devant (endDate >= today — miroir de `isActionableWeek`).
     *
     * @return list<array{monday: string, startDate: string, endDate: string}>
     */
    private function offeredWeeks(string $motherStart, string $motherEnd, ?string $seasonStart, ?string $seasonEnd, string $today): array
    {
        $weeks = [];
        $monday = new DateTimeImmutable($motherStart)->modify(\sprintf('-%d days', (int) new DateTimeImmutable($motherStart)->format('N') - 1));
        while ($monday->format('Y-m-d') <= $motherEnd) {
            $mondayIso = $monday->format('Y-m-d');
            $sundayIso = $monday->modify('+6 days')->format('Y-m-d');
            $start = null === $seasonStart ? $mondayIso : max($mondayIso, $seasonStart);
            $end = null === $seasonEnd ? $sundayIso : min($sundayIso, $seasonEnd);
            if ($start <= $end && $end >= $today) {
                $weeks[] = ['monday' => $mondayIso, 'startDate' => $start, 'endDate' => $end];
            }
            $monday = $monday->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * Retire les semaines DE VACANCES (lundi→vendredi couvert par une vacance). Sources, comme le
     * front : les entrées HOLIDAY non ignorées de ce club+saison ∪ le feed des vacances scolaires de
     * la zone du club (moins celles qu'une entrée matérialisée a explicitement ignorées).
     *
     * @param list<array{monday: string, startDate: string, endDate: string}> $offered
     *
     * @return list<array{monday: string, startDate: string, endDate: string}>
     */
    private function dropHolidayWeeks(array $offered, string $clubId, string $seasonId, ?string $seasonStart, ?string $seasonEnd): array
    {
        if (null === $seasonStart || null === $seasonEnd) {
            return $offered;
        }
        $holidayWindows = $this->holidayWindows($clubId, $seasonId, $seasonStart, $seasonEnd);
        if ([] === $holidayWindows) {
            return $offered;
        }

        return array_values(array_filter($offered, function (array $week) use ($holidayWindows, $seasonStart, $seasonEnd): bool {
            foreach ($holidayWindows as [$holStart, $holEnd]) {
                if (HolidayWorkweekRule::covers($week['monday'], $holStart, $holEnd, $seasonStart, $seasonEnd)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Les fenêtres de vacances qui gouvernent des semaines, [start, end] en Y-m-d.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function holidayWindows(string $clubId, string $seasonId, string $seasonStart, string $seasonEnd): array
    {
        // Entrées calendrier de type vacances (SQL brut : season_filter épinglerait la lecture à la
        // saison active, RLS scope le club). Une IGNORÉE ne compte pas ; son schoolHolidayId retire
        // en plus la vacance scolaire correspondante du feed.
        /** @var list<array{title: string, start_date: string, end_date: string, status: string, school_holiday_id: ?string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT title, start_date, end_date, status, school_holiday_id FROM calendar_entry '
            . 'WHERE club_id = :club AND season_id = :season AND period_type = \'holiday\'',
            ['club' => $clubId, 'season' => $seasonId],
        );
        $ignoredHolidayIds = [];
        $windows = [];
        foreach ($rows as $row) {
            if ('ignored' === $row['status']) {
                if (null !== $row['school_holiday_id']) {
                    $ignoredHolidayIds[$row['school_holiday_id']] = true;
                }

                continue;
            }
            $windows[] = [mb_substr((string) $row['start_date'], 0, 10), mb_substr((string) $row['end_date'], 0, 10)];
        }

        // Feed des vacances scolaires de la zone du club, clampé à la saison, hors ignorées.
        $zone = $this->entityManager->getConnection()->fetchOne('SELECT school_zone FROM club WHERE id = :club', ['club' => $clubId]);
        if (\is_string($zone) && '' !== $zone) {
            foreach ($this->schoolHolidayRepository->findByZoneAndWindow($zone, new DateTimeImmutable($seasonStart), new DateTimeImmutable($seasonEnd)) as $holiday) {
                if (isset($ignoredHolidayIds[$holiday->getId()])) {
                    continue;
                }
                $start = max($holiday->getStartDate()->format('Y-m-d'), $seasonStart);
                $end = min($holiday->getEndDate()->format('Y-m-d'), $seasonEnd);
                if ($start <= $end) {
                    $windows[] = [$start, $end];
                }
            }
        }

        return $windows;
    }

    /**
     * Retire les semaines qu'un AUTRE plan de période gouverne déjà (chevauchement de dates), la
     * famille de la mère exclue — miroir de `subtractPlannedWeeks`.
     *
     * @param list<array{monday: string, startDate: string, endDate: string}> $offered
     *
     * @return list<array{monday: string, startDate: string, endDate: string}>
     */
    private function dropPlannedWeeks(array $offered, string $clubId, string $seasonId, string $rootEntryId): array
    {
        if ([] === $offered) {
            return $offered;
        }
        $envelopeStart = $offered[0]['startDate'];
        $envelopeEnd = $offered[\count($offered) - 1]['endDate'];
        $governing = $this->windowUniquenessGuard->governingWindows($clubId, $seasonId, $rootEntryId, $envelopeStart, $envelopeEnd);
        if ([] === $governing) {
            return $offered;
        }

        return array_values(array_filter($offered, static function (array $week) use ($governing): bool {
            foreach ($governing as $window) {
                if ($window['start_date'] <= $week['endDate'] && $window['end_date'] >= $week['startDate']) {
                    return false;
                }
            }

            return true;
        }));
    }
}
