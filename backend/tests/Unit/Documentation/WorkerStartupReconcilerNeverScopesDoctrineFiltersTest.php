<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Promesse (axe tenant isolation) : ce qui tourne dans le worker HORS message — le rattrapage
 * des placements au démarrage, sa commande, le listener d'échec définitif — ne pose jamais de
 * périmètre Doctrine qui survivrait jusqu'au message suivant.
 *
 * `TenantDoctrineScope::scopeClub()` active `tenant_filter` sur l'EM et MÉMORISE le club ;
 * `resetManager()` le repose ; seul `kernel.reset` (début de requête) l'oublie. Le hook tourne
 * sur `WorkerStartedEvent`, AVANT le premier message : un club mémorisé là filtrerait le premier
 * message traité, peut-être d'un autre club. Ces trois classes se scopent donc par le GUC seul
 * (`TenantConnectionContext`), jamais par `TenantDoctrineScope` ni `getFilters()->enable`.
 *
 * Lecture du CODE seul (commentaires et docblocks exclus : le docblock du reconciler NOMME
 * `TenantDoctrineScope` pour dire qu'il ne l'utilise pas). Angle comportemental jumeau :
 * `StuckPlacementRunReconcilerTest::testReconciliationLeavesNoTenantFilterNorGucBehind`.
 */
#[Group('phase1')]
final class WorkerStartupReconcilerNeverScopesDoctrineFiltersTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../src/';

    /** @return iterable<string, array{0: string}> */
    public static function workerSideFiles(): iterable
    {
        yield 'rattrapage' => ['Service/StuckPlacementRunReconciler.php'];
        yield 'hook de démarrage' => ['EventListener/PlacementRunStartupReconciler.php'];
        yield 'listener d\'échec définitif' => ['EventListener/PlaceMatchesFailureListener.php'];
    }

    #[DataProvider('workerSideFiles')]
    public function testWorkerSideCodeScopesByTheGucOnly(string $relativePath): void
    {
        $path = self::SRC . $relativePath;
        self::assertFileExists($path, $relativePath . ' a disparu : ce garde ne protège plus rien, déplacez-le avec le code');

        $source = file_get_contents($path);
        self::assertIsString($source);
        $code = $this->codeWithoutComments($source);

        self::assertStringNotContainsString('TenantDoctrineScope', $code, $relativePath . ' : hors requête, le périmètre mémorisé de TenantDoctrineScope fuirait jusqu\'au premier message du worker');
        self::assertDoesNotMatchRegularExpression('/getFilters\(\)\s*->\s*enable\s*\(/', $code, $relativePath . ' : un filtre Doctrine activé hors requête survit au premier message du worker');
        self::assertStringContainsString('TenantConnectionContext', $code, $relativePath . ' : le périmètre d\'un club se pose par le GUC (TenantConnectionContext)');
    }

    private function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
