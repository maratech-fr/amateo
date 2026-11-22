<?php

declare(strict_types=1);

namespace App\Entity;

use App\Structure\Family;
use App\Structure\Level;
use App\Structure\PlaceDirectory;
use App\Structure\UnusedIdentitySweeper;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le lieu d'un gymnase au club : son adresse et sa position (latitude, longitude en chaînes
 * décimales). Il vit une fois, au club, au-delà des saisons ; le gymnase d'une saison ({@see Venue})
 * en est l'usage cette saison-là et le pointe par `venue.place_id` (clé étrangère composée
 * `(place_id, club_id)` → `(id, club_id)`, posée à la main en migration, `ON DELETE RESTRICT` : un
 * lieu pointé par un gymnase ne s'efface pas).
 *
 * Lu par {@see PlaceDirectory}. Un lieu sans gymnase reste au club jusqu'à la purge de la saison, où
 * {@see UnusedIdentitySweeper} le balaie (remise à zéro, purge de rétention) ; l'effacement du club
 * emporte tous les lieux.
 */
#[Family(Level::CLUB, keptAtSeasonPurge: 'lieu au club, au-delà d\'une saison : un lieu sans gymnase est balayé après la purge (UnusedIdentitySweeper) ; porte de sortie ErasedClubPurger')]
#[ORM\Entity]
#[ORM\Table(name: 'place')]
#[ORM\Index(name: 'idx_place_club', columns: ['club_id'])]
#[ORM\UniqueConstraint(name: 'uniq_place_id_club', columns: ['id', 'club_id'])]
#[ORM\HasLifecycleCallbacks]
class Place implements TenantOwnedInterface
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

    /** L'adresse saisie qu'on géocode (BAN) en latitude et longitude. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $longitude = null;

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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): self
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): self
    {
        $this->longitude = $longitude;

        return $this;
    }
}
