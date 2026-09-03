<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-141 addendum — `make play` doit être NON DESTRUCTEUR.
 *
 * `app:demo:seed` est « créer OU RESET » : il PURGE le workspace du club de démo
 * (ErasedClubPurger) avant de re-seeder — SAUF avec `--if-absent`, qui ne seede
 * que si le club est absent et ne touche à rien sinon. `app:bccl:seed` (le club
 * réel) est create-only : no-op si le club est déjà là. La cible racine
 * `make play` doit donc :
 *   - seeder le club réel via `seed-bccl` (create-only, no-op si présent) ;
 *   - seeder la démo via le mécanisme if-absent (`IF_ABSENT=1 seed-demo`), JAMAIS
 *     `seed-demo` nu (qui purgerait le workspace de démo à chaque re-`make play`) ;
 *   - ne JAMAIS vider la base (`db-empty`) ni recharger des fixtures.
 * Avec un `seed-demo` nu, l'assertion à lookbehind ROUGIT — le test falsifie bien
 * la régression.
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

        // Le club réel : create-only, no-op si présent — appelé directement.
        self::assertStringContainsString(
            'seed-bccl',
            $recipe,
            '`make play` doit poser le club réel via `seed-bccl` (create-only, no-op si présent).',
        );

        // La démo : uniquement via le mécanisme if-absent (non destructeur).
        self::assertStringContainsString(
            'IF_ABSENT=1 seed-demo',
            $recipe,
            '`make play` doit seeder la démo via `IF_ABSENT=1 seed-demo` (non destructeur).',
        );

        // Aucun `seed-demo` sans le préfixe `IF_ABSENT=1 ` (le seed destructeur créer OU RESET).
        self::assertDoesNotMatchRegularExpression(
            '/(?<!IF_ABSENT=1 )seed-demo/',
            $recipe,
            '`make play` ne doit JAMAIS invoquer `seed-demo` nu (il purge le workspace de démo).',
        );

        // Aucune purge de base ni rechargement de fixtures dans `make play`.
        self::assertStringNotContainsString(
            'db-empty',
            $recipe,
            '`make play` ne doit JAMAIS vider la base (`db-empty`) — il ne fait que créer/migrer + seeder si absent.',
        );
        self::assertStringNotContainsString(
            'fixtures',
            $recipe,
            '`make play` ne doit plus charger de fixtures — un seul chemin de remplissage (seed).',
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
