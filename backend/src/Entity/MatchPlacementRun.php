<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MatchPlacementRunStatus;
use App\Repository\MatchPlacementRunRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un RUN de placement automatique des matchs — le rail de placement est passé
 * ASYNCHRONE (patron de la génération : contrôleur → Messenger → worker → import
 * → Mercure). Cette ligne EST le statut persistant du placement : PENDING à
 * l'enfilage, RUNNING pendant le solve, puis COMPLETED/FAILED (toujours terminal).
 *
 * Donnée de CLUB (`club_id`) + saison (`season_id`, nullable — une place hors
 * saison résolue reste rare mais possible) : les filtres Doctrine (TenantFilter +
 * SeasonFilter) et la RLS PostgreSQL s'appliquent PAR COLONNE, automatiquement ;
 * la ligne est donc invisible d'un autre club. Le demandeur (`requested_by_user_id`)
 * sert à l'e-mail de fin — jamais rendu lisiblement.
 *
 * `result_data` = ce que la réponse synchrone renvoyait autrefois (placés, ignorés,
 * non placés nommés, diagnostics, métriques) OU, en cas d'échec, le message d'erreur.
 * L'écran de matchs le relit à l'ouverture (endpoint GET) pour afficher le résultat
 * d'un run déjà terminé, et l'événement Mercure `club:{clubId}:placement` le réveille
 * à la bascule terminale.
 */
#[ORM\Entity(repositoryClass: MatchPlacementRunRepository::class)]
#[ORM\Table(name: 'match_placement_run')]
#[ORM\Index(name: 'idx_match_placement_run_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_match_placement_run_created', columns: ['club_id', 'created_at'])]
class MatchPlacementRun implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $seasonId;

    #[ORM\Column(type: 'guid')]
    private string $requestedByUserId;

    #[ORM\Column(type: 'string', length: 16, enumType: MatchPlacementRunStatus::class)]
    private MatchPlacementRunStatus $status = MatchPlacementRunStatus::PENDING;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $resultData = null;

    public function __construct(
        string $clubId,
        ?string $seasonId,
        string $requestedByUserId,
        DateTimeImmutable $createdAt,
    ) {
        $this->id = $this->newUuid();
        $this->clubId = $clubId;
        $this->seasonId = $seasonId;
        $this->requestedByUserId = $requestedByUserId;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getClubId(): ?string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): ?string
    {
        return $this->seasonId;
    }

    public function getRequestedByUserId(): string
    {
        return $this->requestedByUserId;
    }

    public function getStatus(): MatchPlacementRunStatus
    {
        return $this->status;
    }

    public function setStatus(MatchPlacementRunStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getResultData(): ?array
    {
        return $this->resultData;
    }

    /** @param array<string, mixed>|null $resultData */
    public function setResultData(?array $resultData): self
    {
        $this->resultData = $resultData;

        return $this;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
