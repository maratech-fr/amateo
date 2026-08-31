<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\SharedTrainingBlockInput;
use App\Entity\SharedTrainingBlock;
use App\State\Processor\SharedTrainingBlockStateProcessor;
use App\State\Provider\SharedTrainingBlockStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Le bloc de mutualisation : déclarer un ensemble d'équipes qui se comporte comme UNE équipe,
 * avec son propre nombre de séances communes (``commonSessions``). Scopé club+saison, filtrable
 * par ``schedulePlanId`` (NULL = socle saison ; un UUID = plan de période). Écriture réservée au
 * management.
 */
// `?schedulePlanId=` est lu DANS le state provider, pas par un `ApiFilter` Doctrine
// (la ressource a son propre provider, l'extension Doctrine ne tourne jamais).
#[ApiResource(shortName: 'SharedTrainingBlock', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: SharedTrainingBlockInput::class, paginationEnabled: false, provider: SharedTrainingBlockStateProvider::class, processor: SharedTrainingBlockStateProcessor::class)]
class SharedTrainingBlockResource
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
    public ?string $schedulePlanId = null;

    /** @var list<string> */
    #[Groups(['read'])]
    public array $teamIds = [];

    #[Groups(['read'])]
    public int $commonSessions = 0;

    /**
     * @param list<string> $teamIds
     */
    public static function fromEntity(SharedTrainingBlock $entity, array $teamIds): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->teamIds = $teamIds;
        $dto->commonSessions = $entity->getCommonSessions();

        return $dto;
    }
}
