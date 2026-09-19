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
                '200' => $this->schemas->jsonResponse('Queues an ASYNC autofill of the AUTO driving/walking minutes for every geolocated venue pair (the paced IGN routing runs in the worker). The cap is checked synchronously (422). Progress and the terminal verdict ({filled, unresolved}) are pushed on the club Mercure travel topic; a MANUAL value is NEVER overwritten. When a travel computation is already running for the club, nothing is dispatched (queued=false, alreadyRunning=true).', [
                    'type' => 'object',
                    'properties' => [
                        'queued' => ['type' => 'boolean', 'description' => 'The computation was dispatched to the worker'],
                        'alreadyRunning' => ['type' => 'boolean', 'description' => 'A travel computation was already in flight for this club, so nothing was dispatched'],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('The selected season is archived (read-only)'),
                '422' => new Response('Too many geolocated venue pairs for an automatic fill (fill by hand)'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Queue the async autofill of the venue travel-time matrix (management only; never overwrites a MANUAL value)',
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

        $venueView = [
            'type' => 'object',
            'description' => 'One paired gym of the opponent club (an OpponentVenueLink).',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The link id — the target of PUT/DELETE /api/opponents/venue-links/{id}'],
                'label' => ['type' => 'string', 'description' => 'The federal gym label'],
                'externalRef' => ['type' => ['string', 'null'], 'description' => 'The FFBB salle number, or null for a gym pinned by coordinates only'],
                'source' => ['type' => 'string', 'enum' => ['AUTO', 'MANUAL']],
                'travelMinutes' => ['type' => ['integer', 'null'], 'description' => 'One-way car travel from the club siège (from the constant travel cache; null = not computed yet)'],
                'travelStatus' => ['type' => 'string', 'enum' => ['done', 'pending', 'unavailable'], 'description' => 'Server-computed: done (minutes present), pending (a computation is in flight for this club), unavailable'],
                'approximated' => ['type' => 'boolean', 'description' => 'Always false for a link (an exact gym); the approximate fallback lives in the per-fixture projection'],
                'fixtureCount' => ['type' => 'integer', 'description' => 'How many AWAY fixtures resolve to this gym via its label'],
                'fallbackVenueName' => ['type' => ['string', 'null'], 'description' => 'The gym that would receive this one\'s fixtures if it were removed (the most frequent remaining gym of the club); null if it is the last one — server-computed, never re-derived by the client'],
            ],
        ];
        $opponentView = [
            'type' => 'object',
            'description' => 'One AWAY opponent CLUB, with its paired gyms and its labels still to pair.',
            'properties' => [
                'code' => ['type' => ['string', 'null'], 'description' => 'The opponent FFBB organisme code; null when the code is unresolved (grouped by label, not pairable)'],
                'name' => ['type' => 'string', 'description' => 'The opponent name (federal directory, else the raw fixture label)'],
                'city' => ['type' => ['string', 'null'], 'description' => 'The opponent commune from the shared directory'],
                'precision' => ['type' => ['string', 'null'], 'enum' => ['VENUE', 'CITY', null], 'description' => 'The federal directory precision — the client tells « city only » (CITY) from « no known gym » (null) when there is no link'],
                'hasLogo' => ['type' => 'boolean', 'description' => 'A federal logo is known (serve it via GET /api/opponents/{code}/logo, member only)'],
                'fixtureCount' => ['type' => 'integer', 'description' => 'Total AWAY fixtures against this opponent this season'],
                'venues' => ['type' => 'array', 'items' => $venueView],
                'unmatchedLabels' => ['type' => 'array', 'description' => 'The file salle labels of this opponent that carry no link yet (« to pair »)', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'label' => ['type' => 'string'],
                        'fixtureCount' => ['type' => 'integer'],
                    ],
                ]],
            ],
        ];
        $writeView = [
            'type' => 'object',
            'description' => 'The written link + the resulting count carried by its target gym (merge « will carry N »).',
            'properties' => [
                'id' => ['type' => 'string'],
                'opponentOrganismeCode' => ['type' => 'string'],
                'fbiLabel' => ['type' => 'string', 'description' => 'The file salle label this link keys on'],
                'label' => ['type' => 'string', 'description' => 'The federal gym label'],
                'externalRef' => ['type' => ['string', 'null']],
                'source' => ['type' => 'string', 'enum' => ['AUTO', 'MANUAL']],
                'travelMinutes' => ['type' => ['integer', 'null'], 'description' => 'One-way car travel from the club siège (warmed synchronously on write; null = IGN mute)'],
                'targetFixtureCount' => ['type' => 'integer', 'description' => 'The fixtures that now resolve to this gym (sum of the labels pointing at it — the merge result)'],
            ],
        ];

        $paths->addPath('/api/opponents/travel', new PathItem(get: new Operation(
            operationId: 'listOpponentTravel',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Per AWAY opponent CLUB: its paired gyms (each with travel, status, source, fixture count and the fallback gym if removed) and its file labels still to pair. Read-only display feed for the Adversaires screen.', [
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
            summary: 'The AWAY opponents\' gyms and travel times for the current club/season',
        )));

        $addVenueBody = $this->schemas->jsonBody([
            'type' => 'object',
            'required' => ['venueLabel', 'latitude', 'longitude'],
            'properties' => [
                'venueLabel' => ['type' => 'string', 'description' => 'The federal gym label'],
                'venueExternalRef' => ['type' => 'string', 'nullable' => true, 'description' => 'The FFBB salle number, when picked from /api/ffbb/salles (re-resolved server-side for the shared count)'],
                'latitude' => ['type' => 'number'],
                'longitude' => ['type' => 'number'],
                'fbiLabel' => ['type' => 'string', 'nullable' => true, 'description' => 'The file salle label this gym covers; defaults to the gym label when omitted'],
            ],
        ]);
        $paths->addPath('/api/opponents/{code}/venues', new PathItem(post: new Operation(
            operationId: 'addOpponentVenue',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Adds a gym for the opponent (a MANUAL link) — by FFBB salle ref or chosen coordinates — and warms its travel. The shared count is re-resolved federally (never client text).', $writeView),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Invalid gym/label, or the code is not an away opponent this season'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Add a gym for an away opponent (management only)',
            parameters: [['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The opponent FFBB organisme code (must be an away opponent this season)']],
            requestBody: $addVenueBody,
        )));

        $paths->addPath('/api/opponents/{code}/venue-links', new PathItem(post: new Operation(
            operationId: 'pairOpponentVenueLabel',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Pairs an orphan file salle label to a gym (a MANUAL link) and warms its travel. The label must be a salle actually played away by this opponent.', $writeView),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Invalid gym/label, the code is not an away opponent, or the label is on no away fixture of it'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Pair an orphan file salle label of an away opponent to a gym (management only)',
            parameters: [['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The opponent FFBB organisme code']],
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['fbiLabel', 'venueLabel', 'latitude', 'longitude'],
                'properties' => [
                    'fbiLabel' => ['type' => 'string', 'description' => 'The orphan file salle label to pair (must be played away by this opponent)'],
                    'venueLabel' => ['type' => 'string'],
                    'venueExternalRef' => ['type' => 'string', 'nullable' => true],
                    'latitude' => ['type' => 'number'],
                    'longitude' => ['type' => 'number'],
                ],
            ]),
        )));

        $repointBody = $this->schemas->jsonBody([
            'type' => 'object',
            'required' => ['venueLabel', 'latitude', 'longitude'],
            'properties' => [
                'venueLabel' => ['type' => 'string'],
                'venueExternalRef' => ['type' => 'string', 'nullable' => true],
                'latitude' => ['type' => 'number'],
                'longitude' => ['type' => 'number'],
            ],
        ]);
        $paths->addPath('/api/opponents/venue-links/{id}', new PathItem(
            put: new Operation(
                operationId: 'repointOpponentVenueLink',
                tags: ['Fixture'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Re-points a link to another gym (re-pair / merge — the file label stays recognised at import). The shared count follows; travel is warmed. The response carries the target gym\'s resulting fixture count.', $writeView),
                    '400' => new Response('No club or season in context'),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                    '403' => new Response('Not a management member'),
                    '404' => new Response('No such link for this club (byte-identical for a foreign link)'),
                    '422' => new Response('Invalid gym'),
                    '429' => new Response('Too many requests (per-user rate limit)'),
                ],
                summary: 'Re-pair or merge an opponent venue link onto another gym (management only)',
                parameters: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The link id']],
                requestBody: $repointBody,
            ),
            delete: new Operation(
                operationId: 'deleteOpponentVenueLink',
                tags: ['Fixture'],
                responses: [
                    '204' => new Response('The local pairing was removed (the federal catalogue is never touched; the shared count is decremented if it was MANUAL)'),
                    '400' => new Response('No club or season in context'),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                    '403' => new Response('Not a management member'),
                    '404' => new Response('No such link for this club (byte-identical for a foreign link)'),
                ],
                summary: 'Remove an opponent venue link — the local pairing only (management only)',
                parameters: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The link id']],
            ),
        ));

        $paths->addPath('/api/opponents/travel/resolve', new PathItem(post: new Operation(
            operationId: 'resolveOpponentTravel',
            tags: ['Fixture'],
            responses: [
                '200' => $this->schemas->jsonResponse('Queues an ASYNC compute of the car travel from the club siège to every away opponent GYM (link) whose travel is MISSING from the cache (a travel is a constant — an already-cached one is never recomputed). The cap is checked synchronously (422); the paced IGN routing runs in the worker, progress pushed on the club Mercure travel topic. When a travel computation is already running for the club, nothing is dispatched (queued=false, alreadyRunning=true).', [
                    'type' => 'object',
                    'properties' => [
                        'queued' => ['type' => 'boolean', 'description' => 'The computation was dispatched to the worker'],
                        'alreadyRunning' => ['type' => 'boolean', 'description' => 'A travel computation was already in flight for this club, so nothing was dispatched'],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('Too many away opponents to resolve at once (retry with fewer)'),
                '429' => new Response('Too many requests (per-user rate limit)'),
            ],
            summary: 'Queue the async recompute of the season\'s away-opponent travel times (management only)',
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
                        'autoLocated' => ['type' => 'object', 'description' => 'Pass (b): the gym auto-pairing from the FBI file salle label', 'properties' => [
                            'located' => ['type' => 'integer', 'description' => 'File salle labels paired to a unique federal salle (a link posted)'],
                            'ambiguous' => ['type' => 'integer', 'description' => 'File salle labels matching two or more federal salles (nothing written)'],
                            'unmatched' => ['type' => 'integer', 'description' => 'File salle labels with no unique federal salle'],
                            'skipped' => ['type' => 'integer', 'description' => 'File salle labels left untouched because a MANUAL link already governs them'],
                        ]],
                        'travel' => ['type' => 'object', 'description' => 'Pass (c): the AUTO travel recompute — DISPATCHED to the worker (paced IGN routing > HTTP ceiling); progress pushed on the club Mercure travel topic. When a travel computation is already running for the club, nothing is dispatched (queued=false, alreadyRunning=true) — passes (a)/(b) still ran.', 'properties' => [
                            'queued' => ['type' => 'boolean', 'description' => 'The travel computation was dispatched to the worker'],
                            'alreadyRunning' => ['type' => 'boolean', 'description' => 'A travel computation was already in flight for this club, so nothing was dispatched'],
                            'pending' => ['type' => 'integer', 'description' => 'How many distinct away opponents the queued computation will process'],
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

        $paths->addPath('/api/opponents/{code}/logo', new PathItem(get: new Operation(
            operationId: 'serveOpponentLogo',
            tags: ['Fixture'],
            responses: [
                '200' => new Response('The opponent federal logo bytes (image/*), re-hosted lazily on the first GET; Cache-Control private, max-age 86400'),
                '401' => new Response('Unauthorized (missing/expired JWT) — member only, never public'),
                '404' => new Response('No federal logo known for this opponent, or its download failed (best-effort)'),
            ],
            summary: 'Serve the opponent federal logo (member only; re-hosted lazily, 404 without one)',
            parameters: [['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The opponent FFBB organisme code ([A-Za-z0-9]{1,24})']],
        )));
    }
}
