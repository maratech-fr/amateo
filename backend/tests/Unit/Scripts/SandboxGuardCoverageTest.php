<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P4-141 — garde-fou anti-pollution : chaque script mutateur de `backend/scripts/`
 * DOIT sourcer `lib/sandbox-guard.sh`, sinon il peut écrire dans n'importe quelle
 * base (dont la base de JEU du fondateur `amateo_local`, ou la prod `amateo`).
 *
 * Ce test rougit dès qu'un `.sh` de `backend/scripts/` n'inclut plus la garde —
 * y compris un NOUVEAU script ajouté sans elle. La garde elle-même, sous
 * `backend/scripts/lib/`, est une bibliothèque (elle est SOURCÉE, pas exécutée
 * comme un mutateur) : elle est exclue du balayage.
 *
 * NON gatant (ce n'est pas un axe structurant §7.1) : ce test tourne dans
 * `unit-tests`, il n'est volontairement listé NI dans `.github/workflows/ci.yml`
 * (job `blocking-tests`) NI dans `docs/testing/blocking-tests.md`.
 */
#[Group('phase1')]
final class SandboxGuardCoverageTest extends TestCase
{
    public function testEveryMutatingScriptSourcesTheSandboxGuard(): void
    {
        $backend = \dirname(__DIR__, 3);
        $scriptsDir = $backend . '/scripts';
        $libDir = $scriptsDir . '/lib/';

        self::assertFileExists(
            $scriptsDir . '/lib/sandbox-guard.sh',
            'La bibliothèque de garde doit exister : backend/scripts/lib/sandbox-guard.sh',
        );

        $offenders = [];
        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scriptsDir, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $path => $file) {
            if (!$file->isFile() || 'sh' !== $file->getExtension()) {
                continue;
            }

            // La garde elle-même n'est pas un mutateur : elle est sourcée par eux.
            if (str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $libDir))) {
                continue;
            }

            $contents = file_get_contents($path);
            self::assertIsString($contents);

            if (!str_contains($contents, 'lib/sandbox-guard.sh')) {
                $offenders[] = str_replace($backend . '/', '', $path);
            }
        }

        sort($offenders);
        self::assertSame(
            [],
            $offenders,
            'Tout script mutateur de backend/scripts/ doit sourcer backend/scripts/lib/sandbox-guard.sh (garde fail-closed P4-141).',
        );
    }
}
