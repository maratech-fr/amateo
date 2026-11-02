<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

use App\Enum\FixtureUnplacedReason;
use DateTimeImmutable;

/**
 * La MAISON UNIQUE des matchs d'une date avec leur fenêtre de personne : à domicile le match seul, à
 * l'extérieur le trajet et l'échauffement, y compris sur une heure estimée. Un terrain neutre compte
 * son échauffement comme un extérieur, sans trajet : l'équipe n'y est pas chez elle.
 *
 * Les minutes se comptent depuis le minuit de la date : un match qui franchit minuit dépasse 1440
 * le premier jour et part d'un nombre négatif le lendemain.
 *
 * @phpstan-import-type FixtureView from PersonConflicts
 */
final class DayMatches
{
    /**
     * @param list<FixtureView> $views
     *
     * @return list<array{fixtureId: string, teamId: string, homeAway: string, startMinutes: int, endMinutes: int, personIds: list<string>}>
     */
    public function forDate(DateTimeImmutable $date, array $views): array
    {
        $midnight = $date->setTime(0, 0);
        $day = $midnight->format('Y-m-d');
        $rows = [];
        foreach ($views as $view) {
            $fixture = $view['fixture'];
            $window = FixtureUnplacedReason::NEUTRAL_GROUND === $fixture->getUnplacedReason() ? $view['window'] : $view['conflictWindow'];
            if ($window['start']->format('Y-m-d') > $day || $window['end']->format('Y-m-d') < $day) {
                continue;
            }
            $rows[] = [
                'fixtureId' => $fixture->getId(),
                'teamId' => $fixture->getTeamId(),
                'homeAway' => $fixture->getHomeAway()->value,
                'startMinutes' => intdiv($window['start']->getTimestamp() - $midnight->getTimestamp(), 60),
                'endMinutes' => intdiv($window['end']->getTimestamp() - $midnight->getTimestamp(), 60),
                'personIds' => $view['personIds'],
            ];
        }

        return $rows;
    }
}
