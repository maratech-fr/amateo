<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\Enum\AdminJobSource;
use App\Enum\AdminJobStatus;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Super-admin async-job custom routes. */
final readonly class AdminJobPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->adminJobPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * @return array<string, PathItem>
     */
    private function adminJobPaths(): array
    {
        $latestRun = [
            'type' => 'object',
            'nullable' => true,
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'status' => ['type' => 'string', 'enum' => AdminJobStatus::values()],
                'source' => ['type' => 'string', 'enum' => AdminJobSource::values()],
                'startedAt' => ['type' => 'string', 'format' => 'date-time'],
                'finishedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'durationMs' => ['type' => 'integer', 'nullable' => true],
                'exitCode' => ['type' => 'integer', 'nullable' => true],
            ],
        ];

        return [
            '/api/admin/jobs' => new PathItem(get: new Operation(
                operationId: 'getAdminJobs',
                tags: ['AdminJobs'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Closed operational-job catalog with schedule and latest execution', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'key' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'command' => ['type' => 'string'],
                                'cadence' => ['type' => 'string', 'enum' => ['every_10_minutes', 'daily', 'quarterly']],
                                'manualTriggerAllowed' => ['type' => 'boolean'],
                                'nextRunAt' => ['type' => 'string', 'format' => 'date-time'],
                                'latestRun' => $latestRun,
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Monitor allowlisted operational jobs',
            )),
            '/api/admin/jobs/{key}/run' => new PathItem(post: new Operation(
                operationId: 'postAdminJobRun',
                tags: ['AdminJobs'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Reference import completed', [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => [AdminJobStatus::SUCCEEDED->value]],
                            'exitCode' => ['type' => 'integer', 'enum' => [0]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Missing or invalid X-CSRF-Token header'),
                    '404' => new Response('Unknown job or job not manually triggerable'),
                    '409' => new Response('Job already running'),
                    '500' => new Response('Unexpected execution failure'),
                    '502' => new Response('The import command returned a non-zero exit code'),
                ],
                summary: 'Run one of the two manually triggerable reference imports',
                parameters: [
                    ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['import-school-holidays', 'import-public-holidays']]],
                    ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                ],
            )),
        ];
    }
}
