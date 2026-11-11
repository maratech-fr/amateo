<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
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

    #[ApiProperty(openapiContext: ['format' => 'date'])]
    #[Groups(['read'])]
    public string $startDate = '';

    #[ApiProperty(openapiContext: ['format' => 'date'])]
    #[Groups(['read'])]
    public string $endDate = '';

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

    // Prolonger une fermeture (axe planning lifecycle) : SERVIS seulement sur un plan CLOSURE —
    // sur un plan SEASON/HOLIDAY ils gardent leurs défauts (fenêtre non éditable par ce rail).
    // Le pourquoi (plancher serveur, identité de la fermeture) vit dans l'ADR.
    /** La fenêtre de cette fermeture est-elle modifiable (geste « Modifier les dates ») ? */
    #[Groups(['read'])]
    public bool $windowEditable = false;

    /** Pourquoi la fenêtre n'est pas modifiable (génération en vol, saison archivée…), sinon null. */
    #[Groups(['read'])]
    public ?string $windowLockedReason = null;

    /** Le PLANCHER « le passé ne bouge pas » : la fin ne descend jamais sous cette date (AAAA-MM-JJ). */
    #[ApiProperty(openapiContext: ['format' => 'date'])]
    #[Groups(['read'])]
    public string $minEndDate = '';

    /** L'identité de la FERMETURE (titre de la racine), partagée par tous ses plans-segments. */
    #[Groups(['read'])]
    public string $closureTitle = '';

    // Validation automatique d'un planning de période (7 jours après le début de sa période) :
    // trois champs CALCULÉS par le serveur, en lot sur une collection. Le front les affiche.
    /** Validé automatiquement, et jamais validé à la main depuis (marque « Validé automatiquement »). */
    #[Groups(['read'])]
    public bool $autoValidated = false;

    /**
     * L'alerte du radar pour ce planning de période, sinon null : `auto_validated` (validé
     * automatiquement alors que d'autres versions sont gardées) ou `started_without_plan` (la
     * période a commencé sans version terminée).
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(openapiContext: [
        'type' => 'object',
        'nullable' => true,
        'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['auto_validated', 'started_without_plan']],
            'chosenVersionNumber' => ['type' => 'integer', 'nullable' => true, 'description' => 'The version pointed (auto_validated only)'],
            'keptVersionCount' => ['type' => 'integer', 'description' => 'Other finished versions kept (auto_validated only)'],
        ],
    ])]
    #[Groups(['read'])]
    public ?array $alert = null;

    /** Le club a vu que la période a commencé sans planning : elle sort du radar jusqu'à sa fin. */
    #[Groups(['read'])]
    public bool $radarDismissed = false;

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
        $dto->startDate = $entity->getStartDate()->format('Y-m-d');
        $dto->endDate = $entity->getEndDate()->format('Y-m-d');
        $dto->calendarEntryId = $entity->getCalendarEntryId();
        $dto->chosenScheduleId = $entity->getChosenScheduleId();
        $dto->teamSelectionInitialized = $entity->isTeamSelectionInitialized();

        return $dto;
    }
}
