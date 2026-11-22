<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Tests\Double\SameTransactionClubSubscriptionWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Un double de test ne sort jamais de l'environnement de test — en particulier l'écrivain
 * d'abonnement de test ({@see SameTransactionClubSubscriptionWriter}), dont la fonction SQL
 * `SECURITY DEFINER` donne au rôle applicatif l'écriture que la base lui retire : en production
 * ou en dev, elle rouvrirait à toute faille applicative « s'offrir une saison réglée ».
 *
 * Trois frontières, chacune avec son témoin positif (sans lui, un balayage à zéro ne prouve rien) :
 *   - la configuration : seul `config/services_test.yaml` (ou un dossier d'environnement `test/`)
 *     nomme un `App\Tests\…` — le kernel ne le charge qu'en `APP_ENV=test` ;
 *   - le schéma : aucune migration ni aucun fichier de `src/` ne nomme une fonction `amateo_test_…`
 *     — elle n'existe que dans la base de test, créée par le double ;
 *   - le chargement : `App\Tests\` n'est que dans `autoload-dev` — l'image de production installe
 *     `--no-dev --classmap-authoritative` (docker/php/Dockerfile), la classe y est introuvable.
 * Le refus de l'écriture directe par le rôle applicatif :
 * `RlsIsolationTest::testTheApplicationRoleCanNeitherCreateNorChangeASubscriptionLineEvenOnItsOwnClub`.
 */
#[Group('phase1')]
final class TestDoublesStayInTheTestEnvironmentTest extends TestCase
{
    private const string BACKEND = __DIR__ . '/../../..';

    private const string TEST_FUNCTION_PREFIX = 'amateo_test_';

    public function testOnlyTheTestEnvironmentConfigurationNamesATestClass(): void
    {
        $naming = [];
        foreach ($this->files('config') as $path => $content) {
            if (str_contains($content, 'App\Tests\\')) {
                $naming[] = $path;
            }
        }

        self::assertContains('config/services_test.yaml', $naming, 'témoin : services_test.yaml câble les doubles (le motif doit y matcher)');
        self::assertStringContainsString(SameTransactionClubSubscriptionWriter::class, $this->files('config')['config/services_test.yaml'], 'témoin : l\'écrivain d\'abonnement de test est câblé par services_test.yaml');
        $outside = array_values(array_filter($naming, static fn (string $path): bool => 'config/services_test.yaml' !== $path && !str_contains($path, '/test/')));
        self::assertSame([], $outside, 'une classe de tests/ n\'est câblée qu\'en environnement de test (config/services_test.yaml)');
    }

    public function testNoMigrationNorProductionCodeNamesATestOnlySqlFunction(): void
    {
        self::assertStringStartsWith(self::TEST_FUNCTION_PREFIX, SameTransactionClubSubscriptionWriter::FUNCTION_NAME, 'témoin : la fonction du double porte le préfixe réservé aux fonctions de test');
        self::assertNotSame([], array_filter($this->files('tests/Double'), static fn (string $content): bool => str_contains($content, 'FUNCTION_NAME = \'' . self::TEST_FUNCTION_PREFIX)), 'témoin : le motif matche la déclaration du double');

        $naming = [];
        foreach (['migrations', 'src'] as $dir) {
            foreach ($this->files($dir) as $path => $content) {
                if (str_contains($content, self::TEST_FUNCTION_PREFIX)) {
                    $naming[] = $path;
                }
            }
        }

        self::assertSame([], $naming, 'une fonction SQL de test (amateo_test_…) ne se crée ni ne s\'appelle hors de tests/ : jamais par une migration, jamais par le code de production');
    }

    public function testTestClassesAreOnlyAutoloadedInDevelopment(): void
    {
        $composer = json_decode((string) file_get_contents(self::BACKEND . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($composer));

        self::assertSame('tests/', $composer['autoload-dev']['psr-4']['App\Tests\\'] ?? null, 'témoin : tests/ est chargé en développement');
        $production = $composer['autoload']['psr-4'] ?? [];
        \assert(\is_array($production));
        self::assertArrayNotHasKey('App\Tests\\', $production, 'App\Tests\ n\'est jamais dans l\'autoload de production');
        foreach ($production as $prefix => $dir) {
            self::assertStringStartsNotWith('tests', (string) (\is_array($dir) ? implode(',', $dir) : $dir), \sprintf('l\'autoload de production (%s) ne pointe pas vers tests/', $prefix));
        }
    }

    /** @return array<string, string> chemin relatif à backend/ => contenu */
    private function files(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::BACKEND . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);
            if ($file->isFile()) {
                $files[$dir . substr($file->getPathname(), \strlen(self::BACKEND . '/' . $dir))] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
