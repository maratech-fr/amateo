<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VenueTravelTimeSource;
use App\Repository\VenueTravelTimeRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un barème de trajet ENTRE DEUX GYMNASES du club (P2-53 RMM-8 — « la matrice de
 * temps de trajet »). Deux minutes par paire : le temps ACCEPTABLE en VOITURE
 * (`drivingMinutes`) et À PIED (`walkingMinutes`) — c'est la réalité terrain que
 * le gestionnaire connaît, appliquée par le solveur selon que le coach est
 * véhiculé ou non (consommé en PR-2 ; ici on ne pose QUE le modèle).
 *
 * STRUCTURE de club+saison (patron `TeamLink` — pas de `schedulePlanId`, la
 * matrice nourrit tous les plans du club+saison).
 *
 * SYMÉTRIQUE : le processor normalise venueAId < venueBId (ordre lexical uuid)
 * pour que A–B ≡ B–A, et l'unique en base fait d'un couple UNE ligne. Guids nus,
 * aucune association ORM ni clé étrangère (comme le reste du domaine).
 *
 * `drivingMinutes`/`walkingMinutes` sont NULLABLES : null = paire jamais
 * renseignée pour ce mode. `drivingSource`/`walkingSource` (AUTO|MANUAL) ne
 * portent une valeur que quand la minute correspondante est renseignée. Une
 * valeur MANUAL n'est JAMAIS écrasée par l'autofill.
 */
#[ORM\Entity(repositoryClass: VenueTravelTimeRepository::class)]
#[ORM\Table(name: 'venue_travel_time')]
#[ORM\UniqueConstraint(name: 'uniq_venue_travel_time_couple', columns: ['club_id', 'season_id', 'venue_a_id', 'venue_b_id'])]
#[ORM\Index(name: 'idx_venue_travel_time_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_venue_travel_time_venue_a', columns: ['venue_a_id'])]
#[ORM\Index(name: 'idx_venue_travel_time_venue_b', columns: ['venue_b_id'])]
#[ORM\HasLifecycleCallbacks]
class VenueTravelTime implements TenantOwnedInterface
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
     * Invariant: venueAId < venueBId (normalized by the processor).
     * Explicit column names: Doctrine's underscore strategy would render
     * `venueAId` as `venue_aid`, not the `venue_a_id` the migration creates.
     */
    #[ORM\Column(name: 'venue_a_id', type: 'guid')]
    private string $venueAId;

    #[ORM\Column(name: 'venue_b_id', type: 'guid')]
    private string $venueBId;

    #[ORM\Column(name: 'driving_minutes', type: 'smallint', nullable: true)]
    private ?int $drivingMinutes = null;

    #[ORM\Column(name: 'walking_minutes', type: 'smallint', nullable: true)]
    private ?int $walkingMinutes = null;

    #[ORM\Column(name: 'driving_source', length: 10, nullable: true, enumType: VenueTravelTimeSource::class)]
    private ?VenueTravelTimeSource $drivingSource = null;

    #[ORM\Column(name: 'walking_source', length: 10, nullable: true, enumType: VenueTravelTimeSource::class)]
    private ?VenueTravelTimeSource $walkingSource = null;

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

    public function getVenueAId(): string
    {
        return $this->venueAId;
    }

    public function setVenueAId(string $venueAId): self
    {
        $this->venueAId = $venueAId;

        return $this;
    }

    public function getVenueBId(): string
    {
        return $this->venueBId;
    }

    public function setVenueBId(string $venueBId): self
    {
        $this->venueBId = $venueBId;

        return $this;
    }

    public function getDrivingMinutes(): ?int
    {
        return $this->drivingMinutes;
    }

    public function setDrivingMinutes(?int $drivingMinutes): self
    {
        $this->drivingMinutes = $drivingMinutes;

        return $this;
    }

    public function getWalkingMinutes(): ?int
    {
        return $this->walkingMinutes;
    }

    public function setWalkingMinutes(?int $walkingMinutes): self
    {
        $this->walkingMinutes = $walkingMinutes;

        return $this;
    }

    public function getDrivingSource(): ?VenueTravelTimeSource
    {
        return $this->drivingSource;
    }

    public function setDrivingSource(?VenueTravelTimeSource $drivingSource): self
    {
        $this->drivingSource = $drivingSource;

        return $this;
    }

    public function getWalkingSource(): ?VenueTravelTimeSource
    {
        return $this->walkingSource;
    }

    public function setWalkingSource(?VenueTravelTimeSource $walkingSource): self
    {
        $this->walkingSource = $walkingSource;

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
