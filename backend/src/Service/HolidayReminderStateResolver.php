<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\HolidayCloseReason;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * « Le rappel de cette vacance scolaire est-il fermé pour ce club ? » — branche vacances de
 * `app:periods:remind`. Trois fermeurs, rendus dans cet ordre de priorité :
 *  - IGNORED : le club a ignoré la vacance (entrée HOLIDAY `ignored` liée à elle) ;
 *  - COVERED : la réunion des coupures/fermetures racines actives couvre CHAQUE jour lundi→vendredi
 *    de chacune de ses semaines de vacances (`HolidayWorkweekRule`) — une vacance sans aucune
 *    semaine de vacances n'est jamais couverte ;
 *  - PLANNED : une racine HOLIDAY active qui la chevauche porte une version (générée ou en échec)
 *    sur son plan ou sur celui d'une de ses semaines-enfants.
 * COVERED passe avant PLANNED : une coupure couvrante tait toute la vacance, même déjà en partie
 * générée.
 *
 * Isolation : la connexion runtime lit sous la RLS du club courant ; chaque requête porte EN PLUS
 * sa ceinture `club_id = :club` sur `calendar_entry` (une seule par requête — `schedule_plan` et
 * `schedule` sont joints par l'entrée), falsifiée une à une par `HolidayReminderStateIsolationTest`.
 * Dates en Y-m-d.
 */
final readonly class HolidayReminderStateResolver
{
    public function __construct(private Connection $connection) {}

    public function isClosed(string $clubId, string $schoolHolidayId, string $holidayStart, string $holidayEnd, string $seasonStart, string $seasonEnd): ?HolidayCloseReason
    {
        if ($this->isIgnored($clubId, $schoolHolidayId)) {
            return HolidayCloseReason::IGNORED;
        }
        if ($this->isCovered($clubId, $holidayStart, $holidayEnd, $seasonStart, $seasonEnd)) {
            return HolidayCloseReason::COVERED;
        }
        if ($this->isPlanned($clubId, $holidayStart, $holidayEnd)) {
            return HolidayCloseReason::PLANNED;
        }

        return null;
    }

    /** @param list<array{0: string, 1: string}> $windows */
    private function inAnyWindow(string $day, array $windows): bool
    {
        foreach ($windows as [$from, $to]) {
            if ($day >= $from && $day <= $to) {
                return true;
            }
        }

        return false;
    }

    private function isIgnored(string $clubId, string $schoolHolidayId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM calendar_entry '
            . 'WHERE club_id = :club AND school_holiday_id = :holiday '
            . 'AND period_type = \'holiday\' AND status = \'ignored\' LIMIT 1',
            ['club' => $clubId, 'holiday' => $schoolHolidayId],
        );
    }

    private function isCovered(string $clubId, string $holidayStart, string $holidayEnd, string $seasonStart, string $seasonEnd): bool
    {
        /** @var list<array{start_date: string, end_date: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT start_date, end_date FROM calendar_entry '
            . 'WHERE club_id = :club AND kind = \'period\' AND status = \'active\' AND parent_entry_id IS NULL '
            . 'AND period_type IN (\'cutoff\', \'closure\') '
            . 'AND start_date <= :holidayEnd AND end_date >= :holidayStart',
            ['club' => $clubId, 'holidayStart' => $holidayStart, 'holidayEnd' => $holidayEnd],
        );
        if ([] === $rows) {
            return false;
        }
        $windows = array_map(
            static fn (array $row): array => [mb_substr((string) $row['start_date'], 0, 10), mb_substr((string) $row['end_date'], 0, 10)],
            $rows,
        );

        // Lundis à partir de celui de la semaine du début de la vacance (patron
        // ClosureSegmentation::offeredWeeks) ; seules les semaines DE VACANCES sont à couvrir.
        $start = new DateTimeImmutable($holidayStart);
        $monday = $start->modify(\sprintf('-%d days', (int) $start->format('N') - 1));
        $holidayWeeks = 0;
        while ($monday->format('Y-m-d') <= $holidayEnd) {
            $mondayIso = $monday->format('Y-m-d');
            if (HolidayWorkweekRule::covers($mondayIso, $holidayStart, $holidayEnd, $seasonStart, $seasonEnd)) {
                ++$holidayWeeks;
                for ($offset = 0; $offset < 5; ++$offset) {
                    $day = $monday->modify(\sprintf('+%d days', $offset))->format('Y-m-d');
                    $outOfSeason = $day < $seasonStart || $day > $seasonEnd;
                    if (!$outOfSeason && !$this->inAnyWindow($day, $windows)) {
                        return false;
                    }
                }
            }
            $monday = $monday->modify('+7 days');
        }

        return $holidayWeeks > 0;
    }

    private function isPlanned(string $clubId, string $holidayStart, string $holidayEnd): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM calendar_entry e '
            . 'WHERE e.club_id = :club AND e.parent_entry_id IS NULL AND e.kind = \'period\' '
            . 'AND e.period_type = \'holiday\' AND e.status = \'active\' '
            . 'AND e.start_date <= :holidayEnd AND e.end_date >= :holidayStart '
            . 'AND EXISTS (SELECT 1 FROM schedule_plan p JOIN schedule s ON s.schedule_plan_id = p.id '
            . 'WHERE p.calendar_entry_id = e.id '
            . 'OR p.calendar_entry_id IN (SELECT c.id FROM calendar_entry c WHERE c.parent_entry_id = e.id)) '
            . 'LIMIT 1',
            ['club' => $clubId, 'holidayStart' => $holidayStart, 'holidayEnd' => $holidayEnd],
        );
    }
}
