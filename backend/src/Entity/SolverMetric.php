<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SolverMetricRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable technical telemetry captured for one schedule generation attempt.
 *
 * APPEND-ONLY (décision fondateur 2026-07-18) : l'historique des TENTATIVES est la
 * vérité d'usage du produit — une métrique ne meurt NI avec sa version (validation
 * supprime les sœurs, pas leur télémétrie) NI au reset de saison. Seule porte de
 * sortie : l'effacement RGPD du club (ErasedClubPurger, delete par clubId).
 * `scheduleId` peut donc nommer un planning supprimé — assumé, on ne joint plus :
 * les dimensions d'analyse (planType, tailles) sont dénormalisées à la capture.
 */
#[Family(Level::CLUB, keptAtSeasonPurge: 'club-scoped sans saison — porte de sortie ErasedClubPurger (télémétrie append-only, décision fondateur 2026-07-18)')]
#[ORM\Entity(repositoryClass: SolverMetricRepository::class)]
#[ORM\Table(name: 'solver_metrics')]
#[ORM\Index(name: 'idx_solver_metrics_club_created', columns: ['club_id', 'created_at'])]
#[ORM\Index(name: 'idx_solver_metrics_schedule', columns: ['schedule_id'])]
#[ORM\Index(name: 'idx_solver_metrics_plan_type_created', columns: ['plan_type', 'created_at'])]
class SolverMetric implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Schedule::class, OnDelete::KEEP, 'télémétrie append-only : survit à sa version, dimensions dénormalisées à la capture')]
    private string $scheduleId;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'string', length: 30)]
    private string $status;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $wallTimeMs;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbVariables;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbConstraints;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbConflicts;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $score;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $solverVersion;

    /** Type du plan (SEASON/CLOSURE/HOLIDAY) dénormalisé à la capture — survit à la
     *  suppression du plan/de la version. null = historique d'avant la colonne, ou
     *  plan disparu au moment de la capture (best-effort, jamais bloquant). */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $planType;

    /** Équipes actives de la SAISON au moment de la tentative — la TAILLE DU CLUB
     *  (stat fondateur), PAS la taille du sous-problème résolu : un overlay de
     *  période ne solve qu'un sous-ensemble d'équipes, et porte quand même le
     *  compte saison. Pour la taille du problème solveur, voir nbVariables. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbTeams;

    /** Gymnases actifs de la saison au moment de la tentative (taille du club, idem nbTeams). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nbVenues;

    /**
     * P5-10 — métriques de capacité de la tentative. Toutes nullable (ADD COLUMN
     * additif) : l'historique d'avant la colonne, et les chemins terminaux où
     * l'engine n'a rien renvoyé (échec/timeout), les laissent null.
     *
     * `queuedAt`/`solveStartedAt` copiés du Schedule à la capture (l'attente en file
     * et l'instant de solve survivent à la mort de la version, comme le reste ici).
     * `payloadBytes` = taille du payload envoyé à l'engine. `totalWallTimeMs` = le
     * solve ENTIER (pas seulement la dernière phase). `solverStatusDetail` =
     * OPTIMAL/FEASIBLE/INFEASIBLE/UNKNOWN de la phase 1. `peakRssMb`/`rssBeforeMb` =
     * échantillonnage RSS côté engine.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $queuedAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $solveStartedAt;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $payloadBytes;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $totalWallTimeMs;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cpuTimeMs;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $workers;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $budgetSeconds;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $solverStatusDetail;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $peakRssMb;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rssBeforeMb;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $engineWaitMs;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $scheduleId,
        string $clubId,
        string $status,
        ?int $wallTimeMs,
        ?int $nbVariables,
        ?int $nbConstraints,
        ?int $nbConflicts,
        ?int $score,
        ?string $solverVersion,
        ?DateTimeImmutable $createdAt = null,
        ?string $planType = null,
        ?int $nbTeams = null,
        ?int $nbVenues = null,
        ?DateTimeImmutable $queuedAt = null,
        ?DateTimeImmutable $solveStartedAt = null,
        ?int $payloadBytes = null,
        ?int $totalWallTimeMs = null,
        ?int $cpuTimeMs = null,
        ?int $workers = null,
        ?int $budgetSeconds = null,
        ?string $solverStatusDetail = null,
        ?float $peakRssMb = null,
        ?float $rssBeforeMb = null,
        ?int $engineWaitMs = null,
    ) {
        $this->id = $this->newUuid();
        $this->scheduleId = $scheduleId;
        $this->clubId = $clubId;
        $this->status = $status;
        $this->wallTimeMs = $wallTimeMs;
        $this->nbVariables = $nbVariables;
        $this->nbConstraints = $nbConstraints;
        $this->nbConflicts = $nbConflicts;
        $this->score = $score;
        $this->solverVersion = $solverVersion;
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->planType = $planType;
        $this->nbTeams = $nbTeams;
        $this->nbVenues = $nbVenues;
        $this->queuedAt = $queuedAt;
        $this->solveStartedAt = $solveStartedAt;
        $this->payloadBytes = $payloadBytes;
        $this->totalWallTimeMs = $totalWallTimeMs;
        $this->cpuTimeMs = $cpuTimeMs;
        $this->workers = $workers;
        $this->budgetSeconds = $budgetSeconds;
        $this->solverStatusDetail = $solverStatusDetail;
        $this->peakRssMb = $peakRssMb;
        $this->rssBeforeMb = $rssBeforeMb;
        $this->engineWaitMs = $engineWaitMs;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getScheduleId(): string
    {
        return $this->scheduleId;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getWallTimeMs(): ?int
    {
        return $this->wallTimeMs;
    }

    public function getNbVariables(): ?int
    {
        return $this->nbVariables;
    }

    public function getNbConstraints(): ?int
    {
        return $this->nbConstraints;
    }

    public function getNbConflicts(): ?int
    {
        return $this->nbConflicts;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function getSolverVersion(): ?string
    {
        return $this->solverVersion;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPlanType(): ?string
    {
        return $this->planType;
    }

    public function getNbTeams(): ?int
    {
        return $this->nbTeams;
    }

    public function getNbVenues(): ?int
    {
        return $this->nbVenues;
    }

    public function getQueuedAt(): ?DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function getSolveStartedAt(): ?DateTimeImmutable
    {
        return $this->solveStartedAt;
    }

    public function getPayloadBytes(): ?int
    {
        return $this->payloadBytes;
    }

    public function getTotalWallTimeMs(): ?int
    {
        return $this->totalWallTimeMs;
    }

    public function getCpuTimeMs(): ?int
    {
        return $this->cpuTimeMs;
    }

    public function getWorkers(): ?int
    {
        return $this->workers;
    }

    public function getBudgetSeconds(): ?int
    {
        return $this->budgetSeconds;
    }

    public function getSolverStatusDetail(): ?string
    {
        return $this->solverStatusDetail;
    }

    public function getPeakRssMb(): ?float
    {
        return $this->peakRssMb;
    }

    public function getRssBeforeMb(): ?float
    {
        return $this->rssBeforeMb;
    }

    public function getEngineWaitMs(): ?int
    {
        return $this->engineWaitMs;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
