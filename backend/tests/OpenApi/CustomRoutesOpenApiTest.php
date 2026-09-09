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
            '/api/school-holidays',
            '/api/public-holidays',
            '/api/league-match-windows',
            '/api/fixtures/conflicts',
            '/api/fixtures/review',
            '/api/fixtures/review/deviations',
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
}
