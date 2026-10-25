<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\Controller\ExportPdfDownloadController;
use App\OpenApi\CustomPathContributor;
use ArrayObject;

/**
 * P4-357 — téléchargement AUTHENTIFIÉ du rendu PDF d'un planning
 * ({@see ExportPdfDownloadController}). Route Symfony tenant, hors API Platform, décrite ici pour
 * entrer au contrat et au snapshot (EveryCustomRouteIsDocumentedTest). Pas d'`OpenApiSchemas` :
 * la réponse 200 est un fichier binaire (`application/pdf`), pas un corps JSON à bâtir.
 */
final readonly class ExportPdfDownloadPaths implements CustomPathContributor
{
    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/schedules/{id}/export-pdf/{filename}', new PathItem(get: new Operation(
            operationId: 'getScheduleExportPdfRender',
            tags: ['Schedule'],
            responses: [
                '200' => new Response(
                    'The rendered PDF file, as an attachment.',
                    new ArrayObject(['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]),
                ),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '404' => new Response('Unknown schedule, another club\'s render, or a filename that is not a render of this schedule'),
            ],
            summary: 'Download the rendered PDF of a schedule (authenticated, scoped to the club; the file lives outside the docroot)',
        )));
    }
}
