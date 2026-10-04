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

        $paths->addPath('/api/match-constraints/coherence', new PathItem(get: new Operation(
            operationId: 'getMatchConstraintCoherence',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Coherence alerts between the club match rules and the teams\' ideal slots (read-only, computed — nothing stored, nothing blocked). Two projections of the same collision set: byRule (constraints screen) and byHabit (ideal-slots screen)', [
                    'type' => 'object',
                    'properties' => [
                        'byRule' => ['type' => 'array', 'description' => 'Per club rule, the ideal slots it collides with', 'items' => ['type' => 'object', 'properties' => [
                            'ruleId' => ['type' => 'string'],
                            'habits' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'teamId' => ['type' => 'string'],
                                'teamName' => ['type' => 'string'],
                                'week' => ['type' => 'string', 'enum' => ['A', 'B']],
                                'dayOfWeek' => ['type' => 'integer'],
                                'kickoff' => ['type' => 'string', 'description' => '« HH:MM »'],
                            ]]],
                        ]]],
                        'byHabit' => ['type' => 'array', 'description' => 'Per ideal slot, the club rules it collides with', 'items' => ['type' => 'object', 'properties' => [
                            'habitId' => ['type' => 'string'],
                            'rules' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'ruleId' => ['type' => 'string'],
                                'ruleType' => ['type' => 'string', 'enum' => ['HARD', 'PREFERRED']],
                                'daysOfWeek' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                'kickoffMin' => ['type' => 'string', 'nullable' => true],
                                'kickoffMax' => ['type' => 'string', 'nullable' => true],
                            ]]],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Coherence alerts between club match rules and team ideal slots (read-only, computed)',
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

        // The additive per-side detail fields carried by the MATCH_MATCH (left/right)
        // and MATCH_TRAINING (fixture) sides, so the UI can render one line per side
        // with the opponent, the place and the travel/estimated hours.
        $sideDetails = [
            'estimatedKickoffTime' => ['type' => 'string', 'nullable' => true, 'description' => 'Estimated kickoff « HH:MM » borrowed from the team habit — set only when the kickoff is estimated (an away match with no real hour), null otherwise'],
            'travelOneWayMinutes' => ['type' => 'integer', 'nullable' => true, 'description' => 'One-way car travel minutes to the opponent; null when no travel is modelled, and always null on a home side'],
            'matchDurationMinutes' => ['type' => 'integer', 'description' => 'Match duration in minutes for this side\'s team (its resolved category profile, else the default)'],
            'opponentLabel' => ['type' => 'string', 'description' => 'The opponent label of this fixture'],
            'opponentPlace' => ['type' => 'string', 'nullable' => true, 'description' => 'The opponent place on away sides only: the city of the gym chosen for this opponent team, else the federal directory city; null when unknown'],
        ];

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
                            'type' => ['type' => 'string', 'enum' => ['VENUE_OVERLAP', 'LEAGUE_WINDOW_VIOLATION', 'CLUB_RULE_VIOLATION', 'TEAM_VENUE_FORBIDDEN', 'MATCH_MATCH', 'MATCH_TRAINING', 'VENUE_UNAVAILABLE', 'ACCESS_WINDOW_LOST', 'COMPETITION_INCOMPLETE', 'AWAY_NO_FOOTPRINT', 'FRIENDLY_ON_MATCH_SLOT']],
                            'coachId' => ['type' => 'string', 'description' => 'The double-booked person (a coach or a player) — MATCH_MATCH / MATCH_TRAINING'],
                            'coachRole' => ['type' => 'string', 'enum' => ['MAIN', 'ASSISTANT', 'PLAYER'], 'description' => 'Aggregate role of the person: MAIN when every side is MAIN, ASSISTANT as soon as one side is ASSISTANT, PLAYER otherwise'],
                            'start' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Overlap segment start'],
                            'end' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Overlap segment end'],
                            'left' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_MATCH: the earliest-starting fixture', 'properties' => [
                                'role' => ['type' => 'string', 'enum' => ['MAIN', 'ASSISTANT', 'PLAYER'], 'description' => 'The person\'s role on this side'],
                            ] + $sideDetails],
                            'right' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_MATCH: the later fixture', 'properties' => [
                                'role' => ['type' => 'string', 'enum' => ['MAIN', 'ASSISTANT', 'PLAYER'], 'description' => 'The person\'s role on this side'],
                            ] + $sideDetails],
                            'fixture' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_TRAINING: the match', 'properties' => [
                                'role' => ['type' => 'string', 'enum' => ['MAIN', 'ASSISTANT', 'PLAYER'], 'description' => 'The person\'s role with the match team'],
                            ] + $sideDetails],
                            'training' => ['type' => 'object', 'nullable' => true, 'description' => 'MATCH_TRAINING: the training slot', 'properties' => [
                                'role' => ['type' => 'string', 'enum' => ['MAIN', 'ASSISTANT', 'PLAYER'], 'description' => 'The person\'s role with the training team'],
                            ]],
                            'windows' => ['type' => 'array', 'description' => 'ACCESS_WINDOW_LOST: the match-access windows of the fixture\'s venue, the match weekday first — so the screen can name where the kickoff should have sat', 'items' => ['type' => 'object', 'properties' => [
                                'dayOfWeek' => ['type' => 'integer', 'description' => 'ISO weekday 1..7'],
                                'startTime' => ['type' => 'string', 'description' => '« HH:MM »'],
                                'endTime' => ['type' => 'string', 'description' => '« HH:MM »'],
                            ]]],
                            'rules' => ['type' => 'array', 'description' => 'CLUB_RULE_VIOLATION: the HARD club match rules the placed kickoff violates — so the screen can name which rule was broken', 'items' => ['type' => 'object', 'properties' => [
                                'daysOfWeek' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ISO weekdays the rule covers'],
                                'kickoffMin' => ['type' => 'string', 'nullable' => true, 'description' => '« HH:MM » or null (open lower bound)'],
                                'kickoffMax' => ['type' => 'string', 'nullable' => true, 'description' => '« HH:MM » or null (open upper bound)'],
                            ]]],
                            'competitionId' => ['type' => 'string', 'description' => 'COMPETITION_INCOMPLETE: the paired competition whose fixture count is checked'],
                            'competitionName' => ['type' => 'string', 'description' => 'COMPETITION_INCOMPLETE: the competition name'],
                            'teamId' => ['type' => 'string', 'description' => 'COMPETITION_INCOMPLETE: the team of that competition'],
                            'imported' => ['type' => 'integer', 'description' => 'COMPETITION_INCOMPLETE: fixtures attached to the competition (n)'],
                            'expected' => ['type' => 'integer', 'description' => 'COMPETITION_INCOMPLETE: the round-trip target (2×real opponents, exempts excluded); single-round is expected/2'],
                            'reason' => ['type' => 'string', 'enum' => ['OVER', 'INCOHERENT', 'PENDING'], 'description' => 'COMPETITION_INCOMPLETE: OVER (too many matches) and INCOHERENT (neither single-round nor round-trip, deadline passed) are alerts (severity 6); PENDING (phase not fully out yet) is a discreet info (severity 7)'],
                            'fingerprint' => ['type' => 'string', 'description' => 'Stable identity of the conflict — same while it is the same dispute, changes when its nature changes (the guardian compares it across visits)'],
                            'resolution' => ['type' => 'object', 'nullable' => true, 'description' => 'The handling status a manager stamped on this conflict (null = « à traiter », the default with no row)', 'properties' => [
                                'status' => ['type' => 'string', 'enum' => ['DEROGATION_REQUESTED', 'RESOLVED_INTERNALLY', 'NO_SOLUTION_YET', 'COACHES_NOT_PLAYING', 'PLAYS_NOT_COACHING', 'IMPORT_MISSING_MATCHES', 'FBI_ERROR', 'MATCH_TO_MOVE']],
                                'note' => ['type' => 'string', 'nullable' => true],
                                'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                            ]],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'Same-coach match/training conflict radar (read-only, computed on the fly)',
        )));

        $paths->addPath('/api/fixtures/conflicts/{fingerprint}/resolution', new PathItem(
            put: new Operation(
                operationId: 'putFixtureConflictResolution',
                tags: ['Match'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The handling status now stamped on the conflict (the conflict itself stays on the radar; this only says where its resolution stands)', [
                        'type' => 'object',
                        'properties' => [
                            'fingerprint' => ['type' => 'string'],
                            'resolution' => ['type' => 'object', 'properties' => [
                                'status' => ['type' => 'string', 'enum' => ['DEROGATION_REQUESTED', 'RESOLVED_INTERNALLY', 'NO_SOLUTION_YET', 'COACHES_NOT_PLAYING', 'PLAYS_NOT_COACHING', 'IMPORT_MISSING_MATCHES', 'FBI_ERROR', 'MATCH_TO_MOVE']],
                                'note' => ['type' => 'string', 'nullable' => true],
                                'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                            ]],
                        ],
                    ]),
                    '400' => new Response('No club in context'),
                    '403' => new Response('Not a management member'),
                    '404' => new Response('Malformed fingerprint (routing)'),
                    '422' => new Response('Unknown status, note over 500 characters, a fingerprint absent from the current radar, a status outside its conflict family\'s table, a play-only status (COACHES_NOT_PLAYING / PLAYS_NOT_COACHING) on a conflict where nobody plays, or an FBI_ERROR whose fbiCorrection does not name a valid side fixture and field'),
                ],
                summary: 'Set (or replace) the handling status of a conflict — management only. « À traiter » is not a status here (it is the absence of a row): reset with DELETE',
                parameters: [['name' => 'fingerprint', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The stable fingerprint of the conflict (from the radar feed)']],
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['status'],
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['DEROGATION_REQUESTED', 'RESOLVED_INTERNALLY', 'NO_SOLUTION_YET', 'COACHES_NOT_PLAYING', 'PLAYS_NOT_COACHING', 'IMPORT_MISSING_MATCHES', 'FBI_ERROR', 'MATCH_TO_MOVE']],
                        'note' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional free note, at most 500 characters'],
                        'fbiCorrection' => [
                            'type' => 'object',
                            'nullable' => true,
                            'description' => 'FBI_ERROR only — opens a « to correct in FBI » ledger entry for the offending fixture and field, atomically with the resolution. The fixture must be one of the two sides of this conflict; the target value stays empty (the app imported the error and does not know the right value)',
                            'properties' => [
                                'fixtureId' => ['type' => 'string', 'description' => 'The offending fixture — one of the two sides of this conflict'],
                                'field' => ['type' => 'string', 'enum' => ['date', 'kickoff', 'venue']],
                            ],
                        ],
                    ],
                ]),
            ),
            delete: new Operation(
                operationId: 'deleteFixtureConflictResolution',
                tags: ['Match'],
                responses: [
                    '204' => new Response('Reset to « à traiter » (idempotent — 204 even when there was no row)'),
                    '400' => new Response('No club in context'),
                    '403' => new Response('Not a management member'),
                    '404' => new Response('Malformed fingerprint (routing)'),
                ],
                summary: 'Reset a conflict to « à traiter » (removes the handling status) — management only',
                parameters: [['name' => 'fingerprint', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'The stable fingerprint of the conflict']],
            ),
        ));

        $paths->addPath('/api/fixtures/fbi-corrections', new PathItem(get: new Operation(
            operationId: 'listFbiCorrections',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('The OPEN « to correct in FBI » ledger of the club+season: divergences where the manager kept the app value, so FBI is behind and must be edited by hand. Each entry says what to type in FBI (appValue) against what FBI still shows (fbiValue).', [
                    'type' => 'object',
                    'properties' => [
                        'corrections' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'id' => ['type' => 'string'],
                            'fixtureId' => ['type' => 'string'],
                            'field' => ['type' => 'string', 'enum' => ['date', 'kickoff', 'venue']],
                            'appValue' => ['type' => 'string', 'nullable' => true, 'description' => 'What to type in FBI (the app value)'],
                            'fbiValue' => ['type' => 'string', 'nullable' => true, 'description' => 'What FBI still shows'],
                            'venueFbiLabel' => ['type' => 'string', 'nullable' => true, 'description' => 'The FBI alias of the app venue, when known — what to select in FBI'],
                            'decidedAt' => ['type' => 'string', 'format' => 'date-time'],
                            'lastSeenInFbiAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'Last time a deposit re-saw this divergence in FBI'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'The open « to correct in FBI » ledger for the club+season (read-only, open to any member)',
        )));

        $paths->addPath('/api/fixtures/fbi-corrections/{id}/close', new PathItem(post: new Operation(
            operationId: 'closeFbiCorrection',
            tags: ['Match'],
            responses: [
                '200' => new Response('The correction, now closed manually'),
                '403' => new Response('Not a management member'),
                '404' => new Response('No open correction with this id in the club+season (byte-identical cross-club)'),
                '409' => new Response('Archived (read-only) season'),
            ],
            summary: 'Mark a « to correct in FBI » entry as done in FBI (closed_by=manual) — management only',
            parameters: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
        )));

        $paths->addPath('/api/fixtures/fbi-corrections/{id}/reopen', new PathItem(post: new Operation(
            operationId: 'reopenFbiCorrection',
            tags: ['Match'],
            responses: [
                '200' => new Response('The correction, reopened'),
                '403' => new Response('Not a management member'),
                '404' => new Response('No correction with this id in the club+season (byte-identical cross-club)'),
                '409' => new Response('Already open, closed by a deposit, or the manual close is older than 24 hours — cannot be reopened, or archived season'),
            ],
            summary: 'Undo a recent manual « done in FBI » (only a manual close under 24 hours) — management only',
            parameters: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
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
                            'toPlaceCount' => ['type' => 'integer', 'description' => 'Home fixtures still UNPLACED to place — the « validé ligue » validatable ones are subtracted (they are to confirm, counted in toConfirmCount, not to place)'],
                            'toEnterCount' => ['type' => 'integer', 'description' => 'Home fixtures PLACED but not yet entered in FBI (UNPLACED and already-submitted excluded)'],
                            'withinWindow' => ['type' => 'boolean', 'description' => 'True within seven days of the deadline (overdue included)'],
                        ]]],
                        'fbiTodo' => ['type' => 'object', 'description' => 'The GLOBAL « to do in FBI » counts (all weeks), so the cockpit and the counters bar never load the fixtures', 'properties' => [
                            'toEnter' => ['type' => 'integer', 'description' => 'Home fixtures PLACED but not yet entered in FBI (UNPLACED and already-submitted excluded)'],
                            'toCorrect' => ['type' => 'integer', 'description' => 'Open « to correct in FBI » ledger entries (kept-app divergences FBI is still behind on)'],
                        ]],
                        'toConfirmCount' => ['type' => 'integer', 'description' => 'Home fixtures ready to be marked « validé ligue » in one gesture (matured championships, kickoff + identified venue, no pending deviation) — the grand total of the batch outlook'],
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

        $placementRunResult = [
            'type' => 'object',
            'nullable' => true,
            'description' => 'The run result (the former synchronous body) on a COMPLETED run, or {error} on a FAILED one, null while pending/running',
            'properties' => [
                'placed' => ['type' => 'integer'],
                'skipped' => ['type' => 'integer', 'description' => 'Placements refused at write time (a manual gesture won during the solve)'],
                'unplaced' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'matchId' => ['type' => 'string'],
                    'reason' => ['type' => 'string', 'enum' => ['no_access_window', 'no_league_intersection', 'club_rule_no_slot', 'team_venue_forbidden', 'venue_unavailable', 'venue_full', 'not_selected']],
                    'message' => ['type' => 'string'],
                ]]],
                'diagnostics' => ['type' => 'array', 'items' => ['type' => 'object']],
                'metrics' => ['type' => 'object', 'nullable' => true],
                'message' => ['type' => 'string', 'description' => 'Set when nothing was to place'],
                'error' => ['type' => 'string', 'description' => 'Set on a FAILED run'],
            ],
        ];

        $paths->addPath('/api/fixtures/place', new PathItem(post: new Operation(
            operationId: 'placeMatches',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Nothing to place: there was no placeable UNPLACED home match (no run is enqueued, no credit consumed)', [
                    'type' => 'object',
                    'properties' => [
                        'placed' => ['type' => 'integer'],
                        'skipped' => ['type' => 'integer'],
                        'unplaced' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'diagnostics' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'message' => ['type' => 'string'],
                    ],
                ]),
                '202' => $this->schemas->jsonResponse('Placement enqueued (asynchronous rail): the solve runs in the worker. Poll GET /api/fixtures/placement-run (or subscribe to the placement Mercure topic) for the terminal result', [
                    'type' => 'object',
                    'properties' => [
                        'runId' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['PENDING']],
                    ],
                ]),
                '400' => new Response('No club or no user in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member — or, on the Découverte credit plan, automatic placement must be week-end by week-end (send a one-week window)'),
                '409' => new Response('Placement already running for this club, season plan not chosen, or archived season'),
                '422' => new Response('Invalid window (a bound is not a date, or from is after to)'),
            ],
            summary: 'Enqueue the auto-placement of the unplaced home matches (async; writes PLACED+SOLVER in the worker; manual anchors never move)',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'description' => 'Optional placement window: place only the matches dated within [from, to] (« Placer ce week-end »); the already-placed matches outside it stay fixed. No body = the whole club. On the Découverte credit plan the window is mandatory and at most one week (Mon→Sun).',
                'properties' => [
                    'from' => ['type' => 'string', 'format' => 'date', 'description' => 'First day of the window (AAAA-MM-JJ), inclusive'],
                    'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Last day of the window (AAAA-MM-JJ), inclusive'],
                ],
            ]),
        )));

        $paths->addPath('/api/fixtures/placement-run', new PathItem(get: new Operation(
            operationId: 'getPlacementRun',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('The latest async match-placement run of the current club/season (so the screen knows on open whether a placement is in progress and can show a finished run\'s result) — {run: null} when there is none, and never another club\'s run', [
                    'type' => 'object',
                    'properties' => [
                        'run' => [
                            'type' => 'object',
                            'nullable' => true,
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'status' => ['type' => 'string', 'enum' => ['PENDING', 'RUNNING', 'COMPLETED', 'FAILED']],
                                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                                'startedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                'finishedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                'result' => $placementRunResult,
                            ],
                        ],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
            ],
            summary: 'The latest async match-placement run of the current club/season (read-only, open to any member)',
        )));

        $paths->addPath('/api/fixtures/review', new PathItem(post: new Operation(
            operationId: 'reviewFixtures',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Mark fixtures as reviewed (« traité »). One gesture per call: by fixtureIds (per line — pending deviations are cleared, keeping the app value) or by teamId (bulk — fixtures still carrying pending deviations are skipped and named, never arbitrated in bulk).', [
                    'type' => 'object',
                    'properties' => [
                        'reviewed' => ['type' => 'integer', 'description' => 'How many fixtures were marked reviewed'],
                        'skipped' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'fixtureId' => ['type' => 'string'],
                            'reason' => ['type' => 'string', 'enum' => ['pending_deviations']],
                        ]]],
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Season plan not chosen, or archived season'),
                '422' => new Response('Neither or both of fixtureIds and teamId were given'),
            ],
            summary: 'Mark fixtures as reviewed — by line (fixtureIds) or in bulk (teamId), management only',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'properties' => [
                    'fixtureIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Per-line gesture: these fixtures are reviewed, their pending deviations cleared'],
                    'teamId' => ['type' => 'string', 'description' => 'Bulk gesture: every fixture of the team is reviewed, except those still carrying pending deviations'],
                ],
            ]),
        )));

        $paths->addPath('/api/fixtures/review/deviations', new PathItem(post: new Operation(
            operationId: 'reviewFixtureDeviation',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Resolve ONE pending deviation of a fixture: keep_app drops it, take_source adopts the persisted source value (replayed server-side). When the last deviation is resolved, the fixture becomes reviewed.', [
                    'type' => 'object',
                    'properties' => [
                        'fixtureId' => ['type' => 'string'],
                        'reviewState' => ['type' => 'string', 'enum' => ['NEW', 'OUT_OF_SYNC', 'REVIEWED']],
                        'reviewedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        'pendingDeviations' => ['type' => 'array', 'items' => ['type' => 'object']],
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '404' => new Response('Fixture not found (or another club\'s)'),
                '409' => new Response('Season plan not chosen, or archived season'),
                '422' => new Response('Malformed body, or no pending deviation on that field'),
            ],
            summary: 'Resolve one pending deviation of a fixture (keep_app | take_source), management only',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['fixtureId', 'field', 'choice'],
                'properties' => [
                    'fixtureId' => ['type' => 'string'],
                    'field' => ['type' => 'string', 'enum' => ['date', 'kickoff', 'venue']],
                    'choice' => ['type' => 'string', 'enum' => ['keep_app', 'take_source']],
                ],
            ]),
        )));

        $paths->addPath('/api/fixtures/league-validation', new PathItem(
            get: new Operation(
                operationId: 'leagueValidationOutlook',
                tags: ['Match'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The batch « validé ligue » outlook, driven by each competition having STARTED. A championship has started when its entry deadline has passed (inclusive) OR its first match is already played (min match date strictly in the past) — so only STARTED competitions are proposed; a future-deadline championship with no match yet played (e.g. a new October youth wave with provisional dates) is deliberately left untouched. Per started competition: its name, deadline (null when it started by first match played), source, how it matured (deadline | firstMatchPlayed), the first match date (when that is what started it), and the count of validatable home fixtures (UNPLACED, kickoff + identified venue, no pending deviation). Plus the NAMED fixtures still to treat (no kickoff, no venue, or a pending deviation), the no-deadline competitions NOT YET started that nonetheless have ready fixtures (deadline to be entered), and the grand total to validate. Friendly competitions (label « Amical … ») are never proposed.', [
                        'type' => 'object',
                        'properties' => [
                            'matured' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'competitionId' => ['type' => 'string'],
                                        'name' => ['type' => 'string'],
                                        'deadline' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'The effective entry deadline, or null when the championship started by its first match being played'],
                                        'deadlineSource' => ['type' => 'string', 'enum' => ['club', 'community']],
                                        'maturedBy' => ['type' => 'string', 'enum' => ['deadline', 'firstMatchPlayed'], 'description' => 'What made the championship started: its deadline passed, or its first match already played'],
                                        'firstMatchDate' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'The first match date, present only when maturedBy is firstMatchPlayed'],
                                        'validatableCount' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                            'toTreat' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'fixtureId' => ['type' => 'string'],
                                        'teamId' => ['type' => 'string'],
                                        'competitionName' => ['type' => 'string'],
                                        'matchDate' => ['type' => 'string', 'format' => 'date'],
                                        'opponentLabel' => ['type' => 'string'],
                                        'reason' => ['type' => 'string', 'enum' => ['NO_KICKOFF', 'NO_VENUE', 'PENDING_DEVIATION']],
                                    ],
                                ],
                            ],
                            'missingDeadline' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'competitionId' => ['type' => 'string'],
                                        'name' => ['type' => 'string'],
                                        'validatableCount' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                            'totalValidatable' => ['type' => 'integer'],
                        ],
                    ]),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                    '403' => new Response('Not a management member'),
                    '409' => new Response('No active season, season plan not chosen, or archived season'),
                ],
                summary: 'The batch « validé ligue » outlook by matured competition (read-only, management only)',
            ),
            post: new Operation(
                operationId: 'confirmLeagueValidatedFixtures',
                tags: ['Match'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Mark every eligible home fixture « validé ligue » (status VALIDATED + placement source MANUAL — the anchor the grid lock and the placement solver both require). Idempotent: a second call finds nothing (the predicate excludes VALIDATED).', [
                        'type' => 'object',
                        'properties' => [
                            'confirmed' => ['type' => 'integer', 'description' => 'How many fixtures were switched to « validé ligue »'],
                        ],
                    ]),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                    '403' => new Response('Not a management member'),
                    '409' => new Response('No active season, season plan not chosen, archived season, or a concurrent modification (another tab / double-click)'),
                ],
                summary: 'Mark the eligible home fixtures « validé ligue » in one confirmed gesture (management only)',
            ),
        ));
    }
}
