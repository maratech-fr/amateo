<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\Controller\SchedulePlanAlertSeenController;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/**
 * « J'ai vu » sur l'alerte d'un planning de période au radar ({@see SchedulePlanAlertSeenController}).
 * Route Symfony tenant, hors API Platform, décrite ici pour entrer au contrat et au snapshot.
 */
final readonly class SchedulePlanAlertPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/schedule_plans/{id}/alert-seen', new PathItem(post: new Operation(
            operationId: 'postSchedulePlanAlertSeen',
            tags: ['SchedulePlan'],
            responses: [
                '200' => $this->schemas->jsonResponse('The club has seen this alert of the period plan (for every manager of the club), dated the club day.', [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'alertSeenDate' => ['type' => 'string', 'format' => 'date'],
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '404' => new Response('Unknown plan, or a plan belonging to another club'),
                '409' => new Response('The season is archived'),
                '422' => new Response('Unknown alert kind'),
            ],
            summary: 'Mark the radar alert of a period plan as seen by the club',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['kind'],
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['auto_validated', 'started_without_plan'], 'description' => 'The alert seen'],
                ],
            ]),
        )));
    }
}
