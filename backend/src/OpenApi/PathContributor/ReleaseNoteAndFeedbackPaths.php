<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;
use ArrayObject;

/** User-facing release-note and feedback custom routes. */
final readonly class ReleaseNoteAndFeedbackPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->releaseNotePaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->feedbackPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * P5-12 — le journal de nouveautés côté membre (lecture des notes publiées +
     * marquage « vu »). `release_note` est GLOBALE ; seule la surface est décrite.
     *
     * @return array<string, PathItem>
     */
    private function releaseNotePaths(): array
    {
        return [
            '/api/release-notes' => new PathItem(get: new Operation(
                operationId: 'getReleaseNotes',
                tags: ['ReleaseNotes'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Published release notes (drafts excluded), newest first, plus how far the member has read', [
                        'type' => 'object',
                        'properties' => [
                            // ISO instant up to which the member marked the journal read, or null (never marked).
                            'seenUpTo' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                // Editorial date (antedatable) — display only, never the "what's new" gate.
                                'date' => ['type' => 'string', 'format' => 'date'],
                                'title' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                // Publication instant — drives the "what's new" modal (publishedAt > seenUpTo).
                                'publishedAt' => ['type' => 'string', 'format' => 'date-time'],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('Unauthorized'),
                ],
                summary: 'Read the published release notes and the member read watermark',
            )),
            '/api/release-notes/seen' => new PathItem(post: new Operation(
                operationId: 'markReleaseNotesSeen',
                tags: ['ReleaseNotes'],
                responses: [
                    '204' => new Response('Read watermark set to now (self-only) — no body'),
                    '401' => new Response('Unauthorized'),
                ],
                summary: 'Mark the release-notes journal read up to now (self-only)',
            )),
        ];
    }

    /**
     * Member feedback channel: any authenticated member reports a bug, a missing
     * constraint or an idea. Heavy context (a named schedule's snapshot +
     * diagnostics) is copied server-side under the tenant filter.
     *
     * @return array<string, PathItem>
     */
    private function feedbackPaths(): array
    {
        // Même corps, deux portes : JSON (chemin historique) OU multipart/form-data quand une
        // capture d'écran est jointe — le JSON passe alors dans le champ `payload`, la capture
        // dans le fichier `screenshot` (sondé serveur : PNG/JPEG/WebP, ≤ 2 Mo).
        $bodySchema = [
            'type' => 'object',
            'required' => ['topic', 'message'],
            'properties' => [
                'topic' => ['type' => 'string', 'enum' => ['bug', 'missing_constraint', 'idea']],
                'message' => ['type' => 'string', 'maxLength' => 5000],
                // Optional light context. When `scheduleId` names a schedule of
                // the current club, the server copies its snapshot + diagnostics
                // into the stored context; a foreign/unknown id is a 404.
                'context' => ['type' => 'object', 'properties' => [
                    'screen' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'scheduleId' => ['type' => 'string', 'format' => 'uuid'],
                    'requestId' => ['type' => 'string'],
                    'userAgent' => ['type' => 'string'],
                    // Repères TEXTE joints par le client pour situer l'incident (jamais d'id).
                    'screenLabel' => ['type' => 'string'],
                    'activeFilters' => ['type' => 'string'],
                    'displayedRange' => ['type' => 'string'],
                    // Version du bundle front déclarée par le client (bornée serveur à 40).
                    'appVersion' => ['type' => 'string', 'maxLength' => 40],
                    'lastClick' => ['type' => 'string'],
                    'incidentStatus' => ['type' => 'string'],
                    'incidentUrl' => ['type' => 'string'],
                    'incidentCode' => ['type' => 'string'],
                ]],
            ],
        ];

        return [
            '/api/feedback' => new PathItem(post: new Operation(
                operationId: 'submitFeedback',
                tags: ['Feedback'],
                responses: [
                    '201' => $this->schemas->jsonResponse('Feedback stored; an acknowledgement email is queued to the author', [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string', 'format' => 'uuid']],
                    ]),
                    '401' => new Response('Unauthorized'),
                    '404' => new Response('No club in context, or the referenced schedule is unknown/foreign (nothing is stored)'),
                    '422' => new Response('Invalid topic, empty message, message too long, or an invalid screenshot (type/size/dimensions) — nothing is stored'),
                    '429' => new Response('Too many feedback submissions (per-user rate limit)'),
                ],
                summary: 'Submit a member feedback report (bug, missing constraint or idea)',
                requestBody: new RequestBody(content: new ArrayObject([
                    'application/json' => ['schema' => $bodySchema],
                    'multipart/form-data' => ['schema' => [
                        'type' => 'object',
                        'required' => ['payload'],
                        'properties' => [
                            'payload' => ['type' => 'string', 'description' => 'JSON-encoded feedback body (same shape as the application/json request)'],
                            'screenshot' => ['type' => 'string', 'format' => 'binary', 'description' => 'Optional screenshot (PNG/JPEG/WebP, max 2 MB, type sniffed server-side; SVG and non-images rejected)'],
                        ],
                    ]],
                ])),
            )),
        ];
    }
}
