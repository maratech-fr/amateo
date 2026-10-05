<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubInvitation;
use App\Service\TenantConnectionContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubInvitation>
 */
final class ClubInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly TenantConnectionContext $tenantContext)
    {
        parent::__construct($registry, ClubInvitation::class);
    }

    /**
     * Résolution d'un jeton (page PUBLIQUE) par son hash. Lecture HORS contexte
     * tenant (patron ClubUserRepository::findActiveClubIds) : c'est la ligne lue qui
     * PORTE le club, donc elle doit être trouvable AVANT que le GUC soit posé — et
     * aussi quand l'appelant authentifié (accept en un clic) porte le GUC d'un AUTRE
     * club. La branche SELECT hybride RLS (ouverte GUC vide) couvre la base ; runWithoutTenant
     * garantit l'ouverture même si un GUC traîne. Le hash (sha256 d'un secret de 32 octets,
     * UNIQUE) rend ce SELECT cross-tenant sûr : aucun oracle, l'inconnu rend null.
     */
    public function findOneByTokenHash(string $tokenHash): ?ClubInvitation
    {
        return $this->tenantContext->runWithoutTenant(
            fn (): ?ClubInvitation => $this->findOneBy(['tokenHash' => $tokenHash]),
        );
    }

    /**
     * L'invitation vivante d'un couple (club courant, adresse) — tenant-scopée par le
     * GUC posé. Sert l'absorption « renvoyer » (régénère sur la même ligne, jamais une
     * seconde). L'unicité (club_id, email) la garantit unique.
     */
    public function findOneByClubAndEmail(string $clubId, string $email): ?ClubInvitation
    {
        return $this->findOneBy(['clubId' => $clubId, 'email' => $email]);
    }

    /**
     * Les invitations du club courant, les plus récentes d'abord. Tenant-scopé par le
     * GUC (RLS + filtre Doctrine) — jamais celles d'un autre club.
     *
     * @return list<ClubInvitation>
     */
    public function findForClub(string $clubId): array
    {
        /** @var list<ClubInvitation> $rows */
        $rows = $this->createQueryBuilder('i')
            ->where('i.clubId = :clubId')
            ->setParameter('clubId', $clubId)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
