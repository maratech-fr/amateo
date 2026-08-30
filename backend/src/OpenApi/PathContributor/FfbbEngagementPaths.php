<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** FFBB engagement / rencontre synchronisation custom routes. */
final readonly class FfbbEngagementPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/ffbb/engagements', new PathItem(get: new Operation(
            operationId: 'listFfbbEngagements',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('The club\'s FFBB engagements of the current season — on-demand, never cached; each row carries a pre-fill suggestion', [
                    'type' => 'object',
                    'properties' => [
                        'engagements' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'ffbbCompetitionId' => ['type' => 'string'],
                            'ffbbCompetitionCode' => ['type' => 'string'],
                            'competitionName' => ['type' => 'string'],
                            'ffbbPouleId' => ['type' => 'string'],
                            'pouleName' => ['type' => 'string'],
                            'category' => ['type' => 'string', 'nullable' => true],
                            'level' => ['type' => 'string', 'nullable' => true],
                            'gender' => ['type' => 'string', 'nullable' => true],
                            'pouleSize' => ['type' => 'integer'],
                            'pouleOpponents' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'suggestedTeamId' => ['type' => 'string', 'nullable' => true],
                            'suggestedCompetitionId' => ['type' => 'string', 'nullable' => true],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club/season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '422' => new Response('The club has no FFBB code'),
                '502' => new Response('FFBB unreachable — retry later'),
            ],
            summary: 'List the club\'s FFBB engagements to pair (league data — corrections happen with the league)',
        )));

        $paths->addPath('/api/ffbb/engagements/confirm', new PathItem(post: new Operation(
            operationId: 'confirmFfbbPairings',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Pairings written on each team\'s Competition (refs + frozen expectedMatchdays + poule opponents, all from a server-side re-read)', [
                    'type' => 'object',
                    'properties' => [
                        'confirmed' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'competitionId' => ['type' => 'string'],
                            'teamId' => ['type' => 'string'],
                            'ffbbCompetitionId' => ['type' => 'string'],
                        ]]],
                    ],
                ]),
                '400' => new Response('No club/season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Season plan not chosen, or archived season'),
                '422' => new Response('Unknown engagement for this season, foreign/unknown team, or malformed pairing — nothing written'),
                '502' => new Response('FFBB unreachable — retry later'),
            ],
            summary: 'Confirm the FFBB pairings in block (re-paired at each phase — 1 click)',
        )));

        $paths->addPath('/api/ffbb/rencontres', new PathItem(get: new Operation(
            operationId: 'listFfbbRencontres',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('The club\'s FFBB-published rencontres crossed with the app — on demand, never cached; the diff (deviations) plus the rencontres absent of the app (creatable, the amicaux), proposed for creation', [
                    'type' => 'object',
                    'properties' => [
                        'deviations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'fixtureId' => ['type' => 'string'],
                            'externalRef' => ['type' => 'string'],
                            'division' => ['type' => 'string'],
                            'teamId' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'persisting' => ['type' => 'boolean'],
                            'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => [
                                'app' => ['type' => 'string', 'nullable' => true],
                                'file' => ['type' => 'string', 'nullable' => true],
                            ]]],
                        ]]],
                        'creatable' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'rencontreId' => ['type' => 'string'],
                            'competitionNom' => ['type' => 'string'],
                            'date' => ['type' => 'string'],
                            'kickoff' => ['type' => 'string', 'nullable' => true],
                            'homeAway' => ['type' => 'string'],
                            'opponentLabel' => ['type' => 'string'],
                            'venueLabel' => ['type' => 'string', 'nullable' => true],
                            'numeroJournee' => ['type' => 'string', 'nullable' => true],
                            'suggestedTeamId' => ['type' => 'string', 'nullable' => true],
                        ]]],
                        'fetchedAt' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ]),
                '400' => new Response('No club/season in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Season plan not chosen'),
                '422' => new Response('The club has no FFBB code'),
                '502' => new Response('FFBB unreachable — retry later'),
            ],
            summary: 'Cross the club\'s FFBB-published rencontres with the app (FBI stays the truth — the API is a convenience)',
        )));

        $paths->addPath('/api/ffbb/rencontres/apply', new PathItem(post: new Operation(
            operationId: 'applyFfbbRencontres',
            tags: ['Match'],
            responses: [
                '200' => $this->schemas->jsonResponse('Decisions applied (SAME engine as the xlsx import) and the chosen rencontres created (idempotent); values come from a server re-fetch, never the client', [
                    'type' => 'object',
                    'properties' => [
                        'created' => ['type' => 'integer'],
                        'updated' => ['type' => 'integer'],
                        'unresolvedDeviations' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'depositedAt' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ]),
                '400' => new Response('No club/season in context, or malformed decisions/creations'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member'),
                '409' => new Response('Season plan not chosen, archived season, or a concurrent create collided'),
                '422' => new Response('The club has no FFBB code'),
                '502' => new Response('FFBB unreachable — retry later'),
            ],
            summary: 'Apply the per-écart decisions and create the chosen rencontres (server re-fetch, never client values)',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'properties' => [
                    'decisions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'fixtureId' => ['type' => 'string'],
                        'field' => ['type' => 'string', 'enum' => ['date', 'kickoff', 'venue']],
                        'choice' => ['type' => 'string', 'enum' => ['keep_app', 'take_file']],
                    ]]],
                    'creations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'rencontreId' => ['type' => 'string'],
                        'teamId' => ['type' => 'string'],
                    ]]],
                ],
            ]),
        )));
    }
}
