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
                'opponentTeamKey' => ['type' => ['string', 'null'], 'description' => 'Server-normalized opponent label — the grain of a per-team travel override; null for an unresolved opponent'],
                'opponentLabel' => ['type' => 'string'],
                'located' => ['type' => 'boolean', 'description' => 'A resolvable location exists (directory entry or manual override)'],
                'precision' => ['type' => ['string', 'null'], 'enum' => ['VENUE', 'CITY', null], 'description' => 'How precisely the opponent venue is known'],
                'locationName' => ['type' => ['string', 'null'], 'description' => 'The gym label (VENUE / override) or the commune (CITY)'],
                'city' => ['type' => ['string', 'null'], 'description' => 'The opponent commune from the shared directory'],
                'postalCode' => ['type' => ['string', 'null'], 'description' => 'The opponent postal code from the shared directory'],
                'travelMinutes' => ['type' => ['integer', 'null'], 'description' => 'One-way car travel from the club siège (null = best-effort miss)'],
                'travelStatus' => ['type' => 'string', 'enum' => ['done', 'pending', 'unavailable'], 'description' => 'Server-computed travel state: done (minutes present), pending (a computation is in flight for this club), unavailable (attempted without a result, or no location to route)'],
                'approximated' => ['type' => 'boolean', 'description' => 'Server-computed: the location is only city-precise'],
                'source' => ['type' => ['string', 'null'], 'enum' => ['AUTO', 'MANUAL', null]],
                'scope' => ['type' => ['string', 'null'], 'enum' => ['TEAM', 'CLUB', null], 'description' => 'Which grain governs this travel: a per-team override (TEAM), the club default (CLUB), or none (null)'],
                'overrideVenueLabel' => ['type' => ['string', 'null'], 'description' => 'The gym the manager pinned by hand'],
            ],
        ];
        $writeView = [
            'type' => 'object',
            'properties' => [
                'opponentOrganismeCode' => ['type' => 'string'],
                'opponentTeamKey' => ['type' => ['string', 'null'], 'description' => 'The opponent-team grain of this row; null = the club default'],
                'scope' => ['type' => ['string', 'null'], 'enum' => ['TEAM', 'CLUB', null]],
                'travelMinutes' => ['type' => ['integer', 'null']],
                'source' => ['type' => ['string', 'null'], 'enum' => ['AUTO', 'MANUAL', null]],
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
                        'clubGeolocated' => ['type' => 'boolean', 'description' => 'The club siège has coordinates (without which no opponent travel can be estimated) — a boolean only, never the raw coordinates'],
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
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Pin an opponent\'s gym by hand and recompute its travel (management only)',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['opponentOrganismeCode', 'venueLabel', 'latitude', 'longitude'],
                'properties' => [
                    'opponentOrganismeCode' => ['type' => 'string', 'description' => 'The opponent FFBB organisme code (must be an away opponent of the season)'],
                    'opponentTeamKey' => ['type' => 'string', 'nullable' => true, 'description' => 'The server-normalized opponent label to pin a SINGLE team; omit for the club default'],
                    'scope' => ['type' => 'string', 'enum' => ['TEAM', 'CLUB'], 'nullable' => true, 'description' => 'Grain of the override — defaults to TEAM when opponentTeamKey is given, else CLUB'],
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
                    'opponentTeamKey' => ['type' => 'string', 'nullable' => true, 'description' => 'Revert a SINGLE team to automatic (deletes its override); omit to revert the club default'],
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

        $paths->addPath('/api/opponents/{code}/venue-suggestions', new PathItem(get: new Operation(
            operationId: 'listOpponentVenueSuggestions',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('The community-shared venue suggestions for one away opponent: the gyms known for it — seen in the federal calendar (FFBB_API) or picked by clubs (MANUAL, its label re-resolved federally, never client text) — each with a COUNT of choices, NEVER by whom. FFBB_API first, then MANUAL by descending count.', [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'The opponent FFBB organisme code'],
                        'suggestions' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'externalRef' => ['type' => ['string', 'null'], 'description' => 'The FFBB salle number for a MANUAL suggestion; null for an FFBB_API one (the rencontre hit carries no salle number)'],
                                'label' => ['type' => 'string', 'description' => 'The gym label (federal — never club free-text)'],
                                'city' => ['type' => ['string', 'null']],
                                'postalCode' => ['type' => ['string', 'null']],
                                'latitude' => ['type' => ['number', 'null'], 'format' => 'float'],
                                'longitude' => ['type' => ['number', 'null'], 'format' => 'float'],
                                'source' => ['type' => 'string', 'enum' => ['FFBB_API', 'MANUAL']],
                                'chosenByCount' => ['type' => 'integer', 'description' => 'How many times this gym was chosen (per club/season/team) — never by whom'],
                                'lastChosenAt' => ['type' => ['string', 'null'], 'format' => 'date', 'description' => 'Day only (no time — no temporal side-channel)'],
                            ],
                        ]],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('The code is not an away opponent of the current season'),
            ],
            summary: 'The community-shared venue suggestions for an away opponent (management only; a count, never a who)',
            parameters: [['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The opponent FFBB organisme code (must be an away opponent of the current season)']],
        )));

        $paths->addPath('/api/opponents/refresh', new PathItem(post: new Operation(
            operationId: 'refreshOpponents',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Runs, in ONE call, the three best-effort passes that update the season\'s away opponents: (a) catch up FFBB organisme codes into the shared directory and stamp the fixtures; (b) auto-locate each opponent team\'s gym from the FBI file salle label (federal salle, tenant AUTO override, never client text); (c) recompute the AUTO car travel. Each pass is independent — a failure in one does not cancel the others. A shared wall-clock budget bounds the whole call: once it is spent, the remaining opponents of each pass come back unresolved/skipped (a partial result) so a degraded federation can never hold the request near the upstream timeout — re-run to continue.', [
                    'type' => 'object',
                    'properties' => [
                        'codes' => ['type' => 'object', 'description' => 'Pass (a): the FFBB organisme code catch-up', 'properties' => [
                            'resolved' => ['type' => 'integer', 'description' => 'Opponents written/refined in the shared directory'],
                            'unresolved' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Opponent names that could not be located'],
                            'skipped' => ['type' => 'integer', 'description' => 'Opponents already known at venue precision (no network call)'],
                            'stamped' => ['type' => 'integer', 'description' => 'Away fixtures whose opponent organisme code was stamped (join key)'],
                        ]],
                        'autoLocated' => ['type' => 'object', 'description' => 'Pass (b): the gym auto-location from the FBI file salle label', 'properties' => [
                            'located' => ['type' => 'integer', 'description' => 'Opponent teams whose gym was located from the file (a unique federal salle)'],
                            'ambiguous' => ['type' => 'integer', 'description' => 'Opponent teams whose file labels pointed to two different federal salles (nothing written)'],
                            'unmatched' => ['type' => 'integer', 'description' => 'Opponent teams with no unique federal salle for their file label'],
                            'skipped' => ['type' => 'integer', 'description' => 'Opponent teams left untouched because a MANUAL override already governs them'],
                        ]],
                        'travel' => ['type' => 'object', 'description' => 'Pass (c): the AUTO travel recompute', 'properties' => [
                            'resolved' => ['type' => 'integer', 'description' => 'Opponents with a computed travel time'],
                            'unresolved' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Opponent codes with no located venue or no routing duration'],
                            'skippedManual' => ['type' => 'integer', 'description' => 'Opponents whose MANUAL override was preserved'],
                        ]],
                        'failedSteps' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['codes', 'auto-locate', 'travel']], 'description' => 'Passes that threw and fell back to their neutral (zero) result — empty in the nominal case. A non-empty list means the update is PARTIAL: re-run to continue.'],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Too many distinct opponents to update at once (retry with fewer)'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Update all away opponents in one call — catch up codes, auto-locate gyms from the file, recompute travel (management only)',
        )));
    }
}
