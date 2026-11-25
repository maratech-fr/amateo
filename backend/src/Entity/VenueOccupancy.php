<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VenueOccupancyRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une OCCUPATION de gymnase : un intervalle nommé où le gymnase n'est pas au club (« École »,
 * mardi 14:00-16:15). Elle se définit comme un créneau (jour, début, durée) et vit dans la même
 * grille, mais n'informe que le lecteur : aucun lecteur de `VenueTrainingSlot` ne la voit, elle
 * n'entre ni dans l'envoi au moteur, ni dans l'empreinte, ni dans le Récap.
 *
 * ⚠ Rien à voir avec `VenueWindowOccupancy` / `ReservationGroupOccupancy` (remplissage d'une
 * fenêtre par des séances) : homonymie de vocabulaire seulement.
 *
 * `schedulePlanId` : le plan de saison pour une occupation du socle, un plan de période pour la copie
 * qu'il porte (prise à sa naissance, comme la grille de créneaux — ADR-0002). Aucune clé étrangère sur
 * `venueId` : une occupation dont le gymnase est absent de la version chargée DORT (en base,
 * absente de la liste) et revient avec lui.
 */
#[Family(Level::PLAN, copiedAtPlanBirth: true, cold: [], copiedToNextSeason: true)]
#[ORM\Entity(repositoryClass: VenueOccupancyRepository::class)]
#[ORM\Table(name: 'venue_occupancy')]
#[ORM\Index(name: 'idx_venue_occupancy_club_venue', columns: ['club_id', 'venue_id'])]
#[ORM\Index(name: 'idx_venue_occupancy_schedule_plan', columns: ['schedule_plan_id'])]
#[ORM\HasLifecycleCallbacks]
class VenueOccupancy implements TenantOwnedInterface
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

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Venue::class, OnDelete::DELETE)]
    private string $venueId;

    #[ORM\Column(type: 'smallint')]
    private int $dayOfWeek;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $startTime;

    #[ORM\Column(type: 'integer')]
    private int $durationMinutes;

    #[ORM\Column(type: 'string', length: 40)]
    private string $label = '';

    #[ORM\Column(type: 'guid')]
    #[RefersTo(SchedulePlan::class, OnDelete::DELETE)]
    private string $schedulePlanId;

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

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getVenueId(): string
    {
        return $this->venueId;
    }

    public function setVenueId(string $venueId): self
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getStartTime(): DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): self
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getSchedulePlanId(): string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

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
