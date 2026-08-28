<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * RGPD — journal d'audit APPEND-ONLY (accountability, art. 5.2).
 *
 * Aucune opération API Platform (pas d'attribut ApiResource) : la table ne
 * s'écrit que par AuditTrail et ne se lira que par la future console
 * superadmin (SA1, connexion admin). L'append-only est aussi tenu PAR LA DB :
 * la migration ne crée AUCUNE policy UPDATE/DELETE pour amateo_app — le rôle
 * runtime ne peut physiquement pas réécrire l'histoire (RLS FORCE).
 *
 * clubId nullable : les événements globaux (register, login raté) n'ont pas de
 * tenant — invisibles sous un GUC club, lisibles via la connexion admin.
 * details = jsonb MINIMAL et SANS PII (jamais d'email/nom — des ids).
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_club_id', columns: ['club_id'])]
class AuditLog implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $actorUserId = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $clubId = null;

    #[ORM\Column(type: 'string', length: 40)]
    private string $action = '';

    #[ORM\Column(type: 'string', length: 60, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $entityId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $details = [];

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->occurredAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorUserId(): ?string
    {
        return $this->actorUserId;
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

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
