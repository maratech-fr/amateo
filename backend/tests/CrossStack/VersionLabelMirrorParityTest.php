<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\VersionLabel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le « V2 » que dit le serveur et celui que montre l'écran désignent la même version : rang de
 * création dans le plan. Les mêmes cas (`versionLabel.parity.json`, arbre front) traversent
 * `App\Service\VersionLabel::of` et `planning/lib/versions.ts::versionLabelOf`.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest`.
 */
#[Group('contract')]
final class VersionLabelMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/planning/lib/versionLabel.parity.json';

    public function testBackendLabelsMatchTheSharedCases(): void
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded['cases'] ?? []);

        foreach ($decoded['cases'] as $case) {
            self::assertSame(
                $case['expected'],
                VersionLabel::of($case['versions'], $case['id']),
                'PARITÉ ROMPUE : ' . $case['label'],
            );
        }
    }
}
