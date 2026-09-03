<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PublicHoliday;
use App\Entity\SchoolHolidayPeriod;
use App\Repository\PublicHolidayRepository;
use App\Repository\SchoolHolidayPeriodRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seeds the GLOBAL holiday reference tables (vacances scolaires + jours fériés)
 * from versioned offline JSON — no network. Idempotent upsert by natural key.
 * Driven by the `app:{school,public}-holidays:seed` commands, which every seed
 * path runs (`make seed-holidays`, `make play`, the smoke, CI) — a seeded club's
 * school zone then actually shows holidays.
 *
 * @phpstan-type SeedResult array{created: int, updated: int, skipped: int}
 */
final class HolidaySeeder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolHolidayPeriodRepository $schoolRepository,
        private readonly PublicHolidayRepository $publicRepository,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {}

    public function defaultSchoolFile(): string
    {
        return $this->projectDir . '/data/school-holidays.fr-metropole.json';
    }

    public function defaultPublicFile(): string
    {
        return $this->projectDir . '/data/public-holidays.fr-national.json';
    }

    /**
     * @throws JsonException
     *
     * @return SeedResult
     */
    public function seedSchool(?string $file = null): array
    {
        $data = $this->readJson($file ?? $this->defaultSchoolFile());
        /** @var list<array<string, string>> $periods */
        $periods = $data['periods'] ?? [];

        $created = $updated = $skipped = 0;
        foreach ($periods as $row) {
            $zone = $row['zone'] ?? '';
            $type = $row['holidayType'] ?? '';
            $year = $row['schoolYear'] ?? '';
            $start = $this->parseDate($row['startDate'] ?? null);
            $end = $this->parseDate($row['endDate'] ?? null);
            if (\in_array('', [$zone, $type, $year], true) || !$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
                ++$skipped;

                continue;
            }

            $entity = $this->schoolRepository->findOneByNaturalKey($zone, $type, $year);
            if (!$entity instanceof SchoolHolidayPeriod) {
                $entity = (new SchoolHolidayPeriod)->setZone($zone)->setHolidayType($type)->setSchoolYear($year);
                $this->entityManager->persist($entity);
                ++$created;
            } else {
                ++$updated;
            }
            $entity->setLabel($row['label'] ?? '')->setStartDate($start)->setEndDate($end);
        }

        $this->entityManager->flush();

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @throws JsonException
     *
     * @return SeedResult
     */
    public function seedPublic(?string $file = null): array
    {
        $data = $this->readJson($file ?? $this->defaultPublicFile());
        /** @var array<string, string> $holidays */
        $holidays = $data['holidays'] ?? [];

        $created = $updated = $skipped = 0;
        foreach ($holidays as $dateStr => $label) {
            $date = $this->parseDate((string) $dateStr);
            if (!$date instanceof DateTimeImmutable || '' === (string) $label) {
                ++$skipped;

                continue;
            }

            $entity = $this->publicRepository->findOneByNaturalKey(PublicHoliday::NATIONAL, $date);
            if (!$entity instanceof PublicHoliday) {
                $entity = (new PublicHoliday)->setZone(PublicHoliday::NATIONAL)->setDate($date);
                $this->entityManager->persist($entity);
                ++$created;
            } else {
                ++$updated;
            }
            $entity->setLabel((string) $label);
        }

        $this->entityManager->flush();

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException(\sprintf('Holiday source file not found: %s', $file));
        }
        $raw = file_get_contents($file);
        if (false === $raw) {
            throw new RuntimeException(\sprintf('Could not read holiday source file: %s', $file));
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        if (null === $value || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        // getLastErrors catches format-valid but calendar-invalid dates ("2028-02-31").
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
