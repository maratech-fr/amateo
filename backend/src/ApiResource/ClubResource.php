<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
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
// GetCollection retiré (nettoyage API) : le front ne liste jamais les clubs
// (l'identité tenant vient du JWT, un seul club actif). Get/Put sont tenant-scopés
// dans le provider/processor aux adhésions ClubUser actives de l'appelant.
#[ApiResource(shortName: 'Club', operations: [
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
    // Chantier G — dry-run de l'import de parc par IA. Le corps EST le texte parc-v1 BRUT
    // (JSON), lu par le contrôleur via getContent() ; `deserialize: false` empêche API
    // Platform de le désérialiser en ClubInput (un JSON illisible doit donner notre 422
    // nommé, jamais un 400 de désérialisation). Écrit RIEN.
    new Post(
        uriTemplate: '/clubs/{id}/parc-import/analyze',
        controller: 'App\Controller\ParcImportAnalyzeController',
        read: false,
        deserialize: false,
        name: 'parc_import_analyze',
    ),
    // Chantier G PR2 — import RÉEL du parc. Le corps est `{payload, decisions}` (JSON brut lu par
    // le contrôleur, `deserialize: false`) : le payload est re-validé, les lignes cochées sont
    // basculées en écriture tout-ou-rien. Même gate et mêmes bornes que l'analyse.
    new Post(
        uriTemplate: '/clubs/{id}/parc-import',
        controller: 'App\Controller\ParcImportExecuteController',
        read: false,
        deserialize: false,
        name: 'parc_import',
    ),
], input: ClubInput::class, provider: ClubStateProvider::class, processor: ClubStateProcessor::class)]
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

    /** Nom COURT (libellé d'e-mail) — null = repli sur le nom long. Saisi via /api/club/short-name. */
    #[Groups(['read'])]
    public ?string $shortName = null;

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

    /** Modèle de week-end sur deux semaines (A/B) — aide visuelle « Semaine type ». */
    #[Groups(['read'])]
    public bool $weekendAlternates = false;

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
        $dto->shortName = $entity->getShortName();
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
        $dto->weekendAlternates = $entity->weekendAlternates();
        $dto->ffbbClubCode = $entity->getFfbbClubCode();
        $dto->logoUrl = $entity->getLogoUrl();
        $dto->accentColor = $entity->getAccentColor();
        $dto->accentPalette = $entity->getAccentPalette();

        return $dto;
    }
}
