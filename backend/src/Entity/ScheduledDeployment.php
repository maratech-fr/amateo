<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeploymentStatus;
use App\Repository\ScheduledDeploymentRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une « mise en prod programmée » (console superadmin) : la version à déployer, l'heure H et la
 * fenêtre de maintenance annoncée. GLOBALE, pas tenant-owned : un déploiement concerne TOUTE la
 * plateforme, pas un club — aucun `club_id`, donc pas de RLS, seulement un GRANT à `amateo_app`
 * (même patron que `release_note` / `public_holiday`). Le rail runtime la lit (bandeau
 * d'annonce) et la commande `app:deployment:tick` la fait avancer ; la console l'écrit.
 *
 * Un seul déploiement ACTIF (`scheduled`|`dispatched`) à la fois — garanti par un index unique
 * PARTIEL sur `status`.
 */
#[ORM\Entity(repositoryClass: ScheduledDeploymentRepository::class)]
#[ORM\Table(name: 'scheduled_deployment')]
#[ORM\HasLifecycleCallbacks]
class ScheduledDeployment
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** Le tag déployé (`^v`, p. ex. `v1.0.0`) — jamais dérivé en URL, confiné au corps du dispatch. */
    #[ORM\Column(type: 'string', length: 64)]
    private string $version;

    #[ORM\Column(name: 'scheduled_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $scheduledAt;

    #[ORM\Column(name: 'expected_return_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $expectedReturnAt;

    #[ORM\Column(type: 'string', length: 20, enumType: DeploymentStatus::class)]
    private DeploymentStatus $status;

    /** L'identité SA0 qui a programmé — tracée, jamais servie à un club (le bandeau l'ignore). */
    #[ORM\Column(name: 'created_by', type: 'guid')]
    private string $createdBy;

    #[ORM\Column(name: 'dispatched_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $dispatchedAt = null;

    /** Id du run GitHub `deploy.yml` déclenché (bigint — les ids tiennent dans un int 64 bits). */
    #[ORM\Column(name: 'github_run_id', type: Types::BIGINT, nullable: true)]
    private ?int $githubRunId = null;

    #[ORM\Column(name: 'failure_reason', type: 'text', nullable: true)]
    private ?string $failureReason = null;

    /**
     * Note de DÉPART posée par le tick (retard d'attente, plafond forcé) — rendue en console
     * (carte active + historique) et reportée dans les e-mails de succès/échec. Jamais servie au
     * club (le bandeau d'annonce l'ignore).
     */
    #[ORM\Column(name: 'dispatch_note', type: 'text', nullable: true)]
    private ?string $dispatchNote = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $version,
        DateTimeImmutable $scheduledAt,
        DateTimeImmutable $expectedReturnAt,
        string $createdBy,
    ) {
        $this->id = $this->newUuid();
        $this->version = $version;
        $this->scheduledAt = $scheduledAt;
        $this->expectedReturnAt = $expectedReturnAt;
        $this->createdBy = $createdBy;
        $this->status = DeploymentStatus::SCHEDULED;
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getScheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getExpectedReturnAt(): DateTimeImmutable
    {
        return $this->expectedReturnAt;
    }

    public function getStatus(): DeploymentStatus
    {
        return $this->status;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getDispatchedAt(): ?DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function getGithubRunId(): ?int
    {
        return $this->githubRunId;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getDispatchNote(): ?string
    {
        return $this->dispatchNote;
    }

    public function setDispatchNote(?string $dispatchNote): void
    {
        $this->dispatchNote = $dispatchNote;
    }

    /**
     * Re-date l'heure de retour sur le dispatch RÉEL : `expectedReturnAt ← now + fenêtre d'origine`
     * (fenêtre = `expectedReturnAt − scheduledAt`, calculée AVANT l'écrasement). Le tick la passe
     * ensuite en `maintenance_until` pour que le bandeau annonce un retour juste même si le départ
     * a été retardé par une génération en cours.
     */
    public function redateExpectedReturn(DateTimeImmutable $now): void
    {
        $windowSeconds = $this->expectedReturnAt->getTimestamp() - $this->scheduledAt->getTimestamp();
        $this->expectedReturnAt = $now->modify(\sprintf('+%d seconds', $windowSeconds));
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return DeploymentStatus::SCHEDULED === $this->status || DeploymentStatus::DISPATCHED === $this->status;
    }

    public function cancel(): void
    {
        $this->status = DeploymentStatus::CANCELLED;
    }

    public function markDispatched(int $githubRunId, DateTimeImmutable $dispatchedAt): void
    {
        $this->status = DeploymentStatus::DISPATCHED;
        $this->dispatchedAt = $dispatchedAt;
        $this->githubRunId = $githubRunId;
    }

    public function markSucceeded(): void
    {
        $this->status = DeploymentStatus::SUCCEEDED;
    }

    public function markFailed(string $reason): void
    {
        $this->status = DeploymentStatus::FAILED;
        $this->failureReason = $reason;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
