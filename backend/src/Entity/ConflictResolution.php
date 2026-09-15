<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConflictResolutionStatus;
use App\Repository\ConflictResolutionRepository;
use App\Service\ConflictFingerprinter;
use App\Service\SeasonDataPurger;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le statut de traitement qu'un gestionnaire pose sur UN conflit du radar, POUR
 * CE CLUB ET CETTE SAISON (P4-207). Donnée tenant (RLS FORCE), keyée sur
 * l'EMPREINTE STABLE du conflit ({@see ConflictFingerprinter}) —
 * l'identité durable du litige, constante tant que « c'est le même conflit ».
 *
 * Le conflit reste TOUJOURS rendu par le radar ; le champ additif `resolution`
 * dit seulement son statut. Un statut « À traiter » ne se stocke JAMAIS : c'est
 * le défaut (aucune ligne). Une ligne dont l'empreinte n'est plus dans le flux
 * courant (conflit disparu, ou nature changée = nouvelle empreinte) est un
 * ORPHELIN : jamais rendu (la jointure ne sert que les empreintes du flux),
 * jamais nettoyé à la volée — purgé avec la saison ({@see SeasonDataPurger}).
 *
 * Un couple (club, saison, empreinte) = une ligne (unique). Patron structurel
 * {@see OpponentTravel}.
 */
#[ORM\Entity(repositoryClass: ConflictResolutionRepository::class)]
#[ORM\Table(name: 'conflict_resolution')]
#[ORM\UniqueConstraint(name: 'uniq_conflict_resolution_fingerprint', columns: ['club_id', 'season_id', 'fingerprint'])]
#[ORM\Index(name: 'idx_conflict_resolution_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class ConflictResolution implements TenantOwnedInterface
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

    #[ORM\Column(length: 255)]
    private string $fingerprint;

    #[ORM\Column(length: 30, enumType: ConflictResolutionStatus::class)]
    private ConflictResolutionStatus $status;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    /** L'utilisateur qui a posé (ou modifié) le statut. */
    #[ORM\Column(name: 'updated_by', type: 'guid')]
    private string $updatedBy;

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

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function getStatus(): ConflictResolutionStatus
    {
        return $this->status;
    }

    public function setStatus(ConflictResolutionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getUpdatedBy(): string
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(string $updatedBy): self
    {
        $this->updatedBy = $updatedBy;

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
