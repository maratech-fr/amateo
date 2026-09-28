<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\ClubLeagueWindowInput;
use App\Entity\ClubLeagueWindow;
use App\State\Processor\ClubLeagueWindowStateProcessor;
use App\State\Provider\ClubLeagueWindowStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * La copie, propre au club, d'une fenêtre de coup d'envoi de la ligue, éditable
 * par le gestionnaire (section Ligue de l'écran des contraintes de match). CRUD
 * réservé au gestionnaire. Le badge (« modifié » / « ajouté ») est calculé côté
 * serveur par clé naturelle face au modèle de la ligue effective — le front
 * l'affiche, il ne le recalcule pas.
 */
#[ApiResource(shortName: 'ClubLeagueWindow', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: ClubLeagueWindowInput::class, paginationEnabled: false, provider: ClubLeagueWindowStateProvider::class, processor: ClubLeagueWindowStateProcessor::class)]
class ClubLeagueWindowResource
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
    public string $league = '';

    #[Groups(['read'])]
    public string $category = '';

    #[Groups(['read'])]
    public string $level = '';

    #[Groups(['read'])]
    public ?string $gender = null;

    #[Groups(['read'])]
    public int $dayOfWeek = 0;

    /** HH:MM */
    #[Groups(['read'])]
    public string $kickoffMin = '';

    /** HH:MM */
    #[Groups(['read'])]
    public string $kickoffMax = '';

    /**
     * Server-computed provenance vs the effective-league seed, by natural key:
     * `added` (no seed row shares the key), `modified` (same key, moved end
     * time), or null (identical to the seed). Deleted seed rows leave no copy —
     * they carry no badge.
     */
    #[Groups(['read'])]
    public ?string $badge = null;

    public static function fromEntity(ClubLeagueWindow $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->league = $entity->getLeague();
        $dto->category = $entity->getCategory();
        $dto->level = $entity->getLevel();
        $dto->gender = $entity->getGender();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->kickoffMin = $entity->getKickoffMin()->format('H:i');
        $dto->kickoffMax = $entity->getKickoffMax()->format('H:i');

        return $dto;
    }
}
