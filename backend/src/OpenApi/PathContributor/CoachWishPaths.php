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
    }
}
