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
use App\Dto\ConstraintPeriodOverrideInput;
use App\Entity\PlanConstraint;
use App\State\Processor\ConstraintPeriodOverrideStateProcessor;
use App\State\Provider\ConstraintPeriodOverrideStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * La coche d'une règle de saison pour une période : la ligne de la règle dans le plan de période, vue de l'écran comme
 * un réglage épars. La liste ne rend que les lignes qui diffèrent du défaut de la nature du plan (fermeture : toute
 * règle s'applique ; vacances : seules les règles de tout le club). Publier la coche d'un couple (période, règle) met
 * sa ligne à jour ; la supprimer la rend au défaut. Le planning principal reste intact.
 */
#[ApiResource(shortName: 'ConstraintPeriodOverride', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: ConstraintPeriodOverrideInput::class, paginationEnabled: false, provider: ConstraintPeriodOverrideStateProvider::class, processor: ConstraintPeriodOverrideStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['schedulePlanId' => 'exact', 'constraintId' => 'exact'])]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class ConstraintPeriodOverrideResource
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
    public string $constraintId = '';

    #[Groups(['read'])]
    public bool $isActive = true;

    public static function fromEntity(PlanConstraint $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->constraintId = $entity->getConstraintId();
        $dto->isActive = $entity->applies();

        return $dto;
    }
}
