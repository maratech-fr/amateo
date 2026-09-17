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
use App\Dto\ScheduleCapabilities;
use App\Dto\ScheduleInput;
use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\State\Processor\ScheduleStateProcessor;
use App\State\Provider\ScheduleStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Schedule', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
    new Post(
        uriTemplate: '/schedules/{id}/export-pdf',
        controller: 'App\Controller\ExportPdfController',
        read: false,
        name: 'export_pdf',
    ),
    new Post(
        uriTemplate: '/schedules/{id}/export-xlsx',
        controller: 'App\Controller\ExportXlsxController',
        read: false,
        name: 'export_xlsx',
    ),
    new Post(
        uriTemplate: '/schedules/{id}/generate',
        controller: 'App\Controller\GenerateScheduleController',
        input: false,
        read: false,
        name: 'generate_schedule',
    ),
], mercure: true, input: ScheduleInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: ScheduleStateProvider::class, processor: ScheduleStateProcessor::class)]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact'])]
class ScheduleResource
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
    public ScheduleStatus $status;

    /**
     * The type of THIS version's plan — SEASON | CLOSURE | HOLIDAY. The
     * SOLE truth of "is this the socle?" (SEASON) vs a period overlay, replacing the
     * derivation from `calendarEntryId`. Batched by ScheduleStateProvider (a per-DTO
     * lookup would N+1); null on the bare fromEntity path and for an anomalous version
     * with no plan (which should not exist).
     */
    #[Groups(['read'])]
    public ?string $planType = null;

    /** The SchedulePlan this schedule is a version of (null pre-backfill). */
    #[Groups(['read'])]
    public ?string $schedulePlanId = null;

    /** This schedule's version number within its plan (V1, V2…). */
    #[Groups(['read'])]
    public ?int $versionNumber = null;

    #[Groups(['read'])]
    public ?int $score = null;

    /**
     * Ce planning a-t-il été retouché à la main (déplacement de créneau) depuis sa
     * génération ? Vrai ⇒ le score ci-dessus est PÉRIMÉ (le placement a changé, pas le
     * score). L'écran l'affiche pour ne pas laisser lire un nombre qui ne décrit plus
     * le planning. Remis à faux par une (re)génération.
     */
    #[Groups(['read'])]
    public bool $manuallyEditedSinceGeneration = false;

    /**
     * Une contrainte a-t-elle changé depuis la génération de ce planning ? Vrai ⇒ ce
     * planning décrit un état ANTÉRIEUR des règles — pas faux, mais PÉRIMÉ. L'écran l'affiche
     * (bannière unifiée avec « retouché à la main ») pour que le gestionnaire régénère afin
     * de savoir. Remis à faux par une (re)génération.
     */
    #[Groups(['read'])]
    public bool $constraintsChangedSinceGeneration = false;

    /**
     * Une donnée du club autre qu'une contrainte (gymnase, coach, créneau, grille de période,
     * réservation, override, tag d'équipe, calendrier) a-t-elle changé depuis la génération de
     * ce planning ? Vrai ⇒ ce planning décrit un état ANTÉRIEUR des données — pas faux, PÉRIMÉ.
     * L'écran l'affiche dans la bannière unifiée de péremption. Remis à faux par une
     * (re)génération.
     */
    #[Groups(['read'])]
    public bool $resourcesChangedSinceGeneration = false;

    #[Groups(['read'])]
    public int $solverSeed = 0;

    #[Groups(['read'])]
    public ?string $snapshotHash = null;

    #[Groups(['read'])]
    public ?string $solverVersion = null;

    #[Groups(['read'])]
    public ?string $constraintVersion = null;

    #[Groups(['read'])]
    public ?string $scoreFormulaVersion = null;

    #[Groups(['read'])]
    public ?int $solverTimeoutSeconds = null;

    #[Groups(['read'])]
    public ?int $solverNbVariables = null;

    #[Groups(['read'])]
    public ?int $solverNbConstraints = null;

    #[Groups(['read'])]
    public ?int $solverNbConflicts = null;

    #[Groups(['read'])]
    public ?int $solverWallTimeMs = null;

    #[Groups(['read'])]
    public ?string $pdfExportStatus = null;

    #[Groups(['read'])]
    public ?string $pdfExportUrl = null;

    /**
     * Number of teams in the frozen solve input (planning-versions divergence
     * banner: "generated with N teams — the structure has changed since").
     * Read-only; null until a generation froze a snapshot.
     */
    #[Groups(['read'])]
    public ?int $generatedTeamCount = null;

    /**
     * planning-versions D3: does this version carry a restorable structure photo
     * (ScheduleStructureSnapshot, D2)? Only then can "Charger cette version"
     * succeed — a plan generated before D2 has a solver payload (so
     * generatedTeamCount is set) but no photo, and must not offer the action.
     * Set by ScheduleStateProvider (batched); false on the bare fromEntity path.
     */
    #[Groups(['read'])]
    public bool $hasStructurePhoto = false;

    /**
     * planning-versions: is THIS the version whose structure is the season's
     * currently loaded context (★)? Set on every COMPLETED season plan and
     * re-pointed by "Charger cette version". The ★ tracks the loaded context,
     * not the version being viewed. Set by ScheduleStateProvider (batched).
     */
    #[Groups(['read'])]
    public bool $isLiveContext = false;

    /**
     * Does this version's plan POINT at it? That is the whole of
     * "validated" — the plan holds the version that counts, and there is no status
     * saying so. True for the season's calendar in force and for a period's overlay
     * in force alike. Set by ScheduleStateProvider (batched); false on the bare
     * fromEntity path.
     */
    #[Groups(['read'])]
    public bool $isChosen = false;

    // P2-8 (PR A) : bloc calculé SERVEUR par le même code que les gardes d'écriture
    // (ScheduleCapabilityResolver) — « capacité affichée == verdict du refus » ; renseigné
    // EN BATCH par ScheduleStateProvider, null sur le chemin fromEntity nu (réponse POST/PUT)
    // comme planType/isChosen — le front le recharge par un GET après une mutation.
    /**
     * Les permissions applicables à cette version pour l'utilisateur courant (ce qu'il peut
     * faire dessus), calculées côté serveur. Absent (null) dans la réponse d'une création ou
     * d'une mise à jour : rechargez la version via un GET pour les obtenir.
     */
    #[Groups(['read'])]
    public ?ScheduleCapabilities $capabilities = null;

    public static function fromEntity(Schedule $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->name = $entity->getName();
        $dto->status = $entity->getStatus();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->versionNumber = $entity->getVersionNumber();
        $dto->score = $entity->getScore();
        $dto->manuallyEditedSinceGeneration = $entity->isManuallyEditedSinceGeneration();
        $dto->constraintsChangedSinceGeneration = $entity->isConstraintsChangedSinceGeneration();
        $dto->resourcesChangedSinceGeneration = $entity->isResourcesChangedSinceGeneration();
        $dto->solverSeed = $entity->getSolverSeed();
        $dto->snapshotHash = $entity->getSnapshotHash();
        $dto->solverVersion = $entity->getSolverVersion();
        $dto->constraintVersion = $entity->getConstraintVersion();
        $dto->scoreFormulaVersion = $entity->getScoreFormulaVersion();
        $dto->solverTimeoutSeconds = $entity->getSolverTimeoutSeconds();
        $dto->solverNbVariables = $entity->getSolverNbVariables();
        $dto->solverNbConstraints = $entity->getSolverNbConstraints();
        $dto->solverNbConflicts = $entity->getSolverNbConflicts();
        $dto->solverWallTimeMs = $entity->getSolverWallTimeMs();
        $dto->pdfExportStatus = $entity->getPdfExportStatus();
        $dto->pdfExportUrl = $entity->getPdfExportUrl();
        $snapshotTeams = $entity->getSnapshotData()['teams'] ?? null;
        $dto->generatedTeamCount = \is_array($snapshotTeams) ? \count($snapshotTeams) : null;

        return $dto;
    }
}
