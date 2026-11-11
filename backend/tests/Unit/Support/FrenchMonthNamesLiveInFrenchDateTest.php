<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Promesse : une date française lue par un humain s'écrit d'une seule façon (« 1er novembre ») —
 * aucune table de noms de mois ne vit hors de `App\Support\FrenchDate`. Une copie locale divergeait
 * déjà : l'e-mail d'invitation écrivait « 1 novembre 2026 », les 422 d'accès match
 * « samedi 1 novembre », là où le PDF écrit « 1er ».
 *
 * Motif : le littéral `'février'` (ou `"février"`), présent dans toute table de mois et dans aucune
 * prose de commentaire. Témoin positif : `FrenchDate.php` le porte, sans quoi le motif ne prouverait
 * rien. Lecture du disque (pas `git grep`, absent d'un conteneur de worktree).
 */
final class FrenchMonthNamesLiveInFrenchDateTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../src';

    private const string HOME = 'Support/FrenchDate.php';

    public function testNoMonthNameTableOutsideFrenchDate(): void
    {
        $offenders = [];
        foreach ($this->phpFiles() as $relative => $source) {
            if (self::HOME !== $relative && 1 === preg_match('/[\'"]février[\'"]/u', $source)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, "Une table de noms de mois vit hors de App\\Support\\FrenchDate :\n" . implode("\n", $offenders) . "\nLe geste attendu : FrenchDate::long / weekday / longDay.");
    }

    public function testThePatternFindsTheTableInItsHome(): void
    {
        self::assertMatchesRegularExpression('/[\'"]février[\'"]/u', $this->phpFiles()[self::HOME] ?? '');
    }

    /** @return array<string, string> chemin relatif à `src/` => contenu */
    private function phpFiles(): array
    {
        $root = (string) realpath(self::SRC);
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof SplFileInfo);
            if ('php' === $file->getExtension()) {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
