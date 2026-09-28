<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Training-plan (in-effect season version) person-conflict radar — P4-269. */
final readonly class TrainingConflictPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $side = [
            'teamId' => ['type' => 'string'],
            'teamName' => ['type' => 'string'],
            'venueId' => ['type' => 'string'],
            'venueName' => ['type' => 'string'],
            'startTime' => ['type' => 'string', 'description' => 'Session start « HHhMM » (e.g. « 18h00 »)'],
        ];

        $paths->addPath('/api/training/placed-conflicts', new PathItem(get: new Operation(
            operationId: 'getTrainingPlacedConflicts',
            tags: ['Planning'],
            responses: [
                '200' => $this->schemas->jsonResponse('Same-person time-occupancy conflicts on the IN-EFFECT training plan (the chosen version of the SEASON plan): a coach (MAIN/ASSISTANT) or a player placed at two overlapping placed sessions in DIFFERENT gyms. Recomputed live, read-only, nothing persisted.', [
                    'type' => 'object',
                    'properties' => [
                        'clubId' => ['type' => 'string'],
                        'seasonId' => ['type' => 'string', 'nullable' => true],
                        'seasonPlanChosen' => ['type' => 'boolean', 'description' => 'False when no version of the SEASON plan is chosen: there is no in-effect training plan to scan, so an empty conflicts list does not mean « all clear »'],
                        'conflicts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'personId' => ['type' => 'string', 'description' => 'The person present at both sessions (a coach or a player)'],
                            'personName' => ['type' => 'string'],
                            'dayOfWeek' => ['type' => 'integer', 'description' => 'ISO weekday 1..7 (both sessions share it)'],
                            'first' => ['type' => 'object', 'description' => 'The earlier-starting session', 'properties' => $side],
                            'second' => ['type' => 'object', 'description' => 'The later session', 'properties' => $side],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
            ],
            summary: 'In-effect training-plan person-conflict radar (read-only, computed on the fly, management only)',
        )));
    }
}
