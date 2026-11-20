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
 * Le test compose les trois demandes (socle `buildForClubSeason`, période `buildForPeriodPlan`,
 * placement `MatchPlacementPayloadBuilder::build`), RÉÉCRIT À L'IDENTIQUE les lignes du club de
 * chaque table que lisent les constructeurs, puis recompose : les trois demandes doivent être
 * identiques (assertSame, ordre des listes compris). Le cache du socle est purgé avant chaque
 * composition — sinon la seconde lirait le cache et le test serait vert sans rien prouver.
 *
 * ⚠ La réécriture est un DELETE suivi de la réinsertion des mêmes lignes (ordre d'id décroissant),
 * dans une seule instruction. Un `UPDATE … SET id = id` ne suffit PAS : c'est une mise à jour HOT,
 * la ligne change de place dans la page mais l'entrée d'index pointe toujours sur l'ancienne
 * position — or toutes ces lectures passent par l'index du club (RLS), l'ordre lu ne bouge donc
 * pas et le test serait vert sans rien prouver (constaté au premier essai). Le test vérifie que
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

    public function testRewritingTheRowsIdenticallyLeavesTheThreeRequestsUnchanged(): void
    {
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
        self::assertSame($before['socle'], $after['socle'], 'demande du socle (buildForClubSeason) : l\'ordre physique des lignes a changé la demande');
        self::assertSame($before['periode'], $after['periode'], 'demande de la période (buildForPeriodPlan) : l\'ordre physique des lignes a changé la demande');
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
        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);

        $this->purgeEngineRequestCache();
        $socle = $builder->buildForClubSeason(self::ER_CLUB, self::ER_SEASON);

        $entry = $em->getRepository(CalendarEntry::class)->find(self::ER_ENTRY);
        self::assertInstanceOf(CalendarEntry::class, $entry);
        $periodPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId(self::ER_ENTRY);
        self::assertIsString($periodPlanId);
        $periode = $builder->buildForPeriodPlan(self::ER_CLUB, self::ER_SEASON, $periodPlanId, $entry, scheduleId: self::ER_PERIOD_P1);

        $club = $em->getRepository(Club::class)->find(self::ER_CLUB);
        self::assertInstanceOf(Club::class, $club);
        $placement = self::getContainer()->get(MatchPlacementPayloadBuilder::class)->build($club, self::ER_SEASON);

        return ['socle' => $socle, 'periode' => $periode, 'placement' => $placement];
    }

    /**
     * Réécrit à l'identique les lignes du club de chaque table lue (DELETE puis réinsertion, ids
     * décroissants) et vérifie que l'ordre de lecture sans ORDER BY a changé.
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
            $count = (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table} WHERE club_id = :c", ['c' => self::ER_CLUB]);
            self::assertGreaterThan(0, $count, $class . ' : le décor doit remplir cette table');
            $readBefore = $connection->fetchFirstColumn("SELECT id FROM {$table} WHERE club_id = :c", ['c' => self::ER_CLUB]);
            $connection->executeStatement(
                "WITH gone AS (DELETE FROM {$table} WHERE club_id = :c RETURNING *) INSERT INTO {$table} SELECT * FROM gone ORDER BY id DESC",
                ['c' => self::ER_CLUB],
            );
            $readAfter = $connection->fetchFirstColumn("SELECT id FROM {$table} WHERE club_id = :c", ['c' => self::ER_CLUB]);
            self::assertEqualsCanonicalizing($readBefore, $readAfter, $class . ' : la réécriture ne perd, n\'ajoute ni ne change aucune ligne');
            if ($count >= 2) {
                self::assertNotSame($readBefore, $readAfter, $class . ' : la réécriture doit changer l\'ordre des lignes lues sans ORDER BY');
                $moved[] = $class;
            }
        }

        return $moved;
    }
}
