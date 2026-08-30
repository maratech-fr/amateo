<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Super-admin audit-journal custom routes. */
final readonly class AdminJournalPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->adminJournalPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * Les trois journaux read-only de la console (livrés avec les onglets, 2026-07-25).
     *
     * @return array<string, PathItem>
     */
    private function adminJournalPaths(): array
    {
        $pageParameters = [
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
        ];

        return [
            '/api/admin/audit-log' => new PathItem(get: new Operation(
                operationId: 'getAdminAuditLog',
                tags: ['AdminJournal'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Paginated super-admin audit trail (who did what on the cross-tenant surface)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                'actorId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                                'actorEmail' => ['type' => ['string', 'null']],
                                'route' => ['type' => ['string', 'null']],
                                // `details` du modèle : le contexte fusionné par
                                // AdminAuditSubscriber (clubId, actionKey…), forme libre.
                                'context' => ['type' => 'object', 'additionalProperties' => true],
                                'status' => ['type' => ['integer', 'null']],
                                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                            ]]],
                            'pagination' => $this->paginationSchema(),
                        ],
                    ]),
                    '400' => new Response('Invalid page, limit, actor UUID or since date'),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the paginated super-admin audit log',
                parameters: [
                    ...$pageParameters,
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
                    ['name' => 'actor', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ['name' => 'route', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Substring match on the audited route'],
                    ['name' => 'since', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'ISO date (YYYY-MM-DD)'],
                ],
            )),
            '/api/admin/messenger/failed' => new PathItem(get: new Operation(
                operationId: 'getAdminMessengerFailed',
                tags: ['AdminJournal'],
                responses: [
                    // ⚠ JAMAIS le body du message — il porte de la donnée club (PII). Seuls la
                    // classe, l'horodatage et le message d'erreur sortent.
                    '200' => $this->schemas->jsonResponse('Paginated Messenger failure queue — metadata only, never the message body (PII)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                'class' => ['type' => 'string', 'description' => 'Message FQCN, or "(classe inconnue)" when the class no longer exists in the code'],
                                'failedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'lastErrorMessage' => ['type' => 'string'],
                            ]]],
                            'pagination' => $this->paginationSchema(),
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List the failed Messenger envelopes (metadata only)',
                parameters: [
                    ...$pageParameters,
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10]],
                ],
            )),
            '/api/admin/system-errors' => new PathItem(get: new Operation(
                operationId: 'getAdminSystemErrors',
                tags: ['AdminJournal'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Paginated union of failed job runs and auth failures, deduplicated per (message, hour)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'source' => ['type' => 'string', 'enum' => ['job', 'audit']],
                                'message' => ['type' => 'string'],
                                'severity' => ['type' => 'string', 'enum' => ['error']],
                                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                            ]]],
                            'pagination' => $this->paginationSchema(),
                        ],
                    ]),
                    '400' => new Response('Invalid page, limit or since date'),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the deduplicated system-error journal',
                parameters: [
                    ...$pageParameters,
                    ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
                    ['name' => 'since', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'ISO date (YYYY-MM-DD)'],
                ],
            )),
        ];
    }

    /** @return array<string, mixed> */
    private function paginationSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'page' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
        ]];
    }
}
