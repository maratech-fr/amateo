<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/**
 * Collecte des vœux des coachs (feature #10) — la route Symfony tenant hors API Platform, décrite
 * ici pour qu'elle entre au contrat et au snapshot (EveryCustomRouteIsDocumentedTest). Les
 * actions `send-links`/`remind` sont, elles, des opérations API Platform (auto-documentées).
 */
final readonly class CoachWishPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/coach_wish_campaigns/{id}/email-preview', new PathItem(get: new Operation(
            operationId: 'getCoachWishEmailPreview',
            tags: ['CoachWishCampaign'],
            responses: [
                '200' => $this->schemas->jsonResponse('The exact coach-link e-mail the initial send would produce — built with a FAKE token (never a real personal link) and an example coach first name. Logos are inlined as data URIs for in-iframe rendering.', [
                    'type' => 'object',
                    'properties' => [
                        'subject' => ['type' => 'string'],
                        'from' => ['type' => 'string', 'description' => 'Display name + address, e.g. "First (Club) via Amateo <no-reply@amateo.app>".'],
                        'html' => ['type' => 'string', 'description' => 'Rendered HTML body, safe to show in a sandboxed iframe.'],
                    ],
                ]),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Caller is not a manager of the current club'),
                '404' => new Response('Unknown campaign, or it belongs to another club (byte-identical)'),
            ],
            summary: 'Preview the coach-link e-mail of a campaign (manager only)',
            parameters: [new Parameter('id', 'path', 'The campaign id', required: true, schema: ['type' => 'string', 'format' => 'uuid'])],
        )));

        $paths->addPath('/api/coach-wishes/transfer', new PathItem(post: new Operation(
            operationId: 'transferCoachWishes',
            tags: ['CoachWish'],
            responses: [
                '200' => $this->schemas->jsonResponse('Converts the SELECTED coach wishes (manager-only gesture) into standard plan objects of their week: slotsWanted>0 → a team period override (active + sessionsPerWeek), slotsWanted=0 → the team is deactivated for that week; unavailableDays → one DATED HARD constraint (coach availability, or team forbidden-days when there is no coach / a co-coached team only partly answered); wishedDays → one DATED PREFERRED team day constraint. Idempotent by (source=coach_wish, occurrence = wish id). A week with no plan is NEVER born, it is counted as ignored with its reason.', [
                    'type' => 'object',
                    'properties' => [
                        'converted' => ['type' => 'integer', 'description' => 'Wishes converted into plan objects (and marked done)'],
                        'ignored' => ['type' => 'integer', 'description' => 'Wishes left untouched'],
                        'ignoredReasons' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'Per-reason count of ignored wishes (e.g. semaine_sans_plan, introuvable, autre_saison, id_invalide)'],
                    ],
                ]),
                '400' => new Response('Malformed wishIds list, or an oversized batch (more than 500)'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Caller is not a manager of the current club, or no club/season in context'),
                '409' => new Response('A generation is running for the club (generation_in_progress) — transferring now would overwrite its result'),
                '422' => new Response('A plan was deleted during the transfer (reload the page)'),
            ],
            summary: 'Transfer the selected coach wishes into their week plan settings (management only)',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['wishIds'],
                'properties' => [
                    'wishIds' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'description' => 'The ids of the checked wishes to transfer (non-empty)'],
                ],
            ]),
        )));
    }
}
