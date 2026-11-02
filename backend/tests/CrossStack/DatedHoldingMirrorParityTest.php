<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : `DatedHolding` (backend) et `match_placement/holding.py` (moteur) restent liés au même
 * fichier de cas, sans quoi ils pourraient dériver chacun avec des suites vertes. Chaque côté rejoue
 * les cas chez lui : `Unit/Service/Conflicts/DatedHoldingTest` et
 * `engine/tests/test_dated_holding_parity.py`. Ce test ne les rejoue pas, il vérifie le lien.
 */
#[Group('contract')]
final class DatedHoldingMirrorParityTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string CASES = 'datedHolding.parity.json';

    public function testBothSidesReadTheSameCases(): void
    {
        self::assertFileExists(self::ROOT . '/backend/tests/Fixtures/' . self::CASES);
        self::assertStringContainsString(self::CASES, $this->read('backend/tests/Unit/Service/Conflicts/DatedHoldingTest.php'));
        self::assertStringContainsString(self::CASES, $this->read('engine/tests/test_dated_holding_parity.py'));
    }

    public function testBothSidesStopAtTheSameBudget(): void
    {
        self::assertMatchesRegularExpression('/BUDGET = 10000;/', $this->read('backend/src/Service/Conflicts/DatedHolding.php'));
        self::assertMatchesRegularExpression('/BUDGET\s*(:\s*\w+\s*)?=\s*10_?000\b/', $this->read('engine/app/solver/match_placement/holding.py'));
    }

    private function read(string $path): string
    {
        $file = self::ROOT . '/' . $path;
        self::assertFileExists($file, 'moitié de miroir absente : ' . $path);

        return (string) file_get_contents($file);
    }
}
