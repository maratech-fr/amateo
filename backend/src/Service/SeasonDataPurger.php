<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HolidayReminderLog;
use App\Entity\Season;
use App\Entity\TeamTagAssignment;
use App\Structure\FamilyCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes all data of a single (club, season), shared by ResetSeasonController (wipe a
 * season's contents, keep the row) and PurgeSeasonsCommand (retention purge — deletes the
 * Season row too).
 *
 * Ce qui part et dans quel ordre se lit dans la déclaration des familles ({@see FamilyCatalog}) :
 * les familles purgées via leur propriétaire d'abord, puis celles bornées par (club, saison),
 * chaque enfant avant le parent dont il meurt. Le nombre rendu compte chaque ligne effacée :
 * les créneaux et occupations d'un plan de période, autrefois emportés par la cascade de
 * leur plan hors du compte, y figurent désormais (mêmes lignes effacées, compte plus juste).
 *
 * Runs under the caller's tenant context (RLS GUC must already be set to the
 * club). Disables the tenant/season Doctrine filters for the bulk DQL DELETEs
 * (they alias the table name, which is invalid SQL for the reserved-word
 * `constraint` table); the deletes are explicitly scoped by clubId + seasonId
 * and RLS still enforces the club boundary at the DB.
 */
final class SeasonDataPurger
{
    use DisablesTenantFilters;

    /**
     * Tenant, mais purgées AUTREMENT que par les boucles sur le catalogue — chacune avec sa
     * raison. Le test PurgeCompletenessTest exige que toute entité tenant soit couverte ici,
     * par le catalogue, ou déclarée épargnée (#[Family(keptAtSeasonPurge: …)]).
     *
     * @var array<string, string> table => comment / pourquoi
     */
    public const HANDLED_APART = [
        'team_tag_assignment' => 'purgé par season_id seul (BCK-11 : porte un club_id mais le périmètre reste la saison, RLS borne le club)',
        'season' => 'la ligne pivot elle-même — supprimée conditionnellement ($deleteSeasonRow : purge de rétention / effacement, jamais le reset)',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly FamilyCatalog $familyCatalog,
    ) {}

