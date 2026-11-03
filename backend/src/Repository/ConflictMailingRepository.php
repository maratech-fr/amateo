<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConflictMailing;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConflictMailing>
 */
final class ConflictMailingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConflictMailing::class);
    }

    /** La mémoire du dernier envoi de la saison (filtres tenant déjà posés : le club courant). */
    public function findOneBySeason(string $seasonId): ?ConflictMailing
    {
        return $this->findOneBy(['seasonId' => $seasonId]);
    }

    /**
     * Écrit (ou remplace) la ligne du (club, saison) en une instruction : `ON CONFLICT DO UPDATE`
     * sur la clé unique — jamais « lecture puis insertion » qui perdrait l'unicité sous la course.
     * SQL brut sous le GUC tenant (la RLS vérifie `club_id`).
     */
    public function upsert(ConflictMailing $row): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO conflict_mailing (id, club_id, season_id, sent_at, sent_by_user_id, recipient_coach_ids, skipped_coach_ids, mailed_count)
             VALUES (:id, :club, :season, :sentAt, :by, :recipients, :skipped, :mailed)
             ON CONFLICT (club_id, season_id) DO UPDATE SET
                 sent_at = EXCLUDED.sent_at,
                 sent_by_user_id = EXCLUDED.sent_by_user_id,
                 recipient_coach_ids = EXCLUDED.recipient_coach_ids,
                 skipped_coach_ids = EXCLUDED.skipped_coach_ids,
                 mailed_count = EXCLUDED.mailed_count',
            [
                'id' => $row->getId(),
                'club' => $row->getClubId(),
                'season' => $row->getSeasonId(),
                'sentAt' => $row->getSentAt()->format(DateTimeInterface::ATOM),
                'by' => $row->getSentByUserId(),
                'recipients' => json_encode($row->getRecipientCoachIds(), \JSON_THROW_ON_ERROR),
                'skipped' => json_encode($row->getSkippedCoachIds(), \JSON_THROW_ON_ERROR),
                'mailed' => $row->getMailedCount(),
            ],
        );
    }
}
