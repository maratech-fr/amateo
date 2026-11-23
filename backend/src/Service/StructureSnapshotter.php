<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\PlanTeam;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Plans\SoclePlan;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * planning-versions D2: captures a FAITHFUL photo of the club structure at the
 * moment a season-plan version is generated, so the D3 restore ("Travailler
 * sur cette version") can rebuild the workspace exactly as it was.
 *
 * Schedule.snapshotData cannot serve this purpose: it is the transformed solver
 * payload (engine vocabulary — targetTag expanded, venue_closed rewritten…),
 * lossy for the backend entities (coach links, reservations, tiers).
 *
 * Two-phase by design (code-review D2):
 * - `serialize()` runs PRE-solve (pure read, consistent with the frozen
 *   payload; a failure cannot poison the EntityManager — nothing is written);
 * - `store()` runs only AFTER the solve COMPLETED, as a direct DBAL
 *   `INSERT … ON CONFLICT DO UPDATE`: no UnitOfWork flush (a failure cannot
 *   close the EM mid-generation), idempotent under a concurrent duplicate
 *   (lost Redis lock), and a FAILED regeneration never overwrites the photo
 *   of the still-visible plan.
 *
 * Serialization is GENERIC via Doctrine ClassMetadata (field name → column
 * value), faithful column by column, zero per-entity code. Normalisation:
 * DateTime → ATOM, BackedEnum → value, arrays as-is.
 */
final class StructureSnapshotter
{
    /**
     * The structural families of a (club, season). Dated constraints and
     * period-overlay reservations belong to the CALENDAR, not the structure —
     * see StructureAnchor::sharedStructureCriteria.
     */
    private const FAMILIES = [
        SportCategory::class,
        Team::class,
        // Lot 4, PR 3a : séances et plafond par jour de chaque équipe, sur sa ligne du plan de saison.
        PlanTeam::class,
        Venue::class,
        VenueTrainingSlot::class,
        Coach::class,
        TeamCoach::class,
        CoachPlayerMembership::class,
        Constraint::class,
        Reservation::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SoclePlan $soclePlan,
    ) {}

    /**
     * Pure read — serialize the structure that is about to feed the solver.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function serialize(string $clubId, string $seasonId): array
    {
        $data = [];
        foreach (self::FAMILIES as $entityClass) {
            $data[$this->familyKey($entityClass)] = array_map(
                fn (object $row): array => $this->serializeRow($row),
                $this->loadFamily($entityClass, $clubId, $seasonId),
            );
        }

        return $data;
    }

    /**
     * Persist the photo attached to this schedule version — direct DBAL upsert,
     * deliberately OUTSIDE the ORM UnitOfWork (see class docblock).
     *
     * @param array<string, list<array<string, mixed>>> $data
     */
    public function store(Schedule $schedule, array $data): void
    {
        $now = new DateTimeImmutable;
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO schedule_structure_snapshot (id, created_at, updated_at, club_id, season_id, schedule_id, data)
             VALUES (:id, :now, :now, :clubId, :seasonId, :scheduleId, :data)
             ON CONFLICT (schedule_id) DO UPDATE SET data = EXCLUDED.data, updated_at = EXCLUDED.updated_at',
            [
                'id' => $this->newUuid(),
                'now' => $now->format(\DATE_ATOM),
                'clubId' => $schedule->getClubId(),
                'seasonId' => $schedule->getSeasonId(),
                'scheduleId' => $schedule->getId(),
                'data' => json_encode($data, \JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @param class-string $entityClass
     *
     * @return list<object>
     */
    private function loadFamily(string $entityClass, string $clubId, string $seasonId): array
    {
        // SportCategory is club-scoped but season-less — le seul cas particulier.
        $criteria = match ($entityClass) {
            SportCategory::class => ['clubId' => $clubId],
            default => ['clubId' => $clubId, 'seasonId' => $seasonId],
        };
        // Only the PERMANENT structure — les lignes de période n'y entrent pas. L'ancre
        // diffère selon l'entité (voir StructureAnchor, qui porte le pourquoi) : la photo prend
        // la grille et les réservations du plan de saison, les contraintes permanentes.
        if (StructureAnchor::isPeriodScoped($entityClass)) {
            $criteria += StructureAnchor::sharedStructureCriteria($entityClass, $this->soclePlan->idOf($seasonId));
        }

        return $this->entityManager->getRepository($entityClass)->findBy($criteria);
    }

    /** @return array<string, mixed> */
    private function serializeRow(object $entity): array
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        $row = [];
        foreach ($metadata->getFieldNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            $row[$field] = match (true) {
                $value instanceof DateTimeInterface => $value->format(\DATE_ATOM),
                $value instanceof BackedEnum => $value->value,
                default => $value,
            };
        }

        return $row;
    }

    /** @param class-string $entityClass */
    private function familyKey(string $entityClass): string
    {
        $pos = strrpos($entityClass, '\\');

        return false === $pos ? $entityClass : substr($entityClass, $pos + 1);
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
