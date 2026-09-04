<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * « Une semaine est-elle DE VACANCES ? » — décision fondateur 2026-09-04.
 *
 * Une semaine n'est de vacances que si la vacance couvre TOUT son lundi→vendredi ; sinon c'est une
 * semaine de saison. Deux nuances : le WEEK-END ne compte pas (samedi/dimanche jamais regardés) ;
 * un jour HORS SAISON compte comme couvert — tolérance qui ne joue qu'aux vacances d'été (seul
 * moment où la saison change), quand le début de saison rogne une semaine à cheval.
 *
 * MIROIR MÉCANIQUE du front `cockpit/lib/holidayWorkweek.ts::holidayCoversWorkweek` : les mêmes cas
 * (`frontend/.../holidayWorkweek.parity.json`) traversent les deux implémentations via
 * `HolidayWorkweekMirrorParityTest`. Le front qualifie l'OFFRE (reprise vs fermeture) ; ce rail
 * garde le POST d'une semaine-enfant de vacances (`CalendarEntryStateProcessor::assertValidWeekChild`).
 */
final class HolidayWorkweekRule
{
    /**
     * La semaine calendaire dont `$monday` est le lundi (dates Y-m-d) est-elle de vacances ?
     * Chaque jour lundi→vendredi doit être DANS la vacance OU hors saison. Comparaisons en dates
     * Y-m-d (ordre lexicographique sûr, exact au jour, sans ambiguïté de fuseau).
     */
    public static function covers(string $monday, string $holidayStart, string $holidayEnd, string $seasonStart, string $seasonEnd): bool
    {
        $mondayDate = new DateTimeImmutable($monday);
        for ($offset = 0; $offset < 5; ++$offset) {
            $day = $mondayDate->modify(\sprintf('+%d days', $offset))->format('Y-m-d');
            $inHoliday = $day >= $holidayStart && $day <= $holidayEnd;
            $outOfSeason = $day < $seasonStart || $day > $seasonEnd;
            if (!$inHoliday && !$outOfSeason) {
                return false;
            }
        }

        return true;
    }
}
