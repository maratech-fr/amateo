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
 * Aperçu coach par coach d'une campagne de collecte (feature #10, lot D2) — route custom
 * `#[Route]` (CoachWishCampaignPreviewController), donc à déclarer ici pour qu'elle figure au
 * contrat (EveryCustomRouteIsDocumentedTest). Authentifiée (firewall gestionnaire, SEC-07) :
 * elle rend la MÊME forme que le GET public, vidée des données du coach.
 */
final readonly class CoachWishPreviewPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/coach_wish_campaigns/{id}/preview', new PathItem(
            get: new Operation(
                operationId: 'previewCoachWishCampaign',
                tags: ['CoachWishCampaign'],
                responses: [
                    '200' => $this->schemas->jsonResponse('The real coach-facing form as the chosen coach will see it — read-only preview, with no wishes/mutualizations and respondedAt null', [
                        'type' => 'object',
                        'properties' => [
                            'coachFirstName' => ['type' => 'string'],
                            'periodTitle' => ['type' => 'string'],
                            'periodStart' => ['type' => ['string', 'null'], 'format' => 'date'],
                            'periodEnd' => ['type' => ['string', 'null'], 'format' => 'date'],
                            'deadline' => ['type' => 'string', 'format' => 'date'],
                            'weeks' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                            'teams' => ['type' => 'array', 'items' => $this->teamRef()],
                            'partnerTeams' => ['type' => 'array', 'items' => $this->teamRef()],
                            'teamLinks' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'teamAId' => ['type' => 'string', 'format' => 'uuid'],
                                'teamBId' => ['type' => 'string', 'format' => 'uuid'],
                            ]]],
                            // Toujours vides en aperçu (le gestionnaire voit le formulaire, pas les réponses du coach).
                            'wishes' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'mutualizations' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'respondedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        ],
                    ]),
                    // Réservé aux gestionnaires (SEC-07) — un membre non gestionnaire est refusé.
                    '403' => new Response('The caller is not a manager of the current club'),
                    // Campagne d'un autre club, campagne absente, ou coach hors du périmètre : BYTE-IDENTIQUE.
                    '404' => new Response('Campaign not found (another club or absent) or coach outside the campaign perimeter — byte-identical'),
                ],
                summary: 'Preview the coach wish form as a chosen coach of the campaign will see it (manager-only, read-only)',
                parameters: [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ['name' => 'coachId', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid'], 'description' => 'A coach of the campaign perimeter to preview'],
                ],
            ),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function teamRef(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'name' => ['type' => 'string'],
        ]];
    }
}
