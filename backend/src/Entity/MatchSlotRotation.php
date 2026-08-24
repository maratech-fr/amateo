<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * RMM-5 (P2-49) — un créneau de match PARTAGÉ entre N équipes qui l'occupent en
 * alternance (« rotation A/B ») : le cas SM1/SM2 sur le 20h30 — semaine A SM1 reçoit,
 * semaine B SM2 reçoit, sur le MÊME créneau physique (refonte-module-matchs.md §8).
 *
 * Le module matchs vit HORS des plans de période (pas de ``schedulePlanId``, patron
 * {@see TeamMatchHabit}/{@see VenueMatchWindow}) : fait saison-scopé, tenant-owned
 * (``club_id``), RLS canon.
 *
 * ⚠ ``venueId`` NOT NULL, à la différence d'une habitude : un créneau partagé SANS gymnase
 * n'est pas un créneau. Un jour ISO + une heure de coup d'envoi complètent l'identité
 * physique — d'où l'unicité ``(club_id, season_id, venue_id, day_of_week, kickoff_time)`` :
 * un même créneau physique ne porte qu'UNE rotation. Les membres (ordonnés) vivent dans
 * {@see MatchSlotRotationTeam}.
 *
 * PR-1 = le MODÈLE et son CRUD seuls. Rien ne le CONSOMME encore (ni payload solveur, ni
 * radar, ni jour de repos dérivé) — c'est voulu : payload/solveur = PR-2/3, UI = plus tard.
 */
#[ORM\Entity]
#[ORM\Table(name: 'match_slot_rotation')]
#[ORM\UniqueConstraint(name: 'uniq_match_slot_rotation_slot', columns: ['club_id', 'season_id', 'venue_id', 'day_of_week', 'kickoff_time'])]
#[ORM\Index(name: 'idx_match_slot_rotation_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_match_slot_rotation_venue', columns: ['venue_id'])]
#[ORM\HasLifecycleCallbacks]
class MatchSlotRotation implements TenantOwnedInterface
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

    /** The shared match slot's venue — NOT NULL (a shared slot without a venue is not a slot). */
    #[ORM\Column(type: 'guid')]
    private string $venueId;

    /** ISO 1 (Monday) .. 7 (Sunday). */
    #[ORM\Column(type: 'smallint')]
    private int $dayOfWeek;

    /** The shared kickoff instant of the rotation. */
    #[ORM\Column(type: 'time_immutable')]
    private DateTimeImmutable $kickoffTime;

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

    public function getKickoffTime(): DateTimeImmutable
    {
        return $this->kickoffTime;
    }

    public function setKickoffTime(DateTimeImmutable $kickoffTime): self
    {
        $this->kickoffTime = $kickoffTime;

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
