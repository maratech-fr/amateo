<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Enum\TeamTagAxis;
use App\State\Provider\TeamTagStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Un groupe du catalogue fermé des étiquettes — servi par le calcul, rien n'est lu en base.
 * L'identifiant EST le nom (« U13 »), le même pour tous les clubs.
 */
#[ApiResource(shortName: 'TeamTag', operations: [
    new GetCollection,
    new Get,
], paginationEnabled: true, paginationItemsPerPage: 30, provider: TeamTagStateProvider::class)]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class TeamTagResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public ?string $color = null;

    #[Groups(['read'])]
    public bool $isSystem = true;

    /** GENRE / NIVEAU / AGE — the axis grouping the constraint target picker. */
    #[Groups(['read'])]
    public ?string $axis = null;

    public static function fromCatalog(string $name, string $color, TeamTagAxis $axis): self
    {
        $dto = new self;
        $dto->id = $name;
        $dto->name = $name;
        $dto->color = $color;
        $dto->isSystem = true;
        $dto->axis = $axis->value;

        return $dto;
    }
}
