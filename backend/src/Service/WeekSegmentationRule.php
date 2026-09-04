<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * DÉCOUPAGE d'une indisponibilité (mère CLOSURE) en segments début·milieu·fin — décision
 * fondateur 2026-09-05.
 *
 * Une indisponibilité se découpe en DÉBUT (semaine entamée de tête), MILIEU (toutes les semaines
 * PLEINES contiguës, UN SEUL plan — un trou de vacances lun→ven ou une fenêtre déjà planifiée
 * coupe le milieu en deux runs, chacun un plan) et FIN (semaine entamée de queue). Jamais une
 * semaine complète isolée, jamais un run qui s'arrête avant son bord, jamais un enfant qui mélange
 * partiel et complet.
 *
 * Algorithme PUR, aux ruptures GÉOMÉTRIQUES seulement (calculables des semaines OFFERTES + de la
 * fenêtre de la mère, AUCUNE règle solveur redérivée) : une semaine que la mère ne couvre pas
 * ENTIÈREMENT (lun→dim) est un bout de taille 1 (kind 'start' si l'événement commence après le
 * lundi — entame de tête —, sinon 'end' — entame de queue) ; un run de semaines pleines contiguës
 * est un 'middle' ; une discontinuité de l'offre coupe le run.
 *
 * MIROIR MÉCANIQUE du front `cockpit/lib/weekSegmentation.ts::weekSegments` : les mêmes cas
 * (`frontend/.../weekSegmentation.parity.json`) traversent les DEUX implémentations via
 * `WeekSegmentationMirrorParityTest`. Le front qualifie l'OFFRE du picker (un segment coché =
 * un plan) ; ce rail garde le POST d'une semaine-enfant de fermeture et le geste « d'un bloc »
 * ({@see CalendarEntryStateProcessor::assertValidWeekChild}, {@see SchedulePlanStateProcessor}).
 * Ce module figure au registre `FrontRederivationRegistryTest`.
 */
final class WeekSegmentationRule
{
    /**
     * Les segments d'une indisponibilité, dérivés de ses semaines OFFERTES et de sa fenêtre.
     *
     * @param list<array{monday: string, startDate: string, endDate: string}> $offered les semaines lun→dim offertes (clamp saison porté par startDate/endDate), déjà trouées des vacances lun→ven et des fenêtres planifiées, dans l'ordre chronologique
     *
     * @return list<array{monday: string, startDate: string, endDate: string, kind: 'start'|'middle'|'end', weeks: list<string>}>
     */
    public static function segments(array $offered, string $eventStart, string $eventEnd): array
    {
        $segments = [];
        /** @var list<array{monday: string, startDate: string, endDate: string}> $run */
        $run = [];
        $flush = static function () use (&$run, &$segments): void {
            if ([] !== $run) {
                $segments[] = self::makeSegment($run, 'middle');
                $run = [];
            }
        };

        $total = \count($offered);
        for ($i = 0; $i < $total; ++$i) {
            $week = $offered[$i];
            if (!self::eventCoversFullWeek($week['monday'], $eventStart, $eventEnd)) {
                $flush();
                // Entame de TÊTE si l'événement commence après le lundi ; de QUEUE sinon (il
                // commence au lundi — ou avant — mais s'arrête avant le dimanche).
                $kind = $eventStart > $week['monday'] ? 'start' : 'end';
                $segments[] = self::makeSegment([$week], $kind);

                continue;
            }
            if ($i > 0 && $week['monday'] !== self::addDays($offered[$i - 1]['monday'], 7)) {
                $flush();
            }
            $run[] = $week;
        }
        $flush();

        return $segments;
    }

    /** L'événement [start, end] couvre-t-il TOUTE la semaine calendaire (lun→dim) dont `$monday` est le lundi ? */
    private static function eventCoversFullWeek(string $monday, string $eventStart, string $eventEnd): bool
    {
        return $eventStart <= $monday && $eventEnd >= self::addDays($monday, 6);
    }

    /**
     * @param list<array{monday: string, startDate: string, endDate: string}> $weeks
     * @param 'start'|'middle'|'end'                                          $kind
     *
     * @return array{monday: string, startDate: string, endDate: string, kind: 'start'|'middle'|'end', weeks: list<string>}
     */
    private static function makeSegment(array $weeks, string $kind): array
    {
        $first = $weeks[0];
        $last = $weeks[\count($weeks) - 1];

        return [
            'monday' => $first['monday'],
            'startDate' => $first['startDate'],
            'endDate' => $last['endDate'],
            'kind' => $kind,
            'weeks' => array_map(static fn (array $w): string => $w['monday'], $weeks),
        ];
    }

    /** ISO date `$n` jours après `$iso` (Y-m-d, exact au jour, sans ambiguïté de fuseau). */
    private static function addDays(string $iso, int $n): string
    {
        return new DateTimeImmutable($iso)->modify(\sprintf('%+d days', $n))->format('Y-m-d');
    }
}
