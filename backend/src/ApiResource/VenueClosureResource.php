<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\Provider\VenueClosureStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Une fermeture de gymnase du calendrier, en lecture seule : le gymnase, le titre et l'intervalle
 * de dates où il est fermé. Scopé au club et à la saison courants.
 */
// Détail interne (hors texte sérialisé) : fait brut lu via `PlanVenueClosures::closureIntervals`
// (dates du `config`, repli legacy = fenêtre de l'entrée porteuse), jamais la composition
// `VenuePeriodOverride` ; miroité par le front pour griser un gymnase fermé (P4-300).
#[ApiResource(shortName: 'VenueClosure', operations: [
    new GetCollection,
], paginationEnabled: false, provider: VenueClosureStateProvider::class)]
class VenueClosureResource
{
    /** L'id de la contrainte `venue_closed` qui porte la fermeture — identifiant de la ligne. */
    #[ApiProperty(identifier: true)]
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public string $venueId = '';

    /** Le titre saisi par le gestionnaire (affiché dans la sous-ligne du sélecteur de gymnase). */
    #[Groups(['read'])]
    public string $title = '';

    /** Y-m-d, borne INCLUSE. */
    #[Groups(['read'])]
    public string $startDate = '';

    /** Y-m-d, borne INCLUSE. */
    #[Groups(['read'])]
    public string $endDate = '';

    /**
     * @param array{constraintId: string, venueId: string, title: string, startDate: string, endDate: string} $closure
     */
    public static function from(array $closure): self
    {
        $dto = new self;
        $dto->id = $closure['constraintId'];
        $dto->venueId = $closure['venueId'];
        $dto->title = $closure['title'];
        $dto->startDate = $closure['startDate'];
        $dto->endDate = $closure['endDate'];

        return $dto;
    }
}
