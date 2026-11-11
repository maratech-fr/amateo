<?php

declare(strict_types=1);

namespace App\Tests\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\OpenApi\PathContributor\SeasonAndFixturePaths;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : le contrat publié de `POST /api/fixtures/place` (opération `placeMatches`,
 * {@see SeasonAndFixturePaths}) dit vrai sur ses refus. Le client front est écrit à la main : le
 * contrat n'est lu que par un humain ou un client tiers, et c'est là qu'il doit être juste.
 *
 *  - 429 déclaré : la route est désormais bornée par le quota de placement du club ;
 *  - 409 couvre TOUTES ses causes : un placement déjà en cours OU déjà en file pour ce club, le
 *    gel de mise en production, le planning de saison non choisi, la saison archivée.
 *
 * Aucun test existant ne regarde les codes de réponse (`OpenApiSnapshotMetaMatchesSnapshotTest`
 * ne compare que le snapshot à sa meta). Le chiffre du quota n'est PAS recopié dans le contrat :
 * sa maison est `rate_limiter.yaml`, gardée par `ClubQuotaTest`.
 */
#[Group('phase1')]
final class PlaceMatchesResponsesDocumentedTest extends KernelTestCase
{
    public function testTheConflictAndQuotaRefusalsAreDocumented(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        $paths = $factory()->getPaths()->getPaths();

        self::assertArrayHasKey('/api/fixtures/place', $paths);
        $post = $paths['/api/fixtures/place']->getPost();
        self::assertNotNull($post);
        self::assertSame('placeMatches', $post->getOperationId());
        $responses = $post->getResponses();

        self::assertArrayHasKey('429', $responses, 'le quota de placement du club rend 429 : le contrat doit le déclarer');
        $quota = (string) $responses['429']->getDescription();
        self::assertStringContainsStringIgnoringCase('quota', $quota);
        self::assertDoesNotMatchRegularExpression('/\d/', $quota, 'le chiffre du quota vit dans rate_limiter.yaml (gardé par ClubQuotaTest), pas dans une troisième maison non gardée');

        self::assertArrayHasKey('409', $responses);
        $conflict = (string) $responses['409']->getDescription();
        self::assertStringContainsStringIgnoringCase('running', $conflict, '409 : un placement déjà en cours');
        self::assertStringContainsStringIgnoringCase('queued', $conflict, '409 : un placement déjà en file pour ce club (pas seulement « en cours »)');
        self::assertStringContainsStringIgnoringCase('deploy', $conflict, '409 : le gel de mise en production refuse aussi la demande');
        self::assertStringContainsStringIgnoringCase('season plan', $conflict, '409 : planning de saison non choisi');
        self::assertStringContainsStringIgnoringCase('archived', $conflict, '409 : saison archivée');
    }
}
