<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-145 — les CIBLES MAKE destructrices doivent porter le garde-fou interactif
 * `scripts/lib/mutation-confirm.sh`.
 *
 * Le garde fail-closed (sandbox-guard.sh) couvre les SCRIPTS de l'IA, PAS les
 * cibles Make : `make fixtures`, `make db-reset`, `make seed-bccl` mutaient/
 * purgeaient la base VISÉE sans aucune vérification — en mode play, elles
 * détruisaient amateo_local. La correction attache à chacune une confirmation
 * (silencieuse sur amateo_dev/*_test, refus sec sur la prod, question sur
 * amateo_local). Ce test rougit si l'une d'elles perd cette protection.
 *
 * NON gatant (pas un axe §7.1) : tourne dans `unit-tests`, ni dans `ci.yml`
 * (job `blocking-tests`) ni dans `docs/testing/blocking-tests.md` — même statut
 * que SandboxGuardCoverageTest et PlayTargetIsNonDestructiveTest.
 */
#[Group('phase1')]
final class MutationTargetsAreGuardedTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function destructiveTargets(): iterable
    {
        yield 'fixtures' => ['fixtures'];
        yield 'db-reset' => ['db-reset'];
        yield 'seed-bccl' => ['seed-bccl'];
    }

    public function testTheConfirmationLibraryExists(): void
    {
        $backend = \dirname(__DIR__, 3);
        self::assertFileExists(
            $backend . '/scripts/lib/mutation-confirm.sh',
            'La bibliothèque de confirmation doit exister : backend/scripts/lib/mutation-confirm.sh',
        );
    }

    #[DataProvider('destructiveTargets')]
    public function testDestructiveTargetInvokesTheConfirmationGuard(string $target): void
    {
        $backend = \dirname(__DIR__, 3);
        $makefile = $backend . '/Makefile';

        self::assertFileExists($makefile, 'Le Makefile backend doit exister.');
        $contents = file_get_contents($makefile);
        self::assertIsString($contents);

        $recipe = $this->extractRecipe($contents, $target);
        self::assertNotSame('', $recipe, \sprintf('Le target `%s` doit exister dans le Makefile backend.', $target));

        self::assertStringContainsString(
            'mutation-confirm.sh',
            $recipe,
            \sprintf('`make %s` mute/purge la base visée — sa recette DOIT porter scripts/lib/mutation-confirm.sh (garde P4-145).', $target),
        );
    }

    /**
     * Extrait la recette (lignes indentées par tabulation) du target donné,
     * jusqu'à la première ligne non indentée. Une éventuelle ligne
     * `<target>: APP_ENV = dev` précède le corps : elle matche aussi et remet
     * simplement `inTarget` à vrai, sans casser la collecte (patron
     * PlayTargetIsNonDestructiveTest).
     */
    private function extractRecipe(string $makefile, string $target): string
    {
        $lines = explode("\n", $makefile);
        $recipe = [];
        $inTarget = false;

        foreach ($lines as $line) {
            if (1 === preg_match('/^' . preg_quote($target, '/') . ':/', $line)) {
                $inTarget = true;

                continue;
            }

            if ($inTarget) {
                if (str_starts_with($line, "\t")) {
                    $recipe[] = $line;

                    continue;
                }

                if ('' === trim($line)) {
                    continue;
                }

                break;
            }
        }

        return implode("\n", $recipe);
    }
}
