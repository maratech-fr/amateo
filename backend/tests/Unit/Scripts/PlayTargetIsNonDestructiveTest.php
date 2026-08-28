<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-141 addendum — `make play` doit être NON DESTRUCTEUR.
 *
 * `app:demo:seed-bccl` est « créer OU RESET » : il PURGE le workspace du club de
 * démo (ErasedClubPurger) avant de re-seeder. La cible racine `make play`
 * l'appelait INCONDITIONNELLEMENT → chaque re-`make play` détruisait le travail
 * du fondateur sur BCCL. La correction : `make play` passe par
 * `seed-bccl-if-absent`, qui ne seede QUE si le club de démo est absent.
 *
 * Ce test lit la recette du target `play` dans le Makefile RACINE et exige :
 *   - qu'elle invoque `seed-bccl-if-absent` (le chemin non destructeur) ;
 *   - qu'elle n'invoque JAMAIS le seed destructeur `seed-bccl` en direct.
 * Avec l'ancien comportement (`$(MAKE) -C backend seed-bccl`), les deux
 * assertions ROUGISSENT — le test falsifie bien la régression.
 *
 * NON gatant (pas un axe §7.1) : tourne dans `unit-tests`, ni dans `ci.yml`
 * (job `blocking-tests`) ni dans `docs/testing/blocking-tests.md`.
 */
#[Group('phase1')]
final class PlayTargetIsNonDestructiveTest extends TestCase
{
    public function testPlayTargetSeedsOnlyWhenTheDemoClubIsAbsent(): void
    {
        $repoRoot = \dirname(__DIR__, 4);
        $makefile = $repoRoot . '/Makefile';

        self::assertFileExists($makefile, 'Le Makefile racine doit exister.');
        $contents = file_get_contents($makefile);
        self::assertIsString($contents);

        $recipe = $this->extractRecipe($contents, 'play');
        self::assertNotSame('', $recipe, 'Le target `play` doit exister dans le Makefile racine.');

        self::assertStringContainsString(
            'seed-bccl-if-absent',
            $recipe,
            '`make play` doit passer par `seed-bccl-if-absent` (non destructeur), pas par le seed direct.',
        );

        // `seed-bccl` NON suivi de `-if-absent` = le seed destructeur (créer OU RESET).
        self::assertDoesNotMatchRegularExpression(
            '/seed-bccl(?!-if-absent)/',
            $recipe,
            '`make play` ne doit JAMAIS invoquer le seed destructeur `seed-bccl` en direct (il purge BCCL).',
        );
    }

    /**
     * Extrait la recette (lignes indentées par tabulation) du target donné,
     * jusqu'à la première ligne non indentée.
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
