<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Décide si une FENÊTRE de gymnase (créneau de grille) est OCCUPÉE par au moins une séance.
 *
 * Règle : une fenêtre compte comme occupée dès qu'une séance du MÊME gymnase et du MÊME jour la
 * CHEVAUCHE dans le temps — intervalles demi-ouverts `[début, fin)` qui s'intersectent — et pas
 * seulement si elle partage son début EXACT. La grille d'une période est une copie prise à la
 * naissance du plan (ADR-0002) et peut être RETAILLÉE après la génération : une séance verrouillée
 * garde alors un début qui ne coïncide plus avec celui de la fenêtre (prod BCCL : fenêtre
 * 17:30-18:45, séance verrouillée 17:15-18:45). L'ancienne égalité de début laissait la fenêtre
 * « vide » à côté de la séance. La capacité n'est pas découpée : un seul chevauchement suffit (MVP).
 *
 * MIROIR DÉCLARÉ (régime 2 de `.claude/rules/frontend.md`) de
 * `frontend/src/features/planning/lib/emptySlots.ts::computeEmptySlots` (la grille à l'écran) —
 * ici, l'export PDF/XLSX. Parité mécanique sur les mêmes cas : `EmptySlotOverlapMirrorParityTest`
 * ⇄ `emptySlots.parity.test.ts`, inscrit au registre `FrontRederivationRegistryTest`. Ne modifier
 * la règle d'un seul côté sans porter le cas partagé `emptySlots.parity.json`.
 */
final class VenueWindowOccupancy
{
    /**
     * Une fenêtre est-elle occupée par au moins un des occupants du même gymnase+jour qui la
     * chevauchent dans le temps ?
     *
     * @param array{venueId: string, dayOfWeek: int, startTime: string, durationMinutes: int}       $window
     * @param list<array{venueId: string, dayOfWeek: int, startTime: string, durationMinutes: int}> $occupants
     */
    public static function windowIsFilled(array $window, array $occupants): bool
    {
        $windowStart = self::startMinutes($window['startTime']);
        $windowEnd = $windowStart + $window['durationMinutes'];
        foreach ($occupants as $occupant) {
            if ($occupant['venueId'] !== $window['venueId'] || $occupant['dayOfWeek'] !== $window['dayOfWeek']) {
                continue;
            }
            $start = self::startMinutes($occupant['startTime']);
            if (self::overlaps($start, $start + $occupant['durationMinutes'], $windowStart, $windowEnd)) {
                return true;
            }
        }

        return false;
    }

    /** Deux intervalles de minutes demi-ouverts `[aStart, aEnd)` et `[bStart, bEnd)` s'intersectent. */
    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /** « H:i » / « H:i:s » → minutes depuis minuit (les secondes sont ignorées, comme à l'écran). */
    private static function startMinutes(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) $parts[0];
        $minutes = (int) ($parts[1] ?? '0');

        return $hours * 60 + $minutes;
    }
}
