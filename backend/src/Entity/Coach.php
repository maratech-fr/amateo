<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CoachRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\PersonDirectory;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le rôle coach d'une personne du club pour une saison : options de planning (plafond de jours,
 * salarié, véhiculé, actif). L'identité (nom, mail, téléphone, genre) vit sur la fiche
 * {@see Person} que `personId` désigne, et se lit par {@see PersonDirectory}, jamais ici.
 * Une personne a au plus un rôle coach par saison (`uniq_coach_season_person`).
 */
#[Family(Level::SEASON, cold: [], copiedToNextSeason: true)]
#[ORM\Entity(repositoryClass: CoachRepository::class)]
#[ORM\Table(name: 'coach')]
#[ORM\Index(name: 'idx_coach_club_season', columns: ['club_id', 'season_id'])]
#[ORM\UniqueConstraint(name: 'uniq_coach_season_person', columns: ['season_id', 'person_id'])]
#[ORM\HasLifecycleCallbacks]
class Coach implements TenantOwnedInterface
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
     * La fiche de la personne. Clé étrangère composée `(person_id, club_id)` → `person (id, club_id)`,
     * posée à la main en migration (invisible de Doctrine), `ON DELETE RESTRICT`.
     */
    #[ORM\Column(type: 'guid')]
    #[RefersTo(Person::class, OnDelete::CUSTOM, 'une fiche pointée par un rôle ne s\'efface pas (ON DELETE RESTRICT) : le rôle part d\'abord, la fiche sans rôle est balayée par UnusedIdentitySweeper')]
    private string $personId;

    /**
     * La fiche passée à la création, tant que ce coach n'a pas été relu de la base (non mappée) :
     * {@see PersonDirectory} la lit sans requête. Un coach chargé par Doctrine ne la porte pas.
     */
    private ?Person $person = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $maxDaysOverride = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean')]
    private bool $isEmployee = false;

    /**
     * P2-53 RMM-8 — véhiculé ou non. Défaut false (prudent : « pas véhiculé »
     * applique la limite À PIED). Consommé par le solveur en PR-2 : véhiculé →
     * barème voiture d'une paire de gymnases, sinon barème à pied.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVehicled = false;

    public function __construct(Person $person)
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->personId = $person->getId();
        $this->person = $person;
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

    public function getPersonId(): string
    {
        return $this->personId;
    }

    /**
     * La fiche passée au constructeur, ou null si ce coach a été relu de la base.
     *
     * @internal lue par {@see PersonDirectory} seulement — tout autre lecteur passe par lui
     */
    public function personGivenAtCreation(): ?Person
    {
        return $this->person;
    }

    public function getMaxDaysOverride(): ?int
    {
        return $this->maxDaysOverride;
    }

    public function setMaxDaysOverride(?int $maxDaysOverride): self
    {
        $this->maxDaysOverride = $maxDaysOverride;

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

    public function isEmployee(): bool
    {
        return $this->isEmployee;
    }

    public function setIsEmployee(bool $isEmployee): self
    {
        $this->isEmployee = $isEmployee;

        return $this;
    }

    public function isVehicled(): bool
    {
        return $this->isVehicled;
    }

    public function setIsVehicled(bool $isVehicled): self
    {
        $this->isVehicled = $isVehicled;

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
