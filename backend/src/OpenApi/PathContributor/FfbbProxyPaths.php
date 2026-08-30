<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;
use ArrayObject;

/** FFBB proxy custom routes. */
final readonly class FfbbProxyPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->ffbbPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * Le proxy FFBB — le frontend n'appelle JAMAIS la fédération (frontière §2), et seuls
     * les champs utiles sont relayés, jamais le hit brut.
     *
     * @return array<string, PathItem>
     */
    private function ffbbPaths(): array
    {
        $salleList = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'address' => ['type' => ['string', 'null']],
            'city' => ['type' => ['string', 'null']],
            'externalRef' => ['type' => ['string', 'null']],
            // Décimaux rendus en STRING : `Venue` les stocke ainsi, on normalise à la source.
            'latitude' => ['type' => ['string', 'null']],
            'longitude' => ['type' => ['string', 'null']],
        ]]];
        $unavailable = new Response('FFBB unreachable — best effort, never a broken gesture');
        $forbidden = new Response('Management role required');

        return [
            '/api/ffbb-logos/{scope}/{code}' => new PathItem(get: new Operation(
                operationId: 'getFfbbLogo',
                tags: ['Ffbb'],
                responses: [
                    '200' => new Response('The rehosted logo bytes (public brand asset, no personal data)', new ArrayObject([
                        'image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                    ])),
                    '404' => new Response('No logo stored under this scope+code'),
                ],
                summary: 'Serve a rehosted FFBB league or committee logo',
                parameters: [
                    ['name' => 'scope', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['league', 'committee']]],
                    ['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,24}$']],
                ],
            )),
            '/api/ffbb/salles' => new PathItem(get: new Operation(
                operationId: 'getFfbbSalles',
                tags: ['Ffbb'],
                responses: [
                    // Ni le param ni le club ne donnent un CP exploitable ⇒ liste VIDE et
                    // `postalCode` null, jamais une erreur : le wizard garde la saisie libre.
                    '200' => $this->schemas->jsonResponse('The FFBB venues of a postal code, sorted by name (empty list when no usable postal code)', [
                        'type' => 'object',
                        'properties' => [
                            'postalCode' => ['type' => ['string', 'null']],
                            'salles' => $salleList,
                        ],
                    ]),
                    '401' => new Response('Unauthorized'),
                    '403' => $forbidden,
                    '502' => $unavailable,
                ],
                summary: 'Search the FFBB venues of a postal code (defaults to the club\'s)',
                parameters: [
                    ['name' => 'postalCode', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^\d{5}$'], 'description' => 'Defaults to the current club\'s postal code'],
                ],
            )),
            '/api/ffbb/salles-proches' => new PathItem(get: new Operation(
                operationId: 'getFfbbSallesNearby',
                tags: ['Ffbb'],
                responses: [
                    // Club sans géoloc ⇒ liste vide et `radiusKm` null : la combobox par CP
                    // reste le chemin. `radiusKm` rend le palier RETENU, pas le demandé —
                    // sans `radius`, la recherche s'élargit 3→5→10→20 tant qu'elle est maigre.
                    '200' => $this->schemas->jsonResponse('The FFBB venues near the club, sorted by distance (empty when the club has no geolocation)', [
                        'type' => 'object',
                        'properties' => [
                            'radiusKm' => ['type' => ['integer', 'null'], 'enum' => [3, 5, 10, 20, null]],
                            'salles' => $salleList,
                        ],
                    ]),
                    '401' => new Response('Unauthorized'),
                    '403' => $forbidden,
                    '502' => $unavailable,
                ],
                summary: 'List the FFBB venues near the club, auto-widening the radius until the result is useful',
                parameters: [
                    ['name' => 'radius', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'enum' => [3, 5, 10, 20]], 'description' => 'Manual radius step in km; absent = auto-widening from 3 km'],
                ],
            )),
        ];
    }
}
