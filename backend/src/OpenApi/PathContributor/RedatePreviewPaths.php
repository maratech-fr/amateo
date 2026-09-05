<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** D3 v2 (P4-174) — l'aperçu du re-datage d'une indisponibilité découpée. */
final readonly class RedatePreviewPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/calendar_entries/{id}/redate-preview', new PathItem(post: new Operation(
            operationId: 'postCalendarEntryRedatePreview',
            tags: ['CalendarEntry'],
            responses: [
                '200' => $this->schemas->jsonResponse('The effects of re-dating a SPLIT closure (a mother with week-children, no block-plan) to a new window, computed server-side — read-only, nothing written. Each `effects[]` line carries its `kind` (keep = unchanged; shift = the segment and its plan glide, versions kept, flagged to regenerate; absorb = an unpaired old segment whose week is covered by a new one, its own plan deleted; vanish = no new segment covers it, deleted; birth = a new segment with no old child, an empty plan is created; holiday_takes_over = a holiday plan the new window overlaps, to regenerate) and its ready-to-display French `label` (dates in clear, no internal identifier). The `token` fingerprints the read state; the confirming PUT sends it back as `previewToken` and a mismatch (the period moved since the preview) is refused 409.', [
                    'type' => 'object',
                    'properties' => [
                        'effects' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['keep', 'shift', 'absorb', 'vanish', 'birth', 'holiday_takes_over']],
                            'label' => ['type' => 'string', 'description' => 'Ready-to-display French sentence (dates in clear, no internal identifier)'],
                        ]]],
                        'token' => ['type' => 'string', 'description' => 'Canonical fingerprint of the read state — sent back as previewToken on the confirming PUT'],
                    ],
                ]),
                '400' => new Response('No club in context'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management member, or the period belongs to another club'),
                '404' => new Response('Unknown period'),
                '422' => new Response('Not a split closure, missing/malformed dates, end before start, or a window outside the season'),
            ],
            summary: 'Preview the effects of re-dating a split closure (read-only) and get the confirmation token',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'required' => ['startDate', 'endDate'],
                'properties' => [
                    'startDate' => ['type' => 'string', 'format' => 'date', 'description' => 'New window lower bound (YYYY-MM-DD)'],
                    'endDate' => ['type' => 'string', 'format' => 'date', 'description' => 'New window upper bound (YYYY-MM-DD)'],
                ],
            ]),
        )));
    }
}
