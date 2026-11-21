<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\State\Provider\TeamTagAssignmentStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Le rattachement d'une équipe de la saison à un groupe du catalogue — CALCULÉ à la lecture
 * depuis sa catégorie, son sexe et son niveau. Identifiant `<teamId>:<NOM>`, `tagId` = le nom.
 * Pas de route d'un rattachement seul : la collection est la seule lecture.
 */
#[ApiResource(shortName: 'TeamTagAssignment', operations: [
    new GetCollection,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamTagAssignmentStateProvider::class)]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class TeamTagAssignmentResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public string $tagId = '';

    #[Groups(['read'])]
    public string $seasonId = '';

    public static function of(string $teamId, string $tagName, string $seasonId): self
    {
        $dto = new self;
        $dto->id = $teamId . ':' . $tagName;
        $dto->teamId = $teamId;
        $dto->tagId = $tagName;
        $dto->seasonId = $seasonId;

        return $dto;
    }
}
