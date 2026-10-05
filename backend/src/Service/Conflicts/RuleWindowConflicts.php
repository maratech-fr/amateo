<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

use App\Entity\Fixture;
use App\Entity\LeagueWindowInterface;
use App\Enum\FixtureHomeAway;
use App\Service\MatchConflictDetector;
use DateTimeImmutable;

/**
 * Famille « règles & fenêtres » du radar de conflits — extraite VERBATIM de
 * {@see MatchConflictDetector} (iso-comportement, aucune règle changée). La façade
 * {@see MatchConflictDetector::detect()} reste l'unique point d'entrée et délègue
 * ici les violations d'ENVELOPPE DE LIGUE et de RÈGLE DE MATCH du club. Les
 * prédicats purs partagés avec le front (`kickoffInsideLeagueWindow`,
 * `kickoffSatisfiesClubRule`) restent publics sur {@see MatchConflictDetector}
 * (consommés par le front, `ClubRuleCoherenceChecker` et les parités) et sont
 * appelés statiquement ici.
 */
final class RuleWindowConflicts
{
    /**
     * Severity 2 — a placed HOME fixture of a MAPPED team outside every resolved
     * league window (day or kickoff). Unmapped team ([] envelope) = silent, same
     * tolerance as the solver and the placement screen.
     *
     * @param list<Fixture>                              $fixtures
     * @param array<string, list<LeagueWindowInterface>> $envelope
     *
     * @return list<array<string, mixed>>
     */
    public function leagueWindowViolations(array $fixtures, array $envelope): array
    {
        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $kickoffTime = $fixture->getKickoffTime();
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway() || !$kickoffTime instanceof DateTimeImmutable) {
                continue;
            }
            // Un amical (competitionId null) n'obéit à aucune enveloppe de ligue
            // FFBB : il ne peut donc jamais être « hors fenêtre autorisée par la
            // ligue » (décision fondateur, 2026-09-08, P4-190). Les autres
            // familles (VENUE_OVERLAP, MATCH_MATCH…) continuent de s'appliquer.
            if (null === $fixture->getCompetitionId()) {
                continue;
            }
            $windows = $envelope[$fixture->getTeamId()] ?? [];
            if ([] === $windows) {
                continue;
            }
            $day = (int) $fixture->getMatchDate()->format('N');
            $kickoff = $kickoffTime->format('H:i');
            $windowArrays = array_map(static fn (LeagueWindowInterface $w): array => [
                'dayOfWeek' => $w->getDayOfWeek(),
                'kickoffMin' => $w->getKickoffMin()->format('H:i'),
                'kickoffMax' => $w->getKickoffMax()->format('H:i'),
            ], $windows);
            if (MatchConflictDetector::kickoffInsideLeagueWindow($day, $kickoff, $windowArrays)) {
                continue;
            }
            $conflicts[] = [
                'type' => 'LEAGUE_WINDOW_VIOLATION',
                'severity' => 2,
                'windows' => $windowArrays,
                'fixture' => $this->bareFixtureView($fixture),
            ];
        }

        return $conflicts;
    }

    /**
     * Severity 3 (P4-272 ③, entre LEAGUE_WINDOW_VIOLATION 2 et ACCESS_WINDOW_LOST 4)
     * — un domicile POSÉ dont le coup d'envoi viole une règle de match HARD du club le
     * jour du match. Règles HARD SEULEMENT (une PREFERRED ne fait jamais violation, elle
     * n'oriente que le solveur). Amicaux EXEMPTÉS (comme LEAGUE_WINDOW_VIOLATION, même
     * décision). La pose manuelle hors règle HARD reste PERMISE — ceci la SIGNALE, il ne
     * la bloque pas. `rules` porte les règles violées, de quoi nommer le motif à l'écran.
     *
     * @param list<Fixture>                                                                                          $fixtures
     * @param list<array{ruleType: string, daysOfWeek: list<int>, kickoffMin: string|null, kickoffMax: string|null}> $clubRules
     *
     * @return list<array<string, mixed>>
     */
    public function clubRuleViolations(array $fixtures, array $clubRules): array
    {
        $hard = array_values(array_filter($clubRules, static fn (array $rule): bool => 'HARD' === $rule['ruleType']));
        if ([] === $hard) {
            return [];
        }

        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $kickoffTime = $fixture->getKickoffTime();
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway() || !$kickoffTime instanceof DateTimeImmutable) {
                continue;
            }
            // Amical (competitionId null) : exempté de toute règle de match (comme
            // l'enveloppe ligue) — il se joue quand le club veut.
            if (null === $fixture->getCompetitionId()) {
                continue;
            }
            $day = (int) $fixture->getMatchDate()->format('N');
            $kickoff = $kickoffTime->format('H:i');
            $violated = [];
            foreach ($hard as $rule) {
                if (!\in_array($day, $rule['daysOfWeek'], true)) {
                    continue;
                }
                if (!MatchConflictDetector::kickoffSatisfiesClubRule($kickoff, $rule)) {
                    $violated[] = [
                        'daysOfWeek' => $rule['daysOfWeek'],
                        'kickoffMin' => $rule['kickoffMin'],
                        'kickoffMax' => $rule['kickoffMax'],
                    ];
                }
            }
            if ([] === $violated) {
                continue;
            }
            $conflicts[] = [
                'type' => 'CLUB_RULE_VIOLATION',
                'severity' => 3,
                'rules' => $violated,
                'fixture' => $this->bareFixtureView($fixture),
            ];
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
}
