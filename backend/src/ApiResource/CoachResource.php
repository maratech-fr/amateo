<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\CoachInput;
use App\Entity\Coach;
use App\State\Processor\CoachStateProcessor;
use App\State\Provider\CoachStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Coach', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: CoachInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: CoachStateProvider::class, processor: CoachStateProcessor::class)]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact'])]
class CoachResource
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
    public string $firstName = '';

    #[Groups(['read'])]
    public string $lastName = '';

    #[Groups(['read'])]
    public ?string $email = null;

    #[Groups(['read'])]
    public ?string $phone = null;

    #[Groups(['read'])]
    public ?int $maxDaysOverride = null;

    #[Groups(['read'])]
    public ?int $acceptableLateMinutes = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    #[Groups(['read'])]
    public bool $isEmployee = false;

    /** Véhiculé (barème voiture) ou non (barème à pied). */
    #[Groups(['read'])]
    public bool $isVehicled = false;

    #[Groups(['read'])]
    public ?string $parentCoachId = null;

    public static function fromEntity(Coach $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->firstName = $entity->getFirstName();
        $dto->lastName = $entity->getLastName();
        $dto->email = $entity->getEmail();
        $dto->phone = $entity->getPhone();
        $dto->maxDaysOverride = $entity->getMaxDaysOverride();
        $dto->acceptableLateMinutes = $entity->getAcceptableLateMinutes();
        $dto->isActive = $entity->getIsActive();
        $dto->isEmployee = $entity->isEmployee();
        $dto->isVehicled = $entity->isVehicled();
        $dto->parentCoachId = $entity->getParentCoachId();

        return $dto;
    }
}
