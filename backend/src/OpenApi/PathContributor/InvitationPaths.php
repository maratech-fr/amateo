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
 * P4-299 — les routes custom d'invitation : la gestion côté gestionnaire (`/api/invitations…`,
 * authentifiée, gate management) et la page publique à jeton (`/api/invitations/public/{token}`,
 * pas de compte). Déclarées ici pour apparaître dans `/api/docs` et le snapshot de contrat
 * (EveryCustomRouteIsDocumentedTest confronte factory ⇄ routeur dans les deux sens).
 *
 * ⚠ Le jeton public EST l'identité : 404 BYTE-IDENTIQUE pour un jeton inconnu, malformé, EXPIRÉ
 * ou révoqué (rien ne distingue une invitation morte d'un jeton inventé) ; rate-limit par IP
 * AVANT toute résolution (un 429 ne dit rien de l'existence). L'accès réel se lit dans
 * `config/packages/security.yaml` (`^/api/invitations/public/` en PUBLIC_ACCESS, GET+POST).
 */
final readonly class InvitationPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->invitationPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /** @return array<string, PathItem> */
    private function invitationPaths(): array
    {
        $forbidden = new Response('The caller is not an active manager of the club');
        $invitation = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'role' => ['type' => 'string', 'enum' => ['admin', 'member']],
                'expiresAt' => ['type' => 'string', 'format' => 'date'],
            ],
        ];
        $idParameter = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string', 'format' => 'uuid'],
            'description' => 'The invitation id (management routes)',
        ];
        $tokenParameter = [
            'name' => 'token',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            'description' => 'The raw 64-hex token from the emailed link — it IS the identity',
        ];
        $notFound = new Response('Not found — identical for an unknown, malformed, expired or revoked token');
        $tooMany = new Response('Too many attempts from this IP (rate limited before any resolution)');

        return [
            '/api/invitations' => new PathItem(
                get: new Operation(
                    operationId: 'listClubInvitations',
                    tags: ['Invitation'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('The pending invitations of the manager\'s club', [
                            'type' => 'object',
                            'properties' => ['invitations' => ['type' => 'array', 'items' => $invitation]],
                        ]),
                        '403' => $forbidden,
                    ],
                    summary: 'List the invitations of the current club (active manager only)',
                ),
                post: new Operation(
                    operationId: 'createClubInvitation',
                    tags: ['Invitation'],
                    responses: [
                        '201' => $this->schemas->jsonResponse('Invitation created (or an existing one for the same email renewed)', $invitation),
                        '403' => new Response('Not an active manager, or a demo manager (demo accounts cannot invite)'),
                        '422' => new Response('Invalid email/role, an address that cannot be invited (demo), or already an active member of this club'),
                        '429' => new Response('Too many invitations sent (per-user rate limit)'),
                    ],
                    summary: 'Invite an email address to join the club at a role (default Member)',
                    requestBody: $this->schemas->jsonBody([
                        'type' => 'object',
                        'required' => ['email'],
                        'properties' => [
                            'email' => ['type' => 'string', 'format' => 'email'],
                            'role' => ['type' => 'string', 'enum' => ['admin', 'member'], 'description' => 'Default member'],
                        ],
                    ]),
                ),
            ),
            '/api/invitations/{id}/resend' => new PathItem(
                post: new Operation(
                    operationId: 'resendClubInvitation',
                    tags: ['Invitation'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('Invitation renewed (fresh token, pushed-back expiry) and re-emailed', $invitation),
                        '403' => new Response('Not an active manager, or a demo manager'),
                        '404' => new Response('Invitation not found in the manager\'s club'),
                        '429' => new Response('Too many invitations sent (per-user rate limit)'),
                    ],
                    summary: 'Regenerate and re-send an invitation link',
                    parameters: [$idParameter],
                ),
            ),
            '/api/invitations/{id}' => new PathItem(
                delete: new Operation(
                    operationId: 'revokeClubInvitation',
                    tags: ['Invitation'],
                    responses: [
                        '204' => new Response('Invitation revoked — its link now returns the identical 404'),
                        '403' => $forbidden,
                        '404' => new Response('Invitation not found in the manager\'s club'),
                    ],
                    summary: 'Revoke an invitation (deletes the row, kills the link)',
                    parameters: [$idParameter],
                ),
            ),
            '/api/invitations/{token}/accept' => new PathItem(
                post: new Operation(
                    operationId: 'acceptClubInvitationAsConnectedUser',
                    tags: ['Invitation'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('Accepted — active membership at the invited role', [
                            'type' => 'object',
                            'properties' => [
                                'membershipStatus' => ['type' => 'string', 'enum' => ['active']],
                                'clubId' => ['type' => 'string', 'format' => 'uuid'],
                            ],
                        ]),
                        '401' => new Response('Not authenticated'),
                        '403' => new Response('The connected account\'s email is not the invited address'),
                        '404' => $notFound,
                    ],
                    summary: 'Accept an invitation as the connected user (one click)',
                    parameters: [$tokenParameter],
                ),
            ),
            '/api/invitations/public/{token}' => new PathItem(
                get: new Operation(
                    operationId: 'getPublicClubInvitation',
                    tags: ['Invitation'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('What the invited person must decide on (no account, no JWT)', [
                            'type' => 'object',
                            'properties' => [
                                'clubName' => ['type' => 'string'],
                                'email' => ['type' => 'string', 'format' => 'email'],
                                'role' => ['type' => 'string', 'enum' => ['admin', 'member']],
                                'hasAccount' => ['type' => 'boolean', 'description' => 'A verified account exists for the invited address (drives log-in vs create)'],
                            ],
                        ]),
                        '404' => $notFound,
                        '429' => $tooMany,
                    ],
                    summary: 'Read an invitation from its public link (no account, no JWT)',
                    parameters: [$tokenParameter],
                ),
            ),
            '/api/invitations/public/{token}/accept' => new PathItem(
                post: new Operation(
                    operationId: 'acceptPublicClubInvitation',
                    tags: ['Invitation'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('Account created (verified) + active membership; JWT set as an httpOnly cookie', [
                            'type' => 'object',
                            'properties' => [
                                'membershipStatus' => ['type' => 'string', 'enum' => ['active']],
                                'user' => ['type' => 'object', 'properties' => [
                                    'id' => ['type' => 'string', 'format' => 'uuid'],
                                    'email' => ['type' => 'string', 'format' => 'email'],
                                ]],
                            ],
                        ]),
                        '404' => $notFound,
                        '409' => new Response('An account already exists for this address — log in to accept'),
                        '422' => new Response('Missing first/last name, weak password, or missing consent'),
                        '429' => $tooMany,
                    ],
                    summary: 'Accept an invitation by creating an account (address locked to the invited one)',
                    parameters: [$tokenParameter],
                    requestBody: $this->schemas->jsonBody([
                        'type' => 'object',
                        'required' => ['firstName', 'lastName', 'password', 'consent'],
                        'properties' => [
                            'firstName' => ['type' => 'string'],
                            'lastName' => ['type' => 'string'],
                            'password' => ['type' => 'string', 'format' => 'password'],
                            'consent' => ['type' => 'boolean', 'description' => 'Acceptance of the terms and privacy policy (account creation)'],
                        ],
                    ]),
                ),
            ),
        ];
    }
}
