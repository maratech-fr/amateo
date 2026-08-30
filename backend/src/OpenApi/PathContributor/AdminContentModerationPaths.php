<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Super-admin release-note and feedback moderation custom routes. */
final readonly class AdminContentModerationPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->adminReleaseNotePaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }

        foreach ($this->adminFeedbackPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * P5-12 — l'atelier superadmin du journal : lister (brouillons inclus), créer,
     * éditer, publier, supprimer. Écritures protégées par CSRF (X-CSRF-Token).
     *
     * @return array<string, PathItem>
     */
    private function adminReleaseNotePaths(): array
    {
        $csrfHeader = ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
        $noteSchema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'date' => ['type' => 'string', 'format' => 'date'],
                'publishedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
        $writeBody = $this->schemas->jsonBody([
            'type' => 'object',
            'required' => ['title', 'body', 'noteDate'],
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 160],
                'body' => ['type' => 'string'],
                'noteDate' => ['type' => 'string', 'format' => 'date', 'description' => 'Editorial date (YYYY-MM-DD), antedatable'],
            ],
        ]);
        $idParameter = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];

        return [
            '/api/admin/release-notes' => new PathItem(
                get: new Operation(
                    operationId: 'getAdminReleaseNotes',
                    tags: ['AdminReleaseNotes'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('Every release note, drafts included, newest editorial date first', [
                            'type' => 'object',
                            'properties' => ['items' => ['type' => 'array', 'items' => $noteSchema]],
                        ]),
                        '401' => new Response('No authenticated super-admin session'),
                    ],
                    summary: 'List every release note (drafts included)',
                ),
                post: new Operation(
                    operationId: 'createAdminReleaseNote',
                    tags: ['AdminReleaseNotes'],
                    responses: [
                        '201' => $this->schemas->jsonResponse('Release note created (as a draft — publishedAt null)', $noteSchema),
                        '400' => new Response('Validation error (title required ≤160, body required, invalid date)'),
                        '401' => new Response('No authenticated super-admin session'),
                        '403' => new Response('Invalid CSRF token'),
                    ],
                    summary: 'Create a release note (draft)',
                    parameters: [$csrfHeader],
                    requestBody: $writeBody,
                ),
            ),
            '/api/admin/release-notes/{id}' => new PathItem(
                delete: new Operation(
                    operationId: 'deleteAdminReleaseNote',
                    tags: ['AdminReleaseNotes'],
                    responses: [
                        '204' => new Response('Release note deleted — no body'),
                        '401' => new Response('No authenticated super-admin session'),
                        '403' => new Response('Invalid CSRF token'),
                        '404' => new Response('Note not found'),
                    ],
                    summary: 'Delete a release note',
                    parameters: [$idParameter, $csrfHeader],
                ),
                patch: new Operation(
                    operationId: 'updateAdminReleaseNote',
                    tags: ['AdminReleaseNotes'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('Release note updated', $noteSchema),
                        '400' => new Response('Validation error'),
                        '401' => new Response('No authenticated super-admin session'),
                        '403' => new Response('Invalid CSRF token'),
                        '404' => new Response('Note not found'),
                    ],
                    summary: 'Update a release note (title, body, editorial date)',
                    parameters: [$idParameter, $csrfHeader],
                    requestBody: $writeBody,
                ),
            ),
            '/api/admin/release-notes/{id}/publish' => new PathItem(post: new Operation(
                operationId: 'publishAdminReleaseNote',
                tags: ['AdminReleaseNotes'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Release note published (publishedAt set to now)', $noteSchema),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Note not found'),
                    '409' => new Response('The note is already published'),
                ],
                summary: 'Publish a release note (makes it visible to members)',
                parameters: [$idParameter, $csrfHeader],
            )),
        ];
    }

    /**
     * P5-6 — le rail superadmin du canal de signalement : lister (cross-tenant, +
     * QoS), lire le détail (contexte lourd inclus), marquer traité / non-traité.
     * Écritures protégées par CSRF (X-CSRF-Token).
     *
     * @return array<string, PathItem>
     */
    private function adminFeedbackPaths(): array
    {
        $csrfHeader = ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
        $idParameter = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $listItemSchema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'clubId' => ['type' => 'string', 'format' => 'uuid'],
                'clubName' => ['type' => ['string', 'null']],
                'topic' => ['type' => 'string'],
                'message' => ['type' => 'string'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'status' => ['type' => 'string', 'enum' => ['untreated', 'treated']],
                'treatedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                // The heavy context (schedule snapshot + diagnostics) is NOT returned
                // by the list — only a flag; read the detail to get it.
                'hasHeavyContext' => ['type' => 'boolean'],
            ],
        ];
        $qosSchema = [
            'type' => 'object',
            'properties' => [
                'treatDelayByMonth' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'month' => ['type' => 'string', 'description' => 'YYYY-MM'],
                    'avgHours' => ['type' => 'number', 'nullable' => true],
                    'p95Hours' => ['type' => 'number', 'nullable' => true],
                ]]],
                'volumeByTopicMonth' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'month' => ['type' => 'string', 'description' => 'YYYY-MM'],
                    'topic' => ['type' => 'string'],
                    'count' => ['type' => 'integer'],
                ]]],
                'treatedShare' => ['type' => 'number', 'description' => 'Treated fraction over all reports (0 when none)'],
                'oldestUntreatedAgeHours' => ['type' => 'number', 'nullable' => true],
            ],
        ];
        $writeResult = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'status' => ['type' => 'string', 'enum' => ['untreated', 'treated']],
                'treatedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ];

        return [
            '/api/admin/feedback' => new PathItem(get: new Operation(
                operationId: 'getAdminFeedback',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Paginated cross-tenant feedback list (heavy context excluded) with a quality-of-service block', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => $listItemSchema],
                            'pagination' => ['type' => 'object', 'properties' => [
                                'page' => ['type' => 'integer'],
                                'limit' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                                'pages' => ['type' => 'integer'],
                            ]],
                            'qos' => $qosSchema,
                        ],
                    ]),
                    '400' => new Response('Invalid pagination or status filter'),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List every club feedback report (newest first) with treatment quality-of-service aggregates',
                parameters: [
                    ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
                    ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['untreated', 'treated']]],
                ],
            )),
            '/api/admin/feedback/{id}' => new PathItem(get: new Operation(
                operationId: 'getAdminFeedbackDetail',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('One feedback report with its full stored context (schedule snapshot + diagnostics when present)', [
                        ...$listItemSchema,
                        'properties' => [
                            ...$listItemSchema['properties'],
                            'context' => ['type' => ['object', 'null'], 'description' => 'Full stored context: light fields + the server-copied schedule snapshot and diagnostics when a schedule was named'],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '404' => new Response('Feedback not found'),
                ],
                summary: 'Read one feedback report in full (heavy context included)',
                parameters: [$idParameter],
            )),
            '/api/admin/feedback/{id}/treat' => new PathItem(post: new Operation(
                operationId: 'treatAdminFeedback',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Marked treated (treatedAt set to now); a "handled + thank you" email is queued to the author when still reachable', $writeResult),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Feedback not found'),
                    '409' => new Response('The feedback is already treated'),
                ],
                summary: 'Mark a feedback report treated (emails the author)',
                parameters: [$idParameter, $csrfHeader],
            )),
            '/api/admin/feedback/{id}/untreat' => new PathItem(post: new Operation(
                operationId: 'untreatAdminFeedback',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Reverted to untreated (treatedAt cleared); no email is sent', $writeResult),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Feedback not found'),
                    '409' => new Response('The feedback is not treated'),
                ],
                summary: 'Revert a feedback report to untreated (no email)',
                parameters: [$idParameter, $csrfHeader],
            )),
        ];
    }
}
