<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Geocoding, venue travel-time autofill and opponent-travel custom routes. */
final readonly class OpponentTravelPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/geocode', new PathItem(get: new Operation(
            operationId: 'geocodeAddress',
            tags: ['Venue'],
            responses: [
                '200' => $this->schemas->jsonResponse('BAN geocoding candidates for a free-text address (top 5) — used to set a venue\'s latitude/longitude', [
                    'type' => 'object',
                    'properties' => [
                        'candidates' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'label' => ['type' => 'string'],
                            'latitude' => ['type' => 'number', 'format' => 'float'],
                            'longitude' => ['type' => 'number', 'format' => 'float'],
                            'score' => ['type' => 'number', 'format' => 'float'],
                        ]]],
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Missing or malformed query (3 to 200 characters)'),
                '502' => new Response('Geocoding service unreachable — retry later'),
            ],
            summary: 'Geocode a free-text address via the Base Adresse Nationale (management only, tenant from JWT)',
            parameters: [['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Free-text address to geocode (3 to 200 characters)']],
        )));

        $paths->addPath('/api/venue-travel-times/autofill', new PathItem(post: new Operation(
            operationId: 'autofillVenueTravelTimes',
            tags: ['Venue'],
            responses: [
                '200' => $this->schemas->jsonResponse('Fills AUTO driving/walking minutes for every geolocated venue pair via IGN routing. A MANUAL value is NEVER overwritten; a pair with a missing geolocation, a routing failure, or a spent batch time budget comes back named (best-effort, re-run to continue).', [
                    'type' => 'object',
                    'properties' => [
                        'filled' => ['type' => 'integer', 'description' => 'Pairs where at least one AUTO minute was written'],
                        'unresolved' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'venueAId' => ['type' => 'string'],
                            'venueBId' => ['type' => 'string'],
                            'reason' => ['type' => 'string', 'enum' => ['missing_geo', 'routing_failed', 'budget_exceeded']],
                        ]]],
                        'skippedManual' => ['type' => 'integer', 'description' => 'Pairs whose MANUAL value was preserved'],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('The selected season is archived (read-only)'),
                '422' => new Response('Too many geolocated venue pairs for an automatic fill (fill by hand)'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Autofill the venue travel-time matrix from IGN routing (management only; never overwrites a MANUAL value)',
        )));

        $paths->addPath('/api/opponents/resolve', new PathItem(post: new Operation(
            operationId: 'resolveOpponentLocations',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Locates the DISTINCT away opponents of the club+season into the shared opponent directory (salle or city, best-effort). An opponent already known at venue precision is skipped; one that cannot be located comes back named.', [
                    'type' => 'object',
                    'properties' => [
                        'resolved' => ['type' => 'integer', 'description' => 'Opponents written/refined in the shared directory'],
                        'unresolved' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Opponent names that could not be located'],
                        'skipped' => ['type' => 'integer', 'description' => 'Opponents already known at venue precision (no network call)'],
                        'stamped' => ['type' => 'integer', 'description' => 'Away fixtures whose opponent organisme code was stamped (join key)'],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Too many distinct opponents to locate at once (retry with fewer)'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Resolve the away opponents of the season into the shared opponent directory (management only)',
        )));

        $opponentView = [
            'type' => 'object',
            'properties' => [
                'opponentOrganismeCode' => ['type' => ['string', 'null']],
                'opponentLabel' => ['type' => 'string'],
                'located' => ['type' => 'boolean', 'description' => 'A resolvable location exists (directory entry or manual override)'],
                'precision' => ['type' => ['string', 'null'], 'enum' => ['VENUE', 'CITY', null], 'description' => 'How precisely the opponent venue is known'],
                'locationName' => ['type' => ['string', 'null'], 'description' => 'The gym label (VENUE / override) or the commune (CITY)'],
                'travelMinutes' => ['type' => ['integer', 'null'], 'description' => 'One-way car travel from the club siège (null = best-effort miss)'],
                'approximated' => ['type' => 'boolean', 'description' => 'Server-computed: the location is only city-precise'],
                'source' => ['type' => ['string', 'null'], 'enum' => ['AUTO', 'MANUAL', null]],
                'overrideVenueLabel' => ['type' => ['string', 'null'], 'description' => 'The gym the manager pinned by hand'],
            ],
        ];
        $writeView = [
            'type' => 'object',
            'properties' => [
                'opponentOrganismeCode' => ['type' => 'string'],
                'travelMinutes' => ['type' => ['integer', 'null']],
                'source' => ['type' => 'string', 'enum' => ['AUTO', 'MANUAL']],
                'overrideVenueLabel' => ['type' => ['string', 'null']],
            ],
        ];

        $paths->addPath('/api/opponents/travel', new PathItem(get: new Operation(
            operationId: 'listOpponentTravel',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Per distinct AWAY opponent: where it plays (precision + location name), the one-way car travel from the club siège (nullable, best-effort), whether it is only approximated (city), and the AUTO/MANUAL source. Read-only display feed for the travel radar.', [
                    'type' => 'object',
                    'properties' => [
                        'clubId' => ['type' => 'string'],
                        'seasonId' => ['type' => 'string'],
                        'opponents' => ['type' => 'array', 'items' => $opponentView],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'The AWAY opponents\' locations and travel times for the current club/season',
        )));

        $paths->addPath('/api/opponents/travel/manual', new PathItem(post: new Operation(
            operationId: 'setOpponentTravelManual',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Pins a specific gym for the opponent (MANUAL override) and recomputes the car travel from it. A MANUAL value is never overwritten by the AUTO pass afterwards.', $writeView),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Invalid opponent/venue, or the opponent has no away fixture this season'),
            ],
            summary: 'Pin an opponent\'s gym by hand and recompute its travel (management only)',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['opponentOrganismeCode', 'venueLabel', 'latitude', 'longitude'],
                'properties' => [
                    'opponentOrganismeCode' => ['type' => 'string', 'description' => 'The opponent FFBB organisme code (must be an away opponent of the season)'],
                    'venueLabel' => ['type' => 'string'],
                    'venueExternalRef' => ['type' => 'string', 'nullable' => true, 'description' => 'The FFBB salle number, when picked from /api/ffbb/salles'],
                    'latitude' => ['type' => 'number'],
                    'longitude' => ['type' => 'number'],
                ],
            ]),
        )));

        $paths->addPath('/api/opponents/travel/auto', new PathItem(post: new Operation(
            operationId: 'setOpponentTravelAuto',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Drops the manual override and recomputes the travel from the shared directory location (return to AUTO).', $writeView),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('No manual override to revert for this opponent'),
            ],
            summary: 'Return an opponent to automatic travel resolution (management only)',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['opponentOrganismeCode'],
                'properties' => [
                    'opponentOrganismeCode' => ['type' => 'string'],
                ],
            ]),
        )));

        $paths->addPath('/api/opponents/travel/resolve', new PathItem(post: new Operation(
            operationId: 'resolveOpponentTravel',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Recomputes the AUTO car travel from the club siège to every away opponent\'s location (best-effort). A MANUAL override is left untouched; an opponent with no located venue comes back named.', [
                    'type' => 'object',
                    'properties' => [
                        'resolved' => ['type' => 'integer', 'description' => 'Opponents with a computed travel time'],
                        'unresolved' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Opponent codes with no located venue or no routing duration'],
                        'skippedManual' => ['type' => 'integer', 'description' => 'Opponents whose MANUAL override was preserved'],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Too many away opponents to resolve at once (retry with fewer)'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Recompute the AUTO travel times of the season\'s away opponents (management only)',
        )));
    }
}
