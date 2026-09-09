<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\Controller\VenueExternalLabelController;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/**
 * P4-187a — « Rattacher » / « Retirer » un libellé de salle FBI/FFBB à un gymnase
 * ({@see VenueExternalLabelController}). Routes custom `#[Route]`,
 * donc invisibles d'API Platform : déclarées ici pour le contrat et le snapshot.
 */
final readonly class VenueAliasPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/venues/{id}/external-labels', new PathItem(post: new Operation(
            operationId: 'attachVenueExternalLabel',
            tags: ['Venue'],
            responses: [
                '200' => $this->schemas->jsonResponse(
                    'Alias ajouté (normalisé, idempotent) puis backfill des domiciles du club encore sans salle dont le libellé égale l\'alias — le gymnase leur est posé sans les placer (`attached` = nombre de rencontres nouvellement rattachées, 0 sur un re-POST)',
                    [
                        'type' => 'object',
                        'properties' => [
                            'venueId' => ['type' => 'string'],
                            'label' => ['type' => 'string', 'description' => 'Le libellé normalisé effectivement stocké'],
                            'attached' => ['type' => 'integer'],
                        ],
                    ],
                ),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '404' => new Response('Venue of another club (invisible) or unknown'),
                '409' => new Response('The venue\'s season is archived (read-only)'),
                '422' => new Response('Empty label after normalization, or the label already belongs to another venue of the club'),
            ],
            summary: 'Rattacher un libellé de salle FBI/FFBB à un gymnase',
        )));

        $paths->addPath('/api/venues/{id}/external-labels/{label}', new PathItem(delete: new Operation(
            operationId: 'detachVenueExternalLabel',
            tags: ['Venue'],
            responses: [
                '204' => new Response('Alias retiré (idempotent) — aucune rencontre déjà rattachée n\'est touchée'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '404' => new Response('Venue of another club (invisible) or unknown'),
                '409' => new Response('The venue\'s season is archived (read-only)'),
            ],
            summary: 'Retirer un libellé de salle rattaché à un gymnase',
        )));
    }
}
