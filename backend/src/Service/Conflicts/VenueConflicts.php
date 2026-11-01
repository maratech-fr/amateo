<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\VenueMatchWindow;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Service\ConflictRadarLoader;
use App\Service\EffectiveScheduleResolver;
use App\Service\MatchConflictDetector;
use DateInterval;
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
    public function __construct(
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
    ) {}

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
                if ($left['fixture']->getVenueId() !== $right['fixture']->getVenueId() || !ConflictMoments::overlaps($left['venueWindow'], $right['venueWindow'])) {
                    continue;
                }
                $conflicts[] = [
                    'type' => 'VENUE_OVERLAP',
                    'severity' => 1,
                    'venueId' => $left['fixture']->getVenueId(),
                    'start' => ConflictMoments::maxMoment($left['venueWindow']['start'], $right['venueWindow']['start'])->format(ConflictMoments::WALL_CLOCK_FORMAT),
                    'end' => ConflictMoments::minMoment($left['venueWindow']['end'], $right['venueWindow']['end'])->format(ConflictMoments::WALL_CLOCK_FORMAT),
                    'left' => ConflictMoments::fixtureView($left),
                    'right' => ConflictMoments::fixtureView($right),
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
                'fixture' => ConflictMoments::bareFixtureView($fixture),
            ];
        }

        return $conflicts;
    }

    /**
     * Severity 4 (dette ii) — a placed HOME fixture whose kickoff no longer sits in any
     * access window APPLICABLE on its date: the window moved AFTER the placement. PANEL rule
     * mirrored exactly (kickoff point, half-open end). A club without any NON-dated line has
     * not adopted the access windows: nothing to enforce, a dated opening alone changes nothing.
     *
     * Le prédicat est {@see MatchConflictDetector::kickoffInsideAccess} : ouvertures datées de ce
     * jour ∪ (férié ? ligne « Jours fériés » : lignes du jour de semaine). Sans plage applicable ce
     * jour-là, le coup d'envoi n'est dans aucune ⇒ ACCESS_WINDOW_LOST (« Hors accès match »).
     *
     * @param list<Fixture>          $fixtures
     * @param list<VenueMatchWindow> $matchWindows
     * @param list<string>           $publicHolidays dates fériées (Y-m-d)
     *
     * @return list<array<string, mixed>>
     */
    public function accessWindowLostConflicts(array $fixtures, array $matchWindows, array $publicHolidays = []): array
    {
        $windowArrays = array_map(MatchConflictDetector::windowArray(...), $matchWindows);
        if (!MatchConflictDetector::hasWeeklyAccess($windowArrays)) {
            return [];
        }

        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $venueId = $fixture->getVenueId();
            $kickoffTime = $fixture->getKickoffTime();
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway() || null === $venueId || !$kickoffTime instanceof DateTimeImmutable) {
                continue;
            }
            $date = $fixture->getMatchDate()->format('Y-m-d');
            $isHoliday = \in_array($date, $publicHolidays, true);
            if (MatchConflictDetector::kickoffInsideAccess($venueId, $date, $isHoliday, $kickoffTime->format('H:i'), $windowArrays)) {
                continue;
            }
            // Les plages applicables à cette date, de quoi dire à l'écran « placé hors des accès
            // (le 11 nov. 14:00–20:00) ». Champ ADDITIF (hors identité : l'empreinte reste TYPE:fixtureId).
            $applicable = MatchConflictDetector::applicableWindows($venueId, $date, $isHoliday, $windowArrays);
            usort($applicable, static fn (array $a, array $b): int => $a['startTime'] <=> $b['startTime']);
            $conflicts[] = [
                'type' => 'ACCESS_WINDOW_LOST',
                'severity' => 4,
                'venueId' => $venueId,
                'fixture' => ConflictMoments::bareFixtureView($fixture),
                'windows' => array_map(static fn (array $w): array => [
                    'dayOfWeek' => $w['dayOfWeek'],
                    'startTime' => $w['startTime'],
                    'endTime' => $w['endTime'],
                    'appliesOnPublicHolidays' => $w['appliesOnPublicHolidays'],
                    'openingDate' => $w['openingDate'],
                ], $applicable),
            ];
        }

        return $conflicts;
    }

    /**
     * Severity 5 — a placed HOME fixture (competition or friendly) whose VENUE window overlaps a
     * session of ANOTHER team in the effective schedule, same venue. A signal, never a move: one
     * item per fixture, listing every session. The session of the match's own team does not count
     * (same rule as MATCH_TRAINING), nor does a public holiday: no session that day, without any
     * period exception (same place in the reading as MATCH_TRAINING).
     *
     * @param list<FixtureView>                                                                      $views
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule
     * @param list<string>                                                                           $publicHolidays  dates fériées (Y-m-d)
     *
     * @return list<array<string, mixed>>
     */
    public function venueTrainingOverlapConflicts(array $views, ?string $seasonScheduleId, array $activePeriods, array $slotsBySchedule, array $publicHolidays = []): array
    {
        $conflicts = [];
        foreach ($views as $view) {
            $fixture = $view['fixture'];
            $venueId = $fixture->getVenueId();
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway()
                || null === $venueId
                || !\in_array($fixture->getStatus(), [FixtureStatus::PLACED, FixtureStatus::SUBMITTED, FixtureStatus::VALIDATED], true)
            ) {
                continue;
            }
            $date = $fixture->getMatchDate();
            if (\in_array($date->format('Y-m-d'), $publicHolidays, true)) {
                continue;
            }
            $scheduleId = $this->effectiveScheduleResolver->resolve($date, $activePeriods, $seasonScheduleId);
            if (null === $scheduleId) {
                continue;
            }

            $trainings = [];
            foreach ($slotsBySchedule[$scheduleId] ?? [] as $slot) {
                if ($slot->getVenueId() !== $venueId
                    || $slot->getDayOfWeek() !== (int) $date->format('N')
                    || $slot->getTeamId() === $fixture->getTeamId()
                ) {
                    continue;
                }
                $slotStart = $slot->getStartTime();
                $start = $date->setTime((int) $slotStart->format('H'), (int) $slotStart->format('i'));
                $end = $start->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'));
                if (!ConflictMoments::overlaps($view['venueWindow'], ['start' => $start, 'end' => $end])) {
                    continue;
                }
                $trainings[] = ['teamId' => $slot->getTeamId(), 'start' => $start->format('H:i'), 'end' => $end->format('H:i')];
            }
            if ([] === $trainings) {
                continue;
            }
            usort($trainings, static fn (array $a, array $b): int => [$a['start'], $a['teamId']] <=> [$b['start'], $b['teamId']]);
            $conflicts[] = [
                'type' => 'VENUE_TRAINING_OVERLAP',
                'severity' => 5,
                'venueId' => $venueId,
                'fixture' => ConflictMoments::bareFixtureView($fixture),
                'matchEnd' => $view['venueWindow']['end']->format('H:i'),
                'trainings' => $trainings,
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
}
