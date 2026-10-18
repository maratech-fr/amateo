<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\Enum\DeploymentStatus;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/**
 * « Mise en prod programmée » : la surface admin (programmer / annuler / lister / tags) et le
 * bandeau d'annonce côté club. Écrites à la main — ce ne sont pas des opérations API Platform.
 */
final readonly class AdminDeploymentPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->deploymentPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * @return array<string, PathItem>
     */
    private function deploymentPaths(): array
    {
        $deployment = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'version' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => DeploymentStatus::values()],
                'scheduledAt' => ['type' => 'string', 'format' => 'date-time'],
                'expectedReturnAt' => ['type' => 'string', 'format' => 'date-time'],
                'dispatchedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'githubRunId' => ['type' => 'integer', 'nullable' => true],
                'failureReason' => ['type' => 'string', 'nullable' => true],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];

        return [
            '/api/admin/deployments' => new PathItem(
                get: new Operation(
                    operationId: 'getAdminDeployments',
                    tags: ['AdminDeployments'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('Recent scheduled deployments (newest first)', [
                            'type' => 'object',
                            'properties' => [
                                'items' => ['type' => 'array', 'items' => $deployment],
                                'configured' => ['type' => 'boolean'],
                            ],
                        ]),
                        '401' => new Response('No authenticated super-admin session'),
                    ],
                    summary: 'List recent scheduled deployments',
                ),
                post: new Operation(
                    operationId: 'postAdminDeployment',
                    tags: ['AdminDeployments'],
                    responses: [
                        '201' => $this->schemas->jsonResponse('Deployment scheduled', [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'version' => ['type' => 'string'],
                                'status' => ['type' => 'string', 'enum' => [DeploymentStatus::SCHEDULED->value]],
                                'scheduledAt' => ['type' => 'string', 'format' => 'date-time'],
                                'expectedReturnAt' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ]),
                        '401' => new Response('No authenticated super-admin session'),
                        '403' => new Response('Missing or invalid X-CSRF-Token header'),
                        '409' => new Response('A deployment is already scheduled'),
                        '422' => new Response('Feature not configured, unknown version, red CI, or invalid schedule'),
                    ],
                    summary: 'Schedule a production deployment (does not dispatch — the tick does, at H)',
                    requestBody: $this->schemas->jsonBody([
                        'type' => 'object',
                        'required' => ['version', 'scheduledAt', 'windowMinutes'],
                        'properties' => [
                            'version' => ['type' => 'string', 'description' => 'A deployable ^v tag'],
                            'scheduledAt' => ['type' => 'string', 'format' => 'date-time'],
                            'windowMinutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 240],
                        ],
                    ]),
                ),
            ),
            '/api/admin/deployments/tags' => new PathItem(get: new Operation(
                operationId: 'getAdminDeploymentTags',
                tags: ['AdminDeployments'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Deployable ^v tags', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List deployable version tags',
            )),
            '/api/admin/deployments/{id}' => new PathItem(delete: new Operation(
                operationId: 'deleteAdminDeployment',
                tags: ['AdminDeployments'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Deployment cancelled', [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'status' => ['type' => 'string', 'enum' => [DeploymentStatus::CANCELLED->value]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Missing or invalid X-CSRF-Token header'),
                    '404' => new Response('Unknown deployment'),
                    '409' => new Response('A dispatched or finished deployment can no longer be cancelled'),
                ],
                summary: 'Cancel a scheduled (not yet dispatched) deployment',
                parameters: [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                ],
            )),
            '/api/deployment-notice' => new PathItem(get: new Operation(
                operationId: 'getDeploymentNotice',
                tags: ['Deployment'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The scheduled-deployment banner, or null when none is active', [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'scheduledAt' => ['type' => 'string', 'format' => 'date-time'],
                            'expectedReturnAt' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ]),
                    '401' => new Response('No authenticated session'),
                ],
                summary: 'Announce a programmed production deployment to every member',
            )),
        ];
    }
}
