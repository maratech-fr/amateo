<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\OpenApi;
use App\OpenApi\PathContributor\AccountSessionPaths;
use App\OpenApi\PathContributor\AdminAuthPaths;
use App\OpenApi\PathContributor\AdminContentModerationPaths;
use App\OpenApi\PathContributor\AdminJobPaths;
use App\OpenApi\PathContributor\AdminJournalPaths;
use App\OpenApi\PathContributor\AdminMonitoringPaths;
use App\OpenApi\PathContributor\AdminSupportPaths;
use App\OpenApi\PathContributor\FfbbEngagementPaths;
use App\OpenApi\PathContributor\FfbbProxyPaths;
use App\OpenApi\PathContributor\HolidayPaths;
use App\OpenApi\PathContributor\ManualEditPaths;
use App\OpenApi\PathContributor\OpponentTravelPaths;
use App\OpenApi\PathContributor\PublicTokenPaths;
use App\OpenApi\PathContributor\ReleaseNoteAndFeedbackPaths;
use App\OpenApi\PathContributor\SeasonAndFixturePaths;
use App\OpenApi\PathContributor\UncoveredCustomPaths;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Registry for custom Symfony `#[Route]`s that are NOT API Platform operations
 * and would otherwise be absent from the generated OpenAPI (and the
 * `specs/courantes/openapi-snapshot.json`). This decorator injects their paths
 * so `/api/docs` and the snapshot document the full contract; the endpoints
 * themselves are unchanged.
 *
 * ⚠ EVERY custom `#[Route]` must be declared in a {@see CustomPathContributor}
 * composed below — a route missing from every contributor is invisible to the
 * export even after the snapshot is regenerated.
 *
 * ⚑ **La dette est soldée depuis P4-47** : les 15 routes qui restaient hors contrat
 * (console superadmin, pages publiques à token, proxy FFBB) sont déclarées, et
 * `EveryCustomRouteIsDocumentedTest::KNOWN_UNDOCUMENTED` est VIDE. Ce test confronte la
 * factory au ROUTEUR dans les deux sens — il n'y a donc plus de baseline où se réfugier :
 * **toute route custom ajoutée sans son entrée ici fait rougir la CI**. Le réflexe est
 * désormais « nouvelle `#[Route]` custom = nouvelle entrée dans un contributeur », point.
 *
 * ⚠ **L'ORDRE de composition est significatif** (`addPath` = append ; le snapshot fige
 * l'ordre exact) : ne réordonnez pas la liste ci-dessous sans une raison de contrat.
 */
// SEC-16 : priorité NÉGATIVE pour être le décorateur le PLUS EXTERNE — lexik
// décore aussi cette factory (priorité 0) et écrit `/api/login` en dur avec un
// `200 {token}` devenu faux. Le plus externe passe en dernier sur les chemins :
// sans cette priorité, notre correction du contrat était silencieusement écrasée.
#[AsDecorator('api_platform.openapi.factory', priority: -10)]
final readonly class CustomRoutesOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();
        $schemas = new OpenApiSchemas;

        foreach ([
            new AccountSessionPaths($schemas),
            new UncoveredCustomPaths($schemas),
            new ManualEditPaths($schemas),
            new HolidayPaths($schemas),
            new AdminAuthPaths($schemas),
            new AdminMonitoringPaths($schemas),
            new AdminJobPaths($schemas),
            new AdminSupportPaths($schemas),
            new AdminJournalPaths($schemas),
            new PublicTokenPaths($schemas),
            new FfbbProxyPaths($schemas),
            new ReleaseNoteAndFeedbackPaths($schemas),
            new AdminContentModerationPaths($schemas),
            new SeasonAndFixturePaths($schemas),
            new FfbbEngagementPaths($schemas),
            new OpponentTravelPaths($schemas),
        ] as $contributor) {
            $contributor->contribute($paths);
        }

        return $openApi;
    }
}
