<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Public token-addressed pages (coach-wish, club-approval) custom routes. */
final readonly class PublicTokenPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->publicTokenPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * Les pages PUBLIQUES à token : approbation de club et doléances coach.
     *
     * ⚠ **Le token EST l'identité** — il n'y a pas de JWT sur ces pages. Ne cherchez pas ça
     * dans l'absence de `security` sur l'opération : ce document déclare `security: []` au
     * GLOBAL et aucune opération ne porte de scheme, authentifiée ou non — l'absence n'y
     * distingue donc rien. Ce que le contrat doit porter, sous peine d'induire un client en
     * erreur, ce sont les trois conséquences OBSERVABLES : le 404 est BYTE-IDENTIQUE pour un
     * token inconnu, malformé ou déjà consommé (rien ne distingue une ressource close d'un
     * token inventé) ; le rate-limit par IP passe AVANT toute résolution, donc un 429 ne dit
     * rien de l'existence du token ; et l'expiration répond 410, seul code de cette famille
     * qui admette l'existence de la ressource — elle ne fuite rien, le lien ayant été envoyé
     * à son destinataire. L'accès réel se lit dans `config/packages/security.yaml`
     * (`^/api/coach-wishes/public/` et `^/api/club-approvals/` en `PUBLIC_ACCESS`, GET+POST).
     *
     * @return array<string, PathItem>
     */
    private function publicTokenPaths(): array
    {
        $tokenParameter = [
            'name' => 'token',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            'description' => 'The raw 64-hex token from the emailed link — it IS the identity',
        ];
        $notFound = new Response('Not found — identical for an unknown, malformed or already-consumed token');
        $tooMany = new Response('Too many attempts from this IP (rate limited before any resolution)');

        return [
            '/api/club-approvals/{token}' => new PathItem(
                get: new Operation(
                    operationId: 'getPublicClubApproval',
                    tags: ['PublicToken'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('What the institutional recipient must decide on', [
                            'type' => 'object',
                            'properties' => [
                                'clubName' => ['type' => 'string'],
                                'ara' => ['type' => 'string'],
                                'requesterName' => ['type' => 'string'],
                                'expiresAt' => ['type' => 'string', 'format' => 'date'],
                            ],
                        ]),
                        '404' => $notFound,
                        '410' => new Response('The request expired — the super-admin console remains the fallback'),
                        '429' => $tooMany,
                    ],
                    summary: 'Read a pending club-creation request from its public approval link (no account, no JWT)',
                    parameters: [$tokenParameter],
                ),
                post: new Operation(
                    operationId: 'decidePublicClubApproval',
                    tags: ['PublicToken'],
                    responses: [
                        // La décision est UNIQUE : le premier POST gagne, les suivants voient 404.
                        '200' => $this->schemas->jsonResponse('Decision recorded (single-shot — a second call sees the 404)', [
                            'type' => 'object',
                            'properties' => ['status' => ['type' => 'string', 'enum' => ['approved', 'refused']]],
                        ]),
                        '404' => $notFound,
                        '410' => new Response('The request expired'),
                        '422' => new Response('decision must be "approve" or "refuse"'),
                        '429' => $tooMany,
                    ],
                    summary: 'Approve or refuse a club creation from the public link',
                    parameters: [$tokenParameter],
                    requestBody: $this->schemas->jsonBody([
                        'type' => 'object',
                        'required' => ['decision'],
                        'properties' => ['decision' => ['type' => 'string', 'enum' => ['approve', 'refuse']]],
                    ]),
                ),
            ),
            '/api/coach-wishes/public/{token}' => new PathItem(
                get: new Operation(
                    operationId: 'getPublicCoachWish',
                    tags: ['PublicToken'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('The coach perimeter and the current value of its wishes (pre-fill)', [
                            'type' => 'object',
                            'properties' => [
                                'coachFirstName' => ['type' => 'string'],
                                'periodTitle' => ['type' => 'string'],
                                'deadline' => ['type' => 'string', 'format' => 'date'],
                                'weeks' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
                                // Le périmètre du coach SEUL — jamais les équipes d'un autre.
                                'teams' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'id' => ['type' => 'string', 'format' => 'uuid'],
                                    'name' => ['type' => 'string'],
                                ]]],
                                'wishes' => ['type' => 'array', 'items' => $this->coachWishSchema()],
                                'respondedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            ],
                        ]),
                        '404' => $notFound,
                        '410' => new Response('Campaign past its deadline, or its season is archived (read-only)'),
                        '429' => $tooMany,
                    ],
                    summary: 'Read a coach wish form from its public link (no account, no JWT)',
                    parameters: [$tokenParameter],
                ),
                post: new Operation(
                    operationId: 'submitPublicCoachWish',
                    tags: ['PublicToken'],
                    responses: [
                        '200' => $this->schemas->jsonResponse('Wishes upserted (idempotent — the last line of a duplicated team+week wins)', [
                            'type' => 'object',
                            'properties' => ['deadline' => ['type' => 'string', 'format' => 'date']],
                        ]),
                        '404' => $notFound,
                        '410' => new Response('Campaign past its deadline, or its season is archived (read-only)'),
                        // Validation COMPLÈTE avant toute écriture : un 422 n'écrit rien.
                        '422' => new Response('Missing/oversized submissions, team outside the perimeter, week outside the collection, or invalid slot/day'),
                        '429' => $tooMany,
                    ],
                    summary: 'Submit the coach wishes for the weeks of the campaign',
                    parameters: [$tokenParameter],
                    requestBody: $this->schemas->jsonBody([
                        'type' => 'object',
                        'required' => ['submissions'],
                        'properties' => [
                            'submissions' => ['type' => 'array', 'items' => $this->coachWishSchema()],
                        ],
                    ]),
                ),
            ),
        ];
    }

    /**
     * La forme d'une doléance — LUE au pré-remplissage et ÉCRITE à la soumission. Une seule
     * définition : les deux sens partagent réellement la même forme (`PublicCoachWishController`
     * lit ce qu'il vient d'écrire), et deux copies divergeraient au premier champ ajouté.
     *
     * @return array<string, mixed>
     */
    private function coachWishSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['teamId', 'weekStart'],
            'properties' => [
                'teamId' => ['type' => 'string', 'format' => 'uuid'],
                'weekStart' => ['type' => 'string', 'format' => 'date', 'description' => 'Must be one of the campaign weeks AND still intersect the parent period'],
                'slotsWanted' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 7],
                'unavailableDays' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7]],
                'comment' => ['type' => ['string', 'null'], 'maxLength' => 2000],
            ],
        ];
    }
}
