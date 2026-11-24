<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Dto\VenuePeriodOverrideInput;
use App\Entity\PlanVenue;
use App\State\Processor\VenuePeriodOverrideStateProcessor;
use App\State\Provider\VenuePeriodOverrideStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Le réglage d'un gymnase pour une période : la ligne du gymnase dans le plan de période, vue
 * de l'écran comme un réglage épars — un `mode` FACULTATIF, DISABLED (le gymnase ne sert pas)
 * ou BLANK (grille ressaisie, l'effet d'une fermeture est levé), et/ou un masque manuel
 * `dayOverrides` (jour ISO → OPEN|CLOSED) qui s'ajoute au défaut de l'indisponibilité. La liste
 * ne rend que les lignes qui diffèrent du défaut. Publier le réglage d'un couple (période,
 * gymnase) met sa ligne à jour ; le supprimer la rend au défaut (INHERIT). L'overlay de période
 * lit ces lignes ; le planning principal reste intact.
 */
#[ApiResource(shortName: 'VenuePeriodOverride', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
    // « Reprendre la grille du planning principal » pour UN gymnase (#8, PR-B).
    // Action, pas état : le mode stocké dit comment le gymnase se comporte, ce geste
    // refait sa grille. Atomique — l'équivalent côté client (poser VIERGE puis
    // supprimer la ligne) laisserait une grille vidée si le second appel échouait.
    new Post(
        uriTemplate: '/venue_period_overrides/reset-grid',
        controller: 'App\\Controller\\VenuePeriodGridActionController',
        input: false,
        read: false,
        name: 'reset_venue_period_grid',
    ),
    // « Vider la grille » d'un gymnase : ACTION, pas mode BLANK. Router « vider » par un
    // PUT de mode serait un no-op quand le mode ne change pas (garde d'idempotence), donc
    // un « vidé » mensonger — une action dédiée vide à chaque appel (revue #8 PR-B).
    new Post(
        uriTemplate: '/venue_period_overrides/clear-grid',
        controller: 'App\\Controller\\VenuePeriodGridActionController',
        input: false,
        read: false,
        name: 'clear_venue_period_grid',
    ),
], input: VenuePeriodOverrideInput::class, paginationEnabled: false, provider: VenuePeriodOverrideStateProvider::class, processor: VenuePeriodOverrideStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['schedulePlanId' => 'exact', 'venueId' => 'exact'])]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class VenuePeriodOverrideResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $schedulePlanId = '';

    #[Groups(['read'])]
    public string $venueId = '';

    #[Groups(['read'])]
    public ?string $mode = null;

    /**
     * Masque manuel jour ISO (1..7) → OPEN|CLOSED, ou null. Le front le lit tel quel — il ne
     * redérive jamais la composition (celle-ci vit dans PlanVenueClosures, servie par le radar).
     *
     * @var array<int|string, string>|null
     */
    #[Groups(['read'])]
    public ?array $dayOverrides = null;

    public static function fromEntity(PlanVenue $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->venueId = $entity->getVenueId();
        $dto->mode = $entity->mode()?->value;
        $dto->dayOverrides = $entity->getDayOverrides();

        return $dto;
    }
}
