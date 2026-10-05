<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

use App\Entity\Fixture;
use App\Entity\VenueMatchWindow;
use App\Enum\ConflictPersonRole;
use App\Enum\FixtureHomeAway;
use App\Service\ConflictRadarLoader;
use App\Service\MatchConflictDetector;
use DateTimeImmutable;

/**
 * Famille « gymnase » du radar de conflits — extraite VERBATIM de
 * {@see MatchConflictDetector} (iso-comportement, aucune règle changée). La façade
 * {@see MatchConflictDetector::detect()} reste l'unique point d'entrée : elle
 * monte les vues/fixtures et délègue ici les collisions de GYMNASE
 * (chevauchement, indisponibilité, gymnase interdit, accès perdu). Les prédicats
 * purs partagés avec le front (`kickoffInsideWindow`, `dateInsideClosure`) restent
 * publics sur {@see MatchConflictDetector} (consommés par le front, les processors
 * et les parités) et sont appelés statiquement ici.
 *
 * @phpstan-type OccupancyWindow array{start: DateTimeImmutable, end: DateTimeImmutable}
 * @phpstan-type FixtureView array{fixture: Fixture, window: OccupancyWindow, venueWindow: OccupancyWindow, conflictWindow: OccupancyWindow, estimated: bool, estimatedKickoffTime: string|null, travelOneWayMinutes: int|null, matchDurationMinutes: int, personIds: list<string>}
 */
