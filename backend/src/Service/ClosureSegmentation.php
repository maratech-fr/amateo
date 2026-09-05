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
 *  1. les semaines PLEINES lun→dim couvrant la mère, clampées à la saison ;
 *  2. moins les semaines DE VACANCES (lundi→vendredi couvert, {@see HolidayWorkweekRule}) — vacances
 *     scolaires de la zone du club ∪ entrées HOLIDAY non ignorées ;
 *  3. moins les semaines qu'un AUTRE plan de période gouverne déjà
 *     ({@see PeriodWindowUniquenessGuard::governingWindows}, famille de la mère exclue).
 *
 * Le découpage lui-même est délégué au miroir pur {@see WeekSegmentationRule::segments}.
 *
 * TOLÉRANCE des semaines RÉVOLUES en tête (fondateur) : le picker n'offre que les semaines dont il
 * reste un jour devant (`isActionableWeek`, endDate >= today), donc un enfant créé au cockpit couvre
 * l'offre ACTIONNABLE (milieu rogné de sa tête révolue) ; un enfant seedé ou d'une période passée
 * couvre la géométrie PLEINE. Les deux sont légitimes — les révolues « en tête sont omises SANS
 * refus ». D'où deux vues : {@see segments} (actionnable, sert la décision « d'un bloc ») et
 * {@see childWindowIsValidSegment} (plein OU actionnable — la garde du POST d'un enfant).
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
     * Les segments ACTIONNABLES d'une mère CLOSURE (semaines révolues en tête omises — miroir du
     * picker). Sert la décision « d'un bloc » (permis ssi un seul segment). Bornes en Y-m-d.
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
        $base = $this->baseOfferedWeeks($clubId, $seasonId, $rootEntryId, $motherStart, $motherEnd, $seasonStart, $seasonEnd);

        return WeekSegmentationRule::segments($this->actionable($base), $motherStart, $motherEnd);
    }

    /**
     * Les segments de la GÉOMÉTRIE PLEINE (sans filtre temporel) — pour juger la FORME d'une
     * fenêtre indépendamment de l'horloge (garde du re-datage D3 : une fenêtre qui « aurait une
     * semaine entamée » se décompose en plus d'un segment, qu'elle soit passée ou à venir).
     *
     * @return list<array{monday: string, startDate: string, endDate: string, kind: 'start'|'middle'|'end', weeks: list<string>}>
     */
    public function fullSegments(
        string $clubId,
        string $seasonId,
        string $rootEntryId,
        string $motherStart,
        string $motherEnd,
        ?string $seasonStart,
        ?string $seasonEnd,
    ): array {
        $base = $this->baseOfferedWeeks($clubId, $seasonId, $rootEntryId, $motherStart, $motherEnd, $seasonStart, $seasonEnd);

        return WeekSegmentationRule::segments($base, $motherStart, $motherEnd);
    }

    /**
     * L'enfant [childStart, childEnd] est-il EXACTEMENT un segment de la mère CLOSURE ? Tolérant aux
     * semaines révolues en tête : le segment PLEIN (géométrie entière) OU le segment ROGNÉ des
     * révolues (ce que le picker a offert) est accepté — l'un naît d'une période passée / du seed,
     * l'autre d'une création au cockpit.
     */
    public function childWindowIsValidSegment(
        string $clubId,
        string $seasonId,
        string $rootEntryId,
        string $motherStart,
        string $motherEnd,
        ?string $seasonStart,
        ?string $seasonEnd,
        string $childStart,
        string $childEnd,
    ): bool {
        $base = $this->baseOfferedWeeks($clubId, $seasonId, $rootEntryId, $motherStart, $motherEnd, $seasonStart, $seasonEnd);
        foreach ([$base, $this->actionable($base)] as $offered) {
            foreach (WeekSegmentationRule::segments($offered, $motherStart, $motherEnd) as $segment) {
                if ($segment['startDate'] === $childStart && $segment['endDate'] === $childEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Les semaines PLEINES lun→dim couvrant [motherStart, motherEnd] — clampées à la saison (une
     * semaine entièrement hors saison est omise), MOINS les semaines de vacances, MOINS les semaines
     * déjà planifiées. AUCUN filtre temporel ici (la tolérance des révolues est appliquée par les
     * appelants).
     *
     * @return list<array{monday: string, startDate: string, endDate: string}>
     */
    private function baseOfferedWeeks(
        string $clubId,
        string $seasonId,
        string $rootEntryId,
        string $motherStart,
        string $motherEnd,
        ?string $seasonStart,
        ?string $seasonEnd,
    ): array {
        $weeks = $this->offeredWeeks($motherStart, $motherEnd, $seasonStart, $seasonEnd);
        if ([] === $weeks) {
            return [];
        }
        $weeks = $this->dropHolidayWeeks($weeks, $clubId, $seasonId, $seasonStart, $seasonEnd);

        return $this->dropPlannedWeeks($weeks, $clubId, $seasonId, $rootEntryId);
    }

    /**
     * Les semaines PLEINES lun→dim couvrant [motherStart, motherEnd], chacune clampée à la saison
     * (une semaine entièrement hors saison est omise).
     *
     * @return list<array{monday: string, startDate: string, endDate: string}>
     */
    private function offeredWeeks(string $motherStart, string $motherEnd, ?string $seasonStart, ?string $seasonEnd): array
    {
        $weeks = [];
        $monday = new DateTimeImmutable($motherStart)->modify(\sprintf('-%d days', (int) new DateTimeImmutable($motherStart)->format('N') - 1));
        while ($monday->format('Y-m-d') <= $motherEnd) {
            $mondayIso = $monday->format('Y-m-d');
            $sundayIso = $monday->modify('+6 days')->format('Y-m-d');
            $start = null === $seasonStart ? $mondayIso : max($mondayIso, $seasonStart);
            $end = null === $seasonEnd ? $sundayIso : min($sundayIso, $seasonEnd);
            if ($start <= $end) {
                $weeks[] = ['monday' => $mondayIso, 'startDate' => $start, 'endDate' => $end];
            }
            $monday = $monday->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * Ne garde que les semaines dont il reste un jour devant (endDate >= today) — miroir de
     * `isActionableWeek`.
     *
     * @param list<array{monday: string, startDate: string, endDate: string}> $weeks
     *
     * @return list<array{monday: string, startDate: string, endDate: string}>
     */
    private function actionable(array $weeks): array
    {
        $today = $this->clock->now()->format('Y-m-d');

        return array_values(array_filter($weeks, static fn (array $week): bool => $week['endDate'] >= $today));
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

        return array_values(array_filter($offered, static function (array $week) use ($holidayWindows, $seasonStart, $seasonEnd): bool {
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
        /** @var list<array{start_date: string, end_date: string, status: string, school_holiday_id: ?string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT start_date, end_date, status, school_holiday_id FROM calendar_entry '
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
