<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** League match-window suggestions — the dominant tendency of the club's federation instance (P4-272 ②). */
final readonly class LeagueWindowSuggestionPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $window = ['type' => 'object', 'properties' => [
            'kickoffMin' => ['type' => 'string', 'description' => 'Kickoff window start « HH:MM »'],
            'kickoffMax' => ['type' => 'string', 'description' => 'Kickoff window end « HH:MM »'],
        ]];

        $item = ['type' => 'object', 'properties' => [
            'category' => ['type' => 'string'],
            'level' => ['type' => 'string', 'description' => 'DEPARTEMENTAL | REGIONAL'],
            'gender' => ['type' => 'string', 'nullable' => true, 'description' => 'M | F | MIXTE, null = all genders'],
            'dayOfWeek' => ['type' => 'integer', 'description' => 'ISO 1..7'],
            'windows' => ['type' => 'array', 'items' => $window, 'description' => 'The canonical ordered set of windows for the combination'],
            'clubCount' => ['type' => 'integer', 'nullable' => true, 'description' => 'How many clubs of the instance entered this exact set (null for a federation-catalog fallback row)'],
            'source' => ['type' => 'string', 'description' => 'clubs (a peer-clubs tendency) | federation (federation-catalog fallback)'],
            'scope' => ['type' => 'string', 'description' => 'comite | ligue | federation — the instance the row is grouped by'],
        ]];

        $paths->addPath('/api/league-window-suggestions', new PathItem(get: new Operation(
            operationId: 'getLeagueWindowSuggestions',
            tags: ['Matches'],
            responses: [
                '200' => $this->schemas->jsonResponse('The suggested league match-window sets for the current club: the dominant tendency of its federation instance (≥ 3 clubs AND a majority of the clubs of the instance that entered the combination) plus the federation-catalog fallback for uncovered combinations. Combinations already identical to the club\'s own copy are hidden server-side. Never reveals WHICH clubs — only a count. An unreadable FFBB code yields a neutral response (instance null, no items).', [
                    'type' => 'object',
                    'properties' => [
                        'instance' => ['type' => 'object', 'nullable' => true, 'properties' => [
                            'ligue' => ['type' => 'string', 'description' => 'FFBB league (3-letter prefix)'],
                            'comite' => ['type' => 'string', 'description' => 'FFBB committee (4 digits)'],
                        ]],
                        'items' => ['type' => 'array', 'items' => $item],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
            ],
            summary: 'League match-window suggestions for the current club (management only, read-only)',
        )));

        $paths->addPath('/api/league-window-suggestions/apply', new PathItem(post: new Operation(
            operationId: 'applyLeagueWindowSuggestions',
            tags: ['Matches'],
            responses: [
                '200' => $this->schemas->jsonResponse('Applied: for each requested combination that still has a recomputed suggestion, the club\'s copy rows for that combination are replaced by the suggested set (transactional). Returns how many combinations were applied.', [
                    'type' => 'object',
                    'properties' => ['applied' => ['type' => 'integer']],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
            ],
            summary: 'Apply league match-window suggestions to the club copy (management only, server recomputes)',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'properties' => [
                    'combinations' => ['type' => 'array', 'description' => 'The combinations to apply (windows are RECOMPUTED server-side, never taken from the client)', 'items' => ['type' => 'object', 'properties' => [
                        'category' => ['type' => 'string'],
                        'level' => ['type' => 'string'],
                        'gender' => ['type' => 'string', 'nullable' => true],
                        'dayOfWeek' => ['type' => 'integer'],
                    ]]],
                ],
            ]),
        )));
    }
}
