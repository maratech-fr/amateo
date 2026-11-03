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
use App\Dto\ConstraintInput;
use App\Entity\Constraint;
use App\State\Processor\ConstraintStateProcessor;
use App\State\Provider\ConstraintStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Constraint', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: ConstraintInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: ConstraintStateProvider::class, processor: ConstraintStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['scope' => 'exact', 'family' => 'exact', 'ruleType' => 'exact'])]
class ConstraintResource
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
    public string $name = '';

    #[Groups(['read'])]
    public ?string $description = null;

    #[Groups(['read'])]
    public string $scope = '';

    #[Groups(['read'])]
    public ?string $scopeTargetId = null;

    #[Groups(['read'])]
    public string $family = '';

    #[Groups(['read'])]
    public string $ruleType = '';

    /** @var array<string, mixed> */
    #[Groups(['read'])]
    public array $config = [];

    #[Groups(['read'])]
    public ?string $createdBy = null;

    #[Groups(['read'])]
    public ?string $source = null;

    #[Groups(['read'])]
    public ?string $sourceOccurrenceId = null;

    #[Groups(['read'])]
    public ?string $calendarEntryId = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    #[Groups(['read'])]
    public int $sortOrder = 0;

    /** N→N+1 lineage: source constraint of the season-transition copy. */
    #[Groups(['read'])]
    public ?string $parentConstraintId = null;

    /**
     * Ce que l'enregistrement d'une fermeture de gymnase a retiré des plannings de période (verrous
     * et réservations au gymnase fermé, ces jours-là) ; null hors de ce geste.
     *
     * @var array{locks: int, reservations: int}|null
     */
    #[Groups(['read'])]
    public ?array $removed = null;

    public static function fromEntity(Constraint $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->description = $entity->getDescription();
        $dto->scope = $entity->getScope()->value;
        $dto->scopeTargetId = $entity->getScopeTargetId();
        $dto->family = $entity->getFamily()->value;
        $dto->ruleType = $entity->getRuleType()->value;
        $dto->config = $entity->getConfig();
        $dto->createdBy = $entity->getCreatedBy();
        $dto->source = $entity->getSource();
        $dto->sourceOccurrenceId = $entity->getSourceOccurrenceId();
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->isActive = $entity->getIsActive();
        $dto->sortOrder = $entity->getSortOrder();
        $dto->parentConstraintId = $entity->getParentConstraintId();

        return $dto;
    }
}
