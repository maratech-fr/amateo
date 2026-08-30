<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Super-admin authentication custom routes (separate stateful firewall). */
final readonly class AdminAuthPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->adminAuthPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * @return array<string, PathItem>
     */
    private function adminAuthPaths(): array
    {
        $credentialsBody = $this->schemas->jsonBody([
            'type' => 'object',
            'required' => ['email', 'password'],
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
                'password' => ['type' => 'string'],
            ],
        ]);

        return [
            '/api/admin/auth/password' => new PathItem(post: new Operation(
                operationId: 'postAdminAuthPassword',
                tags: ['AdminAuth'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Password accepted; TOTP challenge required within five minutes', [
                        'type' => 'object',
                        'properties' => ['mfaRequired' => ['type' => 'boolean', 'enum' => [true]]],
                    ]),
                    '401' => new Response('Invalid credentials'),
                    '429' => new Response('Too many attempts (per-IP rate limit)'),
                ],
                summary: 'Start the separate super-admin authentication flow',
                requestBody: $credentialsBody,
            )),
            '/api/admin/auth/totp' => new PathItem(post: new Operation(
                operationId: 'postAdminAuthTotp',
                tags: ['AdminAuth'],
                responses: [
                    '200' => $this->schemas->jsonResponse('MFA accepted; stateful admin session created', [
                        'type' => 'object',
                        'properties' => [
                            'authenticated' => ['type' => 'boolean', 'enum' => [true]],
                            'csrfToken' => ['type' => 'string'],
                        ],
                    ]),
                    '401' => new Response('Invalid or expired authentication challenge'),
                    '429' => new Response('Too many attempts (per-IP rate limit)'),
                ],
                summary: 'Complete super-admin authentication with the mandatory TOTP code',
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => ['code' => ['type' => 'string', 'pattern' => '^\\d{6}$']],
                ]),
            )),
            '/api/admin/auth/me' => new PathItem(get: new Operation(
                operationId: 'getAdminAuthMe',
                tags: ['AdminAuth'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Authenticated super-admin identity', [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'email' => ['type' => 'string', 'format' => 'email'],
                            'csrfToken' => ['type' => 'string'],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'Hydrate the separate super-admin session',
            )),
            '/api/admin/auth/logout' => new PathItem(post: new Operation(
                operationId: 'postAdminAuthLogout',
                tags: ['AdminAuth'],
                responses: [
                    '204' => new Response('Super-admin session invalidated'),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Missing or invalid X-CSRF-Token header'),
                ],
                summary: 'Invalidate the super-admin session (CSRF-protected)',
                parameters: [[
                    'name' => 'X-CSRF-Token',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ]],
            )),
        ];
    }
}
