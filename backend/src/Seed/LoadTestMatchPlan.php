<?php

declare(strict_types=1);

namespace App\Seed;

/**
 * Calendrier de championnat FICTIF et DÉTERMINISTE pour les clubs de charge
 * (rail placement). Fonction PURE — aucune I/O, aucune entité Doctrine : elle
 * décrit QUOI créer, {@see LoadTestClubSeeder} persiste. Testable isolément.
 *
 * Règles (critères fondateur 2026-10-03) :
 *  - ~1 rencontre par week-end de championnat et par équipe ;
 *  - alternance domicile/extérieur (~50 %) pour une équipe qui REÇOIT (habitude de
 *    match) ; une équipe SANS habitude ne joue qu'à l'EXTÉRIEUR (18/50 au grand club) ;
 *  - samedi (jour ISO 6) ou dimanche (7) uniquement — les seuls jours porteurs de
 *    fenêtres d'accès match ; le jour de réception est l'habitude de l'équipe ;
 *  - découpage en PHASES : jeunes (U*) = 3 phases d'aller simple qui changent aux
 *    vacances ; seniors/U21 = aller-retour (2 phases) ;
 *  - une part des rencontres à domicile (~15-20 %) est DÉJÀ FIXÉE (créneau posé par
 *    la ligue / à la main) que le solveur doit contourner ;
 *  - les adversaires sont des libellés FICTIFS (aucun vrai club).
 *
 * Les dates restent dans les week-ends fournis (le solveur borne ensuite le coup
 * d'envoi par les fenêtres ligue `ClubLeagueWindow` si elles sont présentes).
 *
 * @phpstan-type Weekend array{saturday: string, sunday: string}
 * @phpstan-type TeamPlanInput array{teamId: string, isYouth: bool, hasHabit: bool, idealDay: int, idealKickoff: string, idealVenueId: string|null}
 * @phpstan-type PlannedMatch array{teamId: string, date: string, dayOfWeek: int, homeAway: string, phase: int, fixed: bool, kickoff: string|null, venueId: string|null, opponentLabel: string}
 */
final class LoadTestMatchPlan
{
    /**
     * Part visée de rencontres à domicile déjà fixées (créneau posé) : ~17 %, dans
     * la fourchette 15-20 % demandée. Sélection déterministe par empreinte stable.
     */
    private const int FIXED_PERCENT = 17;

    /**
     * @param list<TeamPlanInput> $teams
     * @param list<Weekend>       $weekends  week-ends de championnat, ordre chronologique
     * @param list<string>        $opponents libellés d'adversaires fictifs (non vide)
     *
     * @return list<PlannedMatch>
     */
    public static function build(array $teams, array $weekends, array $opponents): array
    {
        $weekendCount = \count($weekends);
        if (0 === $weekendCount || [] === $opponents) {
            return [];
        }

        // Bornes de phase : jeunes → 3 phases (2 coupures), seniors → aller-retour (1 coupure).
        $youthCut1 = intdiv($weekendCount, 3);
        $youthCut2 = intdiv(2 * $weekendCount, 3);
        $seniorCut = intdiv($weekendCount, 2);

        $matches = [];
        foreach ($teams as $teamIndex => $team) {
            foreach ($weekends as $weekIndex => $weekend) {
                $phase = $team['isYouth']
                    ? ($weekIndex < $youthCut1 ? 1 : ($weekIndex < $youthCut2 ? 2 : 3))
                    : ($weekIndex < $seniorCut ? 1 : 2);

                // Sans habitude de match → jamais à domicile. Sinon alternance ~50 %.
                $isHome = $team['hasHabit'] && 0 === (($teamIndex + $weekIndex) % 2);

                $day = $team['idealDay'];
                $date = 6 === $day ? $weekend['saturday'] : $weekend['sunday'];
                $opponent = $opponents[($teamIndex + $weekIndex + $phase) % \count($opponents)];

                if (!$isHome) {
                    // Un extérieur porte sa date (il libère la protection d'habitude ce
                    // jour-là) mais n'est jamais confié au solveur : ni créneau, ni gymnase.
                    $matches[] = [
                        'teamId' => $team['teamId'],
                        'date' => $date,
                        'dayOfWeek' => $day,
                        'homeAway' => 'AWAY',
                        'phase' => $phase,
                        'fixed' => false,
                        'kickoff' => null,
                        'venueId' => null,
                        'opponentLabel' => $opponent,
                    ];

                    continue;
                }

                // Domicile : ~17 % déjà fixés (gymnase + coup d'envoi posés), empreinte
                // stable sur (équipe, date) — reproductible d'un reseed à l'autre.
                $fixed = null !== $team['idealVenueId']
                    && (crc32($team['teamId'] . '|' . $date) % 100) < self::FIXED_PERCENT;

                $matches[] = [
                    'teamId' => $team['teamId'],
                    'date' => $date,
                    'dayOfWeek' => $day,
                    'homeAway' => 'HOME',
                    'phase' => $phase,
                    'fixed' => $fixed,
                    'kickoff' => $fixed ? $team['idealKickoff'] : null,
                    'venueId' => $fixed ? $team['idealVenueId'] : null,
                    'opponentLabel' => $opponent,
                ];
            }
        }

        return $matches;
    }
}
