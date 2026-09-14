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
        $paths->addPath('/api/venues/fbi-labels', new PathItem(get: new Operation(
            operationId: 'listVenueFbiLabels',
            tags: ['Venue'],
            responses: [
                '200' => $this->schemas->jsonResponse(
                    'Inventaire agrégé, par libellé de salle FBI/FFBB normalisé de la saison courante : le gymnase confirmé (`venueId`, null si aucun alias), une suggestion tirée des placements réels (`suggestedVenueId` = gymnase unanime des domiciles qui en ont un, null si divergent ou déjà confirmé) et les compteurs. Lecture ouverte à tout membre authentifié',
                    [
                        'type' => 'object',
                        'properties' => [
                            'labels' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'labelKey' => ['type' => 'string', 'description' => 'Le libellé normalisé (clé de regroupement)'],
                                        'displayLabel' => ['type' => 'string', 'description' => 'La graphie brute la plus fréquente'],
                                        'venueId' => ['type' => 'string', 'nullable' => true],
                                        'suggestedVenueId' => ['type' => 'string', 'nullable' => true],
                                        'homeCount' => ['type' => 'integer'],
                                        'placedCount' => ['type' => 'integer'],
                                        'unplacedCount' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Inventaire des libellés de salle FBI/FFBB de la saison courante, par gymnase',
        )));

        $paths->addPath('/api/venues/{id}/external-labels', new PathItem(post: new Operation(
            operationId: 'attachVenueExternalLabel',
            tags: ['Venue'],
            responses: [
                '200' => $this->schemas->jsonResponse(
                    'Alias ajouté (normalisé, idempotent) puis backfill des domiciles du club encore sans salle dont le libellé égale l\'alias — le gymnase leur est posé sans les placer (`attached` = nombre de rencontres nouvellement rattachées, 0 sur un re-POST). Avec `reassign: true`, l\'alias est retiré de son ancien gymnase (`previousVenueId`) et tous les domiciles NON PLACÉS au même libellé sont re-pointés (`attached`), les placés gardant leur salle (`kept`)',
                    [
                        'type' => 'object',
                        'properties' => [
                            'venueId' => ['type' => 'string'],
                            'label' => ['type' => 'string', 'description' => 'Le libellé normalisé effectivement stocké'],
                            'attached' => ['type' => 'integer'],
                            'kept' => ['type' => 'integer', 'description' => 'Ré-affectation seulement : domiciles placés qui gardent leur salle'],
                            'previousVenueId' => ['type' => 'string', 'nullable' => true, 'description' => 'Ré-affectation seulement : le gymnase qui portait l\'alias avant (null sinon)'],
                        ],
                    ],
                ),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '404' => new Response('Venue of another club (invisible) or unknown'),
                '409' => new Response('The venue\'s season is archived (read-only)'),
                '422' => new Response('Empty label after normalization, or (without reassign) the label already belongs to another venue of the club'),
            ],
            summary: 'Rattacher (ou ré-affecter) un libellé de salle FBI/FFBB à un gymnase',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['label'],
                'properties' => [
                    'label' => ['type' => 'string', 'description' => 'Le libellé BRUT (le serveur normalise)'],
                    'reassign' => ['type' => 'boolean', 'description' => 'Retirer l\'alias de son gymnase actuel et re-pointer les domiciles non placés (défaut false)'],
                ],
            ]),
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
