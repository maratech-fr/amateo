<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\ClubInput;
use App\Entity\Club;
use App\State\Processor\ClubStateProcessor;
use App\State\Provider\ClubStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// SEC-01: no bare Post/Delete. A club is created only through /api/register
// (AuthController). Club deletion is intentionally NOT exposed over the API
// yet: dropping a tenant must cascade its child rows (no DB cascade exists
// today) and be confirmed — that dedicated flow is future work, not open CRUD.
// GetCollection/Get/Put are tenant-scoped in the provider/processor to the
// caller's active ClubUser memberships.
#[ApiResource(shortName: 'Club', operations: [
    new GetCollection,
    new Get,
    new Put,
    new Post(
        uriTemplate: '/clubs/{id}/import-teams',
        controller: 'App\Controller\ImportController',
        read: false,
        name: 'import_teams',
    ),
    new Post(
        uriTemplate: '/clubs/{id}/import-teams/analyze',
        controller: 'App\Controller\ImportTeamsAnalyzeController',
        read: false,
        name: 'import_teams_analyze',
    ),
], input: ClubInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: ClubStateProvider::class, processor: ClubStateProcessor::class)]
class ClubResource
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
    public string $slug = '';

    #[Groups(['read'])]
    public ?string $planId = null;

    #[Groups(['read'])]
    public ?string $billingCycle = null;

    #[Groups(['read'])]
    public ?DateTimeImmutable $planExpiresAt = null;

    #[Groups(['read'])]
    public int $generationCountSeason = 0;

    #[Groups(['read'])]
    public ?DateTimeImmutable $lastActivityAt = null;

    #[Groups(['read'])]
    public ?string $schoolZone = null;

    #[Groups(['read'])]
    public string $timezone = '';

    #[Groups(['read'])]
    public string $locale = '';

    #[Groups(['read'])]
    public bool $onboardingCompleted = false;

    #[Groups(['read'])]
    public ?string $ffbbClubCode = null;

    #[Groups(['read'])]
    public ?string $logoUrl = null;

    #[Groups(['read'])]
    public ?string $accentColor = null;

    /** @var list<string>|null */
    #[Groups(['read'])]
    public ?array $accentPalette = null;

    public static function fromEntity(Club $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->slug = $entity->getSlug();
        $dto->planId = $entity->getPlanId();
        $dto->billingCycle = $entity->getBillingCycle();
        $dto->planExpiresAt = $entity->getPlanExpiresAt();
        $dto->generationCountSeason = $entity->getGenerationCountSeason();
        $dto->lastActivityAt = $entity->getLastActivityAt();
        $dto->schoolZone = $entity->getSchoolZone();
        $dto->timezone = $entity->getTimezone();
        $dto->locale = $entity->getLocale();
        $dto->onboardingCompleted = $entity->getOnboardingCompleted();
        $dto->ffbbClubCode = $entity->getFfbbClubCode();
        $dto->logoUrl = $entity->getLogoUrl();
        $dto->accentColor = $entity->getAccentColor();
        $dto->accentPalette = $entity->getAccentPalette();

        return $dto;
    }
}
