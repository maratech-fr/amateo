<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VenueTravelTimeSource;
use App\Repository\PlaceTravelTimeRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le temps de trajet entre deux LIEUX du club ({@see Place}) : il vit une fois, au club, au-delà des
 * saisons. Deux minutes par couple : le temps ACCEPTABLE en voiture (`drivingMinutes`) et en mode non
 * véhiculé (`walkingMinutes` — vélo ou trottinette, le nom technique reste), appliqués par le solveur
 * selon que le coach est véhiculé ou non.
 *
 * Couple non ordonné, rangé « plus petit identifiant de lieu d'abord » (`placeAId < placeBId`, contrôle
 * en base) : A–B ≡ B–A, une ligne par couple et par club. À la sortie (moteur, API), le couple est servi
 * pour une saison, nommé par les gymnases de cette saison et rangé selon eux
 * ({@see PlaceTravelTimeRepository::forSeason}) ; un lieu sans gymnase dans la saison n'y paraît pas.
 *
 * Deux clés étrangères COMPOSÉES `(place_a_id, club_id)` et `(place_b_id, club_id)` → `place (id,
 * club_id)`, `ON DELETE CASCADE`, posées à la main en migration (invisibles de Doctrine) : un temps ne
 * relie jamais les lieux de deux clubs, et il part avec l'un de ses lieux.
 *
 * Minutes NULLABLES : null = mode jamais renseigné. `drivingSource`/`walkingSource` (AUTO|MANUAL) ne
 * portent une valeur que quand la minute correspondante est renseignée. Une valeur MANUAL n'est
 * JAMAIS écrasée par le calcul automatique.
 */
#[Family(Level::CLUB, keptAtSeasonPurge: 'temps de trajet au club, entre deux lieux, au-delà d\'une saison : il part avec son lieu (ON DELETE CASCADE) quand UnusedIdentitySweeper balaie un lieu sans gymnase')]
#[ORM\Entity(repositoryClass: PlaceTravelTimeRepository::class)]
#[ORM\Table(name: 'place_travel_time')]
#[ORM\UniqueConstraint(name: 'uniq_place_travel_time_couple', columns: ['club_id', 'place_a_id', 'place_b_id'])]
#[ORM\Index(name: 'idx_place_travel_time_place_a', columns: ['place_a_id'])]
#[ORM\Index(name: 'idx_place_travel_time_place_b', columns: ['place_b_id'])]
#[ORM\HasLifecycleCallbacks]
class PlaceTravelTime implements TenantOwnedInterface
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

    /**
     * Invariant : placeAId < placeBId ({@see between}). Noms de colonne explicites : la stratégie
     * underscore de Doctrine rendrait `place_aid`.
     */
    #[ORM\Column(name: 'place_a_id', type: 'guid')]
    #[RefersTo(Place::class, OnDelete::DELETE)]
    private string $placeAId;

    #[ORM\Column(name: 'place_b_id', type: 'guid')]
    #[RefersTo(Place::class, OnDelete::DELETE)]
    private string $placeBId;

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
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $this->id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** Le couple de deux lieux du club, rangé plus petit identifiant d'abord. */
    public static function between(string $clubId, string $placeX, string $placeY): self
    {
        return (new self)->setClubId($clubId)->setPlaces($placeX, $placeY);
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

    public function getPlaceAId(): string
    {
        return $this->placeAId;
    }

    public function getPlaceBId(): string
    {
        return $this->placeBId;
    }

    /** Pose le couple, rangé plus petit identifiant de lieu d'abord (A–B ≡ B–A). */
    public function setPlaces(string $placeX, string $placeY): self
    {
        [$this->placeAId, $this->placeBId] = strcmp($placeX, $placeY) > 0 ? [$placeY, $placeX] : [$placeX, $placeY];

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
}
