<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/**
 * Console démo (PR B du lot Démos) — pilotage des deux comptes de démonstration.
 *
 * ⚠ Les 404 de `{target}` sont VOLONTAIREMENT validés EN controller (après CSRF+session),
 * jamais par un `requirements` de route : un 404 au routeur précéderait le firewall admin.
 * Le format de `target` est donc décrit ici, pas imposé au routage.
 */
final readonly class AdminDemoPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->adminDemoPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /** @return array<string, PathItem> */
    private function adminDemoPaths(): array
    {
        $csrfHeader = ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
        $targetParam = ['name' => 'target', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['bccl', 'prospect']]];
        $account = [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string'],
                'activeUntil' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'ISO UTC — the activation window end, or null when closed. Rendered at Europe/Paris time by the console.'],
                'clubName' => ['type' => ['string', 'null']],
                'demoToday' => ['type' => ['string', 'null'], 'format' => 'date', 'description' => 'Simulated clock (bccl only).'],
            ],
        ];

        return [
            '/api/admin/demos' => new PathItem(get: new Operation(
                operationId: 'getAdminDemos',
                tags: ['AdminDemo'],
                responses: [
                    '200' => $this->schemas->jsonResponse('State of the two demo accounts (bccl carries the simulated clock)', [
                        'type' => 'object',
                        'properties' => ['bccl' => $account, 'prospect' => $account],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Read the state of the two demonstration accounts',
            )),
            '/api/admin/demos/{target}/activate' => new PathItem(post: new Operation(
                operationId: 'activateAdminDemo',
                tags: ['AdminDemo'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The activation window was opened for 4 hours (real clock); re-clicking restarts it, never adds up', [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string', 'enum' => ['bccl', 'prospect']],
                            'activeUntil' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Unknown demo target, or the demo account is missing'),
                ],
                summary: 'Open a 4-hour activation window on a demo account',
                parameters: [$targetParam, $csrfHeader],
            )),
            '/api/admin/demos/{target}/deactivate' => new PathItem(post: new Operation(
                operationId: 'deactivateAdminDemo',
                tags: ['AdminDemo'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The activation window was closed', [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string', 'enum' => ['bccl', 'prospect']],
                            'activeUntil' => ['type' => 'null'],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Unknown demo target, or the demo account is missing'),
                ],
                summary: 'Close the activation window on a demo account',
                parameters: [$targetParam, $csrfHeader],
            )),
            '/api/admin/demos/bccl/reset' => new PathItem(post: new Operation(
                operationId: 'resetAdminDemoBccl',
                tags: ['AdminDemo'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The BCCL demo was re-seeded and its simulated clock reset to today', [
                        'type' => 'object',
                        'properties' => ['status' => ['type' => 'string', 'enum' => ['reset']]],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '502' => new Response('The re-seed sub-process failed'),
                ],
                summary: 'Reset the permanent BCCL demonstration (re-seed + clear the simulated clock)',
                parameters: [$csrfHeader],
            )),
            '/api/admin/demos/bccl/clock' => new PathItem(post: new Operation(
                operationId: 'setAdminDemoClock',
                tags: ['AdminDemo'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The simulated clock was set (or cleared)', [
                        'type' => 'object',
                        'properties' => ['demoToday' => ['type' => ['string', 'null'], 'format' => 'date']],
                    ]),
                    '400' => new Response('Malformed body: not exactly one of a real YYYY-MM-DD date or clear:true'),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('The BCCL demo club is absent'),
                ],
                summary: 'Set or clear the simulated clock of the BCCL demonstration club',
                parameters: [$csrfHeader],
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'format' => 'date', 'description' => 'Simulated today, YYYY-MM-DD.'],
                        'clear' => ['type' => 'boolean', 'description' => 'Release the simulated clock (back to real time).'],
                    ],
                ]),
            )),
        ];
    }
}
