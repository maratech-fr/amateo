<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Prolonger/raccourcir une fermeture à travers les vacances — aperçu puis application. */
final readonly class ClosureWindowPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/schedule_plans/{id}/window-preview', new PathItem(post: new Operation(
            operationId: 'postSchedulePlanWindowPreview',
            tags: ['SchedulePlan'],
            responses: [
                '200' => $this->schemas->jsonResponse('The effects of moving a closure plan to a new window (extend or shrink, across holidays), computed server-side — read-only, nothing written. Each `effects[]` line carries its `kind` (unchanged; convert = the already-worked middle kept, its version intact; birth_copy = a run born as a validated copy of the middle; birth_empty = a run born empty, to generate, when the middle is not validated; shift / vanish = the single resulting window moved/shrunk; holiday_pause = the holidays pause the closure) with a ready-to-display French `label` (dates in clear, no internal identifier) and its `startDate`/`endDate`. `summary` is a short French sentence; `futurePlansWarning` means future segments would be swept by reopening the season plan. The `token` fingerprints the read state; the confirming apply sends it back as `previewToken` and a mismatch is refused 409.', [
                    'type' => 'object',
                    'properties' => [
                        'token' => ['type' => 'string', 'description' => 'Canonical fingerprint of the read state — sent back as previewToken on the apply'],
                        'summary' => ['type' => 'string', 'description' => 'Short French summary of the change'],
                        'futurePlansWarning' => ['type' => 'boolean', 'description' => 'Future segments would be swept by reopening the season plan'],
                        'effects' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['unchanged', 'convert', 'birth_copy', 'birth_empty', 'shift', 'vanish', 'holiday_pause']],
                            'startDate' => ['type' => 'string', 'format' => 'date'],
                            'endDate' => ['type' => 'string', 'format' => 'date'],
                            'label' => ['type' => 'string', 'description' => 'Ready-to-display French sentence (dates in clear, no internal identifier)'],
                        ]]],
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '404' => new Response('Unknown plan, or a plan belonging to another club (byte-identical, no existence oracle)'),
                '422' => new Response('Not a closure plan, or missing/malformed dates / end before start'),
            ],
            summary: 'Preview the effects of moving a closure plan to a new window (read-only) and get the confirmation token',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['startDate', 'endDate'],
                'properties' => [
                    'startDate' => ['type' => 'string', 'format' => 'date', 'description' => 'New window lower bound (YYYY-MM-DD)'],
                    'endDate' => ['type' => 'string', 'format' => 'date', 'description' => 'New window upper bound (YYYY-MM-DD)'],
                ],
            ]),
        )));

        $removed = [
            'type' => 'object',
            'description' => 'What the newly closed days removed from the period plans governing them: hard locks and reservations at the closed venue',
            'properties' => ['locks' => ['type' => 'integer'], 'reservations' => ['type' => 'integer']],
        ];

        $paths->addPath('/api/schedule_plans/{id}/window', new PathItem(post: new Operation(
            operationId: 'postSchedulePlanWindow',
            tags: ['SchedulePlan'],
            responses: [
                '200' => $this->schemas->jsonResponse('The closure window was applied. The already-worked middle is kept (same version), each other run is born as a validated copy, the dated closure runs to the new end (its start frozen).', [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'removed' => $removed,
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '404' => new Response('Unknown plan, or a plan belonging to another club'),
                '409' => new Response('Preview token stale/forged (the closure moved since the preview), the season is archived, or a generation is running for the club (code generation_in_progress)'),
                '422' => new Response('Missing preview token, not a closure plan, malformed dates, or a window below the "past does not move" floor'),
            ],
            summary: 'Apply a closure window change after a confirmed preview',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['startDate', 'endDate', 'previewToken'],
                'properties' => [
                    'startDate' => ['type' => 'string', 'format' => 'date', 'description' => 'New window lower bound (YYYY-MM-DD)'],
                    'endDate' => ['type' => 'string', 'format' => 'date', 'description' => 'New window upper bound (YYYY-MM-DD)'],
                    'previewToken' => ['type' => 'string', 'description' => 'The token returned by window-preview, recomputed under locks'],
                ],
            ]),
        )));

        $plan = [
            'type' => 'object',
            'properties' => [
                'entryId' => ['type' => 'string', 'format' => 'uuid'],
                'title' => ['type' => 'string'],
                'startDate' => ['type' => 'string', 'format' => 'date'],
                'endDate' => ['type' => 'string', 'format' => 'date'],
                'chosenScheduleId' => ['type' => 'string', 'nullable' => true],
                'loadedScheduleId' => ['type' => 'string', 'nullable' => true],
                'hasVersions' => ['type' => 'boolean'],
                'sessions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'teamId' => ['type' => 'string'], 'teamName' => ['type' => 'string'], 'date' => ['type' => 'string', 'format' => 'date'], 'venueId' => ['type' => 'string'],
                ]]],
                'toRemove' => ['type' => 'object', 'properties' => [
                    'locks' => ['type' => 'integer'],
                    'reservations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'teamId' => ['type' => 'string', 'nullable' => true], 'teamName' => ['type' => 'string'], 'dayOfWeek' => ['type' => 'integer'],
                    ]]],
                ]],
                'regeneratedWithout' => ['type' => 'boolean', 'description' => 'The pointed version, born after the closure, no longer trains at the closed venue on days the season calendar did'],
            ],
        ];
        $impact = [
            'type' => 'object',
            'properties' => [
                'venueIds' => ['type' => 'array', 'items' => ['type' => 'string']],
                'startDate' => ['type' => 'string', 'format' => 'date'],
                'endDate' => ['type' => 'string', 'format' => 'date'],
                'seasonPlanChosen' => ['type' => 'boolean'],
                'teams' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'teamId' => ['type' => 'string'], 'teamName' => ['type' => 'string'], 'tierLabel' => ['type' => 'string'], 'tierWeight' => ['type' => 'integer'], 'occurrences' => ['type' => 'integer'],
                ]]],
                'trainingOccurrences' => ['type' => 'integer'],
                'trainingSlotCount' => ['type' => 'integer'],
                'matchesToMove' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'fixtureId' => ['type' => 'string'], 'teamId' => ['type' => 'string'], 'teamName' => ['type' => 'string'], 'opponentLabel' => ['type' => 'string'],
                    'matchDate' => ['type' => 'string', 'format' => 'date'], 'kickoffTime' => ['type' => 'string', 'nullable' => true],
                ]]],
                'governingPlans' => ['type' => 'array', 'items' => $plan],
                'fullyGoverned' => ['type' => 'boolean'],
                'closedSessions' => ['type' => 'array', 'items' => ['type' => 'object']],
                'removedAtDeclaration' => ['type' => 'boolean'],
            ],
        ];

        $paths->addPath('/api/venue-closure-impact', new PathItem(get: new Operation(
            operationId: 'getVenueClosureImpact',
            tags: ['CalendarEntry'],
            responses: [
                '200' => $this->schemas->jsonResponse('What closing this venue on these dates would touch: training sessions, home matches to move, and the hard locks and reservations its saving would remove from the period plans governing those dates', $impact),
                '400' => new Response('Missing venue or dates'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '404' => new Response('Unknown venue, or a venue of another club'),
                '422' => new Response('End date before start date'),
            ],
            summary: 'Preview of a venue closure before saving it (read-only)',
        )));

        $paths->addPath('/api/calendar-entries/{id}/impact', new PathItem(get: new Operation(
            operationId: 'getCalendarEntryImpact',
            tags: ['CalendarEntry'],
            responses: [
                '200' => $this->schemas->jsonResponse('What a saved venue closure touches — the same figures as its preview before saving', $impact),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('An entry of another club'),
                '404' => new Response('Unknown entry'),
            ],
            summary: 'Impact of a saved venue closure (read-only)',
        )));
    }
}