    /**
     * @return int number of child rows deleted (excludes the Season row itself)
     */
    public function purge(string $clubId, string $seasonId, bool $deleteSeasonRow = false): int
    {
        $this->disableTenantFilters($this->entityManager);

        // ADR-0002 inv. 12 : le nom du planning vit sur le plan — et le plan est
        // supprimé plus bas. Avant la bascule il vivait sur la saison et survivait donc
        // au reset ; il faut le capturer AVANT la purge, sinon « réinitialiser la
        // saison » renomme silencieusement le planning du gestionnaire.
        $seasonPlanName = $deleteSeasonRow ? null : $this->currentSeasonPlanName($seasonId);

        $deleted = 0;

        // Les familles purgées via leur propriétaire d'abord (FamilyCatalog::purgedViaOwner),
        // résolues par la saison de ce propriétaire : elles doivent partir avant son DELETE
        // de masse, sinon la sous-requête ne les trouve plus et elles orphelinent en silence.
        // SolverMetric n'est VOLONTAIREMENT pas purgé au reset : télémétrie append-only
        // (décision fondateur 2026-07-18) — c'est de l'usage constaté, pas de la donnée
        // de saison ; il survit au reset en nommant des plannings supprimés (assumé, les
        // dimensions sont dénormalisées à la capture). L'effacement RGPD du club reste
        // sa seule porte de sortie (ErasedClubPurger, delete par clubId).
        foreach ($this->familyCatalog->purgedViaOwner() as $entityClass => [$parentRefField, $parentClass]) {
            $deleted += $this->deleteBySubQuery($entityClass, $parentRefField, $parentClass, $clubId, $seasonId);
        }

        // BCK-11 : la table porte désormais un club_id ; la purge reste par saison
        // (c'est son périmètre), et RLS borne la requête au club courant.
        $deleted += (int) $this->entityManager->createQueryBuilder()
            ->delete(TeamTagAssignment::class, 'e')
            ->where('e.seasonId = :seasonId')
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();

        // Journal des rappels de vacances scolaires : sans club_id ni RLS, clé par saison (uuid
        // propre au club) — vidé par saison, le palier repart après un reset.
        $deleted += (int) $this->entityManager->createQueryBuilder()
            ->delete(HolidayReminderLog::class, 'e')
            ->where('e.seasonId = :seasonId')
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();

        // P4-209(b) — le décrément du compteur PARTAGÉ à l'effacement d'un appariement
        // MANUAL a suivi le grain de l'appariement : club-scoped SANS saison (amendement
        // 2026-09-20). Il ne vit donc PLUS ici (une purge de saison ne touche pas les
        // liens, qui survivent au changement de saison) mais dans ErasedClubPurger, la
        // seule porte de sortie d'un lien.
        // Les familles bornées par (club, saison), chaque enfant avant le parent dont il meurt
        // (FamilyCatalog::purgedWithSeason). TeamTagAssignment y figure aussi : déjà vidée par
        // la saison ci-dessus, ce second DELETE n'efface rien.
        foreach ($this->familyCatalog->purgedWithSeason() as $entityClass) {
            $deleted += $this->deleteByClubSeason($entityClass, $clubId, $seasonId);
        }

        if ($deleteSeasonRow) {
            $this->entityManager->createQueryBuilder()
                ->delete(Season::class, 's')
                ->where('s.clubId = :clubId')
                ->andWhere('s.id = :seasonId')
                ->setParameter('clubId', $clubId)
                ->setParameter('seasonId', $seasonId)
                ->getQuery()
                ->execute();
        } else {
            // Keep the Season row but drop its loaded-context star: it names a
            // schedule the purge just deleted. The plan's pointer goes with the
            // plan rows above — a plan naming a deleted planning would keep the
            // cockpit "unlocked" with nothing behind it.
            $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
            if ($season instanceof Season && $season->getClubId() === $clubId) {
                $season->setLiveContextScheduleId(null);
                // ADR-0002: the reset wiped the season's SchedulePlan above, but the
                // season row survives — re-provision its empty SEASON plan so the
                // invariant "a SEASON plan exists as soon as the season does" holds.
                $plan = $this->schedulePlanProvisioner->ensureSeasonPlan($season);
                // Le reset vide les DONNÉES de la saison ; il ne rebaptise pas son
                // planning. Le nom re-provisionné est un défaut — on rend au plan celui
                // que le gestionnaire avait choisi.
                if (null !== $seasonPlanName && '' !== $seasonPlanName) {
                    $plan->setName($seasonPlanName);
                }
                $this->entityManager->flush();
            }
        }

        $this->entityManager->clear();

        return $deleted;
    }

    /**
     * Le nom du plan SEASON tel qu'il est AVANT la purge. SQL brut : le plan est
     * supprimé en DQL de masse juste après, et on ne veut pas d'une entité gérée qui
     * ressusciterait au flush.
     */
    private function currentSeasonPlanName(string $seasonId): ?string
    {
        $name = $this->entityManager->getConnection()->fetchOne(
            'SELECT name FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        return \is_string($name) ? $name : null;
    }

    /**
     * Delete rows of $entityClass whose $parentRefField points at a parent row
     * (of $parentClass) belonging to this club+season. DQL DELETE with subquery.
     */
    private function deleteBySubQuery(string $entityClass, string $parentRefField, string $parentClass, string $clubId, string $seasonId): int
    {
        $sub = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from($parentClass, 'p')
            ->where('p.clubId = :clubId')
            ->andWhere('p.seasonId = :seasonId')
            ->getDQL();

        return (int) $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where(\sprintf('e.%s IN (%s)', $parentRefField, $sub))
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }

    private function deleteByClubSeason(string $entityClass, string $clubId, string $seasonId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }
}
