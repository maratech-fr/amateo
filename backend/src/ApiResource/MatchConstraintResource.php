<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\MatchConstraintInput;
use App\Entity\MatchConstraint;
use App\State\Processor\MatchConstraintStateProcessor;
use App\State\Provider\MatchConstraintStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Une RÈGLE DE MATCH du club, éditable par le gestionnaire (section Club de l'écran
 * des contraintes de match). CRUD réservé au gestionnaire. `daysOfWeek` = jours ISO
 * couverts ; `kickoffMin`/`kickoffMax` bornent le coup d'envoi (HH:MM), chacune
 * facultative. `scope` CLUB en ③ (TEAM/COACH à venir).
 */
#[ApiResource(shortName: 'MatchConstraint', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: MatchConstraintInput::class, paginationEnabled: false, provider: MatchConstraintStateProvider::class, processor: MatchConstraintStateProcessor::class)]
class MatchConstraintResource
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
    public string $scope = '';

    #[Groups(['read'])]
    public ?string $scopeTargetId = null;

    #[Groups(['read'])]
    public string $ruleType = '';

    /** @var list<int> */
    #[Groups(['read'])]
    public array $daysOfWeek = [];

    /** HH:MM or null */
    #[Groups(['read'])]
    public ?string $kickoffMin = null;

    /** HH:MM or null */
    #[Groups(['read'])]
    public ?string $kickoffMax = null;

    #[Groups(['read'])]
    public ?string $venueId = null;

    public static function fromEntity(MatchConstraint $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->scope = $entity->getScope()->value;
        $dto->scopeTargetId = $entity->getScopeTargetId();
        $dto->ruleType = $entity->getRuleType()->value;
        $dto->daysOfWeek = $entity->getDaysOfWeek();
        $dto->kickoffMin = $entity->getKickoffMin()?->format('H:i');
        $dto->kickoffMax = $entity->getKickoffMax()?->format('H:i');
        $dto->venueId = $entity->getVenueId();

        return $dto;
    }
}
