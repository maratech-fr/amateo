<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReservationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A manager pinning a team onto a precise availability slot (day + time + venue),
 * enforced as a HARD lock at solve time. Unlike ScheduleSlotTemplate (which ALSO
 * stores solve RESULTS keyed to an ephemeral schedule), a Reservation is durable
 * pre-generation intent, layered: `schedulePlanId` NULL = base
 * plan, set = a period overlay. The generation pipeline reads these into the
 * engine's `slotTemplates` payload.
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\Index(name: 'idx_reservation_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_reservation_schedule_plan', columns: ['schedule_plan_id'])]
#[ORM\Index(name: 'idx_reservation_team', columns: ['team_id'])]
// Colonnes de l'unicité seulement — le NULLS NOT DISTINCT (qui fait collisionner deux
// réservations de SOCLE, plan NULL, de la même équipe sur la même case) vit dans la migration :
// Doctrine ne sait pas l'exprimer en attribut, et aucun gate schema:validate ne lit cet attribut.
#[ORM\UniqueConstraint(name: 'uniq_reservation_case_team', columns: ['club_id', 'season_id', 'schedule_plan_id', 'venue_id', 'day_of_week', 'start_time', 'team_id'])]
#[ORM\HasLifecycleCallbacks]
class Reservation implements TenantOwnedInterface
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
     * ADR-0002 inv. 5 (lot C3) — la réservation s'accroche au PLAN : « je pose cette équipe
     * DANS CE PLANNING » est une réponse. null = la réservation de base (structure PARTAGÉE,
     * inv. 6), qui alimente la génération du socle.
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $schedulePlanId = null;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    #[ORM\Column(type: 'guid')]
    private string $venueId;

    #[ORM\Column(type: 'smallint')]
    private int $dayOfWeek;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $startTime;

    #[ORM\Column(type: 'smallint')]
    private int $durationMinutes;

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

    public function getSchedulePlanId(): ?string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(?string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): self
    {
        $this->teamId = $teamId;

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

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
