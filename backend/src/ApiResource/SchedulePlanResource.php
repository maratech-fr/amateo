<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\CreatePeriodPlanInput;
use App\Dto\SchedulePlanInput;
use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\State\Processor\SchedulePlanStateProcessor;
use App\State\Provider\SchedulePlanStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

// ⚠️ Le DOCBLOCK ci-dessous est PUBLIÉ : API Platform le recopie verbatim dans la
// description OpenAPI, et /api/docs est en PUBLIC_ACCESS (security.yaml). N'y écrire
// que ce qu'un consommateur externe doit lire — jamais de raisonnement interne, de
// chemin de fichier, ni d'état de migration. Ces commentaires `//`, eux, ne sont pas
// publiés. Le raisonnement vit dans docs/architecture/adr-0002-pattern-plan.md.
/**
 * Le Plan — le conteneur nommé des versions d'une saison ou d'une période. Le plan
 * de saison et sa version choisie sont le calendrier de base de la saison.
 *
 * POST crée le plan d'une période closure/holiday : c'est le geste « adapter cette
 * période » (idempotent — la période a déjà son plan → il est rendu tel quel).
 * Le plan de saison, lui, est créé par le serveur. La version choisie se désigne
 * en validant cette version ; seul le nom est éditable.
 */
// The ?calendarEntryId / ?type query filters are implemented by the custom
// provider (SchedulePlanStateProvider::applyRequestFilters) — a Doctrine
// #[ApiFilter] would be inert here since the custom provider bypasses the
// Doctrine extensions.
#[ApiResource(shortName: 'SchedulePlan', operations: [
    new GetCollection,
    new Get,
    new Post(input: CreatePeriodPlanInput::class),
    new Put,
], input: SchedulePlanInput::class, paginationEnabled: false, provider: SchedulePlanStateProvider::class, processor: SchedulePlanStateProcessor::class)]
class SchedulePlanResource
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
    public string $seasonId = '';

    #[Groups(['read'])]
    public SchedulePlanType $type;

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public DateTimeImmutable $startDate;

    #[Groups(['read'])]
    public DateTimeImmutable $endDate;

    #[Groups(['read'])]
    public ?string $calendarEntryId = null;

    #[Groups(['read'])]
    public ?string $chosenScheduleId = null;

    // Publié dans /api/docs (cf. ⚠️ en tête) : le docblock reste factuel, le pourquoi
    // (garde de seed du wizard, inv. 5) vit dans l'ADR.
    /**
     * La sélection d'équipes de ce plan a-t-elle déjà été configurée au moins une fois ?
     * Toujours faux sur un plan SEASON, qui n'a pas cette étape.
     */
    #[Groups(['read'])]
    public bool $teamSelectionInitialized = false;

    public static function fromEntity(SchedulePlan $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->seasonId = $entity->getSeasonId();
        $dto->type = $entity->getType();
        $dto->name = $entity->getName();
        $dto->startDate = $entity->getStartDate();
        $dto->endDate = $entity->getEndDate();
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->chosenScheduleId = $entity->getChosenScheduleId();
        $dto->teamSelectionInitialized = $entity->isTeamSelectionInitialized();

        return $dto;
    }
}