final class VenueConflicts
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
    private const string WALL_CLOCK_FORMAT = 'Y-m-d\TH:i:s';

    /**
     * Severity 1 — two fixtures on the SAME venue whose VENUE windows overlap.
     * The manual loop lets this happen on purpose (a derogation or the league
     * can impose it); the diagnostic makes it the loudest finding instead. ⚠ D1
     * (2026-09-13): the collision is tested on the VENUE window ([kickoff,
     * kickoff + match], no warm-up) — two matches chained two hours apart in the
     * same gym must NOT collide on their inflated person footprints. The served
     * `start`/`end` are therefore the intersection of the VENUE windows.
     *
     * @param list<FixtureView> $views
     *
     * @return list<array<string, mixed>>
     */
    public function venueOverlapConflicts(array $views): array
    {
        $withVenue = array_values(array_filter($views, static fn (array $view): bool => null !== $view['fixture']->getVenueId()));
        $conflicts = [];
        $count = \count($withVenue);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $left = $withVenue[$i];
                $right = $withVenue[$j];
                if ($left['fixture']->getVenueId() !== $right['fixture']->getVenueId() || !$this->overlaps($left['venueWindow'], $right['venueWindow'])) {
                    continue;
                }
                $conflicts[] = [
                    'type' => 'VENUE_OVERLAP',
                    'severity' => 1,
                    'venueId' => $left['fixture']->getVenueId(),
                    'start' => $this->maxMoment($left['venueWindow']['start'], $right['venueWindow']['start'])->format(self::WALL_CLOCK_FORMAT),
                    'end' => $this->minMoment($left['venueWindow']['end'], $right['venueWindow']['end'])->format(self::WALL_CLOCK_FORMAT),
                    'left' => $this->fixtureView($left),
                    'right' => $this->fixtureView($right),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Severity 3 (P4-272 ④, même gravité que CLUB_RULE_VIOLATION — décision fondateur,
     * ne renumérote rien) — un domicile POSÉ dans un gymnase INTERDIT à son équipe (scope
     * TEAM HARD). Amicaux EXEMPTÉS (comme l'enveloppe ligue et les règles de club, même
     * décision : un amical se joue où le club veut). La pose manuelle dans un gymnase
     * interdit reste PERMISE — ceci la SIGNALE, il ne la bloque pas (le solveur, lui, n'y
     * pose jamais rien). `venueId` porte le gymnase interdit, de quoi le nommer à l'écran.
     * Kickoff-indépendant : c'est le fait d'occuper CE gymnase qui viole, pas l'heure.
     *
     * @param list<Fixture>               $fixtures
     * @param array<string, list<string>> $forbiddenVenuesByTeam teamId → forbidden venue ids
     *
     * @return list<array<string, mixed>>
     */
    public function teamVenueForbiddenConflicts(array $fixtures, array $forbiddenVenuesByTeam): array
    {
        if ([] === $forbiddenVenuesByTeam) {
            return [];
        }

        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $venueId = $fixture->getVenueId();
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway() || null === $venueId) {
                continue;
            }
            // Amical (competitionId null) : exempté (comme LEAGUE_WINDOW_VIOLATION et
            // CLUB_RULE_VIOLATION) — il se joue où le club veut.
            if (null === $fixture->getCompetitionId()) {
                continue;
            }
            $forbidden = $forbiddenVenuesByTeam[$fixture->getTeamId()] ?? [];
            if (!\in_array($venueId, $forbidden, true)) {
                continue;
            }
            $conflicts[] = [
                'type' => 'TEAM_VENUE_FORBIDDEN',
                'severity' => 3,
                'venueId' => $venueId,
                'fixture' => $this->bareFixtureView($fixture),
            ];
        }

        return $conflicts;
    }

    /**
     * Severity 4 (dette ii) — a placed HOME fixture whose kickoff no longer sits
     * in any access window of (venue, weekday): the window moved AFTER the
     * placement. PANEL rule mirrored exactly (kickoff point, half-open end,
     * no window anywhere = data not adopted = nothing to enforce).
     *
     * @param list<Fixture>          $fixtures
     * @param list<VenueMatchWindow> $matchWindows
     *
     * @return list<array<string, mixed>>
     */
    public function accessWindowLostConflicts(array $fixtures, array $matchWindows): array
    {
        if ([] === $matchWindows) {
            return [];
        }

        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $venueId = $fixture->getVenueId();
            $kickoffTime = $fixture->getKickoffTime();
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway() || null === $venueId || !$kickoffTime instanceof DateTimeImmutable) {
                continue;
            }
            $day = (int) $fixture->getMatchDate()->format('N');
            $kickoff = $kickoffTime->format('H:i');
            $windowArrays = array_map(static fn (VenueMatchWindow $w): array => [
                'venueId' => $w->getVenueId(),
                'dayOfWeek' => $w->getDayOfWeek(),
                'startTime' => $w->getStartTime()->format('H:i'),
                'endTime' => $w->getEndTime()->format('H:i'),
            ], $matchWindows);
            if (MatchConflictDetector::kickoffInsideWindow($venueId, $day, $kickoff, $windowArrays)) {
                continue;
            }
            // Les accès match du GYMNASE de la fixture, jour du match d'abord — de quoi
            // dire à l'écran « placé hors des accès (samedi 14:00–18:00, …) ». Champ
            // ADDITIF (hors identité : l'empreinte reste TYPE:fixtureId).
            $venueWindows = array_values(array_filter(
                $windowArrays,
                static fn (array $w): bool => $w['venueId'] === $venueId,
            ));
            usort($venueWindows, static fn (array $a, array $b): int => [
                $a['dayOfWeek'] === $day ? 0 : 1, $a['dayOfWeek'], $a['startTime'],
            ] <=> [
                $b['dayOfWeek'] === $day ? 0 : 1, $b['dayOfWeek'], $b['startTime'],
            ]);
            $conflicts[] = [
                'type' => 'ACCESS_WINDOW_LOST',
                'severity' => 4,
                'venueId' => $venueId,
                'fixture' => $this->bareFixtureView($fixture),
                'windows' => array_map(static fn (array $w): array => [
                    'dayOfWeek' => $w['dayOfWeek'],
                    'startTime' => $w['startTime'],
                    'endTime' => $w['endTime'],
                ], $venueWindows),
            ];
        }

        return $conflicts;
    }

    /**
     * A fixture sitting on a venue that is unavailable on its date. No window
     * math: the closure is all-circumstances, the DATE match suffices (a
     * kickoff-less home fixture with a venue is affected too).
     *
     * P4-300 — les deux SOURCES d'indisponibilité (une `VenueUnavailability` déclarée ET une
     * FERMETURE `venue_closed` du calendrier) arrivent fusionnées en UNE forme tableau commune
     * `{venueId, startDate, endDate, label, sourceId}`, montée par {@see ConflictRadarLoader}. Le
     * détecteur reste PUR : il ne distingue pas la source, le radar signale (sévérité 4), jamais de
     * dé-placement automatique d'un match déjà posé (décision fondateur D3). Le champ émis reste
     * `unavailabilityId` (le front le lit déjà) et porte le `sourceId` quelle que soit la source.
     *
     * @param list<Fixture>                                                                                          $fixtures
     * @param list<array{venueId: string, startDate: string, endDate: string, label: string|null, sourceId: string}> $unavailabilities
     *
     * @return list<array<string, mixed>>
     */
    public function venueUnavailableConflicts(array $fixtures, array $unavailabilities): array
    {
        if ([] === $unavailabilities) {
            return [];
        }

        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $venueId = $fixture->getVenueId();
            if (null === $venueId) {
                continue;
            }
            $date = $fixture->getMatchDate()->format('Y-m-d');
            foreach ($unavailabilities as $unavailability) {
                if ($unavailability['venueId'] !== $venueId) {
                    continue;
                }
                // Inclusive bounds: « du 4 au 28 février » covers the 28th.
                if (!MatchConflictDetector::dateInsideClosure($date, $unavailability['startDate'], $unavailability['endDate'])) {
                    continue;
                }
                $conflicts[] = [
                    'type' => 'VENUE_UNAVAILABLE',
                    'severity' => 4,
                    'venueId' => $venueId,
                    'unavailabilityId' => $unavailability['sourceId'],
                    'label' => $unavailability['label'],
                    'unavailableFrom' => $unavailability['startDate'],
                    'unavailableUntil' => $unavailability['endDate'],
                    'fixture' => [
                        'fixtureId' => $fixture->getId(),
                        'teamId' => $fixture->getTeamId(),
                        'homeAway' => $fixture->getHomeAway()->value,
                        'matchDate' => $date,
                        'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
                        'status' => $fixture->getStatus()->value,
                    ],
                ];
            }
        }

        return $conflicts;
    }

    /** @return array<string, mixed> */
    private function bareFixtureView(Fixture $fixture): array
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
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $a
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $b
     */
    private function overlaps(array $a, array $b): bool
    {
        // Half-open: back-to-back windows (endA == startB) do NOT conflict.
        return $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    private function maxMoment(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }

    private function minMoment(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    /**
     * @param FixtureView             $view
     * @param ConflictPersonRole|null $role the person's role on this fixture's team, on a PERSON conflict
     *                                      (MATCH_MATCH/MATCH_TRAINING). null for the gym/link families,
     *                                      which share this view but carry no person → no `role` key.
     *
     * @return array<string, mixed>
     */
    private function fixtureView(array $view, ?ConflictPersonRole $role = null): array
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
