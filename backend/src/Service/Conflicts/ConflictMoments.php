<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

use App\Entity\Fixture;
use App\Enum\ConflictPersonRole;
use App\Service\MatchConflictDetector;
use DateTimeImmutable;

/**
 * Petits helpers PURS partagés par la façade {@see MatchConflictDetector}
 * et les trois familles de conflits (gymnase, règles & fenêtres, personnes) — maison
 * UNIQUE, zéro doublon (décision fondateur lot architecture, option A). Sans état,
 * sans dépendance : des fonctions statiques sur les fenêtres d'occupation et la
 * sérialisation d'une fixture. Les corps sont déplacés VERBATIM depuis le détecteur
 * (iso-comportement).
 *
 * @phpstan-type OccupancyWindow array{start: DateTimeImmutable, end: DateTimeImmutable}
 * @phpstan-type FixtureView array{fixture: Fixture, window: OccupancyWindow, venueWindow: OccupancyWindow, conflictWindow: OccupancyWindow, estimated: bool, estimatedKickoffTime: string|null, travelOneWayMinutes: int|null, matchDurationMinutes: int, personIds: list<string>}
 */
final class ConflictMoments
{
    /**
     * Occupancy bounds are the club's WALL-CLOCK time, carried WITHOUT an
     * offset (P4-191): `2026-10-03T15:00:00`, not the ATOM `…+02:00`. A match
     * and a training are wall-clock facts of one club — appending the server
     * offset let a browser in another zone shift « 20:45 » by an hour. The UI
     * parses these as local and re-formats them, so no offset must ride along.
     * Only these datetime bounds use it; `matchDate` (Y-m-d) and `kickoffTime`
     * (H:i) are unaffected.
     */
    public const string WALL_CLOCK_FORMAT = 'Y-m-d\TH:i:s';

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $a
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $b
     */
    public static function overlaps(array $a, array $b): bool
    {
        // Half-open: back-to-back windows (endA == startB) do NOT conflict.
        return $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    public static function maxMoment(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }

    public static function minMoment(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    /** @return array<string, mixed> */
    public static function bareFixtureView(Fixture $fixture): array
    {
        return [
            'fixtureId' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'homeAway' => $fixture->getHomeAway()->value,
            'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
            'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
            'status' => $fixture->getStatus()->value,
        ];
    }

    /**
     * @param FixtureView             $view
     * @param ConflictPersonRole|null $role the person's role on this fixture's team, on a PERSON conflict
     *                                      (MATCH_MATCH/MATCH_TRAINING). null for the gym/link families,
     *                                      which share this view but carry no person → no `role` key.
     *
     * @return array<string, mixed>
     */
    public static function fixtureView(array $view, ?ConflictPersonRole $role = null): array
    {
        $fixture = $view['fixture'];
        $window = $view['window'];
        $serialized = [
            'fixtureId' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'homeAway' => $fixture->getHomeAway()->value,
            'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
            'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
            // P1-4 PR C — the window was built on the team's HABITUAL kickoff,
            // not a real hour: the UI must say « heure estimée ».
            'estimatedKickoff' => $view['estimated'],
            // P2-54 conflict side details — ADDITIVE per-side fields served on
            // EVERY family that shares this view, because the conflicts screen now
            // renders a per-side line for the gym family too: VENUE_OVERLAP reads
            // opponentLabel + matchDurationMinutes (matches/lib/conflictSideLines.ts
            // ::venueSide), not only MATCH_MATCH/MATCH_TRAINING. The
            // ConflictFingerprinter is a whitelist and never reads them.
            // `opponentPlace` is NOT here — it is decorated by
            // FixtureConflictsController on AWAY sides after detection.
            'estimatedKickoffTime' => $view['estimatedKickoffTime'],
            'travelOneWayMinutes' => $view['travelOneWayMinutes'],
            'matchDurationMinutes' => $view['matchDurationMinutes'],
            'opponentLabel' => $fixture->getOpponentLabel(),
            'windowStart' => $window['start']->format(self::WALL_CLOCK_FORMAT),
            'windowEnd' => $window['end']->format(self::WALL_CLOCK_FORMAT),
        ];
        if ($role instanceof ConflictPersonRole) {
            $serialized['role'] = $role->value;
        }

        return $serialized;
    }
}
