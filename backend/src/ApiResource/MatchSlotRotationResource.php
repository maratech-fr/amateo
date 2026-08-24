<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\MatchSlotRotationInput;
use App\Entity\MatchSlotRotation;
use App\State\Processor\MatchSlotRotationStateProcessor;
use App\State\Provider\MatchSlotRotationStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Rotation A/B : N équipes déclarées sur un créneau de match partagé (gymnase + jour + heure),
 * qu'elles occupent en alternance. Scopé club+saison, lecture ouverte au Membre (patron du
 * module matchs) ; écriture derrière les gardes tenant+saison (VenueMatchWindow idiom).
 */
#[ApiResource(shortName: 'MatchSlotRotation', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: MatchSlotRotationInput::class, paginationEnabled: false, provider: MatchSlotRotationStateProvider::class, processor: MatchSlotRotationStateProcessor::class)]
class MatchSlotRotationResource
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
    public string $venueId = '';

    #[Groups(['read'])]
    public int $dayOfWeek = 0;

    /** HH:MM */
    #[Groups(['read'])]
    public string $kickoffTime = '';

    /**
     * The rotation's teams, in display order (position ASC).
     *
     * @var list<string>
     */
    #[Groups(['read'])]
    public array $teamIds = [];

    /**
     * @param list<string> $teamIds
     */
    public static function fromEntity(MatchSlotRotation $entity, array $teamIds): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->venueId = $entity->getVenueId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->kickoffTime = $entity->getKickoffTime()->format('H:i');
        $dto->teamIds = $teamIds;

        return $dto;
    }
}
