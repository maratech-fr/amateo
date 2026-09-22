<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScheduleStatus;
use App\Repository\ScheduleRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduleRepository::class)]
#[ORM\Table(name: 'schedule')]
#[ORM\Index(name: 'idx_schedule_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_schedule_status', columns: ['status'])]
// ADR-0002 C4 : l'index idx_schedule_calendar_entry est parti avec la colonne
// calendar_entry_id (Version20260717160000). Le regroupement des versions passe
// désormais par schedule_plan_id (uniq_schedule_plan_version ci-dessous).
// ADR-0002: version numbers are unique within a SchedulePlan (V1, V2…). Partial
// so the many rows still unlinked during the additive transition don't collide.
#[ORM\UniqueConstraint(name: 'uniq_schedule_plan_version', columns: ['schedule_plan_id', 'version_number'], options: ['where' => '(schedule_plan_id IS NOT NULL AND version_number IS NOT NULL)'])]
#[ORM\HasLifecycleCallbacks]
class Schedule implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    /**
     * ADR-0002: the SchedulePlan this schedule is a VERSION of. Nullable during
     * the additive transition (Lot A) — the backfill + SchedulePlanProvisioner
     * fill it. **NOT NULL depuis le lot D** : une version sans plan n'existe pas
     * (toute création la lie). Le type du plan (SEASON vs CLOSURE/HOLIDAY) dit
     * « socle ou overlay ? » — plus de doublon `calendarEntryId` (C4). La propriété
     * PHP reste `?string` (posée après `new` mais AVANT le flush-INSERT) ; c'est la
     * colonne qui scelle l'invariant, et les gardes défensives sur null restent utiles.
     */
    #[ORM\Column(type: 'guid', nullable: false)]
    private string $schedulePlanId;

    /**
     * ADR-0002: this schedule's position within its SchedulePlan (V1, V2…),
     * stored (not derived). **NOT NULL depuis le lot D** ; posé par le provisioner
     * (`MAX(versionNumber du plan) + 1`) AVANT le flush-INSERT. PHP `?int` conservé :
     * la garde d'idempotence de linkSchedule lit `getVersionNumber()` avant l'affectation.
     */
    // 0 = pas encore numérotée (les versions vont de 1) — sentinelle interne : linkSchedule
    // lit getVersionNumber() pour son idempotence AVANT de l'affecter. En base : toujours ≥ 1
    // (linkSchedule numérote avant le flush-INSERT ; sinon il lève et la création rollback).
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $versionNumber = 0;

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(length: 30, enumType: ScheduleStatus::class)]
    private ScheduleStatus $status;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $score = null;

    /**
     * Ce planning a-t-il été retouché À LA MAIN (déplacement de créneau) depuis sa
     * génération ? Vrai après un déplacement accepté ; remis à faux par un import de
     * résultat solveur (le score redevient fidèle au placement). Sert à l'écran : un
     * score affiché sur un planning modifié à la main est PÉRIMÉ — il faut le dire,
     * sinon le gestionnaire lit un nombre qui ne décrit plus son planning.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $manuallyEditedSinceGeneration = false;

    /**
     * Une contrainte du club a-t-elle changé (créée, modifiée, supprimée) DEPUIS la
     * génération de ce planning ? Vrai ⇒ ce planning décrit un état ANTÉRIEUR des règles :
     * il n'est pas faux, il est PÉRIMÉ, et rien d'autre ne le dit. Posé par
     * `ConstraintChangeStaleScheduleListener` (listener d'entité sur `Constraint`, il attrape
     * TOUS les chemins d'écriture, pas un appelant nommé) ; remis à faux par un import de
     * résultat solveur (le planning redevient fidèle aux données). Jumeau du marqueur
     * « retouché à la main » ci-dessus, avec un autre déclencheur — l'écran les affiche unifiés.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $constraintsChangedSinceGeneration = false;

    /**
     * Une DONNÉE DU CLUB autre qu'une contrainte a-t-elle changé DEPUIS la génération de ce
     * planning ? Gymnase (renommage/désactivation), coach, créneau ou grille de période
     * (ADR-0002), réservation, override de période, tag d'équipe, entrée de calendrier : toutes
     * ces sources nourrissent le solveur. Vrai ⇒ ce planning décrit un état ANTÉRIEUR des
     * données — pas faux, PÉRIMÉ, et rien d'autre ne le dit. Posé par
     * `ResourceChangeStaleScheduleListener` (listener d'entité générique, il attrape TOUS les
     * chemins d'écriture) ; remis à faux par un import de résultat solveur. Troisième jumeau des
     * deux marqueurs ci-dessus — l'écran les affiche unifiés. UN SEUL drapeau pour toutes ces
     * sources : la bannière nomme la cause à granularité utile (« les données du club ont
     * changé »), pas source par source.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $resourcesChangedSinceGeneration = false;

    #[ORM\Column(type: 'integer')]
    private int $solverSeed = 42;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $snapshotHash = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $snapshotData = [];

    /**
     * Greffe de CONVERGENCE ajoutée à l'entrée du moteur APRÈS le hash de snapshot
     * (`previousAssignments` en régénération, `socleReferenceAssignments` en comblement) :
     * des clés DISJOINTES du snapshot, JAMAIS intégrées à `snapshotHash`/`snapshotData` —
     * les y mettre ferait diverger `snapshotHash` de `currentStructureHash` (recalculé sans
     * elle) à chaque régénération, cassant en silence le garde « structure inchangée ».
     * NULL = aucune greffe (exact pour une première génération : les builders rendent le
     * payload inchangé quand la source est vide). Persistée parce que NON reconstituable
     * après coup — cf. {@see self::engineInput()}.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payloadGraft = null;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $solverVersion = null;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $constraintVersion = null;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $scoreFormulaVersion = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverTimeoutSeconds = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverNbVariables = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverNbConstraints = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverNbConflicts = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solverWallTimeMs = null;

    /**
     * P5-10 — instants du cycle de vie de la génération, pour mesurer l'attente en
     * file (queued → generating) et le temps réel de solve côté produit. Nullable :
     * l'historique d'avant la colonne, et les chemins qui n'arment pas la mesure.
     * `queuedAt` posé au passage en PENDING (dispatch) ; `solveStartedAt` au flush
     * GENERATING (le dernier passage gagne — retries de verrou : sémantique voulue).
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $queuedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $solveStartedAt = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $pdfExportStatus = null;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private ?string $pdfExportUrl = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getSchedulePlanId(): string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(int $versionNumber): self
    {
        $this->versionNumber = $versionNumber;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getStatus(): ScheduleStatus
    {
        return $this->status;
    }

    public function setStatus(ScheduleStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function isManuallyEditedSinceGeneration(): bool
    {
        return $this->manuallyEditedSinceGeneration;
    }

    public function setManuallyEditedSinceGeneration(bool $manuallyEditedSinceGeneration): self
    {
        $this->manuallyEditedSinceGeneration = $manuallyEditedSinceGeneration;

        return $this;
    }

    public function isConstraintsChangedSinceGeneration(): bool
    {
        return $this->constraintsChangedSinceGeneration;
    }

    public function setConstraintsChangedSinceGeneration(bool $constraintsChangedSinceGeneration): self
    {
        $this->constraintsChangedSinceGeneration = $constraintsChangedSinceGeneration;

        return $this;
    }

    public function isResourcesChangedSinceGeneration(): bool
    {
        return $this->resourcesChangedSinceGeneration;
    }

    public function setResourcesChangedSinceGeneration(bool $resourcesChangedSinceGeneration): self
    {
        $this->resourcesChangedSinceGeneration = $resourcesChangedSinceGeneration;

        return $this;
    }

    public function getSolverSeed(): int
    {
        return $this->solverSeed;
    }

    public function setSolverSeed(int $solverSeed): self
    {
        $this->solverSeed = $solverSeed;

        return $this;
    }

    public function getSnapshotHash(): ?string
    {
        return $this->snapshotHash;
    }

    public function setSnapshotHash(?string $snapshotHash): self
    {
        $this->snapshotHash = $snapshotHash;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSnapshotData(): array
    {
        return $this->snapshotData;
    }

    /** @param array<string, mixed> $snapshotData */
    public function setSnapshotData(array $snapshotData): self
    {
        $this->snapshotData = $snapshotData;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPayloadGraft(): ?array
    {
        return $this->payloadGraft;
    }

    /** @param array<string, mixed>|null $payloadGraft */
    public function setPayloadGraft(?array $payloadGraft): self
    {
        $this->payloadGraft = $payloadGraft;

        return $this;
    }

    /**
     * L'entrée RÉELLE envoyée au moteur : le snapshot gelé PLUS la greffe de convergence
     * (`previousAssignments` / `socleReferenceAssignments`) — clés disjointes par
     * construction, l'une jamais recouverte par l'autre.
     *
     * MAISON UNIQUE de la recomposition. Pourquoi persister la greffe plutôt que la
     * rejouer : elle part de la dernière version COMPLETED du plan (stabilité) ou de la
     * version pointée du socle (comblement) — or, une fois CE planning terminé, c'est LUI
     * qui devient la dernière COMPLETED de son plan. Rejouer `withPreviousAssignments`
     * après coup grefferait donc ses propres placements sur lui-même : la greffe n'est
     * fidèle qu'au moment du solve, elle est figée là et relue, jamais recalculée.
     *
     * @return array<string, mixed>
     */
    public function engineInput(): array
    {
        return array_merge($this->snapshotData, $this->payloadGraft ?? []);
    }

    public function getSolverVersion(): ?string
    {
        return $this->solverVersion;
    }

    public function setSolverVersion(?string $solverVersion): self
    {
        $this->solverVersion = $solverVersion;

        return $this;
    }

    public function getConstraintVersion(): ?string
    {
        return $this->constraintVersion;
    }

    public function setConstraintVersion(?string $constraintVersion): self
    {
        $this->constraintVersion = $constraintVersion;

        return $this;
    }

    public function getScoreFormulaVersion(): ?string
    {
        return $this->scoreFormulaVersion;
    }

    public function setScoreFormulaVersion(?string $scoreFormulaVersion): self
    {
        $this->scoreFormulaVersion = $scoreFormulaVersion;

        return $this;
    }

    public function getSolverTimeoutSeconds(): ?int
    {
        return $this->solverTimeoutSeconds;
    }

    public function setSolverTimeoutSeconds(?int $solverTimeoutSeconds): self
    {
        $this->solverTimeoutSeconds = $solverTimeoutSeconds;

        return $this;
    }

    public function getSolverNbVariables(): ?int
    {
        return $this->solverNbVariables;
    }

    public function setSolverNbVariables(?int $solverNbVariables): self
    {
        $this->solverNbVariables = $solverNbVariables;

        return $this;
    }

    public function getSolverNbConstraints(): ?int
    {
        return $this->solverNbConstraints;
    }

    public function setSolverNbConstraints(?int $solverNbConstraints): self
    {
        $this->solverNbConstraints = $solverNbConstraints;

        return $this;
    }

    public function getSolverNbConflicts(): ?int
    {
        return $this->solverNbConflicts;
    }

    public function setSolverNbConflicts(?int $solverNbConflicts): self
    {
        $this->solverNbConflicts = $solverNbConflicts;

        return $this;
    }

    public function getSolverWallTimeMs(): ?int
    {
        return $this->solverWallTimeMs;
    }

    public function setSolverWallTimeMs(?int $solverWallTimeMs): self
    {
        $this->solverWallTimeMs = $solverWallTimeMs;

        return $this;
    }

    public function getQueuedAt(): ?DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function setQueuedAt(?DateTimeImmutable $queuedAt): self
    {
        $this->queuedAt = $queuedAt;

        return $this;
    }

    public function getSolveStartedAt(): ?DateTimeImmutable
    {
        return $this->solveStartedAt;
    }

    public function setSolveStartedAt(?DateTimeImmutable $solveStartedAt): self
    {
        $this->solveStartedAt = $solveStartedAt;

        return $this;
    }

    public function getPdfExportStatus(): ?string
    {
        return $this->pdfExportStatus;
    }

    public function setPdfExportStatus(?string $pdfExportStatus): self
    {
        $this->pdfExportStatus = $pdfExportStatus;

        return $this;
    }

    public function getPdfExportUrl(): ?string
    {
        return $this->pdfExportUrl;
    }

    public function setPdfExportUrl(?string $pdfExportUrl): self
    {
        $this->pdfExportUrl = $pdfExportUrl;

        return $this;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
