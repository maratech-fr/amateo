<?php

declare(strict_types=1);

namespace App\Tests\Unit\Migration;

use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Une migration n'appelle sur `$this` que des méthodes qui existent : un nom faux ne se voit qu'au
 * jour où la branche tourne — un `down()` qui devait refuser proprement (« irréversible ») meurt
 * alors d'une erreur fatale `Call to undefined method`, et rien ne l'a dit avant.
 *
 * Pourquoi un test : PHPStan n'analyse que `src/` (`phpstan.neon`), les migrations lui échappent.
 * Cas qui l'a fait naître : `throwIrreversibleMigration(` (inexistante) au lieu de
 * `throwIrreversibleMigrationException(` (`AbstractMigration`).
 */
#[Group('phase1')]
final class MigrationsCallOnlyExistingMethodsTest extends TestCase
{
    private const string MIGRATIONS_DIR = __DIR__ . '/../../../migrations';

    public function testEveryMethodCalledOnThisExistsOnTheMigration(): void
    {
        $missing = [];
        $calls = 0;
        foreach ($this->migrationFiles() as $file) {
            $class = 'DoctrineMigrations\\' . basename($file, '.php');
            self::assertTrue(class_exists($class), \sprintf('%s ne déclare pas la classe %s (autoload DoctrineMigrations).', $file, $class));

            foreach ($this->methodsCalledOnThis($file) as $method) {
                ++$calls;
                if (!method_exists($class, $method)) {
                    $missing[] = \sprintf('%s : $this->%s(…)', basename($file), $method);
                }
            }
        }

        // Le motif a matché : sans appel relevé, la boucle passerait à vide.
        self::assertGreaterThan(100, $calls, 'le scan doit relever les appels $this->…( des migrations (addSql au moins)');
        self::assertSame([], array_values(array_unique($missing)), "Migrations qui appellent une méthode inexistante :\n  - " . implode("\n  - ", array_unique($missing)));
    }

    /**
     * Témoins de la sonde : la méthode du refus d'un `down()` existe sous son nom complet, et le nom
     * tronqué n'existe pas — la sonde distingue donc le bon appel du mauvais.
     */
    public function testTheProbeTellsTheIrreversibleMethodFromItsTruncatedName(): void
    {
        self::assertTrue(method_exists(AbstractMigration::class, 'throwIrreversibleMigrationException'), 'témoin positif : le bon nom existe');
        self::assertFalse(method_exists(AbstractMigration::class, 'throwIrreversibleMigration'), 'témoin négatif : le nom tronqué n\'existe pas');
        self::assertSame(
            ['addSql', 'throwIrreversibleMigrationException'],
            $this->methodsCalledIn('$this->addSql(\'SELECT 1\'); $this->connection->fetchOne(\'x\'); $this->throwIrreversibleMigrationException(\'non\');'),
            'la sonde relève les appels de méthode sur $this, pas les accès de propriété',
        );
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(self::MIGRATIONS_DIR . '/Version*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'aucune migration trouvée sous ' . self::MIGRATIONS_DIR);

        return $files;
    }

    /** @return list<string> */
    private function methodsCalledOnThis(string $file): array
    {
        $source = file_get_contents($file);
        self::assertIsString($source, 'illisible : ' . $file);

        return $this->methodsCalledIn($source);
    }

    /** @return list<string> */
    private function methodsCalledIn(string $source): array
    {
        preg_match_all('/\$this->(\w+)\s*\(/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }
}
