<?php

declare(strict_types=1);

namespace App\OpenApi\PathContributor;

use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use App\OpenApi\CustomPathContributor;
use App\OpenApi\OpenApiSchemas;

/** Holiday (school-vacation) custom routes. */
final readonly class HolidayPaths implements CustomPathContributor
{
    public function __construct(private OpenApiSchemas $schemas) {}

    public function contribute(Paths $paths): void
    {
        foreach ($this->holidayPaths() as $path => $pathItem) {
            $paths->addPath($path, $pathItem);
        }
    }

    /**
     * @return array<string, PathItem>
     */
    private function holidayPaths(): array
    {
        $windowParameters = [
            ['name' => 'from', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Window start (YYYY-MM-DD) — defaults to the active season start'],
            ['name' => 'to', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Window end (YYYY-MM-DD) — defaults to the active season end'],
        ];

        return [
            // Statistiques d'utilisation des gymnases : lecture pure, agrégée SERVEUR (le front
            // n'agrège aucune règle métier). Le total par JOUR est le chiffre que le gestionnaire
            // porte devant sa mairie pour négocier ses créneaux.
            '/api/venue-usage-stats' => new PathItem(get: new Operation(
                operationId: 'getVenueUsageStats',
                tags: ['Clubs'],
                responses: [
                    '200' => $this->schemas->jsonResponse('Heures par gymnase et par niveau, ventilées par jour de semaine, réalisées (≤ aujourd\'hui) vs à venir', [
                        'type' => 'object',
                        'properties' => [
                            'range' => ['type' => 'object', 'properties' => [
                                'from' => ['type' => 'string', 'format' => 'date'],
                                'to' => ['type' => 'string', 'format' => 'date'],
                                'today' => ['type' => 'string', 'format' => 'date'],
                            ]],
                            'zone' => ['type' => 'string', 'nullable' => true, 'description' => 'Zone scolaire du club (null → les vacances ne neutralisent rien)'],
                            'venues' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'venueId' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'byDay' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'day' => ['type' => 'integer', 'description' => 'Jour ISO (1 = lundi)'],
                                    'real' => ['type' => 'number'],
                                    'projected' => ['type' => 'number'],
                                    'total' => ['type' => 'number'],
                                ]]],
                                'real' => ['type' => 'number'],
                                'projected' => ['type' => 'number'],
                                'total' => ['type' => 'number'],
                            ]]],
                            'totalByDay' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'day' => ['type' => 'integer'],
                                'real' => ['type' => 'number'],
                                'projected' => ['type' => 'number'],
                                'total' => ['type' => 'number'],
                            ]]],
                            'byLevel' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'level' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'byDay' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                    'day' => ['type' => 'integer'],
                                    'real' => ['type' => 'number'],
                                    'projected' => ['type' => 'number'],
                                    'total' => ['type' => 'number'],
                                ]]],
                                'real' => ['type' => 'number'],
                                'projected' => ['type' => 'number'],
                                'total' => ['type' => 'number'],
                            ]]],
                            'grandTotal' => ['type' => 'object', 'properties' => [
                                'real' => ['type' => 'number'],
                                'projected' => ['type' => 'number'],
                                'total' => ['type' => 'number'],
                            ]],
                        ],
                    ]),
                    '400' => new Response('No club in context, or invalid from/to, or no window (no active season)'),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                ],
                summary: 'Heures d\'utilisation des gymnases par jour (réalisées vs à venir), et par niveau',
                parameters: $windowParameters,
            )),
            '/api/school-holidays' => new PathItem(get: new Operation(
                operationId: 'getSchoolHolidays',
                tags: ['Calendars'],
                responses: [
                    '200' => $this->schemas->jsonResponse('School holidays of the club zone within the window (zone null → empty items)', [
                        'type' => 'object',
                        'properties' => [
                            'zone' => ['type' => 'string', 'nullable' => true],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'holidayType' => ['type' => 'string'],
                                'startDate' => ['type' => 'string', 'format' => 'date'],
                                'endDate' => ['type' => 'string', 'format' => 'date'],
                                'schoolYear' => ['type' => 'string'],
                            ]]],
                        ],
                    ]),
                    '400' => new Response('No club in context, or (when the club zone is set) invalid from/to or no window (no active season) — a null zone short-circuits to 200 with empty items'),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                ],
                summary: 'School holidays of the club academic zone (display feed, read-only)',
                parameters: $windowParameters,
            )),
            '/api/public-holidays' => new PathItem(get: new Operation(
                operationId: 'getPublicHolidays',
                tags: ['Calendars'],
                responses: [
                    '200' => $this->schemas->jsonResponse('NATIONAL public holidays ∪ the club territory extras within the window (zone null → NATIONAL only)', [
                        'type' => 'object',
                        'properties' => [
                            'zone' => ['type' => 'string', 'nullable' => true],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                'id' => ['type' => 'string'],
                                'date' => ['type' => 'string', 'format' => 'date'],
                                'label' => ['type' => 'string'],
                                'national' => ['type' => 'boolean'],
                            ]]],
                        ],
                    ]),
                    '400' => new Response('No club in context, invalid from/to, or no window (no active season) — a null zone still returns the NATIONAL fériés (no short-circuit)'),
                    '401' => new Response('Unauthorized (missing/expired JWT)'),
                ],
                summary: 'Public holidays (jours fériés) applying to the club (display-only, never feeds the solver)',
                parameters: $windowParameters,
            )),
        ];
    }
}
