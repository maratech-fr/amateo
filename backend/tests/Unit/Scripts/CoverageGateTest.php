<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire du cliquet de couverture backend (P4-166, décision B3).
 *
 * `scripts/coverage-gate.php` remplace le seuil natif ABSENT de PHPUnit 11 : il lit la
 * couverture de lignes d'un rapport clover et la compare au plancher `backend` de
 * `coverage-floor.json`. Ce test vérifie la LECTURE (fixture clover minimale) et la
 * DÉCISION (au-dessus / au-dessous du plancher) — sans conteneur ni base.
 *
 * NON gatant (pas un axe §7.1) : tourne dans `unit-tests`.
 */
#[Group('phase1')]
final class CoverageGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/scripts/coverage-gate.php';
    }

    public function testLinePercentFromMinimalClover(): void
    {
        $clover = $this->writeClover(10, 8);

        self::assertEqualsWithDelta(80.0, coverage_gate_line_percent($clover), 0.0001);

        unlink($clover);
    }

    public function testLinePercentIsZeroWhenNoStatements(): void
    {
        $clover = $this->writeClover(0, 0);

        self::assertSame(0.0, coverage_gate_line_percent($clover));

        unlink($clover);
    }

    public function testVerdictPassesAtOrAboveFloor(): void
    {
        self::assertSame(0, coverage_gate_verdict(80.0, 79));
        self::assertSame(0, coverage_gate_verdict(79.0, 79));
    }

    public function testVerdictFailsBelowFloor(): void
    {
        self::assertSame(1, coverage_gate_verdict(78.9, 79));
    }

    private function writeClover(int $statements, int $coveredStatements): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'clover');
        $xml = \sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<coverage generated="0"><project timestamp="0">'
            . '<metrics files="1" statements="%d" coveredstatements="%d"/>'
            . '</project></coverage>',
            $statements,
            $coveredStatements,
        );
        file_put_contents($path, $xml);

        return $path;
    }
}
