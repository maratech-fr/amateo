<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SchoolHolidayPeriodRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * National school-holiday reference data (Éducation nationale open data).
 * GLOBAL, not tenant-owned: same for every club, keyed on the school zone
 * (A/B/C, CORSE, or a DOM/TOM territory code — see SchoolZoneResolver::ZONES)
 * which a club derives from its FFBB code. No club_id → no RLS (public
 * reference, like the `sport` table). Populated from a versioned JSON fallback
 * (app:school-holidays:seed) or the official ODS API (app:school-holidays:import).
 * holidayType is an OPEN slug (métropole + overseas labels differ).
 * See specs/courantes/accueil-cockpit-temporel.md §4bis.
 */
#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: SchoolHolidayPeriodRepository::class)]
#[ORM\Table(name: 'school_holiday_period')]
#[ORM\UniqueConstraint(name: 'uniq_school_holiday_zone_type_year', columns: ['zone', 'holiday_type', 'school_year'])]
#[ORM\Index(name: 'idx_school_holiday_zone_start', columns: ['zone', 'start_date'])]
#[ORM\HasLifecycleCallbacks]
class SchoolHolidayPeriod
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 24)]
    private string $zone;

    #[ORM\Column(type: 'string', length: 120)]
    private string $label;

    #[ORM\Column(type: 'string', length: 40)]
    private string $holidayType;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $endDate;

    #[ORM\Column(type: 'string', length: 9)]
    private string $schoolYear;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $this->createdAt = new DateTimeImmutable;
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getHolidayType(): string
    {
        return $this->holidayType;
    }

    public function setHolidayType(string $holidayType): self
    {
        $this->holidayType = $holidayType;

        return $this;
    }

    public function getStartDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getSchoolYear(): string
    {
        return $this->schoolYear;
    }

    public function setSchoolYear(string $schoolYear): self
    {
        $this->schoolYear = $schoolYear;

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
