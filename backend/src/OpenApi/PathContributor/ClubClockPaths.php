<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\Controller\ClubClockController;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/**
 * Horloge simulée d'un club de DÉMONSTRATION, posée depuis l'app par son gestionnaire
 * (widget d'en-tête, {@see ClubClockController}). Route Symfony tenant, hors
 * API Platform, décrite ici pour entrer au contrat et au snapshot (EveryCustomRouteIsDocumentedTest).
 */
final readonly class ClubClockPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        $paths->addPath('/api/club/clock', new PathItem(post: new Operation(
            operationId: 'setClubClock',
            tags: ['Club'],
            responses: [
                '200' => $this->schemas->jsonResponse('The simulated clock of the current (demo) club was set (or cleared)', [
                    'type' => 'object',
                    'properties' => ['simulatedToday' => ['type' => ['string', 'null'], 'format' => 'date']],
                ]),
                '400' => new Response('Malformed JSON body'),
                '401' => new Response('Unauthorized (missing/expired JWT)'),
                '403' => new Response('Not a management role, or the club is not a demonstration account'),
                '404' => new Response('No club in context'),
                '422' => new Response('Not exactly one of a real YYYY-MM-DD date or clear:true'),
            ],
            summary: 'Set or clear the simulated clock of the current demonstration club',
            requestBody: $this->schemas->jsonBody([
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'format' => 'date', 'description' => 'Simulated today, YYYY-MM-DD.'],
                    'clear' => ['type' => 'boolean', 'description' => 'Release the simulated clock (back to real time).'],
                ],
            ]),
        )));
    }
}
