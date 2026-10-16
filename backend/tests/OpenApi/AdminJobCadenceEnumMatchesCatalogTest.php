<?php

declare(strict_types=1);

namespace App\Tests\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\AdminJob\AdminJobCatalog;
use App\AdminJob\AdminJobDefinition;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : l'enum `cadence` du catalogue des jobs exposé par l'OpenAPI
 * (`/api/admin/jobs`, {@see \App\OpenApi\PathContributor\AdminJobPaths}) couvre
 * EXACTEMENT les cadences réellement servies par {@see AdminJobCatalog} — ni une
 * cadence documentée que le catalogue ne sert plus, ni (le trou qui a motivé ce
 * test) une cadence servie — `weekly`, posée par `db-restore-check` — absente du
 * contrat. Toute cadence future ajoutée au catalogue sans contrat rougit ici.
 */
#[Group('phase1')]
final class AdminJobCadenceEnumMatchesCatalogTest extends KernelTestCase
{
    public function testCadenceEnumMatchesTheCatalogExactly(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        $paths = $factory()->getPaths()->getPaths();

        self::assertArrayHasKey('/api/admin/jobs', $paths, 'The admin jobs catalog route must be documented.');
        $get = $paths['/api/admin/jobs']->getGet();
        self::assertNotNull($get, '/api/admin/jobs must expose a GET operation.');

        $responses = $get->getResponses();
        self::assertArrayHasKey('200', $responses);
        $content = $responses['200']->getContent();
        self::assertNotNull($content);

        $documentedCadences = $content['application/json']['schema']['properties']['items']['items']['properties']['cadence']['enum'];
        self::assertIsArray($documentedCadences);

        $servedCadences = array_values(array_unique(array_map(
            static fn (AdminJobDefinition $definition): string => $definition->schedule->cadence,
            (new AdminJobCatalog)->all(),
        )));

        sort($documentedCadences);
        sort($servedCadences);

        self::assertSame(
            $servedCadences,
            $documentedCadences,
            'The OpenAPI cadence enum must list exactly the cadences the catalog serves (missing here: ' . implode(', ', array_diff($servedCadences, $documentedCadences)) . ').',
        );
    }
}
