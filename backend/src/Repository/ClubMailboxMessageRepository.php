<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubMailboxMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubMailboxMessage>
 */
final class ClubMailboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubMailboxMessage::class);
    }

    /**
     * Écrit une ligne SANS passer par le flush de l'EM : l'interception se joue dans un
     * `MessageEvent` déclenché par `Mailer::send()`, souvent au milieu d'une requête ou d'un
     * cron — un `flush()` global y viderait des changements en attente d'autrui. Un INSERT
     * direct sur la connexion runtime (GUC déjà posé → WITH CHECK tenant OK) évite ce risque.
     */
    public function record(ClubMailboxMessage $message): void
    {
        $this->getEntityManager()->getConnection()->insert('club_mailbox_message', [
            'id' => $message->getId(),
            'club_id' => $message->getClubId(),
            'created_at' => $message->getCreatedAt()->format('Y-m-d H:i:sP'),
            'simulated_date' => $message->getSimulatedDate()->format('Y-m-d'),
            'from_address' => $message->getFromAddress(),
            'to_address' => $message->getToAddress(),
            'subject' => $message->getSubject(),
            'body_text' => $message->getBodyText(),
            'body_html' => $message->getBodyHtml(),
        ]);
    }

    /**
     * La boîte du club, la plus récente d'abord (par instant RÉEL d'écriture). Le filtre
     * explicite sur `clubId` double la RLS/Doctrine — défense en profondeur, jamais la seule.
     *
     * @return list<ClubMailboxMessage>
     */
    public function findForClubNewestFirst(string $clubId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.clubId = :clubId')
            ->setParameter('clubId', $clubId)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countForClub(string $clubId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.clubId = :clubId')
            ->setParameter('clubId', $clubId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneForClub(string $id, string $clubId): ?ClubMailboxMessage
    {
        return $this->findOneBy(['id' => $id, 'clubId' => $clubId]);
    }
}
