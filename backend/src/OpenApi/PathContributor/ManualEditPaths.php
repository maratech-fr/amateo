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

/** Manual schedule-edit custom routes. */
final readonly class ManualEditPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->manualEditPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * @return array<string, PathItem>
     */
    private function manualEditPaths(): array
    {
        $messageResponse = static fn (string $description): Response => new Response(
            $description,
            new ArrayObject(['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['message' => ['type' => 'string']],
            ]]]),
        );

        // P2-32 — le DELTA de confort d'un candidat ACCEPTÉ (leçon P4-101 : forme COMPLÈTE, pas
        // un tableau nu). Aucun identifiant interne dans les descriptions.
        $compromises = [
            'type' => 'array',
            'description' => 'Named comfort trade-offs of an ACCEPTED candidate (empty on refusal): what the move BREAKS or GAINS, ready to display',
            'items' => ['type' => 'object', 'properties' => [
                'family' => ['type' => 'string', 'enum' => ['chaining', 'venue_preference', 'day_preference', 'time_preference', 'match_rest', 'spacing', 'coach_day_cap', 'implicit_rule'], 'description' => 'Which comfort family the trade-off belongs to'],
                'effect' => ['type' => 'string', 'enum' => ['broken', 'gained'], 'description' => 'broken = a preference honored before no longer is; gained = the reverse'],
                'message' => ['type' => 'string', 'description' => 'Ready-to-display sentence naming the team/coach/venue (no internal identifier)'],
                'teamId' => ['type' => 'string', 'nullable' => true, 'description' => 'Team the trade-off is about (grid highlighting)'],
                'coachId' => ['type' => 'string', 'nullable' => true, 'description' => 'Coach involved, when the family concerns one'],
                'venueId' => ['type' => 'string', 'nullable' => true, 'description' => 'Venue involved, when the family concerns one'],
                'dayOfWeek' => ['type' => 'integer', 'nullable' => true, 'description' => 'ISO day (1-7) involved, when relevant'],
                'startTime' => ['type' => 'string', 'nullable' => true, 'description' => 'Start time (HH:MM) involved, when relevant'],
            ]],
        ];
        // Les règles violées, telles que renvoyées dans l'essai (dryRun) d'un candidat refusé.
        $dryViolations = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'rule' => ['type' => 'string', 'description' => 'Machine code of the broken rule (UI branches on it)'],
            'message' => ['type' => 'string', 'description' => 'Human sentence naming the coach/venue/time in conflict'],
            'teamId' => ['type' => 'string', 'nullable' => true],
            'coachId' => ['type' => 'string', 'nullable' => true],
            'venueId' => ['type' => 'string', 'nullable' => true],
            'dayOfWeek' => ['type' => 'integer', 'nullable' => true],
            'startTime' => ['type' => 'string', 'nullable' => true],
            'conflictingTeamId' => ['type' => 'string', 'nullable' => true],
        ]]];
        // `dryRun` : un ESSAI. Même chemin jusqu'au verdict inclus, mais RIEN écrit.
        $dryRunProperty = ['type' => 'boolean', 'nullable' => true, 'description' => 'When true, run the full verdict (pre-engine guards included) but write NOTHING; the response carries the verdict and its named trade-offs'];
        // Verdict INDÉTERMINÉ : le solveur a épuisé son budget sans prouver SAT ni UNSAT.
        // Additive field — absent on a tranché OPTIMAL/FEASIBLE/INFEASIBLE verdict, never both
        // true alongside named violations.
        $indeterminateProperty = ['type' => 'boolean', 'nullable' => true, 'description' => 'True when the solver could not decide within its time budget — neither accepted nor refused (no rule named); nothing was written, retry'];

        return [
            '/api/schedule-slots/{id}/manual-edit/lock' => new PathItem(post: new Operation(
                operationId: 'postManualEditLock',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $messageResponse('Lock applied'),
                    '400' => new Response('Missing/invalid lockLevel'),
                    '404' => new Response('Slot not found'),
                    '409' => new Response('Schedule is validated (read-only)'),
                ],
                summary: 'Set the lock level of a schedule slot',
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['lockLevel'],
                    'properties' => ['lockLevel' => ['type' => 'string', 'enum' => ['NONE', 'HARD']]],
                ]),
            )),
            '/api/schedule-slots/{id}/move' => new PathItem(post: new Operation(
                operationId: 'postScheduleSlotMove',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Move accepted by the solver and written (schedule flagged manually edited, score now stale) — OR a dryRun essai (valid may be false, nothing written) — OR an INDETERMINATE verdict (the solver hit its time budget without proving SAT or UNSAT: valid=false, indeterminate=true, nothing written, retry). Both of the first two carry the named comfort trade-offs', [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'valid' => ['type' => 'boolean'],
                            'dryRun' => $dryRunProperty,
                            'indeterminate' => $indeterminateProperty,
                            'violations' => array_merge($dryViolations, ['nullable' => true, 'description' => 'Present on a dryRun essai that the solver refused (valid=false); absent on a written move or an indeterminate verdict']),
                            'compromises' => $compromises,
                            'evicted' => ['type' => 'object', 'nullable' => true, 'description' => 'Present only when an occupant was evicted from the target (or WOULD be, on a dryRun): its state BEFORE deletion, so the UI can offer to re-place it', 'properties' => [
                                'slotId' => ['type' => 'string'],
                                'teamId' => ['type' => 'string'],
                                'dayOfWeek' => ['type' => 'integer'],
                                'startTime' => ['type' => 'string', 'example' => '20:00'],
                                'venueId' => ['type' => 'string'],
                                'durationMinutes' => ['type' => 'integer'],
                            ]],
                        ],
                    ]),
                    '400' => new Response('Missing or invalid field (dayOfWeek, startTime or venueId)'),
                    '404' => new Response('Slot not found'),
                    '409' => new Response('Schedule is validated (read-only), or a generation is running for the club (body carries code=generation_in_progress)'),
                    '422' => $this->schemas->jsonResponse('Refused with nothing written. Either the solver refused the move (broken rules named for display), OR no venue window is open at the destination — nonexistent target or closed day (body carries code=slot_unavailable), OR the eviction target is invalid (body carries code=evict_target_mismatch), OR the eviction target is locked (body carries code=target_locked)', [
                        'type' => 'object',
                        'properties' => [
                            'valid' => ['type' => 'boolean', 'enum' => [false]],
                            'code' => ['type' => 'string', 'nullable' => true, 'description' => 'slot_unavailable, evict_target_mismatch or target_locked when a server pre-check refused (no engine call)'],
                            'error' => ['type' => 'string', 'nullable' => true, 'description' => 'Human message for the eviction pre-check refusal'],
                            'violations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'rule' => ['type' => 'string', 'description' => 'Machine code of the broken rule (UI branches on it)'],
                                'message' => ['type' => 'string', 'description' => 'Human sentence naming the coach/venue/time in conflict'],
                                'teamId' => ['type' => 'string', 'nullable' => true, 'description' => 'Team the violation is about (grid highlighting)'],
                                'coachId' => ['type' => 'string', 'nullable' => true, 'description' => 'Coach in conflict, when the rule involves one'],
                                'venueId' => ['type' => 'string', 'nullable' => true, 'description' => 'Venue involved in the violation'],
                                'dayOfWeek' => ['type' => 'integer', 'nullable' => true, 'description' => 'ISO day (1-7) of the conflicting occupation'],
                                'startTime' => ['type' => 'string', 'nullable' => true, 'description' => 'Start time (HH:MM) of the conflicting occupation'],
                                'conflictingTeamId' => ['type' => 'string', 'nullable' => true, 'description' => 'Team already occupying the target slot'],
                            ]]],
                        ],
                    ]),
                    '502' => new Response('Engine unreachable — nothing was written, retry'),
                    '504' => $this->schemas->jsonResponse('The engine answered too slowly and the verdict was dropped — nothing was written. Distinct from 502: the engine works, it merely exceeded the transport ceiling. Retrying is the right move (body carries code=engine_timeout)', [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => ['engine_timeout']],
                            'error' => ['type' => 'string', 'description' => 'Human message naming the timeout'],
                        ],
                    ]),
                ],
                summary: 'Move a slot (day / time / venue) under the solver verdict: written only if the engine accepts it, otherwise refused with the named broken rules. Optional evictSlotId frees an occupied target (a locked occupant refuses eviction before any engine call)',
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['dayOfWeek', 'startTime', 'venueId'],
                    'properties' => [
                        'dayOfWeek' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                        'startTime' => ['type' => 'string', 'example' => '20:00'],
                        'venueId' => ['type' => 'string'],
                        'evictSlotId' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional: id of the slot occupying the target, to evict (deleted in the same transaction) once the move is accepted'],
                        'dryRun' => $dryRunProperty,
                    ],
                ]),
            )),
            '/api/schedules/{id}/place-slot' => new PathItem(post: new Operation(
                operationId: 'postSchedulePlaceSlot',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Placement accepted by the solver and written (schedule flagged manually edited, score now stale) — OR a dryRun essai (valid may be false, nothing created) — OR an INDETERMINATE verdict (the solver hit its time budget without proving SAT or UNSAT: valid=false, indeterminate=true, nothing created, retry). The first two carry the named comfort trade-offs', [
                        'type' => 'object',
                        'properties' => [
                            'valid' => ['type' => 'boolean'],
                            'dryRun' => $dryRunProperty,
                            'indeterminate' => $indeterminateProperty,
                            'slotId' => ['type' => 'string', 'nullable' => true, 'description' => 'Id of the newly created (unlocked) slot; absent on a dryRun essai or an indeterminate verdict'],
                            'violations' => array_merge($dryViolations, ['nullable' => true, 'description' => 'Present on a dryRun essai that the solver refused (valid=false); absent on a written placement or an indeterminate verdict']),
                            'compromises' => $compromises,
                        ],
                    ]),
                    '400' => new Response('Missing or invalid field (teamId, dayOfWeek, startTime, venueId; durationMinutes if present must be a positive integer)'),
                    '404' => new Response('Schedule not found'),
                    '409' => new Response('Schedule is validated (read-only), or a generation is running for the club (body carries code=generation_in_progress)'),
                    '422' => $this->schemas->jsonResponse('Refused with nothing written: unknown team for this schedule, OR no venue window at that slot (code=slot_unavailable), OR the asserted durationMinutes contradicts the venue window (code=duration_mismatch), OR the solver refused the placement (broken rules named for display)', [
                        'type' => 'object',
                        'properties' => [
                            'valid' => ['type' => 'boolean', 'enum' => [false]],
                            'code' => ['type' => 'string', 'nullable' => true, 'description' => 'slot_unavailable or duration_mismatch when a server pre-check refused (no engine call)'],
                            'error' => ['type' => 'string', 'nullable' => true, 'description' => 'Human message for an unknown team or a server pre-check refusal'],
                            'violations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'rule' => ['type' => 'string', 'description' => 'Machine code of the broken rule (UI branches on it)'],
                                'message' => ['type' => 'string', 'description' => 'Human sentence naming the coach/venue/time in conflict'],
                                'teamId' => ['type' => 'string', 'nullable' => true],
                                'coachId' => ['type' => 'string', 'nullable' => true],
                                'venueId' => ['type' => 'string', 'nullable' => true],
                                'dayOfWeek' => ['type' => 'integer', 'nullable' => true],
                                'startTime' => ['type' => 'string', 'nullable' => true],
                                'conflictingTeamId' => ['type' => 'string', 'nullable' => true],
                            ]]],
                        ],
                    ]),
                    '502' => new Response('Engine unreachable — nothing was written, retry'),
                    '504' => $this->schemas->jsonResponse('The engine answered too slowly and the verdict was dropped — nothing was written. Distinct from 502: the engine works, it merely exceeded the transport ceiling. Retrying is the right move (body carries code=engine_timeout)', [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => ['engine_timeout']],
                            'error' => ['type' => 'string', 'description' => 'Human message naming the timeout'],
                        ],
                    ]),
                ],
                summary: 'Place a NEW slot (a drift session, e.g. a make-up) under the solver verdict: created only if the engine accepts it. No count guard — the verdict is the sole judge; the slot is created unlocked. Its duration is the venue window\'s, resolved server-side; durationMinutes in the body is only an assertion',
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['teamId', 'dayOfWeek', 'startTime', 'venueId'],
                    'properties' => [
                        'teamId' => ['type' => 'string'],
                        'dayOfWeek' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                        'startTime' => ['type' => 'string', 'example' => '20:00'],
                        'venueId' => ['type' => 'string'],
                        'durationMinutes' => ['type' => 'integer', 'minimum' => 1, 'example' => 90, 'description' => 'Optional assertion: the persisted duration is always the venue window\'s (resolved server-side). If provided and it differs from the window, the request is refused with code=duration_mismatch'],
                        'dryRun' => $dryRunProperty,
                    ],
                ]),
            )),
            '/api/schedule-slots/move-group' => new PathItem(post: new Operation(
                operationId: 'postScheduleSlotMoveGroup',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The whole block session was accepted by the solver and its member slots were moved together (schedule flagged manually edited, score now stale), carrying the named comfort trade-offs — OR an INDETERMINATE verdict (the solver hit its time budget without proving SAT or UNSAT: valid=false, indeterminate=true, none of the member slots moved, retry)', [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'valid' => ['type' => 'boolean'],
                            'indeterminate' => $indeterminateProperty,
                            'compromises' => $compromises,
                            'movedSlotIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids of the member slots that were moved as one; absent on an indeterminate verdict'],
                        ],
                    ]),
                    '400' => new Response('Missing or invalid field (scheduleId, blockId, source or target case)'),
                    '404' => new Response('Schedule or mutualisation block not found'),
                    '409' => new Response('Schedule is validated (read-only), or a generation is running for the club (body carries code=generation_in_progress)'),
                    '422' => $this->schemas->jsonResponse('Refused with NOTHING moved (all-or-nothing): the solver refused the group move (broken rules named for display — e.g. shared_block_broken when the move would split the block), OR no block session sits at the source case / the target window is closed (body carries code=slot_unavailable)', [
                        'type' => 'object',
                        'properties' => [
                            'valid' => ['type' => 'boolean', 'enum' => [false]],
                            'code' => ['type' => 'string', 'nullable' => true, 'description' => 'slot_unavailable when a server pre-check refused (no engine call)'],
                            'error' => ['type' => 'string', 'nullable' => true],
                            'violations' => $dryViolations,
                        ],
                    ]),
                    '502' => new Response('Engine unreachable — nothing was moved, retry'),
                    '504' => $this->schemas->jsonResponse('The engine answered too slowly and the verdict was dropped — nothing was moved. Distinct from 502: the engine works, it merely exceeded the transport ceiling. Retrying is the right move (body carries code=engine_timeout)', [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => ['engine_timeout']],
                            'error' => ['type' => 'string', 'description' => 'Human message naming the timeout'],
                        ],
                    ]),
                ],
                summary: 'Move a WHOLE mutualisation-block session (all its member slots at the source case) to the target case, under ONE solver verdict and in one transaction (all-or-nothing). The server resolves the member slots itself from the block and the source case — never client slot ids. A block declared with commonSessions > 1 has several sessions; the source case designates the one to move',
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['scheduleId', 'blockId', 'source', 'target'],
                    'properties' => [
                        'scheduleId' => ['type' => 'string', 'description' => 'The schedule (version) being edited'],
                        'blockId' => ['type' => 'string', 'description' => 'The mutualisation block whose session moves'],
                        'source' => ['type' => 'object', 'description' => 'The case the block session currently sits at', 'required' => ['venueId', 'dayOfWeek', 'startTime'], 'properties' => [
                            'venueId' => ['type' => 'string'],
                            'dayOfWeek' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                            'startTime' => ['type' => 'string', 'example' => '18:00'],
                        ]],
                        'target' => ['type' => 'object', 'description' => 'The case to move the block session to', 'required' => ['venueId', 'dayOfWeek', 'startTime'], 'properties' => [
                            'venueId' => ['type' => 'string'],
                            'dayOfWeek' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                            'startTime' => ['type' => 'string', 'example' => '20:00'],
                        ]],
                    ],
                ]),
            )),
            '/api/schedule-slots/{id}/mutualize' => new PathItem(post: new Operation(
                operationId: 'postScheduleSlotMutualize',
                tags: ['ManualEdit'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The mutualisation block was declared and anchored to the anchor slot\'s case: all member sessions are co-located and HARD-locked there, the schedule is flagged manually edited. Nothing is sent to the solver', [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'blockId' => ['type' => 'string', 'description' => 'Id of the created mutualisation block'],
                            'movedSlotIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Sessions of already-placed joining teams that were moved onto the case'],
                            'createdSlotIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Sessions created for joining teams that had none in the plan'],
                            'activatedTeamIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Teams activated for the period (one session, origin mutualisation) to join the block'],
                        ],
                    ]),
                    '400' => new Response('Missing or invalid field (teamIds, label over 40 chars, replacedSlotIds)'),
                    '404' => new Response('Anchor slot not found'),
                    '409' => new Response('Schedule is validated (read-only), or a generation is running for the club (body carries code=generation_in_progress)'),
                    '422' => $this->schemas->jsonResponse('Refused with NOTHING written (transaction rolled back): the target is the season base plan (period plans only), a team is inactive or unknown, a block with exactly these teams already exists in this scope, the case is already full (capacity), the shared sessions would exceed a team\'s weekly volume, or an already-placed team did not designate the session the common one replaces', [
                        'type' => 'object',
                        'properties' => [
                            'error' => ['type' => 'string', 'description' => 'Ready-to-display reason for the refusal (no internal identifier)'],
                        ],
                    ]),
                ],
                summary: 'Declare a mutualisation block anchored to the anchor slot\'s case (lot 9): the named teams train together there as ONE common session, in place on a PERIOD plan, with no solver verdict. The source team\'s session is locked there; an already-placed joining team\'s designated session is moved onto the case; a joining team with no session is activated for the period with one. teamIds are re-validated server-side (the source team is always a member)',
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['teamIds'],
                    'properties' => [
                        'teamIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The block members (the anchor slot\'s team is forced in server-side)'],
                        'label' => ['type' => 'string', 'nullable' => true, 'maxLength' => 40, 'description' => 'Optional block name, shown on the grid, the slot detail, the PDF and the Excel export'],
                        'replacedSlotIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'For each already-placed joining team, the id of the session the common one replaces (moved onto the case). A team with a single session may omit it'],
                    ],
                ]),
            )),
        ];
    }
}
