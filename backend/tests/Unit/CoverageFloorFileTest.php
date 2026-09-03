<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Garde du cliquet de couverture (P4-166, décision B3), côté backend.
 *
 * Le plancher de couverture par zone vit dans un fichier UNIQUE versionné à la racine
 * du dépôt, `coverage-floor.json` — une seule maison, lue par le job CI
 * `backend-coverage` (via `scripts/coverage-gate.php`) et remontée dans la MÊME PR
 * quand une mesure s'améliore. Ce test garde la maison côté backend : le fichier
 * existe, est du JSON, et la clé `backend` est un entier 0-100 (jamais `null` : le
 * backend EST mesuré). Les clés `engine`/`frontend` sont gardées par leurs zones
 * (`test_coverage_floor.py`, `coverageFloor.test.ts`).
 *
 * NON gatant (ce n'est pas un axe structurant §7.1) : ce test tourne dans
 * `unit-tests`, il n'est listé NI dans `.github/workflows/ci.yml` (job
 * `blocking-tests`) NI dans `docs/testing/blocking-tests.md`.
 */
#[Group('phase1')]
final class CoverageFloorFileTest extends TestCase
{
    public function testCoverageFloorFileExists(): void
    {
        self::assertFileExists(
            $this->floorPath(),
            'coverage-floor.json manquant à la racine (cliquet de couverture, B3)',
        );
    }

    public function testCoverageFloorIsJson(): void
    {
        $decoded = json_decode((string) file_get_contents($this->floorPath()), true);
        self::assertIsArray($decoded, 'coverage-floor.json doit être un objet JSON');
    }

    public function testBackendFloorIsAnIntegerPercentage(): void
    {
        $floors = json_decode((string) file_get_contents($this->floorPath()), true);
        self::assertIsArray($floors);
        self::assertArrayHasKey('backend', $floors, 'clé `backend` absente de coverage-floor.json');

        $backend = $floors['backend'];
        self::assertIsInt($backend, 'le plancher backend doit être un entier (jamais null : le backend est mesuré)');
        self::assertGreaterThanOrEqual(0, $backend, 'le plancher backend doit être dans 0-100');
        self::assertLessThanOrEqual(100, $backend, 'le plancher backend doit être dans 0-100');
    }

    private function floorPath(): string
    {
        // backend/tests/Unit → trois niveaux au-dessus = racine du dépôt.
        return \dirname(__DIR__, 3) . '/coverage-floor.json';
    }
}
