<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubUser;
use App\Service\TenantConnectionContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubUser>
 */
final class ClubUserRepository extends ServiceEntityRepository
{
    /**
     * Roles allowed to manage a club (edit settings, import). 'owner' is the
     * highest role, 'admin' the operational one — both may manage. 'editor'
     * and 'viewer' may not. Single source of truth for the management gate.
     */
    private const MANAGEMENT_ROLES = ['owner', 'admin'];

    public function __construct(ManagerRegistry $registry, private readonly TenantConnectionContext $tenantContext)
    {
        parent::__construct($registry, ClubUser::class);
    }

    /**
     * The caller's active membership in a given club, or null. Centralises the
     * (userId, clubId, isActive) lookup reused by the Club provider/processor
     * and the import controller (audit SEC-01/04).
     */
    public function findActiveMembership(string $userId, string $clubId): ?ClubUser
    {
        return $this->findOneBy([
            'userId' => $userId,
            'clubId' => $clubId,
            'isActive' => true,
        ]);
    }

    /**
     * All club ids the caller is an active member of. Resolved with a raw DBAL
     * query so the Doctrine tenant_filter does not apply: ClubUser owns a
     * club_id, so a filtered ORM query would narrow the result to the single
     * active tenant and hide the caller's other clubs (audit finding on
     * ClubStateProvider). club_user is readable across the tenant boundary by
     * design (membership resolution bootstraps the tenant).
     *
     * SEC-12: the RLS SELECT policy is now scoped whenever the tenant GUC is
     * set, so this deliberately cross-tenant read steps out of the tenant
     * context for the duration of the query — the callers (club listing,
     * account erasure) run with a GUC pointing at ONE club, and the old open
     * policy silently hid that this query crossed the boundary.
     *
     * @return list<string>
     */
    public function findActiveClubIds(string $userId): array
    {
        /** @var list<string> $ids */
        $ids = $this->tenantContext->runWithoutTenant(
            fn (): array => $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT club_id FROM club_user WHERE user_id = :uid AND is_active = true',
                ['uid' => $userId],
            ),
        );

        return $ids;
    }

    /**
     * All club ids the user has EVER been a member of — `is_active` ignored.
     * Same cross-tenant idiom as findActiveClubIds (docblock above). Sert
     * l'effacement de compte (RMM-3) : la donnée personnelle d'un user (visite
     * du module matchs) doit mourir dans les clubs QUITTÉS aussi — la liste
     * des clubs actifs seule laisserait une ligne orpheline dans un club dont
     * l'adhésion a été désactivée avant l'effacement.
     *
     * @return list<string>
     */
    public function findMemberClubIds(string $userId): array
    {
        /** @var list<string> $ids */
        $ids = $this->tenantContext->runWithoutTenant(
            fn (): array => $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT club_id FROM club_user WHERE user_id = :uid',
                ['uid' => $userId],
            ),
        );

        return $ids;
    }

    /**
     * P1-1 (PR C) — les adhésions DÉSACTIVÉES d'un club (`isActive=false` ET
     * `deactivatedAt` posé). Distinctes des pending (`deactivatedAt=null`) : un
     * désactivé se RÉACTIVE, une pending s'approuve. Sert l'onglet « désactivés »
     * de l'écran de gestion des membres. Le `WHERE club_id` borne au tenant
     * courant (policy RLS scopée quand le GUC est posé).
     *
     * @return list<ClubUser>
     */
    public function findDeactivated(string $clubId): array
    {
        /** @var list<ClubUser> $rows */
        $rows = $this->createQueryBuilder('cu')
            ->where('cu.clubId = :clubId')
            ->andWhere('cu.isActive = false')
            ->andWhere('cu.deactivatedAt IS NOT NULL')
            ->setParameter('clubId', $clubId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function isManagementRole(string $role): bool
    {
        return \in_array($role, self::MANAGEMENT_ROLES, true);
    }

    /**
     * P1-1 (PR B) — les ids des adhésions management ACTIVES du club, VERROUILLÉES
     * (`FOR UPDATE`). Sert l'invariant « au moins un gestionnaire actif » : deux
     * rétrogradations/désactivations concurrentes ne peuvent pas converger vers
     * zéro gestionnaire — la seconde attend le verrou et relit un ensemble à jour.
     *
     * ⚠ À N'APPELER QUE dans une transaction (le `FOR UPDATE` est sinon un no-op).
     * Le `WHERE club_id` est le club courant (GUC posé) : la policy RLS scopée
     * l'autorise, et la garde borne d'elle-même la portée au tenant.
     *
     * @return list<string>
     */
    public function lockActiveManagementIds(string $clubId): array
    {
        $placeholders = implode(', ', array_fill(0, \count(self::MANAGEMENT_ROLES), '?'));

        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM club_user WHERE club_id = ? AND is_active = true AND role IN (' . $placeholders . ') FOR UPDATE',
            [$clubId, ...self::MANAGEMENT_ROLES],
        );

        return $ids;
    }

    /**
     * Emails of a club's active managers (owner + admin). Raw DBAL like
     * findActiveClubIds so the tenant_filter does not narrow the result — the
     * WHERE club_id already scopes it. Used by the reminder cron (no request
     * tenant context). Editors/viewers and inactive memberships are excluded.
     *
     * @return list<string>
     */
    public function findManagementEmails(string $clubId): array
    {
        $placeholders = implode(', ', array_fill(0, \count(self::MANAGEMENT_ROLES), '?'));

        /** @var list<string> $emails */
        $emails = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT u.email FROM club_user cu JOIN app_user u ON u.id = cu.user_id '
            . 'WHERE cu.club_id = ? AND cu.is_active = true AND cu.role IN (' . $placeholders . ')',
            [$clubId, ...self::MANAGEMENT_ROLES],
        );

        return $emails;
    }
}
