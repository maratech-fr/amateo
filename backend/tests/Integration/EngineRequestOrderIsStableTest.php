<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\MatchConstraint;
use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueTrainingSlot;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Plans\SoclePlan;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\EngineRequestDecorTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : les mêmes données donnent la même demande au moteur, quel que soit l'ordre dans lequel
 * PostgreSQL rend les lignes. Sans ORDER BY, PostgreSQL rend les lignes dans l'ordre où il les
 * trouve (ordre physique, ou ordre d'index à clé égale) : une ligne réécrite, une restauration ou un
 * VACUUM FULL changeraient alors la demande, donc peut-être le planning, sans qu'aucune donnée ait
 * changé.
 *
 * Le test compose les trois demandes (socle et période par `buildForPlan`,
 * placement `MatchPlacementPayloadBuilder::build`), RÉÉCRIT À L'IDENTIQUE les lignes du club de
 * chaque table que lisent les constructeurs, puis recompose : les trois demandes doivent être
 * identiques (assertSame, ordre des listes compris). Le cache du socle est purgé avant chaque
 * composition — sinon la seconde lirait le cache et le test serait vert sans rien prouver.
 *
 * ⚠ Les lectures se font ici dans l'ordre PHYSIQUE (parcours d'index coupés pour la transaction du
 * test : restent le parcours séquentiel et le parcours bitmap, qui rendent tous deux les lignes dans
 * l'ordre de leur emplacement). Sans cela, l'ordre lu dépend du plan que choisit PostgreSQL d'après
 * les statistiques laissées par les tests précédents : par l'index `uniq_reservation_case_team`
 * (club, saison, plan, …), les deux réservations sortent dans l'ordre de leur clé, qu'aucune
 * réécriture ne change, et la précondition tombait selon l'ordre de la suite (constaté en suite
 * complète, jamais seul).
 *
 * La réécriture est un DELETE suivi de la réinsertion des mêmes lignes dans l'ordre INVERSE de
 * l'ordre lu, dans une seule instruction. Un `UPDATE … SET id = id` ne suffit PAS : mise à jour HOT,
 * la ligne garde sa place apparente. PostgreSQL choisit seul la page d'une ligne insérée : si la
 * page visée se remplit au milieu de la réinsertion, la suite peut atterrir plus haut dans la table
 * et l'ordre ne pas s'inverser — d'où, au plus, quelques réécritures de plus. Le test vérifie que
 * l'ordre de lecture de chaque table d'au moins deux lignes a bien changé : sans ce changement, un
 * vert ne prouverait rien.
 *
 * Jumeau : `EngineRequestReferenceTest` fige le CONTENU des demandes ; celui-ci garde leur ORDRE
 * face à l'ordre physique — un fichier de référence capturé sur un ordre physique donné ne le dit pas.
 */
#[Group('integration')]
final class EngineRequestOrderIsStableTest extends KernelTestCase
{
    use EngineRequestDecorTrait;

    /** Les tables que lisent les trois constructeurs, toutes filtrées au club. */
    private const array REWRITTEN = [
        Constraint::class,
        Venue::class,
        Team::class,
        Coach::class,
        TeamCoach::class,
        CoachPlayerMembership::class,
        VenueTrainingSlot::class,
        ScheduleSlotTemplate::class,
        Reservation::class,
        TeamLink::class,
        SportCategory::class,
        Fixture::class,
        VenueMatchWindow::class,
        TeamMatchHabit::class,
        ClubLeagueWindow::class,
        MatchConstraint::class,
    ];

    /** Réécritures au plus par table avant de conclure que l'ordre physique ne bouge pas. */
    private const int MAX_REWRITES = 5;

