<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\Enum\AdminJobStatus;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Super-admin support custom routes. */
final readonly class AdminSupportPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->adminSupportPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * SA4 — le catalogue d'actions support et les deux portes de déblocage (P3-4 PR B).
     *
     * ⚠ Les 404 de cette famille sont VOLONTAIREMENT indistincts : action inconnue, uuid
     * malformé et club inexistant rendent la même réponse (`AdminClubActionController:84-99`).
     * Documenter trois codes distincts inviterait un client à croire qu'il peut les
     * discriminer — et un jour quelqu'un l'implémenterait côté serveur pour « respecter le
     * contrat ».
     *
     * @return array<string, PathItem>
     */
    private function adminSupportPaths(): array
    {
        $csrfHeader = ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']];
        $decisionBody = $this->schemas->jsonBody([
            'type' => 'object',
            'required' => ['decision'],
            'properties' => ['decision' => ['type' => 'string', 'enum' => ['approve', 'refuse']]],
        ]);

        return [
            '/api/admin/actions' => new PathItem(get: new Operation(
                operationId: 'getAdminActions',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The CLOSED catalogue of support actions (never an arbitrary command)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'key' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                // Le client DOIT exiger une confirmation nominative dessus.
                                'dangerous' => ['type' => 'boolean'],
                                // Schéma FERMÉ des arguments runtime : le client rend ses pickers DEPUIS
                                // cette liste (choix + présence), jamais d'une liste en dur. `gate`
                                // présent = argument conditionnel (masqué quand le gate ∈ forbiddenValues).
                                'arguments' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'key' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'required' => ['type' => 'boolean'],
                                    'choices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                        'value' => ['type' => 'string'],
                                        'label' => ['type' => 'string'],
                                    ]]],
                                    'gate' => ['type' => 'object', 'properties' => [
                                        'argument' => ['type' => 'string'],
                                        'forbiddenValues' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    ]],
                                ]]],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List the closed catalogue of per-club support actions',
            )),
            '/api/admin/clubs/{clubId}/actions/{key}' => new PathItem(post: new Operation(
                operationId: 'runAdminClubAction',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The action ran and its command exited 0', [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'clubId' => ['type' => 'string', 'format' => 'uuid'],
                            'status' => ['type' => 'string', 'enum' => [AdminJobStatus::SUCCEEDED->value]],
                            'exitCode' => ['type' => 'integer', 'enum' => [0]],
                        ],
                    ]),
                    '400' => new Response('The optional argument body violated the action\'s closed schema: unknown key, value outside the enum, a required argument missing, a forbidden argument present, or any body on a schema-less action'),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Unknown action key, malformed club id, or unknown club (deliberately indistinct)'),
                    '409' => new Response('The same action (or its scheduled twin) is already running'),
                    '500' => new Response('Unexpected execution failure'),
                    '502' => new Response('The command returned a non-zero exit code (body carries exitCode)'),
                ],
                summary: 'Run one catalogue support action against one club (audited, locked, historised)',
                parameters: [
                    // Aucun `requirements` de route sur clubId côté Symfony, délibérément : un
                    // 404 AU ROUTEUR précéderait le firewall et renseignerait un probe anonyme
                    // sans laisser de trace. Le format est donc décrit ici, validé en controller.
                    ['name' => 'clubId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    $csrfHeader,
                ],
                // Body OPTIONNEL : présent seulement pour une action à schéma (ex. set-plan →
                // {plan, paidSeason}). Les clés/valeurs autorisées sont servies par GET /actions ;
                // toute dérive est un 400 fail-closed, jamais un argument libre vers la commande.
                requestBody: $this->schemas->jsonBody([
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => 'Enum-valued arguments bounded by the action\'s closed schema (see GET /api/admin/actions). Empty/absent for schema-less actions.',
                ]),
            )),
            '/api/admin/club-requests' => new PathItem(get: new Operation(
                operationId: 'getAdminClubRequests',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Club-creation requests still actionable — PENDING and EXPIRED alike (an expired one leaves the public link, not the console)', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'clubName' => ['type' => 'string'],
                                'ara' => ['type' => 'string'],
                                'clubEmail' => ['type' => ['string', 'null']],
                                'status' => ['type' => 'string', 'enum' => ['pending', 'expired']],
                                'requesterName' => ['type' => 'string'],
                                'requesterEmail' => ['type' => 'string'],
                                'createdAt' => ['type' => 'string', 'format' => 'date'],
                                'expiresAt' => ['type' => 'string', 'format' => 'date'],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List the club-creation requests awaiting a decision',
            )),
            '/api/admin/club-requests/{id}/decision' => new PathItem(post: new Operation(
                operationId: 'decideAdminClubRequest',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Decision recorded — `clubId` is present on approve only', [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['approved', 'refused']],
                            'clubId' => ['type' => 'string', 'format' => 'uuid'],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    '404' => new Response('Unknown request, malformed id, or already decided'),
                    '422' => new Response('decision must be "approve" or "refuse"'),
                ],
                summary: 'Approve or refuse a club-creation request from the console (fallback when the FFBB mail path is dead)',
                parameters: [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    $csrfHeader,
                ],
                requestBody: $decisionBody,
            )),
            '/api/admin/pending-memberships' => new PathItem(get: new Operation(
                operationId: 'getAdminPendingMemberships',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Memberships awaiting approval, all clubs', [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'clubName' => ['type' => 'string'],
                                'ara' => ['type' => ['string', 'null']],
                                'userName' => ['type' => 'string'],
                                'userEmail' => ['type' => 'string'],
                                'createdAt' => ['type' => 'string', 'format' => 'date'],
                            ]]],
                        ],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                ],
                summary: 'List the pending club memberships across all clubs',
            )),
            '/api/admin/pending-memberships/{id}/activate' => new PathItem(post: new Operation(
                operationId: 'activateAdminPendingMembership',
                tags: ['AdminSupport'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Membership activated', [
                        'type' => 'object',
                        'properties' => ['status' => ['type' => 'string', 'enum' => ['activated']]],
                    ]),
                    '401' => new Response('No authenticated super-admin session'),
                    '403' => new Response('Invalid CSRF token'),
                    // Un membre DÉSACTIVÉ par son club tombe ici aussi : cette porte n'ouvre
                    // que le PENDING, jamais une réactivation (elle contournerait le club).
                    '404' => new Response('Unknown, malformed, already active, or deactivated-by-the-club membership'),
                ],
                summary: 'Activate a pending membership when the club hand-over never happened',
                parameters: [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    $csrfHeader,
                ],
            )),
        ];
    }
}
