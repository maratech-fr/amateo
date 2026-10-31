<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : les recettes de la stack d'épreuve et des worktrees nus ne peuvent atteindre ni la
 * stack ni la base du fondateur, et le rail existant (base de test, confirmations) n'a pas bougé.
 *
 * Grep statique de ce qu'un faux docker ne voit pas : le texte des recettes. Le comportement
 * (qui est routé où, qui est refusé) : `backend/scripts/lib/stack-resolve-test.sh`.
 */
#[Group('phase1')]
final class WorktreeTestRoutingTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function confirmedTargets(): iterable
    {
        yield 'db-empty' => ['db-empty'];
        yield 'seed-demo' => ['seed-demo'];
        yield 'seed-bccl' => ['seed-bccl'];
    }

    /** Deux runs e2e parallèles se liraient leur clé TOTP dans un fichier /tmp au nom fixe. */
    public function testE2eSuperadminFileIsPrivateToTheRun(): void
    {
        $recipe = $this->recipe('frontend/Makefile', 'e2e');

        self::assertStringContainsString('$$(mktemp)', $recipe);
        self::assertStringNotContainsString('/tmp/amateo-e2e-sa.txt', $recipe);
    }

    /** Sinon le `.env` d'épreuve survit, et le worktree ne repasse jamais proprement en nu. */
    public function testEpreuveDestroyRemovesTheWorktreeEnv(): void
    {
        self::assertMatchesRegularExpression('/rm -f \S*\.env\b/', $this->recipe('Makefile', 'epreuve-destroy'));
    }

    /** `seed-demo` et `seed-bccl` se volent les catégories dans une même base. */
    public function testEpreuveNeverSeedsTheDemoClub(): void
    {
        $recipes = $this->epreuveRecipes();

        self::assertStringNotContainsString('seed-demo', $recipes);
        self::assertStringNotContainsString('app:demo:seed', $recipes);
    }

    /** `$(DOCKER_COMPOSE)` (Makefile:3) vise la stack de la copie appelante : le principal. */
    public function testEpreuveRecipesNameTheirProjectLiterally(): void
    {
        $recipes = $this->epreuveRecipes();

        self::assertStringContainsString('-p amateo-epreuve', $recipes);
        self::assertStringNotContainsString('$(DOCKER_COMPOSE)', $recipes);
    }

    public function testSweepTargetRetiresProjectsWithoutAWorktree(): void
    {
        $contents = $this->read('backend/Makefile');
        $found = false;
        foreach ($this->targets($contents) as $target) {
            if (str_starts_with($target, 'test-') && \in_array($target, ['test-up', 'test-down', 'test-destroy', 'test-check'], true)) {
                continue;
            }
            $recipe = $this->extractRecipe($contents, $target);
            if (str_contains($recipe, 'amateo-test-') && str_contains($recipe, 'worktree')) {
                $found = true;
            }
        }

        self::assertTrue($found, 'Aucune cible de backend/Makefile ne balaie les projets amateo-test-* sans worktree.');
    }

    /** La purge des transactions zombies et la cible amateo_test restent le rail de la base de test. */
    public function testDbInitTestIsUnchanged(): void
    {
        $recipe = $this->recipe('backend/Makefile', 'db-init-test');

        self::assertStringContainsString('doctrine:database:create --if-not-exists --connection admin -e test', $recipe);
        self::assertStringContainsString('doctrine:migrations:migrate --no-interaction -e test', $recipe);
        self::assertStringContainsString('psql -U amateo_owner -d postgres', $recipe);
        self::assertStringContainsString('ALTER DATABASE amateo_test SET idle_in_transaction_session_timeout = \'60s\'', $recipe);
    }

    /** Un nu reste sur amateo_test : c'est sa compose qui change, jamais le nom de sa base. */
    public function testDotEnvTestStillPointsAtTheTestDatabase(): void
    {
        $env = $this->read('backend/.env.test');

        self::assertStringContainsString('DATABASE_URL="postgresql://amateo_app:app_user_password@postgres:5432/amateo_test?serverVersion=16&charset=utf8"', $env);
        self::assertStringContainsString('DATABASE_ADMIN_URL="postgresql://amateo_owner:clubscheduler_dev_password@postgres:5432/amateo_test?serverVersion=16&charset=utf8"', $env);
    }

    /**
     * Doublon assumé, angle nommé : MutationTargetsAreGuardedTest lit la recette écrite à plat ;
     * ici on verrouille que le ROUTAGE ne déporte pas la confirmation hors de la recette (dans un
     * `define`, un include), ce qui rendrait ce test jumeau rouge pour une raison trompeuse.
     */
    #[DataProvider('confirmedTargets')]
    public function testConfirmationStaysInTheRecipeOfEveryDestructiveTarget(string $target): void
    {
        self::assertStringContainsString('mutation-confirm.sh', $this->recipe('backend/Makefile', $target));
    }

    /** Patron PlayTargetIsNonDestructiveTest : `play` reste lisible comme règle de premier niveau. */
    public function testPlayRecipeKeepsItsNonDestructiveShape(): void
    {
        $recipe = $this->recipe('Makefile', 'play');

        self::assertStringContainsString('IF_ABSENT=1 seed-demo', $recipe);
        self::assertDoesNotMatchRegularExpression('/(?<!IF_ABSENT=1 )seed-demo/', $recipe);
    }

    private function epreuveRecipes(): string
    {
        $contents = $this->read('Makefile');
        $recipes = '';
        foreach ($this->targets($contents) as $target) {
            if (str_starts_with($target, 'epreuve-')) {
                $recipes .= $this->extractRecipe($contents, $target) . "\n";
            }
        }
        self::assertStringContainsString('epreuve', $contents);
        self::assertNotSame('', trim($recipes), 'Témoin : aucune cible epreuve-* dans le Makefile racine.');

        return $recipes;
    }

    private function recipe(string $file, string $target): string
    {
        $recipe = $this->extractRecipe($this->read($file), $target);
        self::assertNotSame('', $recipe, \sprintf('Le target `%s` doit exister dans %s.', $target, $file));

        return $recipe;
    }

    private function read(string $file): string
    {
        self::assertFileExists(self::ROOT . '/' . $file);
        $contents = file_get_contents(self::ROOT . '/' . $file);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function targets(string $makefile): array
    {
        preg_match_all('/^([a-zA-Z0-9][a-zA-Z0-9_-]*):/m', $makefile, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Patron MutationTargetsAreGuardedTest::extractRecipe (lignes indentées du target, y compris
     * après une ligne `<target>: APP_ENV = …`), en concaténant TOUTES ses règles.
     */
    private function extractRecipe(string $makefile, string $target): string
    {
        $recipe = [];
        $inTarget = false;

        foreach (explode("\n", $makefile) as $line) {
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

                $inTarget = false;
            }
        }

        return implode("\n", $recipe);
    }
}
