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
use App\Dto\TeamInput;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\State\Processor\TeamStateProcessor;
use App\State\Provider\TeamStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Team', operations: [
    new GetCollection,
    new Get,
    new Post(validationContext: ['groups' => ['Default', 'create']]),
    new Put,
    new Delete,
], input: TeamInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamStateProvider::class, processor: TeamStateProcessor::class)]
// Honored by TeamStateProvider::applyRequestFilters (the custom provider bypasses
// API Platform's built-in Doctrine filters) — documented AND functional (BCK-05).
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact'])]
class TeamResource
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
    public string $sportCategoryId = '';

    #[Groups(['read'])]
    public int $priorityTierId = 0;

    #[Groups(['read'])]
    public int $tierOrder = 0;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public ?Gender $gender = null;

    #[Groups(['read'])]
    public ?TeamLevel $level = null;

    /**
     * Cette équipe joue-t-elle déjà en compétition ? Vrai dès qu'elle porte AU MOINS UN
     * match, quel qu'en soit le statut : la correspondance faite par l'import FBI entre
     * une rencontre et cette équipe suffit — la fédération la connaît. Elle ne peut alors
     * plus être supprimée ni changer de `level`. Son nom, son tier, son `isActive` et ses
     * créneaux restent libres.
     *
     * Exposé pour que l'UI grise ces deux gestes sans re-dériver la règle de son côté :
     * un second endroit qui répond « engagée ? » finirait par répondre autre chose que
     * le serveur, et l'écran offrirait un geste toujours refusé. Rempli en lot par
     * TeamStateProvider ; false sur le chemin nu de fromEntity.
     */
    #[Groups(['read'])]
    public bool $isEngaged = false;

    #[Groups(['read'])]
    public int $sessionsPerWeek = 0;

    #[Groups(['read'])]
    public ?int $minSessionsOverride = null;

    #[Groups(['read'])]
    public ?int $matchDay = null;

    #[Groups(['read'])]
    public ?string $forcedVenueId = null;

    #[Groups(['read'])]
    public bool $isActive = false;

    #[Groups(['read'])]
    public ?string $parentTeamId = null;

    /** Équipe déclarée « en entente (CTC) » — repère interne, aucun effet moteur. */
    #[Groups(['read'])]
    public bool $isCtc = false;

    /** Le/les partenaires de l'entente, texte libre opaque (≤ 120) ; null hors entente. */
    #[Groups(['read'])]
    public ?string $ctcPartner = null;

    public static function fromEntity(Team $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->sportCategoryId = $entity->getSportCategoryId();
        $dto->priorityTierId = $entity->getPriorityTierId();
        $dto->tierOrder = $entity->getTierOrder();
        $dto->name = $entity->getName();
        $dto->gender = $entity->getGender();
        $dto->level = $entity->getLevel();
        $dto->sessionsPerWeek = $entity->getSessionsPerWeek();
        $dto->minSessionsOverride = $entity->getMinSessionsOverride();
        $dto->matchDay = $entity->getMatchDay();
        $dto->forcedVenueId = $entity->getForcedVenueId();
        $dto->isActive = $entity->getIsActive();
        $dto->parentTeamId = $entity->getParentTeamId();
        $dto->isCtc = $entity->getIsCtc();
        $dto->ctcPartner = $entity->getCtcPartner();

        return $dto;
    }
}
