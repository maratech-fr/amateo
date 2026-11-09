<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\DeployRunVerdict;
use App\Service\DeployRunOutcome;
use App\Tests\Integration\Command\DeploymentTickCommandTest;
use App\Tests\Unit\Documentation\DeployWorkflowDispatchContractTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : le verdict d'un run `deploy.yml` se lit dans ses JOBS et les STEPS du job de bascule,
 * jamais dans la seule conclusion du run — l'e-mail de fin ne dit « maintenance posée, hors
 * service » que si la maintenance a été posée ET que la bascule a échoué.
 *
 * Les huit règles du plan (v3, D2), dans l'ordre :
 *  1) run success + bascule success + release success → PROD_UPDATED ;
 *  2) run success + bascule skipped/absente (déploiement non armé) → BUILD_ONLY ;
 *  3) bascule success + release ≠ success → PROD_UPDATED_RELEASE_NOT_MOVED ;
 *  4) bascule ≠ success mais `SSH deploy` success (seul Clear a raté) → PROD_UPDATED_MAINTENANCE_LEFT ;
 *  5) bascule ≠ success, `SSH deploy` ≠ success, `Raise` success → FAILED_UNDER_MAINTENANCE ;
 *  6) bascule ≠ success, `Raise` skipped/absent → FAILED_APP_IN_SERVICE ;
 *  7) jobs vides (run annulé avant tout job) → FAILED_APP_IN_SERVICE ;
 *  8) `Raise` failure (fichier peut-être écrit) → UNDETERMINED.
 *
 * Les jeux de jobs reprennent les NOMS RENDUS PAR GITHUB (nom de job évalué
 * `Roll the VM to v1.3.3`, run du 2026-11-03) ; que ces noms collent à `deploy.yml` est la promesse
 * de {@see DeployWorkflowDispatchContractTest::testJobAndStepNamesMatchTheTickClassifier}.
 * L'usage du verdict par le tick (statut, e-mail, note) est prouvé par
 * {@see DeploymentTickCommandTest}.
 *
 * Contrat FIXÉ pour NEO : `DeployRunOutcome::fromJobs(list<array{name, conclusion, steps: list<array{name,
 * conclusion}>}> $jobs, string $runConclusion): self`, propriété publique `verdict` (`DeployRunVerdict`).
 */
#[Group('unit')]
final class DeployRunOutcomeTest extends TestCase
{
    private const string ROLL = 'Roll the VM to v1.3.3';

    /** @return iterable<string, array{string, list<array<string, mixed>>, DeployRunVerdict}> */
    public static function runs(): iterable
    {
        $allUp = self::steps('success', 'success', 'success');

        yield 'r1 tout réussi' => ['success', self::jobs('success', 'success', $allUp, 'success'), DeployRunVerdict::PROD_UPDATED];
        yield 'r1 nom de bascule à sortie vide' => ['success', [
            ['name' => 'Build & push prod images', 'conclusion' => 'success', 'steps' => []],
            ['name' => 'Roll the VM to ', 'conclusion' => 'success', 'steps' => $allUp],
            ['name' => 'Fast-forward `release` to the deployed commit', 'conclusion' => 'success', 'steps' => []],
        ], DeployRunVerdict::PROD_UPDATED];

        yield 'r2 bascule skipped (non armé)' => ['success', self::jobs('success', 'skipped', [], 'skipped'), DeployRunVerdict::BUILD_ONLY];
        yield 'r2 bascule absente' => ['success', [
            ['name' => 'Build & push prod images', 'conclusion' => 'success', 'steps' => []],
        ], DeployRunVerdict::BUILD_ONLY];

        foreach (['failure', 'cancelled', 'timed_out'] as $release) {
            yield 'r3 release ' . $release => ['failure', self::jobs('success', 'success', $allUp, $release), DeployRunVerdict::PROD_UPDATED_RELEASE_NOT_MOVED];
        }

        yield 'r4 Clear raté après SSH réussi' => ['failure', self::jobs('success', 'failure', self::steps('success', 'success', 'failure'), 'skipped'), DeployRunVerdict::PROD_UPDATED_MAINTENANCE_LEFT];

        yield 'r5 SSH deploy raté sous maintenance' => ['failure', self::jobs('success', 'failure', self::steps('success', 'failure', 'skipped'), 'skipped'), DeployRunVerdict::FAILED_UNDER_MAINTENANCE];
        yield 'r5 SSH deploy annulé sous maintenance' => ['cancelled', self::jobs('success', 'cancelled', self::steps('success', 'cancelled', 'skipped'), 'skipped'), DeployRunVerdict::FAILED_UNDER_MAINTENANCE];

        yield 'r6 build raté, bascule skipped' => ['failure', self::jobs('failure', 'skipped', [], 'skipped'), DeployRunVerdict::FAILED_APP_IN_SERVICE];
        yield 'r6 fenêtre invalide, Raise skipped' => ['failure', self::jobs('success', 'failure', [
            ['name' => 'Validate the maintenance window', 'conclusion' => 'failure'],
            ['name' => 'Raise the maintenance window', 'conclusion' => 'skipped'],
            ['name' => 'SSH deploy', 'conclusion' => 'skipped'],
            ['name' => 'Clear the maintenance window', 'conclusion' => 'skipped'],
        ], 'skipped'), DeployRunVerdict::FAILED_APP_IN_SERVICE];
        yield 'r6 build raté, bascule absente' => ['failure', [
            ['name' => 'Build & push prod images', 'conclusion' => 'failure', 'steps' => []],
        ], DeployRunVerdict::FAILED_APP_IN_SERVICE];

        yield 'r7 jobs vides (annulé avant tout job)' => ['cancelled', [], DeployRunVerdict::FAILED_APP_IN_SERVICE];

        yield 'r8 Raise en échec' => ['failure', self::jobs('success', 'failure', self::steps('failure', 'skipped', 'skipped'), 'skipped'), DeployRunVerdict::UNDETERMINED];
    }

    /**
     * @param list<array{name: string, conclusion: ?string}> $rollSteps
     *
     * @return list<array{name: string, conclusion: ?string, steps: list<array{name: string, conclusion: ?string}>}>
     */
    private static function jobs(string $build, string $roll, array $rollSteps, string $release): array
    {
        return [
            ['name' => 'Build & push prod images', 'conclusion' => $build, 'steps' => []],
            ['name' => self::ROLL, 'conclusion' => $roll, 'steps' => $rollSteps],
            ['name' => 'Fast-forward `release` to the deployed commit', 'conclusion' => $release, 'steps' => []],
        ];
    }

    /** @return list<array{name: string, conclusion: ?string}> */
    private static function steps(string $raise, string $ssh, string $clear): array
    {
        return [
            ['name' => 'Validate the maintenance window', 'conclusion' => 'success'],
            ['name' => 'Raise the maintenance window', 'conclusion' => $raise],
            ['name' => 'SSH deploy', 'conclusion' => $ssh],
            ['name' => 'Clear the maintenance window', 'conclusion' => $clear],
        ];
    }

    /** @param list<array<string, mixed>> $jobs */
    #[DataProvider('runs')]
    public function testTheVerdictReadsTheJobsAndStepsOfTheRun(string $runConclusion, array $jobs, DeployRunVerdict $expected): void
    {
        self::assertSame($expected, DeployRunOutcome::fromJobs($jobs, $runConclusion)->verdict);
    }
}
