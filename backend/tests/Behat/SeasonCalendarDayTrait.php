<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Service\SeasonResolver;
use DateTimeImmutable;
use RuntimeException;

/**
 * Les dates d'un scénario de transition de saison, dites comme le gestionnaire les dit
 * (« le 30 avril de la saison en cours », « le 1er mai de la saison suivante ») et
 * calculées depuis la date RÉELLE : le scénario tient quel que soit le jour où il tourne.
 *
 * Une saison va du 15 juillet au 14 juillet (pivot de {@see SeasonResolver}) : « le 30 avril
 * de la saison en cours » est le 30 avril qui tombe DANS la saison en cours, donc l'année
 * civile qui suit son début ; « le 20 juillet de la saison suivante » tombe dans l'année où
 * elle commence.
 *
 * L'horloge d'un club RÉEL se fige par l'horloge de dev globale (`POST /api/dev/clock`,
 * dev seulement) ; elle se relâche en fin de scénario, quoi qu'il arrive.
 *
 * @phpstan-require-extends BaseContext
 */
trait SeasonCalendarDayTrait
{
    private const array SEASON_MONTHS = [
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
    ];

    /** « 30 avril de la saison en cours » → `Y-m-d`. */
    private function seasonDay(string $label): string
    {
        if (1 !== preg_match('/^(?P<day>\d{1,2})(?:er)? (?P<month>\p{L}+) de la saison (?P<season>en cours|suivante)$/u', $label, $m)) {
            throw new RuntimeException(\sprintf('date « %s » illisible (attendu « 30 avril de la saison en cours »)', $label));
        }
        $month = self::SEASON_MONTHS[$m['month']] ?? throw new RuntimeException(\sprintf('mois « %s » inconnu', $m['month']));
        $seasonStartYear = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + ('suivante' === $m['season'] ? 1 : 0);
        $monthDay = \sprintf('%02d-%02d', $month, (int) $m['day']);
        $year = $monthDay >= SeasonResolver::TRANSITION_MONTH_DAY ? $seasonStartYear : $seasonStartYear + 1;

        return \sprintf('%d-%s', $year, $monthDay);
    }

    /** Fige l'horloge de l'app (dev seulement, honorée par toute l'app) à 9 h du jour donné. */
    private function pinDevClock(string $isoDay, string $token): void
    {
        $response = $this->apiPost('dev/clock', ['at' => $isoDay . 'T09:00:00'], $token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('impossible de figer l\'horloge de dev au %s (HTTP %d) — la stack tourne-t-elle en dev ?', $isoDay, $response['status']));
        }
    }

    /** Relâche l'horloge de dev → temps réel. Ne lève pas : le nettoyage doit continuer. */
    private function releaseDevClock(string $token): void
    {
        if ('' !== $token) {
            $this->apiPost('dev/clock', ['at' => null], $token);
        }
    }
}