    public function testRewritingTheRowsIdenticallyLeavesTheThreeRequestsUnchanged(): void
    {
        $this->readInPhysicalOrder();
        $before = $this->composeAll();
        $moved = $this->rewriteRowsOfEachTable();
        $after = $this->composeAll();

        self::assertNotSame([], $moved, 'la réécriture doit avoir changé l\'ordre de lecture d\'au moins une table');
        // Les trois verdicts d'un coup, avant le détail de la première demande qui diffère.
        self::assertSame(
            [],
            array_keys(array_filter(['socle' => $before['socle'] !== $after['socle'], 'periode' => $before['periode'] !== $after['periode'], 'placement' => $before['placement'] !== $after['placement']])),
            'demandes changées par le seul ordre physique des lignes',
        );
        self::assertSame($before['socle'], $after['socle'], 'demande du socle (buildForPlan) : l\'ordre physique des lignes a changé la demande');
        self::assertSame($before['periode'], $after['periode'], 'demande de la période (buildForPlan) : l\'ordre physique des lignes a changé la demande');
        self::assertSame($before['placement'], $after['placement'], 'demande de placement (MatchPlacementPayloadBuilder::build) : l\'ordre physique des lignes a changé la demande');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->pinEngineRequestClock();

        $this->seedEngineRequestBase();
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [
            [1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::HARD],
            [2, self::ER_TEAM_U15, self::ER_VENUE_NORD, 3, '18:00', LockLevel::NONE],
            [3, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
        $planId = $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::COMPLETED, [
            [11, self::ER_TEAM_U15, self::ER_VENUE_NORD, 1, '19:30', LockLevel::HARD],
            [12, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        self::getContainer()->get(SchedulePlanProvisioner::class)->pointLockSource($planId, self::ER_PERIOD_P1);
        $this->seedEngineRequestMatches();
    }

    protected function tearDown(): void
    {
        $this->unpinEngineRequestClock();
        parent::tearDown();
    }

    /** @return array{socle: array<string, mixed>, periode: array<string, mixed>, placement: array<string, mixed>} */
    private function composeAll(): array
    {
        $em = $this->erEm();
        $em->flush();
        $em->clear();
        $this->scopeGucToClub(self::ER_CLUB);
        $this->assertReadsInPhysicalOrder();
        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);

        $this->purgeEngineRequestCache();
        $socle = $builder->buildForPlan(self::getContainer()->get(SoclePlan::class)->idOf(self::ER_SEASON));

        $entry = $em->getRepository(CalendarEntry::class)->find(self::ER_ENTRY);
        self::assertInstanceOf(CalendarEntry::class, $entry);
        $periodPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId(self::ER_ENTRY);
        self::assertIsString($periodPlanId);
        $periode = $builder->buildForPlan($periodPlanId, versionId: self::ER_PERIOD_P1);

        $club = $em->getRepository(Club::class)->find(self::ER_CLUB);
        self::assertInstanceOf(Club::class, $club);
        $placement = self::getContainer()->get(MatchPlacementPayloadBuilder::class)->build($club, self::ER_SEASON);

        return ['socle' => $socle, 'periode' => $periode, 'placement' => $placement];
    }

    /**
     * Coupe les parcours d'index pour la transaction du test (DAMA la tient ouverte, SET LOCAL meurt
     * au rollback) : toute lecture sans ORDER BY suit alors l'ordre physique des lignes.
     */
    private function readInPhysicalOrder(): void
    {
        $connection = $this->erEm()->getConnection();
        $connection->executeStatement('SET LOCAL enable_indexscan = off');
        $connection->executeStatement('SET LOCAL enable_indexonlyscan = off');
        $this->assertReadsInPhysicalOrder();
    }

    /** Hors transaction, SET LOCAL ne tiendrait pas : on le relit avant chaque composition. */
    private function assertReadsInPhysicalOrder(): void
    {
        $connection = $this->erEm()->getConnection();
        self::assertSame(['off', 'off'], [$connection->fetchOne('SHOW enable_indexscan'), $connection->fetchOne('SHOW enable_indexonlyscan')], 'les parcours d\'index doivent rester coupés pour la transaction du test');
    }

    /**
     * Réécrit à l'identique les lignes du club de chaque table lue (DELETE puis réinsertion dans
     * l'ordre inverse de l'ordre lu) et vérifie que l'ordre de lecture sans ORDER BY a changé.
     *
     * @return list<string> les tables dont l'ordre de lecture a effectivement changé
     */
    private function rewriteRowsOfEachTable(): array
    {
        $em = $this->erEm();
        $connection = $em->getConnection();
        $moved = [];
        foreach (self::REWRITTEN as $class) {
            $table = $connection->quoteSingleIdentifier($em->getClassMetadata($class)->getTableName());
            $read = "SELECT id FROM {$table} WHERE club_id = :c";
            $readBefore = $connection->fetchFirstColumn($read, ['c' => self::ER_CLUB]);
            self::assertNotSame([], $readBefore, $class . ' : le décor doit remplir cette table');
            $attempts = 0;
            do {
                // Réinsertion dans l'ordre inverse de l'ordre lu au départ : `array_position` rend le rang.
                $connection->executeStatement(
                    "WITH gone AS (DELETE FROM {$table} WHERE club_id = :c RETURNING *) INSERT INTO {$table} SELECT * FROM gone ORDER BY array_position(CAST(:reversed AS uuid[]), gone.id)",
                    ['c' => self::ER_CLUB, 'reversed' => '{' . implode(',', array_reverse($readBefore)) . '}'],
                );
                $readAfter = $connection->fetchFirstColumn($read, ['c' => self::ER_CLUB]);
            } while ($readAfter === $readBefore && \count($readBefore) >= 2 && ++$attempts < self::MAX_REWRITES);
            self::assertEqualsCanonicalizing($readBefore, $readAfter, $class . ' : la réécriture ne perd, n\'ajoute ni ne change aucune ligne');
            if (\count($readBefore) >= 2) {
                self::assertNotSame($readBefore, $readAfter, $class . ' : la réécriture doit changer l\'ordre des lignes lues sans ORDER BY');
                $moved[] = $class;
            }
        }

        return $moved;
    }
}
