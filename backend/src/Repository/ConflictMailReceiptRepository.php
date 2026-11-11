<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConflictMailReceipt;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Les reçus par conflit de l'envoi aux coachs. SQL brut sur LA connexion de l'EntityManager —
 * celle où le GUC tenant du club est posé (la RLS vérifie `club_id`), celle aussi de la transaction
 * qui écrit la mémoire {@see ConflictMailingRepository::upsert} : jamais une connexion distincte.
 *
 * @extends ServiceEntityRepository<ConflictMailReceipt>
 */
final class ConflictMailReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConflictMailReceipt::class);
    }

    /**
     * Les reçus de la saison (club courant, par la RLS) : coach → empreinte → digest et instant.
     *
     * @return array<string, array<string, array{digest: string, sentAt: DateTimeImmutable}>>
     */
    public function mapBySeason(string $seasonId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT coach_id, fingerprint, content_digest, sent_at FROM conflict_mail_receipt WHERE season_id = :season',
            ['season' => $seasonId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['coach_id']][(string) $row['fingerprint']] = [
                'digest' => (string) $row['content_digest'],
                'sentAt' => new DateTimeImmutable((string) $row['sent_at']),
            ];
        }

        return $out;
    }

    /**
     * Remplace les reçus d'UN coach par la liste qui vient de lui partir : ses reçus d'avant
     * disparaissent (un conflit disparu n'en garde pas), une ligne par empreinte (le paramètre est
     * déjà indexé par empreinte ; `ON CONFLICT` reste un filet).
     *
     * @param array<string, string> $digestByFingerprint empreinte → digest du contenu envoyé
     */
    public function replaceForCoach(string $clubId, string $seasonId, string $coachId, array $digestByFingerprint, DateTimeImmutable $sentAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM conflict_mail_receipt WHERE club_id = :club AND season_id = :season AND coach_id = :coach',
            ['club' => $clubId, 'season' => $seasonId, 'coach' => $coachId],
        );

        foreach ($digestByFingerprint as $fingerprint => $digest) {
            $connection->executeStatement(
                'INSERT INTO conflict_mail_receipt (id, club_id, season_id, fingerprint, coach_id, content_digest, sent_at)
                 VALUES (:id, :club, :season, :fingerprint, :coach, :digest, :sentAt)
                 ON CONFLICT (club_id, season_id, fingerprint, coach_id) DO UPDATE SET
                     content_digest = EXCLUDED.content_digest,
                     sent_at = EXCLUDED.sent_at',
                [
                    'id' => Uuid::v4()->toRfc4122(),
                    'club' => $clubId,
                    'season' => $seasonId,
                    'fingerprint' => (string) $fingerprint,
                    'coach' => $coachId,
                    'digest' => $digest,
                    'sentAt' => $sentAt->format(DateTimeInterface::ATOM),
                ],
            );
        }
    }

    /**
     * Supprime les reçus des coachs donnés pour la saison (prévenus « plus aucun conflit »). Liste
     * vide : rien — jamais une clause vide qui toucherait d'autres coachs.
     *
     * @param list<string> $coachIds
     */
    public function deleteForCoaches(string $seasonId, array $coachIds): void
    {
        if ([] === $coachIds) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM conflict_mail_receipt WHERE season_id = :season AND coach_id IN (:coaches)',
            ['season' => $seasonId, 'coaches' => $coachIds],
            ['coaches' => ArrayParameterType::STRING],
        );
    }
}
