<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CoachGender;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\PersonDirectory;
use App\Structure\UnusedIdentitySweeper;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * La fiche d'une personne du club : prénom, nom de famille (vide si la personne n'a été saisie que
 * par son prénom), mail, téléphone, genre. Elle vit une fois, au club, au-delà des saisons ; le coach
 * d'une saison ({@see Coach}) est le rôle de cette personne cette saison-là et la pointe par
 * `coach.person_id` (clé étrangère composée `(person_id, club_id)` → `(id, club_id)`, posée à la main
 * en migration, `ON DELETE RESTRICT` : une fiche pointée par un rôle ne s'efface pas).
 *
 * Lue par {@see PersonDirectory} seulement. Une fiche sans rôle reste au club jusqu'à la purge de la
 * saison, où {@see UnusedIdentitySweeper} la balaie (remise à zéro, purge de rétention) ; l'effacement
 * du club emporte toutes les fiches.
 */
#[Family(Level::CLUB, keptAtSeasonPurge: 'fiche au club, au-delà d\'une saison : une fiche sans rôle est balayée après la purge (UnusedIdentitySweeper) ; porte de sortie ErasedClubPurger')]
#[ORM\Entity]
#[ORM\Table(name: 'person')]
#[ORM\Index(name: 'idx_person_club', columns: ['club_id'])]
#[ORM\UniqueConstraint(name: 'uniq_person_id_club', columns: ['id', 'club_id'])]
#[ORM\HasLifecycleCallbacks]
class Person implements TenantOwnedInterface
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

    #[ORM\Column(type: 'string', length: 120)]
    private string $firstName = '';

    #[ORM\Column(type: 'string', length: 120)]
    private string $lastName = '';

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $phone = null;

    /** Genre pour accorder les libellés désignant la personne ; jamais envoyé au moteur. */
    #[ORM\Column(length: 12, enumType: CoachGender::class, options: ['default' => 'UNSPECIFIED'])]
    private CoachGender $gender = CoachGender::UNSPECIFIED;

    public function __construct()
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $this->id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
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

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getGender(): CoachGender
    {
        return $this->gender;
    }

    public function setGender(CoachGender $gender): self
    {
        $this->gender = $gender;

        return $this;
    }
}
