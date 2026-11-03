<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubUserRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClubUserRepository::class)]
#[ORM\Table(name: 'club_user')]
#[ORM\Index(name: 'idx_club_user_club', columns: ['club_id'])]
#[ORM\Index(name: 'idx_club_user_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_club_user_membership', columns: ['club_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class ClubUser implements TenantOwnedInterface
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
    private string $userId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $role;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $joinedAt;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    /**
     * P1-1 (PR B) — l'horodatage de désactivation, qui DISTINGUE une adhésion
     * désactivée d'une adhésion en attente : les deux sont `isActive=false`,
     * mais une pending a `deactivatedAt=null` (elle n'est jamais entrée), une
     * désactivée le porte. La file d'approbation ne voit que les pending ;
     * `/api/me` rend « deactivated » plutôt que « pending » quand il est posé.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $deactivatedAt = null;

    /**
     * Chantier Membre (PR 3) — la demande en cours de ce Membre à devenir gestionnaire, auprès des
     * gestionnaires du club (null = aucune). Effacée à la promotion, au refus, à l'annulation, à la
     * désactivation et à l'effacement du compte.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $managerRequestedAt = null;

    /**
     * La demande, sans réponse des gestionnaires 14 jours, a été transmise à l'adresse officielle du
     * club (une seule transmission par demande). Effacée avec {@see $managerRequestedAt}.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $managerRequestEscalatedAt = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->joinedAt = $now;
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

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getJoinedAt(): DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(DateTimeImmutable $joinedAt): self
    {
        $this->joinedAt = $joinedAt;

        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getDeactivatedAt(): ?DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function setDeactivatedAt(?DateTimeImmutable $deactivatedAt): self
    {
        $this->deactivatedAt = $deactivatedAt;

        return $this;
    }

    public function getManagerRequestedAt(): ?DateTimeImmutable
    {
        return $this->managerRequestedAt;
    }

    public function setManagerRequestedAt(?DateTimeImmutable $managerRequestedAt): self
    {
        $this->managerRequestedAt = $managerRequestedAt;

        return $this;
    }

    public function getManagerRequestEscalatedAt(): ?DateTimeImmutable
    {
        return $this->managerRequestEscalatedAt;
    }

    public function setManagerRequestEscalatedAt(?DateTimeImmutable $managerRequestEscalatedAt): self
    {
        $this->managerRequestEscalatedAt = $managerRequestEscalatedAt;

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
