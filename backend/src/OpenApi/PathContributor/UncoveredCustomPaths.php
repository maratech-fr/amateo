<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/**
 * Custom `#[Route]`s that API Platform does not cover on its own — declared here so
 * `/api/docs` and the snapshot document the full contract.
 */
final readonly class UncoveredCustomPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->uncoveredCustomPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * P4-47 — les routes `#[Route]` custom qui n'apparaissaient nulle part dans le contrat.
     *
     * ⚑ Une route absente d'OpenAPI n'existe pour personne : le frontend écrit ses types à
     * partir de ce contrat, et un agent qui planifie le lit comme la vérité. Ces 18-là
     * étaient invisibles alors qu'elles portent des gestes centraux — valider un planning,
     * régénérer, réordonner les équipes, approuver une adhésion.
     *
     * ⚠ Les codes de réponse sont relevés DANS les contrôleurs, pas devinés : c'est la
     * moitié utile de la documentation d'une route. Une entrée qui annonce un 200 là où le
     * serveur rend 409 est pire que pas d'entrée — elle est crue.
     *
     * @return array<string, PathItem>
     */
    private function uncoveredCustomPaths(): array
    {
        $unauthorized = new Response('Unauthorized');
        $notFound = new Response('Resource not found');

        return [
            '/api/health' => new PathItem(get: new Operation(
                operationId: 'getApiHealth',
                tags: ['Ops'],
                responses: ['200' => new Response('Service is up (public, no authentication)')],
                summary: 'Liveness probe',
            )),
            '/api/schedules/{id}/validate' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleValidate',
                tags: ['Schedules'],
                responses: [
                    '200' => new Response('Version pointed as the plan in force; COMPLETED siblings are archived'),
                    '401' => $unauthorized,
                    '409' => new Response('A version is already in force for this plan, or the schedule is not COMPLETED'),
                ],
                summary: 'Point this version as the plan in force',
            )),
            '/api/schedules/{id}/validate-impact' => new PathItem(get: new Operation(
                operationId: 'getApiScheduleValidateImpact',
                tags: ['Schedules'],
                responses: [
                    '200' => $this->schemas->jsonResponse('What validating this version will make lose its venue: home matches pointing at a venue no longer affiliated to the club (deleted venue, or a dangling pointer left by exploration). Same predicate as the validation trigger — read-only, open to any member.', [
                        'type' => 'object',
                        'properties' => [
                            'orphanedFixtures' => ['type' => 'integer', 'description' => 'Home matches that will go back to « à placer » on validation'],
                            'declaredOrphanedFixtures' => ['type' => 'integer', 'description' => 'Subset already declared to the federation (SUBMITTED/VALIDATED) — to re-submit'],
                        ],
                    ]),
                    '401' => $unauthorized,
                    '403' => new Response('Version belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Venue-loss impact of validating this version (read-only, open to any member)',
            )),
            '/api/schedules/{id}/reopen' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleReopen',
                tags: ['Schedules'],
                responses: [
                    '200' => new Response('Plan un-pointed: the season has no version in force again'),
                    '401' => $unauthorized,
                    '409' => new Response('This version is not the one in force'),
                ],
                summary: 'Un-point the plan so it can be regenerated',
            )),
            '/api/schedule_plans/{id}/transcribe-from-socle' => new PathItem(post: new Operation(
                operationId: 'postApiSchedulePlanTranscribeFromSocle',
                tags: ['Schedules'],
                responses: [
                    '201' => $this->schemas->jsonResponse('V1 created as a COMPLETED transcription of the season plan pointed version, filtered by the period settings (disabled teams/venues, effective closed weekdays, reduced sessions). Sessions that no longer fit are NOT copied and are returned as the to-replace list (team, weekday, start time, origin venue, reason). No solver call, the plan is NOT pointed.', [
                        'scheduleId' => ['type' => 'string', 'format' => 'uuid'],
                        'toReplace' => ['type' => 'array', 'items' => ['type' => 'object']],
                    ]),
                    '401' => $unauthorized,
                    '404' => $notFound,
                    '409' => new Response('The plan already has versions, is a SEASON plan, or the season plan has no pointed version to transcribe'),
                ],
                summary: 'Birth the period plan V1 as a copy of the season baseline',
            )),
            '/api/schedules/{id}/regenerate' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleRegenerate',
                tags: ['Schedules'],
                responses: [
                    '202' => new Response('Regeneration queued (async, progress on the Mercure topic)'),
                    '401' => $unauthorized,
                    '409' => new Response('Version in force, or a generation is already running for this club'),
                    '429' => new Response('Club generation quota reached'),
                ],
                summary: 'Regenerate this version in place',
            )),
            '/api/schedules/{id}/regenerate-from' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleRegenerateFrom',
                tags: ['Schedules'],
                responses: [
                    '202' => new Response('New version queued from the source version structure'),
                    '401' => $unauthorized,
                    '409' => new Response('Version in force, or a generation is already running'),
                    '429' => new Response('Club generation quota reached'),
                ],
                summary: 'Create a new version from an existing one and regenerate it',
            )),
            '/api/schedules/{id}/fill' => new PathItem(post: new Operation(
                operationId: 'postApiScheduleFill',
                tags: ['Schedules'],
                responses: [
                    '202' => new Response('Fill queued — a new period version that pins the placed sessions and only places the ones to relocate'),
                    '401' => $unauthorized,
                    '409' => new Response('Not a period version, version in force, or a generation is already running'),
                    '422' => new Response('Problem too complex, or a pinned session has no matching slot'),
                    '429' => new Response('Club generation quota reached'),
                ],
                summary: 'Fill the sessions to relocate on a period version (partial solve, placed sessions kept)',
            )),
            '/api/schedules/{id}/socle-deviation' => new PathItem(get: new Operation(
                operationId: 'getApiScheduleSocleDeviation',
                tags: ['Schedules'],
                responses: [
                    '200' => new Response('Named deviations of a CLOSURE period version vs the pointed season plan: `moved` sessions (paired chronologically, season placement → period placement) and `unplaced` sessions (the season remainder, each with a `reason` SERVED from the period selection — team_reduced/venue_disabled/venue_closed, or null when the selection does not explain it). New and unchanged sessions are never reported. Read-only, re-callable.'),
                    '401' => $unauthorized,
                    '403' => new Response('Version belongs to another club'),
                    '404' => $notFound,
                    '409' => new Response('Version not completed, or the season plan has no pointed version to compare against'),
                    '422' => new Response('Not a period plan (SEASON), or the period is not a CLOSURE'),
                ],
                summary: 'Named deviations of a closure period version vs the season plan, computed server-side',
            )),
            '/api/planned-windows' => new PathItem(get: new Operation(
                operationId: 'getApiPlannedWindows',
                tags: ['Cockpit'],
                responses: [
                    '200' => new Response('The period windows another plan ALREADY governs inside `[start, end]`, sorted by start date — served so the UI never offers a week whose plan creation the server would refuse with 409. Same predicate as the write guard, by construction. Each `windows[]` item: `entryId`, `title`, `startDate`, `endDate`, `label`, and `reason` — the ready-to-display sentence, composed server-side by the same helper that names the 409 refusal, and shown as-is (the UI adds only the shortcut to the conflicting planning; it never composes a domain sentence). With `entryId` the querying family is excluded (mother and sibling weeks are legitimate); with `seasonId` (mother not yet created) nothing is excluded. Read-only, open to members.'),
                    '400' => new Response('No club in context'),
                    '401' => $unauthorized,
                    '404' => new Response('Unknown entryId/seasonId, or belonging to another club (never an existence oracle)'),
                    '422' => new Response('Missing or malformed start/end (YYYY-MM-DD), or neither entryId nor seasonId given'),
                ],
                summary: 'Period windows already planned inside a date range, served for the UI',
                parameters: [
                    ['name' => 'start', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Window lower bound (YYYY-MM-DD)'],
                    ['name' => 'end', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Window upper bound (YYYY-MM-DD)'],
                    ['name' => 'entryId', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'The materialised period entry; its season is used and its root family excluded. Provide this OR seasonId'],
                    ['name' => 'seasonId', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'The season, for the "mother not yet created" path (no family excluded). Provide this OR entryId'],
                ],
            )),
            '/api/reservations/group' => new PathItem(post: new Operation(
                operationId: 'postApiReservationsGroup',
                tags: ['Reservation'],
                responses: [
                    '201' => $this->schemas->jsonResponse('Shared-training block or group reserved on one slot: N reservations (one per member) written in a single flush; the response carries their ids', [
                        'type' => 'object',
                        'properties' => [
                            'ids' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                            'count' => ['type' => 'integer'],
                        ],
                    ]),
                    '400' => new Response('Invalid JSON, or a missing/malformed field (neither sharedTrainingBlockId nor sharedTrainingGroupId given)'),
                    '401' => $unauthorized,
                    '403' => new Response('Not a management member'),
                    '404' => new Response('Unknown shared-training group (or another club\'s — never an existence oracle); a block id that resolves to no block falls back to the group lookup'),
                    '409' => new Response('Archived season (read-only)'),
                    '422' => new Response('Scope mismatch (block/group not declared for this plan), closed venue, occupied slot (exclusivity), shared-sessions ceiling reached, a member over its weekly sessions, or unknown schedule plan'),
                ],
                summary: 'Reserve a shared-training block or group on one slot (atomic batch write of one HARD reservation per member)',
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['venueId', 'dayOfWeek', 'startTime'],
                    'properties' => [
                        'sharedTrainingBlockId' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Resolved FIRST if provided (a set of teams that behaves as one team)'],
                        'sharedTrainingGroupId' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Legacy fallback, resolved only when sharedTrainingBlockId is absent or does not resolve — transitional, kept until the frontend fully migrates to blocks'],
                        'venueId' => ['type' => 'string', 'format' => 'uuid'],
                        'dayOfWeek' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                        'startTime' => ['type' => 'string', 'description' => 'HH:MM'],
                        'durationMinutes' => ['type' => 'integer', 'default' => 90],
                        'schedulePlanId' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true, 'description' => 'NULL = base (season) plan; set = a period overlay — must match the block\'s or group\'s own scope'],
                    ],
                ]),
            )),
            '/api/teams/reorder' => new PathItem(post: new Operation(
                operationId: 'postApiTeamsReorder',
                tags: ['Teams'],
                responses: [
                    '200' => new Response('New tier order persisted for every team, in ONE transaction'),
                    '401' => $unauthorized,
                    '422' => new Response('Unknown team, or a team outside the current club'),
                ],
                summary: 'Bulk reorder — atomic, so concurrent PUTs never race on the optimistic-lock version',
            )),
            '/api/constraints/validate' => new PathItem(post: new Operation(
                operationId: 'postApiConstraintsValidate',
                tags: ['Constraints'],
                responses: [
                    '200' => new Response('Advisory pre-solve report: blocking errors and warnings'),
                    '401' => $unauthorized,
                ],
                summary: 'Advisory gate — never writes, and does NOT run on generate/regenerate',
            )),
            '/api/venue_training_slots/{id}/deletion-impact' => new PathItem(get: new Operation(
                operationId: 'getApiVenueTrainingSlotDeletionImpact',
                tags: ['Deletion'],
                responses: [
                    '200' => new Response('What deleting this availability slot would destroy — announced BEFORE confirmation. Its children never cite the slot id: reservations and the HARD locks they materialised attach by (venue, weekday, start time) AND by LAYER, so the counts are bounded to the slot own layer (season grid vs a period copy). Solver-chosen SOFT/NONE placements are results, never touched. Read-only.'),
                    '401' => $unauthorized,
                    '403' => new Response('Slot belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Impact of deleting this availability slot, computed server-side',
            )),
            '/api/venues/{id}/deletion-impact' => new PathItem(get: new Operation(
                operationId: 'getApiVenueDeletionImpact',
                tags: ['Deletion'],
                responses: [
                    '200' => new Response('What deleting this entity would destroy — announced BEFORE confirmation. `lines` are counted by walking the SAME cascade plan the delete executes (App\\Deletion\\CascadePlan), labels included, so a destruction can never be added without its announcement. `blocked`/`reason` carry the engaged-perimeter refusal (the UI must not offer a gesture the server will 409). `slotsInForce` = touched sessions living in a version the plan POINTS AT. `declaredFixtures` = matches already SUBMITTED/VALIDATED to the federation that will lose their venue — announced, never blocking. Read-only.'),
                    '401' => $unauthorized,
                    '403' => new Response('Entity belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Impact of deleting this venue, computed server-side',
            )),
            '/api/teams/{id}/deletion-impact' => new PathItem(get: new Operation(
                operationId: 'getApiTeamDeletionImpact',
                tags: ['Deletion'],
                responses: [
                    '200' => new Response('What deleting this entity would destroy — announced BEFORE confirmation. `lines` are counted by walking the SAME cascade plan the delete executes (App\\Deletion\\CascadePlan), labels included, so a destruction can never be added without its announcement. `blocked`/`reason` carry the engaged-perimeter refusal (the UI must not offer a gesture the server will 409). `slotsInForce` = touched sessions living in a version the plan POINTS AT. `declaredFixtures` = matches already SUBMITTED/VALIDATED to the federation that will lose their venue — announced, never blocking. Read-only.'),
                    '401' => $unauthorized,
                    '403' => new Response('Entity belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Impact of deleting this team, computed server-side',
            )),
            '/api/coaches/{id}/deletion-impact' => new PathItem(get: new Operation(
                operationId: 'getApiCoachDeletionImpact',
                tags: ['Deletion'],
                responses: [
                    '200' => new Response('What deleting this entity would destroy — announced BEFORE confirmation. `lines` are counted by walking the SAME cascade plan the delete executes (App\\Deletion\\CascadePlan), labels included, so a destruction can never be added without its announcement. `blocked`/`reason` carry the engaged-perimeter refusal (the UI must not offer a gesture the server will 409). `slotsInForce` = touched sessions living in a version the plan POINTS AT. `declaredFixtures` = matches already SUBMITTED/VALIDATED to the federation that will lose their venue — announced, never blocking. Read-only.'),
                    '401' => $unauthorized,
                    '403' => new Response('Entity belongs to another club'),
                    '404' => $notFound,
                ],
                summary: 'Impact of deleting this coach, computed server-side',
            )),
            '/api/calendar-entries/{id}/conflicts' => new PathItem(get: new Operation(
                operationId: 'getApiCalendarEntryConflicts',
                tags: ['Cockpit'],
                responses: [
                    '200' => new Response('Venue closures declared over this period (venue, title, dates, closed weekdays) plus the baseline sessions that fall on a closed venue during the window; sessions require a chosen season plan, closures do not'),
                    '401' => $unauthorized,
                    '404' => $notFound,
                ],
                summary: 'Venue closures and clashing baseline sessions for a period',
            )),
            '/api/reset-season' => new PathItem(post: new Operation(
                operationId: 'postApiResetSeason',
                tags: ['Season'],
                responses: [
                    '200' => new Response('Season workspace emptied'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '409' => new Response('A generation is running, or the season is archived'),
                ],
                summary: 'Destructive — empties the season workspace (management only)',
            )),
            '/api/club/appearance' => new PathItem(patch: new Operation(
                operationId: 'patchApiClubAppearance',
                tags: ['Club'],
                responses: [
                    '200' => new Response('Appearance updated'),
                    '400' => new Response('No club in context, or invalid JSON'),
                    '404' => new Response('Club not found'),
                ],
                summary: 'Club colours and display preferences',
            )),
            '/api/club/logo' => new PathItem(
                post: new Operation(
                    operationId: 'postApiClubLogo',
                    tags: ['Club'],
                    responses: [
                        '200' => new Response('Logo stored; returns its URL'),
                        '400' => new Response('No club in context, or no file in the "file" field'),
                        '404' => new Response('Club not found'),
                        '422' => new Response('Larger than 500 KB, or not PNG/JPEG/WebP'),
                    ],
                    summary: 'Upload the club logo (multipart, MIME and size validated server-side)',
                ),
                delete: new Operation(
                    operationId: 'deleteApiClubLogo',
                    tags: ['Club'],
                    responses: [
                        '200' => new Response('Logo removed'),
                        '400' => new Response('No club in context'),
                        '404' => new Response('Club not found'),
                    ],
                    summary: 'Remove the club logo',
                ),
            ),
            '/api/clubs/{clubId}/logo' => new PathItem(get: new Operation(
                operationId: 'getApiClubLogo',
                tags: ['Club'],
                responses: [
                    '200' => new Response('Logo bytes (PUBLIC_ACCESS — read-only, no tenant)'),
                    '404' => new Response('No logo for this club'),
                ],
                summary: 'Serve a club logo',
            )),
            '/api/club/ffbb-import' => new PathItem(post: new Operation(
                operationId: 'postApiClubFfbbImport',
                tags: ['Club'],
                responses: [
                    '200' => new Response('Club refreshed from the FFBB public API (best-effort)'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                ],
                summary: 'Re-import the club identity from FFBB (hosts hard-coded, SSRF-safe)',
            )),
            '/api/memberships' => new PathItem(get: new Operation(
                operationId: 'getApiMembershipsList',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Active members of the current club (id, userId, email, firstName, lastName, role, isSelf); with `?includeDeactivated=1`, also a `deactivated` array of the same shape'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                ],
                summary: 'List the active members of the current club (management-only)',
                parameters: [
                    ['name' => 'includeDeactivated', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean'], 'description' => 'When truthy (1/true), also return a `deactivated` array of deactivated (reactivable) memberships alongside `members`'],
                ],
            )),
            '/api/memberships/pending' => new PathItem(get: new Operation(
                operationId: 'getApiMembershipsPending',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Pending membership requests for the current club (deactivated members excluded)'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                ],
                summary: 'Membership requests awaiting a decision',
            )),
            '/api/memberships/{id}/approve' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipApprove',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Membership activated with the required role (body {"role":"admin"|"member"})'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Target is not genuinely pending (already active, or deactivated — use reactivate)'),
                    '422' => new Response('Role missing, or outside the assignable set (admin|member)'),
                ],
                summary: 'Approve a membership request, setting its role (required body {"role":"admin"|"member"})',
            )),
            '/api/memberships/{id}/role' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipRole',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Role changed'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Would leave the club without an active manager'),
                    '422' => new Response('Role missing, or outside the assignable set (admin|member)'),
                ],
                summary: 'Change a member role (required body {"role":"admin"|"member"}, management-only)',
            )),
            '/api/memberships/{id}/deactivate' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipDeactivate',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Membership deactivated'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Not an active membership, or would leave the club without an active manager'),
                ],
                summary: 'Deactivate an active membership (management-only)',
            )),
            '/api/memberships/{id}/reactivate' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipReactivate',
                tags: ['Memberships'],
                responses: [
                    '200' => new Response('Membership reactivated with its prior role'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Not a deactivated membership (a pending one activates only via approve)'),
                ],
                summary: 'Reactivate a deactivated membership (management-only)',
            )),
            '/api/memberships/{id}/reject' => new PathItem(post: new Operation(
                operationId: 'postApiMembershipReject',
                tags: ['Memberships'],
                responses: [
                    '204' => new Response('Membership request rejected'),
                    '401' => $unauthorized,
                    '403' => new Response('Member but not a management role'),
                    '404' => $notFound,
                    '409' => new Response('Target is not genuinely pending (an active member is deactivated, never rejected)'),
                ],
                summary: 'Reject a membership request',
            )),
            '/api/password/forgot' => new PathItem(post: new Operation(
                operationId: 'postApiPasswordForgot',
                tags: ['Auth'],
                responses: [
                    '200' => new Response('Always 200 — the response never reveals whether the email exists (anti-enumeration)'),
                    '429' => new Response('Per-IP rate limit'),
                ],
                summary: 'Request a password reset link (PUBLIC_ACCESS)',
            )),
            '/api/password/reset' => new PathItem(post: new Operation(
                operationId: 'postApiPasswordReset',
                tags: ['Auth'],
                responses: [
                    '200' => new Response('Password changed'),
                    '400' => new Response('Token unknown, expired or already used, or password too short'),
                ],
                summary: 'Consume a password reset token (PUBLIC_ACCESS)',
            )),
        ];
    }
}
