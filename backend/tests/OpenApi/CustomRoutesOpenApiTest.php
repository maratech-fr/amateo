<?php

declare(strict_types=1);

namespace App\Tests\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guard: the custom Symfony #[Route]s (AuthController, ManualEditController,
 * SchoolHolidaysController, PublicHolidaysController) are excluded from API
 * Platform's auto-generated OpenAPI, so CustomRoutesOpenApiFactory injects them.
 * This locks their presence in the schema (and the regenerated
 * specs/courantes/openapi-snapshot.json).
 */
#[Group('phase1')]
final class CustomRoutesOpenApiTest extends KernelTestCase
{
    public function testCustomRoutesAreDocumented(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        $paths = $factory()->getPaths()->getPaths();

        $expected = [
            '/api/register',
            '/api/register/verify',
            '/api/me',
            '/api/me/password',
            '/api/schedule-slots/{id}/manual-edit/lock',
            '/api/schedules/{id}/export-pdf/{filename}',
            '/api/school-holidays',
            '/api/public-holidays',
            '/api/league-match-windows',
            '/api/fixtures/conflicts',
            '/api/fixtures/conflicts/mail-coaches/preview',
            '/api/fixtures/review',
            '/api/fixtures/review/deviations',
            '/api/fixtures/league-validation',
            '/api/venues/{id}/external-labels',
            '/api/venues/{id}/external-labels/{label}',
            '/api/release-notes',
            '/api/release-notes/seen',
            '/api/feedback',
            '/api/admin/feedback',
            '/api/admin/feedback/{id}',
            '/api/admin/feedback/{id}/treat',
            '/api/admin/feedback/{id}/untreat',
        ];
        foreach ($expected as $path) {
            self::assertArrayHasKey($path, $paths, $path . ' must be documented in the OpenAPI');
        }
    }

    /**
     * Le contrat PUBLIÉ de `/api/me` annonce la date d'ouverture de la saison suivante
     * (`seasonPreparationOpensOn`, chaîne `Y-m-d` ou null), dans le contrat vivant ET dans le
     * snapshot dont le front tire ses types. Le comportement (refus avant la date, date servie)
     * est prouvé par `features/la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature`.
     */
    public function testMeDocumentsTheSeasonPreparationOpeningDate(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        $operation = $factory()->getPaths()->getPath('/api/me')?->getGet();
        self::assertNotNull($operation, '/api/me must document its GET');
        $ok = ($operation->getResponses() ?? [])['200'] ?? null;
        self::assertNotNull($ok);
        $live = $ok->getContent()['application/json']['schema']['properties']['seasonPreparationOpensOn'] ?? null;
        self::assertIsArray($live, '/api/me documents seasonPreparationOpensOn');
        self::assertSame(['string', 'null'], $live['type'] ?? null, 'seasonPreparationOpensOn is a Y-m-d string or null');

        $snapshot = json_decode((string) file_get_contents(__DIR__ . '/../../../specs/courantes/openapi-snapshot.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        $published = $snapshot['paths']['/api/me']['get']['responses']['200']['content']['application/json']['schema']['properties']['seasonPreparationOpensOn'] ?? null;
        self::assertIsArray($published, 'the OpenAPI snapshot carries seasonPreparationOpensOn — regenerate it (api:openapi:export)');
    }

    /**
     * Lot prod Matchs PR 2 — le contrat PUBLIÉ de « marquer comme vu » porte le troisième geste :
     * `scope` ne vaut que `away` (le client ne peut rien demander d'autre), et la raison d'une
     * rencontre sautée dit ce qu'elle signifie désormais — un écart ARBITRABLE, pas toute trace.
     */
    public function testReviewFixturesDocumentsTheAwayScopeAndTheArbitrableSkipReason(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        $operation = $factory()->getPaths()->getPath('/api/fixtures/review')?->getPost();
        self::assertNotNull($operation, '/api/fixtures/review must document its POST');

        $body = $operation->getRequestBody()?->getContent()['application/json']['schema'] ?? null;
        self::assertIsArray($body);
        self::assertSame(['away'], $body['properties']['scope']['enum'] ?? null, 'scope is documented with the single value « away »');

        $responses = $operation->getResponses() ?? [];
        $ok = $responses['200'] ?? null;
        self::assertNotNull($ok);
        $schema = $ok->getContent()['application/json']['schema'] ?? null;
        self::assertIsArray($schema);
        $reason = $schema['properties']['skipped']['items']['properties']['reason'] ?? null;
        self::assertIsArray($reason);
        self::assertSame(['pending_deviations'], $reason['enum'] ?? null, 'the skip reason value is unchanged');
        self::assertStringContainsStringIgnoringCase('arbitrable', (string) ($reason['description'] ?? ''), 'the skip reason says it means an arbitrable deviation');
    }
}
