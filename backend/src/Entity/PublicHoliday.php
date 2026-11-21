<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PublicHolidayRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * National / territorial public-holiday reference (jours fériés, etalab open data).
 * GLOBAL, not tenant-owned: same for every club, no club_id → no RLS (public
 * reference, like `school_holiday_period`). Imported from the official etalab API
 * (app:public-holidays:import). Display-only — never feeds the solver.
 *
 * `zone` is either the synthetic marker `NATIONAL` (métropole fériés, apply to
 * every club) or one of the DOM/TOM codes of SchoolZoneResolver::ZONES for
 * territory-specific fériés (e.g. Abolition de l'esclavage → GUADELOUPE).
 * `NATIONAL` is intentionally NOT part of ZONES — it only lives in this column.
 * The DB column is `holiday_date` (`date` is a reserved SQL word).
 * See specs/evolution/roadmap.md §2.
 */
#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: PublicHolidayRepository::class)]
#[ORM\Table(name: 'public_holiday')]
#[ORM\UniqueConstraint(name: 'uniq_public_holiday_zone_date', columns: ['zone', 'holiday_date'])]
class PublicHoliday
{
    public const NATIONAL = 'NATIONAL';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 24)]
    private string $zone;

    #[ORM\Column(name: 'holiday_date', type: 'date_immutable')]
    private DateTimeImmutable $date;

    #[ORM\Column(type: 'string', length: 120)]
    private string $label;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $this->createdAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getZone(): string
    {
        return $this->zone;
    }

    public function setZone(string $zone): self
    {
        $this->zone = $zone;

        return $this;
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(DateTimeImmutable $date): self
    {
        $this->date = $date;

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

    public function isNational(): bool
    {
        return self::NATIONAL === $this->zone;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
