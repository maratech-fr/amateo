<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feedback;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feedback>
 */
final class FeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feedback::class);
    }

    /**
     * D16 (anti double envoi) — le dernier signalement IDENTIQUE (même club + même auteur + même
     * topic + même message) créé depuis `$since`, s'il existe. Le `createdAt` de l'entité est posé à
     * l'horloge RÉELLE (constructeur) : la fenêtre se compare donc à l'heure réelle, jamais à l'horloge
     * métier simulée. Tenant-scopé (RLS + clause `clubId` explicite).
     */
    public function findRecentDuplicate(string $clubId, string $userId, string $topic, string $message, DateTimeImmutable $since): ?Feedback
    {
        return $this->createQueryBuilder('f')
            ->where('f.clubId = :club')
            ->andWhere('f.userId = :user')
            ->andWhere('f.topic = :topic')
            ->andWhere('f.message = :message')
            ->andWhere('f.createdAt >= :since')
            ->setParameter('club', $clubId)
            ->setParameter('user', $userId)
            ->setParameter('topic', $topic)
            ->setParameter('message', $message)
            ->setParameter('since', $since)
            ->orderBy('f.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
