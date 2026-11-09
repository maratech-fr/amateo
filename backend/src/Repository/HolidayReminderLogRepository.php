<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HolidayReminderLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HolidayReminderLog>
 */
final class HolidayReminderLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HolidayReminderLog::class);
    }

    public function alreadySent(string $seasonId, string $schoolHolidayId, int $threshold): bool
    {
        return null !== $this->findOneBy(['seasonId' => $seasonId, 'schoolHolidayId' => $schoolHolidayId, 'threshold' => $threshold]);
    }

    /**
     * Journalise un palier envoyé. DBAL `ON CONFLICT … DO NOTHING` sur la liste de colonnes de
     * l'index unique (pas une contrainte nommée) : une ligne posée entre-temps par un passage
     * concurrent est tolérée — un persist+flush lèverait la violation et fermerait l'EntityManager
     * pour le reste du passage.
     */
    public function markSent(string $seasonId, string $schoolHolidayId, int $threshold): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO holiday_reminder_log (id, season_id, school_holiday_id, threshold, sent_at) '
            . 'VALUES (gen_random_uuid(), :season, :holiday, :threshold, now()) '
            . 'ON CONFLICT (season_id, school_holiday_id, threshold) DO NOTHING',
            [
                'season' => $seasonId,
                'holiday' => $schoolHolidayId,
                'threshold' => $threshold,
            ],
        );
    }
}
