<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VenueTrainingSlotRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VenueTrainingSlotRepository::class)]
#[ORM\Table(name: 'venue_training_slot')]
#[ORM\Index(name: 'idx_venue_training_slot_club_venue', columns: ['club_id', 'venue_id'])]
#[ORM\HasLifecycleCallbacks]
class VenueTrainingSlot implements TenantOwnedInterface
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
    private string $venueId;

    #[ORM\Column(type: 'smallint')]
    private int $dayOfWeek;

    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $startTime;

    #[ORM\Column(type: 'integer')]
    private int $durationMinutes;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $capacity = 1;

    /**
     * Libellé libre d'un créneau MUTUALISÉ (« CEC3 ») — deux équipes s'entraînent ensemble et on
     * le NOMME. Esthétique pur : le solveur ne le consomme pas, il n'apparaît qu'à l'écran et
     * dans les exports. Posé ⟺ fusion (pas de booléen séparé) ; n'a de sens qu'avec capacity ≥ 2
     * (garde côté processeur). null = créneau ordinaire sans libellé.
     *
     * SAISON SEULEMENT (décision fondateur 2026-10-11) : le libellé appartient au planning de
     * saison. Un créneau rattaché à un plan (schedulePlanId non null = créneau de période) ne le
     * porte jamais — la copie prise à la naissance du plan l'émet NULL, et le processeur refuse
     * de l'y poser. null constant sur toute la grille de période.
     */
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $groupLabel = null;

    /**
     * ADR-0002 inv. 5 (lot C3) — le créneau PRÊTÉ s'accroche au PLAN : « la mairie me prête
     * ce gymnase POUR CET AJUSTEMENT » est une réponse, pas un fait. null = le créneau
     * saisonnier permanent (structure PARTAGÉE, inv. 6 — surtout pas rattaché au plan
     * SEASON : la structure n'est pas un réglage de période).
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $schedulePlanId = null;

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

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): self
    {
        $this->capacity = $capacity;

        return $this;
    }

    public function getGroupLabel(): ?string
    {
        return $this->groupLabel;
    }

    public function setGroupLabel(?string $groupLabel): self
    {
        $this->groupLabel = $groupLabel;

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

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
