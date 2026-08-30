<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Super-admin monitoring / health custom routes. */
final readonly class AdminMonitoringPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->adminMonitoringPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * @return array<string, PathItem>
     */
    private function adminMonitoringPaths(): array
    {
        $solverSummary = [
            'type' => 'object',
            'properties' => [
                'generations' => ['type' => 'integer'],
                'infeasible' => ['type' => 'integer'],
                'infeasibleRate' => ['type' => 'number', 'format' => 'float'],
                'p50WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                'p95WallTimeMs' => ['type' => 'integer', 'nullable' => true],
            ],
        ];

        return [
            '/api/admin/health' => new PathItem(get: new Operation(
                operationId: 'getAdminHealth',
                tags: ['AdminMonitoring'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Bounded infrastructure probes; individual failures produce a degraded payload, never a probe exception', [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['healthy', 'degraded']],
                            'checkedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'services' => ['type' => 'object', 'properties' => [
                                'database' => $this->healthProbeSchema(),
                                'redis' => $this->healthProbeSchema(),
                                'engine' => $this->healthProbeSchema(),
                                'worker' => ['type' => 'object', 'properties' => [
                                    'status' => ['type' => 'string', 'enum' => ['up', 'down', 'unknown']],
                                    'lastHeartbeatAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                    'ageSeconds' => ['type' => 'integer', 'nullable' => true],
                                ]],
                                'mercure' => $this->healthProbeSchema(),
                            ]],
                            'messenger' => ['type' => 'object', 'properties' => [
                                'status' => ['type' => 'string', 'enum' => ['up', 'degraded', 'unknown']],
                                'backlog' => ['type' => 'integer', 'nullable' => true],
                                'failed' => ['type' => 'integer', 'nullable' => true],
                                'retriesToday' => ['type' => 'integer', 'nullable' => true],
                                'backlogWarningThreshold' => ['type' => 'integer'],
                            ]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Probe DB, Redis, engine, worker heartbeat, Mercure and Messenger queues',
            )),
            '/api/admin/freshness' => new PathItem(get: new Operation(
                operationId: 'getAdminFreshness',
                tags: ['AdminMonitoring'],
                responses: [
                    // « Jamais importé » est PÉRIMÉ, pas inconnu (fail-visible) : `lastUpdatedAt`
                    // null va toujours avec `stale` true. Le job d'alerte lit la même liste.
                    '200' => $this->schemas->jsonResponse('Reference-data freshness board (school holidays, public holidays, FFBB directory, DB backup)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'key' => ['type' => 'string', 'enum' => ['school-holidays', 'public-holidays', 'ffbb-directory', 'db-backup']],
                                'label' => ['type' => 'string'],
                                'lastUpdatedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'staleAfterDays' => ['type' => 'integer'],
                                'stale' => ['type' => 'boolean'],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the data-freshness board of the reference datasets',
            )),
            '/api/admin/overview' => new PathItem(get: new Operation(
                operationId: 'getAdminOverview',
                tags: ['AdminMonitoring'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Cross-tenant fleet and 30-day solver aggregates', [
                        'type' => 'object',
                        'properties' => [
                            'clubs' => ['type' => 'object', 'properties' => [
                                'total' => ['type' => 'integer'],
                                'active7d' => ['type' => 'integer'],
                                'active30d' => ['type' => 'integer'],
                                'new7d' => ['type' => 'integer'],
                                'unsubscribed' => ['type' => 'integer'],
                            ]],
                            'solver' => [...$solverSummary, 'properties' => [
                                ...$solverSummary['properties'],
                                'windowDays' => ['type' => 'integer', 'enum' => [30]],
                                'completed' => ['type' => 'integer'],
                                'failed' => ['type' => 'integer'],
                                'daily' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'date' => ['type' => 'string', 'format' => 'date'],
                                    'generations' => ['type' => 'integer'],
                                    'infeasible' => ['type' => 'integer'],
                                    'p50WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                                    'p95WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                                ]]],
                            ]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the global fleet and solver monitoring overview',
            )),
            '/api/admin/capacity' => new PathItem(get: new Operation(
                operationId: 'getAdminCapacity',
                tags: ['AdminMonitoring'],
                responses: [
                    // Toutes les valeurs sont nullable : l'historique d'avant les colonnes de
                    // capacité et les chemins terminaux (échec, timeout) les laissent vides.
                    '200' => $this->schemas->jsonResponse('Cross-tenant 90-day capacity aggregates from solver telemetry', [
                        'type' => 'object',
                        'properties' => [
                            'windowDays' => ['type' => 'integer', 'enum' => [90]],
                            'totalSolves' => ['type' => 'integer'],
                            'volume' => ['type' => 'object', 'properties' => [
                                'perDay' => ['type' => 'object', 'properties' => [
                                    'p50' => ['type' => 'integer', 'nullable' => true],
                                    'max' => ['type' => 'integer', 'nullable' => true],
                                    'maxDate' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                ]],
                                'hourly' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'hour' => ['type' => 'integer'],
                                    'solves' => ['type' => 'integer'],
                                ]]],
                            ]],
                            'wait' => ['type' => 'object', 'properties' => [
                                'queueP50Ms' => ['type' => 'integer', 'nullable' => true],
                                'queueP95Ms' => ['type' => 'integer', 'nullable' => true],
                                'queueMaxMs' => ['type' => 'integer', 'nullable' => true],
                                'engineWaitP95Ms' => ['type' => 'integer', 'nullable' => true],
                            ]],
                            'bySize' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'bucket' => ['type' => 'string', 'enum' => ['small', 'medium', 'large']],
                                'solves' => ['type' => 'integer'],
                                'p50WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                                'p95WallTimeMs' => ['type' => 'integer', 'nullable' => true],
                                'p95BudgetRatio' => ['type' => 'number', 'nullable' => true],
                                'unclosedProofRate' => ['type' => 'number'],
                            ]]],
                            'memory' => ['type' => 'object', 'properties' => [
                                'peakP50Mb' => ['type' => 'number', 'nullable' => true],
                                'peakP95Mb' => ['type' => 'number', 'nullable' => true],
                                'peakMaxMb' => ['type' => 'number', 'nullable' => true],
                                'lastBaselineMb' => ['type' => 'number', 'nullable' => true],
                            ]],
                            'issues' => ['type' => 'object', 'properties' => [
                                'byStatus' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'status' => ['type' => 'string'],
                                    'solves' => ['type' => 'integer'],
                                ]]],
                                'payloadP95Bytes' => ['type' => 'integer', 'nullable' => true],
                            ]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the 90-day capacity board (volume, queue wait, durations by problem size, engine memory, issues)',
            )),
            '/api/admin/clubs' => new PathItem(get: new Operation(
                operationId: 'getAdminClubs',
                tags: ['AdminMonitoring'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Paginated cross-tenant club supervision list', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'name' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'ffbbClubCode' => ['type' => 'string', 'nullable' => true],
                                'plan' => ['type' => 'object', 'nullable' => true, 'properties' => [
                                    'code' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                ]],
                                'paidSeasonYear' => ['type' => 'integer', 'nullable' => true],
                                'effectivePlan' => ['type' => 'object', 'properties' => [
                                    'code' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                ]],
                                'billingCycle' => ['type' => 'string', 'nullable' => true],
                                'generationCountSeason' => ['type' => 'integer'],
                                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                                'lastActivityAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                'unsubscribed' => ['type' => 'boolean'],
                                'currentSeason' => ['type' => 'object', 'nullable' => true, 'properties' => [
                                    'id' => ['type' => 'string', 'format' => 'uuid'],
                                    'name' => ['type' => 'string'],
                                    'status' => ['type' => 'string'],
                                ]],
                                'volumes' => ['type' => 'object', 'properties' => [
                                    'teams' => ['type' => 'integer'],
                                    'venues' => ['type' => 'integer'],
                                    'coaches' => ['type' => 'integer'],
                                    'constraints' => ['type' => 'integer'],
                                ]],
                                'solver' => [...$solverSummary, 'properties' => [
                                    ...$solverSummary['properties'],
                                    'latestStatus' => ['type' => 'string', 'nullable' => true],
                                    'latestAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                ]],
                            ]]],
                            'pagination' => ['type' => 'object', 'properties' => [
                                'page' => ['type' => 'integer'],
                                'limit' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                                'pages' => ['type' => 'integer'],
                            ]],
                            'metricsWindowDays' => ['type' => 'integer', 'enum' => [30]],
                        ],
                    ]),
                    '400' => new Response('Invalid pagination or query parameters'),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Search and paginate all clubs with current-season and solver indicators',
                parameters: [
                    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
                    ['name' => 'query', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 100]],
                ],
            )),
        ];
    }

    /** @return array<string, mixed> */
    private function healthProbeSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['up', 'down', 'unknown']],
                'latencyMs' => ['type' => 'integer'],
            ],
        ];
    }
}
