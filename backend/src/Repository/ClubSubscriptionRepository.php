<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lecture de la ligne d'abonnement d'une saison, par la connexion applicative : la RLS la borne au
 * locataire posé, la requête nomme le club lu.
 *
 * @extends ServiceEntityRepository<ClubSubscription>
 */
final class ClubSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubSubscription::class);
    }

    /**
     * Requête native, relue en base à chaque appel : la ligne ne s'écrit que par SQL (connexion
     * propriétaire), jamais par l'ORM — une instance gardée par la carte d'identité serait périmée
     * après un geste du support. Le filtre Doctrine `tenant_filter` (qui ne s'applique pas à une
     * requête native, patron de `ClubUserRepository::findActiveClubIds`) ne restreindrait qu'au club
     * de la requête, déjà nommé ici et borné par la RLS.
     */
    public function forSeasonYear(string $clubId, int $seasonYear): ?ClubSubscription
    {
        $mapping = new ResultSetMappingBuilder($this->getEntityManager());
        $mapping->addRootEntityFromClassMetadata(ClubSubscription::class, 'cs');

        $line = $this->getEntityManager()
            ->createNativeQuery(\sprintf('SELECT %s FROM club_subscription cs WHERE cs.club_id = :clubId AND cs.season_year = :seasonYear', $mapping->generateSelectClause()), $mapping)
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonYear', $seasonYear)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $line instanceof ClubSubscription ? $line : null;
    }
}
