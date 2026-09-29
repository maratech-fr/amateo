<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MatchConstraint;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;

/**
 * P4-272 ③ (ajout fondateur) — l'ALERTE DE COHÉRENCE entre les règles de match du
 * club et les créneaux idéaux des équipes (`TeamMatchHabit`, semaine type A/B).
 *
 * LECTURE SEULE, CALCULÉE, rien n'est stocké, rien n'est bloqué : quand une règle
 * club (HARD OU PREFERRED) heurte le créneau idéal d'une équipe (le coup d'envoi
 * idéal tombe hors de la fourchette de la règle, un jour que la règle couvre), on
 * le SIGNALE. Le gestionnaire décide.
 *
 * MAISON UNIQUE de ce croisement (le front l'affiche, il ne le redérive pas — la
 * frontière métier §rule 1 : le prédicat de violation vit déjà côté serveur,
 * {@see MatchConflictDetector::kickoffSatisfiesClubRule}, réutilisé ici). Deux
 * projections du MÊME ensemble de collisions :
 *  - `byRule` : par règle, les créneaux idéaux qu'elle heurte (section Club de
 *    l'écran des contraintes) ;
 *  - `byHabit` : par créneau idéal, les règles qui le heurtent (écran Semaine type).
 *
 * Pur : le contrôleur charge les entités scopées et les passe ; ce service ne fait
 * que croiser, donc testable sans kernel.
 */
final class ClubRuleCoherenceChecker
{
    /**
     * @param list<MatchConstraint> $clubRules CLUB-scoped rules (HARD and PREFERRED both alert)
     * @param list<TeamMatchHabit>  $habits    the teams' ideal slots
     * @param list<Team>            $teams     to name the team an ideal slot belongs to
     *
     * @return array{
     *     byRule: list<array{ruleId: string, habits: list<array{teamId: string, teamName: string, week: string, dayOfWeek: int, kickoff: string}>}>,
     *     byHabit: list<array{habitId: string, rules: list<array{ruleId: string, ruleType: string, daysOfWeek: list<int>, kickoffMin: string|null, kickoffMax: string|null}>}>
     * }
     */
    public function check(array $clubRules, array $habits, array $teams): array
    {
        $teamNameById = [];
        foreach ($teams as $team) {
            $teamNameById[$team->getId()] = $team->getName();
        }

        $byRule = [];
        $byHabit = [];
        foreach ($clubRules as $rule) {
            $ruleBounds = [
                'kickoffMin' => $rule->getKickoffMin()?->format('H:i'),
                'kickoffMax' => $rule->getKickoffMax()?->format('H:i'),
            ];
            $ruleDays = $rule->getDaysOfWeek();
            $ruleHabits = [];
            foreach ($habits as $habit) {
                if (!\in_array($habit->getDayOfWeek(), $ruleDays, true)) {
                    continue;
                }
                $kickoff = $habit->getKickoffTime()->format('H:i');
                if (MatchConflictDetector::kickoffSatisfiesClubRule($kickoff, $ruleBounds)) {
                    continue;
                }
                $ruleHabits[] = [
                    'teamId' => $habit->getTeamId(),
                    'teamName' => $teamNameById[$habit->getTeamId()] ?? '',
                    'week' => $habit->getWeek()->value,
                    'dayOfWeek' => $habit->getDayOfWeek(),
                    'kickoff' => $kickoff,
                ];
                $byHabit[$habit->getId()][] = [
                    'ruleId' => $rule->getId(),
                    'ruleType' => $rule->getRuleType()->value,
                    'daysOfWeek' => $ruleDays,
                    'kickoffMin' => $ruleBounds['kickoffMin'],
                    'kickoffMax' => $ruleBounds['kickoffMax'],
                ];
            }
            if ([] !== $ruleHabits) {
                $byRule[] = ['ruleId' => $rule->getId(), 'habits' => $ruleHabits];
            }
        }

        $byHabitList = [];
        foreach ($byHabit as $habitId => $rules) {
            $byHabitList[] = ['habitId' => $habitId, 'rules' => $rules];
        }

        return ['byRule' => $byRule, 'byHabit' => $byHabitList];
    }
}
