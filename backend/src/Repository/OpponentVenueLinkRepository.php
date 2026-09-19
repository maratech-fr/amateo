<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OpponentVenueLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpponentVenueLink>
 *
 * L'appariement « libellé FBI → gymnase » d'un club, club-scoped SANS saison (le
 * filtre tenant Doctrine + la RLS bornent déjà les lectures au club courant ; le
 * `clubId` explicite double la borne au niveau applicatif, patron des autres repos).
 */
final class OpponentVenueLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpponentVenueLink::class);
    }

    /**
     * Tous les liens du club (le grain n'a pas de saison). Ordre stable par code puis
     * libellé — l'API groupe par club adverse et la projection y lit son repli « gymnase
     * le plus fréquent ».
     *
     * @return list<OpponentVenueLink>
     */
    public function findByClub(string $clubId): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.clubId = :clubId')
            ->setParameter('clubId', $clubId)
            ->orderBy('l.opponentOrganismeCode', 'ASC')
            ->addOrderBy('l.fbiLabelNorm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les liens d'un adversaire précis du club (les gymnases connus de ce club adverse).
     *
     * @return list<OpponentVenueLink>
     */
    public function findByCode(string $clubId, string $opponentOrganismeCode): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.clubId = :clubId')
            ->andWhere('l.opponentOrganismeCode = :code')
            ->setParameter('clubId', $clubId)
            ->setParameter('code', $opponentOrganismeCode)
            ->orderBy('l.fbiLabelNorm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le lien au grain exact `(club, code, libellé FBI normalisé)`, ou null. C'est la
     * clé qu'une rencontre AWAY résout par le libellé de SA salle et que l'auto-
     * localisateur upserte.
     */
    public function findOneByKey(string $clubId, string $opponentOrganismeCode, string $fbiLabelNorm): ?OpponentVenueLink
    {
        return $this->findOneBy([
            'clubId' => $clubId,
            'opponentOrganismeCode' => $opponentOrganismeCode,
            'fbiLabelNorm' => $fbiLabelNorm,
        ]);
    }
}
