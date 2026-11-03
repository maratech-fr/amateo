<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\ConflictCoachMailController;
use App\Controller\FixtureConflictsController;
use App\Entity\Coach;

/**
 * Envoi des conflits aux coachs — le service UNIQUE qui dit, pour le GET (compteurs du bouton,
 * {@see FixtureConflictsController}) comme pour le POST (envoi, {@see ConflictCoachMailController}),
 * QUI reçoit QUOI et qui est encore à prévenir. PUR : aucune lecture, aucune écriture.
 *
 *  - Périmètre : les trois familles « personne sur un match » (`MATCH_MATCH`, `MATCH_TRAINING`,
 *    `MATCH_COACH_UNAVAILABLE`), traitées ou non ; une ligne tenue par un autre coach n'est jamais
 *    envoyée.
 *  - Destinataires d'un conflit : la personne + les coachs PRINCIPAUX de chaque équipe d'un côté ;
 *    pour un côté entraînement, de chaque équipe du groupe mutualisé du créneau DANS SA VERSION.
 *    Adjoints : non. Un coach supprimé (absent de `$coachesById`) ne reçoit rien.
 *  - Mémoire : voir {@see nextMemory}.
 */
final class ConflictCoachMailing
{
    /** Les familles qui portent une personne sur un match — les seules envoyées. */
    public const array MAILED_FAMILIES = ['MATCH_MATCH', 'MATCH_TRAINING', 'MATCH_COACH_UNAVAILABLE'];

    /** Une adresse que l'envoi accepte (le filet de forme, la saisie fait foi). */
    public static function hasValidAddress(Coach $coach): bool
    {
        $email = $coach->getEmail();

        return null !== $email && false !== filter_var($email, \FILTER_VALIDATE_EMAIL);
    }

    /**
     * @param list<array<string, mixed>>        $conflicts          le flux servi (lignes tenues comprises ou non)
     * @param array<string, list<string>>       $mainCoachIdsByTeam teamId → coachs principaux
     * @param array<string, list<list<string>>> $groupsBySchedule   version → groupes mutualisés déclarés
     * @param list<string>                      $memory             les coachs qui détiennent encore une liste
     * @param array<string, Coach>              $coachesById        les coachs qui existent encore
     *
     * @return array{byCoach: array<string, list<array<string, mixed>>>, cleared: list<string>, pendingRecipientCount: int, personConflictCount: int}
     */
    public function plan(array $conflicts, array $mainCoachIdsByTeam, array $groupsBySchedule, array $memory, array $coachesById): array
    {
        $byCoach = [];
        $personConflictCount = 0;
        foreach ($conflicts as $conflict) {
            if (!\in_array($conflict['type'] ?? null, self::MAILED_FAMILIES, true) || isset($conflict['heldBy'])) {
                continue;
            }
            ++$personConflictCount;

            $recipients = [];
            $person = $conflict['coachId'] ?? null;
            if (\is_string($person)) {
                $recipients[$person] = true;
            }
            foreach ($this->teamsOf($conflict, $groupsBySchedule) as $teamId) {
                foreach ($mainCoachIdsByTeam[$teamId] ?? [] as $mainId) {
                    $recipients[$mainId] = true;
                }
            }
            foreach (array_keys($recipients) as $coachId) {
                if (isset($coachesById[$coachId])) {
                    $byCoach[$coachId][] = $conflict;
                }
            }
        }

        $cleared = [];
        $pending = 0;
        foreach (array_values(array_unique($memory)) as $coachId) {
            if (isset($byCoach[$coachId]) || !isset($coachesById[$coachId])) {
                continue;
            }
            $cleared[] = $coachId;
            if (self::hasValidAddress($coachesById[$coachId])) {
                ++$pending;
            }
        }

        return [
            'byCoach' => $byCoach,
            'cleared' => $cleared,
            'pendingRecipientCount' => $pending,
            'personConflictCount' => $personConflictCount,
        ];
    }

    /**
     * La règle de mémoire, unique : nouvelle mémoire = (ancienne ∪ coachs à qui une liste a été
     * enfilée) − coachs à qui « plus aucun conflit » a été enfilé − coachs supprimés. Un coach ne
     * sort que lorsqu'il a réellement été informé ; jamais de doublon.
     *
     * @param list<string>         $memory
     * @param list<string>         $enqueuedConflictLists
     * @param list<string>         $enqueuedCleared
     * @param array<string, Coach> $coachesById
     *
     * @return list<string>
     */
    public function nextMemory(array $memory, array $enqueuedConflictLists, array $enqueuedCleared, array $coachesById): array
    {
        $out = [];
        foreach ([...$memory, ...$enqueuedConflictLists] as $coachId) {
            if (\in_array($coachId, $enqueuedCleared, true) || !isset($coachesById[$coachId]) || \in_array($coachId, $out, true)) {
                continue;
            }
            $out[] = $coachId;
        }

        return $out;
    }

    /**
     * Les équipes des côtés d'un conflit ; un côté entraînement vaut pour toutes les équipes du
     * groupe mutualisé du créneau dans SA version.
     *
     * @param array<string, mixed>              $conflict
     * @param array<string, list<list<string>>> $groupsBySchedule
     *
     * @return list<string>
     */
    private function teamsOf(array $conflict, array $groupsBySchedule): array
    {
        $teams = [];
        foreach (['left', 'right', 'fixture'] as $key) {
            $side = $conflict[$key] ?? null;
            if (\is_array($side) && \is_string($side['teamId'] ?? null)) {
                $teams[] = $side['teamId'];
            }
        }

        $training = $conflict['training'] ?? null;
        if (\is_array($training) && \is_string($training['teamId'] ?? null)) {
            $teamId = $training['teamId'];
            $scheduleId = \is_string($training['scheduleId'] ?? null) ? $training['scheduleId'] : '';
            $teams[] = $teamId;
            foreach ($groupsBySchedule[$scheduleId] ?? [] as $group) {
                if (\in_array($teamId, $group, true)) {
                    array_push($teams, ...$group);
                }
            }
        }

        return array_values(array_unique($teams));
    }
}
