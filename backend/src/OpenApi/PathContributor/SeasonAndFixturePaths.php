<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Season-transition and fixture/match custom routes. */
final readonly class SeasonAndFixturePaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/seasons/{id}/transition', new PathItem(post: new Operation(
            operationId: 'transitionSeason',
            tags: ['Season'],
            responses: [
                '201' => $this->schemas->jsonResponse('N+1 draft season created from the source season entries (never the generated plan)', [
                    'type' => 'object',
                    'properties' => [
                        'seasonId' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'startDate' => ['type' => 'string', 'format' => 'date'],
                        'endDate' => ['type' => 'string', 'format' => 'date'],
                        'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '403' => new Response('Management role required'),
                '404' => new Response('Season not found (or another club\'s)'),
                '409' => new Response('Source is not the current season, or a next season already exists (body carries existingSeasonId)'),
            ],
            summary: 'Copy the current season entries (venues/teams/coaches/links/permanent constraints) into a fresh N+1 draft',
            parameters: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Source season id (must be the current season)']],
        )));

        $paths->addPath('/api/league-match-windows', new PathItem(get: new Operation(
            operationId: 'getLeagueMatchWindows',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Federation match-kickoff windows inherited by the club (league envelope, AURA default)', [
                    'type' => 'object',
                    'properties' => [
                        'league' => ['type' => 'string'],
                        'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'id' => ['type' => 'string'],
                            'league' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'level' => ['type' => 'string'],
                            'gender' => ['type' => 'string', 'nullable' => true],
                            'dayOfWeek' => ['type' => 'integer'],
                            'kickoffMin' => ['type' => 'string'],
                            'kickoffMax' => ['type' => 'string'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'League match-kickoff windows inherited by the club (global reference, read-only)',
        )));

        $paths->addPath('/api/fbi-ingestions/latest', new PathItem(get: new Operation(
            operationId: 'getLatestFbiIngestion',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('The last FBI export deposit of the club/season (freshness: « last deposit N days ago ») — null when none yet', [
                    'type' => 'object',
                    'properties' => [
                        'latest' => ['type' => 'object', 'nullable' => true, 'properties' => [
                            'depositedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'source' => ['type' => 'string', 'enum' => ['FBI_XLSX', 'FFBB_API']],
                            'created' => ['type' => 'integer'],
                            'updated' => ['type' => 'integer'],
                            'unchanged' => ['type' => 'integer'],
                            'deviationsCount' => ['type' => 'integer'],
                        ]],
                    ],
                ]),
                '400' => new Response('No club or season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Last FBI export deposit of the club/season (freshness feed, read-only, open to any member)',
        )));

        $paths->addPath('/api/fixtures/conflicts', new PathItem(get: new Operation(
            operationId: 'getFixtureConflicts',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Same-coach time-occupancy conflicts (match↔match and match↔training) recomputed live for the current club/season', [
                    'type' => 'object',
                    'properties' => [
                        'clubId' => ['type' => 'string'],
                        'seasonId' => ['type' => 'string', 'nullable' => true],
                        'conflicts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['MATCH_MATCH', 'MATCH_TRAINING', 'VENUE_UNAVAILABLE', 'TEAM_LINK_OVERLAP']],
                            'coachId' => ['type' => 'string'],
                            'start' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Overlap segment start'],
                            'end' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Overlap segment end'],
                            'left' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_MATCH: first fixture'],
                            'right' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_MATCH: second fixture'],
                            'fixture' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_TRAINING: the match'],
                            'training' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_TRAINING: the training slot'],
                            'fingerprint' => ['type' => 'string', 'description' => 'Stable identity of the conflict — same while it is the same dispute, changes when its nature changes (the guardian compares it across visits)'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Same-coach match/training conflict radar (read-only, computed on the fly)',
        )));

        $paths->addPath('/api/matches/module-visit', new PathItem(post: new Operation(
            operationId: 'stampMatchModuleVisit',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('What changed in the match module since this user\'s previous visit — new fixtures arrived, new conflicts, and whether the season plan moved. Stamps the visit as a side effect (first visit stays silent).', [
                    'type' => 'object',
                    'properties' => [
                        'firstVisit' => ['type' => 'boolean', 'description' => 'True on the very first visit: the reference is set silently, every count is zero'],
                        'newFixturesCount' => ['type' => 'integer', 'description' => 'Fixtures created since the reference was taken'],
                        'newConflictFingerprints' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Conflicts present now and absent from the reference (vanished ones are not reported)'],
                        'planningChanged' => ['type' => 'boolean', 'description' => 'The chosen season version or the latest completed one differs from the reference'],
                        'referenceTakenAt' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The moment the badges are measured against'],
                    ],
                ]),
                '400' => new Response('No club or no season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Stamp the match-module visit and return what changed since the previous one (per user; open to any member)',
        )));

        $paths->addPath('/api/competitions/entry-deadlines', new PathItem(post: new Operation(
            operationId: 'setCompetitionEntryDeadlines',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('The competitions whose league/committee entry deadline was set (or cleared). When a paired competition receives a non-null deadline, it also becomes the overridable community default for that federation competition (last write wins).', [
                    'type' => 'object',
                    'properties' => [
                        'updated' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids of the competitions written'],
                        'deadline' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'The deadline applied (null = cleared)'],
                    ],
                ]),
                '403' => new Response('Not a management member'),
                '409' => new Response('The selected season is archived (read-only)'),
                '422' => new Response('No competitions, malformed deadline, or an unknown/foreign competition id (nothing is written)'),
            ],
            summary: 'Set (or clear) the entry deadline on a set of competitions — management only',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['competitionIds', 'deadline'],
                'properties' => [
                    'competitionIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The competitions to stamp with the same deadline'],
                    'deadline' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'The league/committee entry deadline (AAAA-MM-JJ), or null to clear the club value'],
                ],
            ]),
        )));

        $paths->addPath('/api/matches/deadline-outlook', new PathItem(get: new Operation(
            operationId: 'getMatchDeadlineOutlook',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('The entry-deadline cockpit outlook: each still-owed effective deadline (club value, else community default) with its competitions, how many home fixtures remain to enter, and whether the seven-day reminder window is open. When at least one window is open, the current user\'s guardian delta is joined (read-only, the visit is not stamped).', [
                    'type' => 'object',
                    'properties' => [
                        'windows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'deadline' => ['type' => 'string', 'format' => 'date'],
                            'source' => ['type' => 'string', 'enum' => ['club', 'community'], 'description' => 'Where the effective deadline came from'],
                            'competitionNames' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'toEnterCount' => ['type' => 'integer', 'description' => 'Home fixtures not yet entered in FBI (UNPLACED included)'],
                            'withinWindow' => ['type' => 'boolean', 'description' => 'True within seven days of the deadline (overdue included)'],
                        ]]],
                        'guardianDelta' => ['type' => 'object', 'nullable' => true, 'description' => 'Present only when a reminder window is open AND the user already has a visit reference', 'properties' => [
                            'newFixturesCount' => ['type' => 'integer'],
                            'newConflictFingerprints' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'planningChanged' => ['type' => 'boolean'],
                        ]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'League/committee entry-deadline outlook for the cockpit (read-only, open to any member)',
        )));

        $paths->addPath('/api/venue-unavailability-impact', new PathItem(get: new Operation(
            operationId: 'getVenueUnavailabilityImpact',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Per-unavailability impact: affected placed matches + training sessions of the effective schedules (cockpit alert feed)', [
                    'type' => 'object',
                    'properties' => [
                        'clubId' => ['type' => 'string'],
                        'seasonId' => ['type' => 'string', 'nullable' => true],
                        'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'unavailabilityId' => ['type' => 'string'],
                            'venueId' => ['type' => 'string'],
                            'startDate' => ['type' => 'string', 'format' => 'date'],
                            'endDate' => ['type' => 'string', 'format' => 'date'],
                            'label' => ['type' => 'string', 'nullable' => true],
                            'affectedFixtures' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'trainingOccurrences' => ['type' => 'integer', 'description' => 'Dated training sessions inside the range'],
                            'trainingSlotCount' => ['type' => 'integer', 'description' => 'Distinct weekly slots affected'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Venue unavailability impact (alert-only, computed on the fly — blocks nothing)',
        )));

        $paths->addPath('/api/fixtures/place', new PathItem(post: new Operation(
            operationId: 'placeMatches',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Synchronous match placement: the solver places every placeable UNPLACED home match; the rest comes back named', [
                    'type' => 'object',
                    'properties' => [
                        'placed' => ['type' => 'integer'],
                        'skipped' => ['type' => 'integer', 'description' => 'Placements refused at write time (a manual gesture won during the solve)'],
                        'unplaced' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'matchId' => ['type' => 'string'],
                            'reason' => ['type' => 'string', 'enum' => ['no_access_window', 'no_league_intersection', 'venue_unavailable', 'venue_full']],
                            'message' => ['type' => 'string'],
                        ]]],
                        'diagnostics' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'metrics' => ['type' => 'object', 'nullable' => true],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Placement already running, season plan not chosen, or archived season'),
                '502' => new Response('Engine unreachable — retry, nothing was written'),
            ],
            summary: 'Auto-place the unplaced home matches (writes PLACED+SOLVER; manual anchors never move)',
        )));
    }
}
