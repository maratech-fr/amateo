<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Message\PlaceMatchesMessage;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sœur de {@see GenerationLockOutlivesTheSolveTest} pour le rail de PLACEMENT asynchrone.
 *
 * Le verrou {@see MatchPlacementLock} est pris par le contrôleur et rendu par
 * le worker : son TTL (= le budget de mur du run) doit TOUJOURS survivre au solve. S'il
 * expirait pendant que le worker attend l'engine, un second placement prendrait le verrou,
 * deux imports se marcheraient dessus, et le `release()` par token no-op correctement — aucune
 * exception, aucun log, aucun test rouge (la corruption la plus silencieuse, patron D-05).
 *
 * Le moteur solve ISO-semaine par ISO-semaine (ENG-50) : chaque semaine construit son modèle
 * sous `BUILD_BUDGET_SECONDS` PUIS solve sous `solver_timeout_seconds` (= le budget/semaine
 * envoyé par le backend). Le pire cas d'une semaine = build + solve. Ce test lit ces deux
 * bornes dans le moteur et vérifie que le budget réservé par semaine les couvre, et que le
 * budget total du run dépasse le pire cas moteur pour un éventail de tailles.
 */
#[Group('contract')]
final class PlacementLockOutlivesTheSolveTest extends TestCase
{
    private const string MATCH_PLACEMENT = __DIR__ . '/../../../engine/app/solver/match_placement/__init__.py';

    public function testTheReservedPerWeekBudgetCoversTheEngineWorstCasePerWeek(): void
    {
        $reservedPerWeek = MatchPlacementPayloadBuilder::WEEK_BUDGET_SECONDS + PlaceMatchesMessage::ENGINE_PER_WEEK_OVERHEAD_SECONDS;
        $engineWorstPerWeek = $this->engineSolveCapPerWeek() + $this->engineBuildBudgetSeconds();

        self::assertGreaterThanOrEqual($engineWorstPerWeek, $reservedPerWeek, \sprintf(
            "Le budget réservé par semaine (%d s) ne couvre plus le pire cas moteur par semaine (%d s = build %d + solve %d).\n"
            . 'Relevez ENGINE_PER_WEEK_OVERHEAD_SECONDS : sinon, sur assez de semaines, le verrou expire pendant le solve.',
            $reservedPerWeek,
            $engineWorstPerWeek,
            $this->engineBuildBudgetSeconds(),
            $this->engineSolveCapPerWeek(),
        ));
    }

    public function testTheRunBudgetExceedsTheEngineWorstCaseForEveryRealisticWeekCount(): void
    {
        $engineWorstPerWeek = $this->engineSolveCapPerWeek() + $this->engineBuildBudgetSeconds();

        // Une saison de jeunes dépasse rarement ~35 semaines de matchs ; on couvre large.
        foreach ([1, 2, 10, 35, 52] as $weeks) {
            $ttl = PlaceMatchesMessage::budgetSecondsFor($weeks);
            $worstCase = $weeks * $engineWorstPerWeek;

            self::assertGreaterThan($worstCase, $ttl, \sprintf(
                "Pour %d semaines, le verrou (TTL %d s) ne survit plus au pire solve moteur (%d s).\n"
                . 'Il expirerait pendant l\'attente du moteur : deux placements tourneraient sur le même club.',
                $weeks,
                $ttl,
                $worstCase,
            ));
        }
    }

    /** Le budget CP-SAT d'UNE semaine côté moteur = le `solver_timeout_seconds` du payload. */
    private function engineSolveCapPerWeek(): int
    {
        self::assertSame(
            1,
            preg_match('/max_time_in_seconds = float\(input_data\.solver_timeout_seconds\)/', $this->source()),
            'Le moteur ne borne plus le solve hebdo par `solver_timeout_seconds` — ce test doit suivre, pas se taire.',
        );

        // C'est exactement le budget/semaine que le backend envoie (MatchPlacementPayloadBuilder).
        return MatchPlacementPayloadBuilder::WEEK_BUDGET_SECONDS;
    }

    /** Le plafond de BUILD d'un sous-modèle hebdomadaire (`BUILD_BUDGET_SECONDS`). */
    private function engineBuildBudgetSeconds(): int
    {
        self::assertSame(
            1,
            preg_match('/^BUILD_BUDGET_SECONDS = (\d+(?:\.\d+)?)$/m', $this->source(), $matches),
            'BUILD_BUDGET_SECONDS a disparu du moteur — le pire cas par semaine n\'est plus borné par cette constante.',
        );

        return (int) ceil((float) $matches[1]);
    }

    private function source(): string
    {
        $source = file_get_contents(self::MATCH_PLACEMENT);
        self::assertIsString($source, 'engine/app/solver/match_placement/__init__.py est illisible.');

        return $source;
    }
}
