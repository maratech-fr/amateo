<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use DOMDocument;
use DOMElement;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Chaque sous-dossier direct de `tests/` qui porte au moins un `*Test.php` doit appartenir à
 * EXACTEMENT une testsuite de `phpunit.xml.dist` — sinon `--testsuite` (et donc `make test`,
 * qui lance la suite `Unit` seule, gotcha §10.1 de `CLAUDE.md`) le saute EN SILENCE. La CI
 * `unit-tests` lance `phpunit tests/` entier et couvre tout ; le trou n'est visible que par
 * testsuite, jamais en local sur la suite rapide.
 *
 * Relevé du 2026-09-03 : huit dossiers étaient hors de toute testsuite (`Api`, `Command`,
 * `OpenApi`, `Validator`, `MessageHandler`, `EventListener`, `Logging`, `Messenger`) — verts en
 * CI, jamais joués par `make test` ni par aucun `--testsuite`. Ce garde est ce qui empêche le
 * trou de se reformer à la prochaine création de dossier (P4-169).
 *
 * Deuxième invariant tenu ici : la suite `Unit` reste SANS conteneur (rapide). Un test qui
 * `extends KernelTestCase|WebTestCase` rangé par erreur sous `Unit` alourdirait la suite rapide
 * et trahirait la règle de rangement PAR NATURE (E1) — il appartient à `Integration`.
 */
final class TestsuitesCoverEveryTestDirectoryTest extends TestCase
{
    private const string TESTS_DIR = __DIR__ . '/..';

    private const string PHPUNIT_CONFIG = __DIR__ . '/../../phpunit.xml.dist';

    public function testEveryTestDirectoryBelongsToExactlyOneTestsuite(): void
    {
        $suitesByDirectory = $this->topLevelDirectoriesBySuite();

        $errors = [];
        foreach ($this->topLevelDirectoriesHoldingTests() as $directory) {
            $suites = array_values(array_unique($suitesByDirectory[$directory] ?? []));

            if ([] === $suites) {
                $errors[] = \sprintf(
                    'tests/%s : dans AUCUNE testsuite — `make test` et tout `--testsuite` le sautent en silence.',
                    $directory,
                );

                continue;
            }

            if (\count($suites) > 1) {
                $errors[] = \sprintf(
                    'tests/%s : dans %d testsuites (%s) — il doit figurer dans exactement une.',
                    $directory,
                    \count($suites),
                    implode(', ', $suites),
                );
            }
        }

        self::assertSame([], $errors, \sprintf(
            "Rangement des testsuites incomplet dans phpunit.xml.dist :\n  - %s\n"
            . 'Règle (par NATURE) : un dossier avec au moins un test conteneur (Kernel/WebTestCase) va dans '
            . '`Integration`, un dossier 100%% `TestCase` pur va dans `Unit`.',
            implode("\n  - ", $errors),
        ));
    }

    public function testUnitSuiteHoldsNoContainerBootingTest(): void
    {
        $offenders = [];
        foreach ($this->directoriesOfSuite('Unit') as $directory) {
            foreach ($this->testFilesUnder($directory) as $file) {
                $source = file_get_contents($file);
                self::assertIsString($source);

                if (1 === preg_match('/\bclass\s+\w+\s+extends\s+(?:KernelTestCase|WebTestCase)\b/', $source)) {
                    $offenders[] = str_replace(self::TESTS_DIR . '/', 'tests/', $file);
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            'Ces fichiers démarrent un conteneur (extends Kernel/WebTestCase) sous la suite « Unit », '
            . "qui doit rester rapide et sans conteneur :\n  - %s\n"
            . 'Rangez-les sous un dossier de la suite `Integration` (rangement par NATURE, E1).',
            implode("\n  - ", $offenders),
        ));
    }

    /**
     * Sous-dossiers directs de `tests/` contenant au moins un `*Test.php` (récursif).
     *
     * @return list<string>
     */
    private function topLevelDirectoriesHoldingTests(): array
    {
        $directories = [];
        foreach (scandir(self::TESTS_DIR) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = self::TESTS_DIR . '/' . $entry;
            if (is_dir($path) && [] !== $this->testFilesUnder($path)) {
                $directories[] = $entry;
            }
        }

        sort($directories);

        return $directories;
    }

    /**
     * Nom de dossier de premier niveau (sous `tests/`) → liste des testsuites qui le couvrent.
     * Le XML est parsé (pas de regex fragile) ; un `<directory>tests/Xxx/...</directory>` est
     * rattaché à son premier segment `Xxx`.
     *
     * @return array<string, list<string>>
     */
    private function topLevelDirectoriesBySuite(): array
    {
        $document = new DOMDocument;
        self::assertTrue($document->load(self::PHPUNIT_CONFIG), 'phpunit.xml.dist illisible.');

        $suites = $document->getElementsByTagName('testsuite');
        self::assertGreaterThan(0, $suites->length, 'phpunit.xml.dist ne déclare aucune testsuite.');

        $map = [];
        foreach ($suites as $suite) {
            self::assertInstanceOf(DOMElement::class, $suite);
            $suiteName = $suite->getAttribute('name');

            foreach ($suite->getElementsByTagName('directory') as $directory) {
                $segment = $this->firstSegmentUnderTests(trim($directory->textContent));
                if (null !== $segment) {
                    $map[$segment][] = $suiteName;
                }
            }
        }

        return $map;
    }

    /**
     * Dossiers de premier niveau (sous `tests/`) couverts par une testsuite donnée.
     *
     * @return list<string>
     */
    private function directoriesOfSuite(string $suiteName): array
    {
        $directories = [];
        foreach ($this->topLevelDirectoriesBySuite() as $segment => $suites) {
            if (\in_array($suiteName, $suites, true)) {
                $directories[] = self::TESTS_DIR . '/' . $segment;
            }
        }

        return $directories;
    }

    private function firstSegmentUnderTests(string $directory): ?string
    {
        if (!str_starts_with($directory, 'tests/')) {
            return null;
        }

        $segments = explode('/', substr($directory, \strlen('tests/')));

        return '' === $segments[0] ? null : $segments[0];
    }

    /**
     * @return list<string>
     */
    private function testFilesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
