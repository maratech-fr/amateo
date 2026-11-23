<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\Integration\Migration\Lot4MigrationChainTest;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR d'axe *tenant isolation* et test d'abus (lot 4, PR 3a, ligne 22 du plan) : les équipes d'un plan ne traversent
 * jamais la frontière d'un club. Un plan d'un club ne reçoit jamais le réglage d'une équipe d'un autre club, une
 * équipe d'un club n'atteint jamais le plan d'un autre, et les réglages d'un autre club ne se lisent pas.
 *
 * Deux étages, parce qu'aucun ne suffit seul : la route d'exception (`/api/team_period_overrides`, le geste d'un
 * gestionnaire qui forgerait un identifiant) refuse et n'écrit rien ; en base, sous la RLS de `plan_team`, une ligne
 * d'un autre club est invisible et intouchable, et la clé composée (plan, club) refuse une ligne dont le plan est
 * celui d'un autre club — une clé simple vers le plan ne verrait pas la RLS. dama annule chaque test.
 *
 * Jumeaux cités : la structure de la table (unicité, clé composée en cascade, RLS forcée) à la conversion,
 * {@see Lot4MigrationChainTest::testTheConversionGivesEveryTeamALineInEveryPlan} ; la RLS de toute table à `club_id`,
 * {@see RlsIsolationTest::testEveryClubIdTableIsUnderForcedRls}.
 *
 * Bloquant : step `Blocking suite` de `ci.yml` et `docs/testing/blocking-tests.md`.
 */
#[Group('phase1')]
#[Group('integration')]
final class PlanCompositionTenantIsolationTest extends WebTestCase
{
    use ComposesPlanTeamsTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var array{club: Club, user: User, season: Season, holiday: string, team: string} */
    private array $a;

    /** @var array{club: Club, user: User, season: Season, holiday: string, team: string} */
    private array $b;

    /** Abus : le gestionnaire du club A règle, dans son plan de vacances, l'équipe du club B. Refusé, rien n'est écrit. */
    public function testAPlanOfOneClubNeverTakesTheTeamOfAnother(): void
    {
        $bBefore = $this->linesOfClub($this->b);

        $this->post($this->a, ['schedulePlanId' => $this->a['holiday'], 'teamId' => $this->b['team'], 'isActive' => false, 'sessionsPerWeek' => 1]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 422], 'l\'équipe d\'un autre club est refusée : ' . $this->client->getResponse()->getContent());
        self::assertSame(0, $this->countUnscoped('SELECT COUNT(*) FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$this->a['holiday'], $this->b['team']]), 'aucune ligne du plan A ne porte l\'équipe B');
        self::assertSame($bBefore, $this->linesOfClub($this->b), 'les lignes du club B sont intactes');
    }

    /** Abus : le gestionnaire du club A règle son équipe dans le plan de vacances du club B. Refusé, rien n'est écrit. */
    public function testATeamOfOneClubNeverReachesThePlanOfAnother(): void
    {
        $bBefore = $this->linesOfClub($this->b);

        $this->post($this->a, ['schedulePlanId' => $this->b['holiday'], 'teamId' => $this->a['team'], 'isActive' => false, 'sessionsPerWeek' => 1]);

        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 422], 'le plan d\'un autre club est refusé : ' . $this->client->getResponse()->getContent());
        self::assertSame(0, $this->countUnscoped('SELECT COUNT(*) FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$this->b['holiday'], $this->a['team']]), 'aucune ligne du plan B ne porte l\'équipe A');
        self::assertSame($bBefore, $this->linesOfClub($this->b), 'les lignes du club B sont intactes');
    }

    /** Les réglages d'équipes du plan d'un autre club ne se lisent pas, même en nommant son plan. */
    public function testTheTeamSettingsOfAnotherClubAreNeverRead(): void
    {
        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->b['holiday'], [], [], $this->headers($this->a));
        $members = json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
        self::assertSame([], $members, 'aucun réglage du club B ne sort vers le club A');

        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->b['holiday'], [], [], $this->headers($this->b));
        self::assertResponseIsSuccessful();
        $own = json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
        self::assertCount(1, \is_array($own) ? $own : [], 'témoin : le club B lit son réglage (son équipe écartée des vacances)');
    }

    /** En base, sous la RLS : une ligne d'un autre club est invisible et intouchable ; la clé composée refuse un plan étranger. */
    public function testTheDatabaseKeepsEveryLineInsideItsClub(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $this->scopeGucToClub($this->a['club']->getId());

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM plan_team WHERE club_id = ?', [$this->b['club']->getId()]), 'les lignes du club B sont invisibles au club A');
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM plan_team WHERE club_id = ?', [$this->a['club']->getId()]), 'témoin : le club A voit les siennes');
        self::assertSame(0, $connection->executeStatement('UPDATE plan_team SET sessions_per_week = 4 WHERE schedule_plan_id = ?', [$this->b['holiday']]), 'UPDATE des lignes du club B : zéro ligne');
        self::assertSame(0, $connection->executeStatement('DELETE FROM plan_team WHERE schedule_plan_id = ?', [$this->b['holiday']]), 'DELETE des lignes du club B : zéro ligne');

        self::assertSame('23503', $this->sqlStateOf($connection, $this->a, $this->b['holiday']), 'une ligne du club A ne désigne jamais le plan du club B (clé composée avec le club)');
        $connection->executeStatement('DELETE FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$this->a['holiday'], $this->a['team']]);
        self::assertNull($this->sqlStateOf($connection, $this->a, $this->a['holiday']), 'témoin : une ligne du club A désigne son propre plan');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->a = $this->seedClub('A');
        $this->b = $this->seedClub('B');
        // Le club B écarte son équipe de ses vacances : un réglage qui diffère du socle, à protéger.
        $this->scopeGucToClub($this->b['club']->getId());
        $this->teamLine($this->b['holiday'], $this->b['team'])->setParticipating(false);
        $this->em->flush();
        $this->clearGuc();
    }

    /** @return array{club: Club, user: User, season: Season, holiday: string, team: string} */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $club = (new Club)->setName('Composition ' . $tag . ' ' . $uid)->setSlug('composition-' . strtolower($tag) . '-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $user = (new User)->setEmail('composition-' . strtolower($tag) . $uid . '@test.com')->setFirstName('G')->setLastName('Estionnaire');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $season->getId());
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setSportCategoryId('33333333-3333-3333-3333-333333333333')
            ->setPriorityTierId(1)->setName('SM1 ' . $tag)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        $this->setSocleTeam($season->getId(), $team->getId(), 2);
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël ' . $tag)
            ->setStartDate(new DateTimeImmutable('2025-12-22'))->setEndDate(new DateTimeImmutable('2025-12-28'));
        $this->em->persist($entry);
        $holiday = $this->planIdOf($entry);
        $this->layTeamLines($season->getId());

        return ['club' => $club, 'user' => $user, 'season' => $season, 'holiday' => $holiday, 'team' => $team->getId()];
    }

    /**
     * @param array{club: Club, user: User, season: Season, holiday: string, team: string} $as
     * @param array<string, mixed>                                                          $body
     */
    private function post(array $as, array $body): void
    {
        $this->client->request('POST', '/api/team_period_overrides', [], [], $this->headers($as), json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{club: Club, user: User, season: Season, holiday: string, team: string} $as
     *
     * @return array<string, string>
     */
    private function headers(array $as): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($as['user']),
            'HTTP_X-Season-Id' => $as['season']->getId(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }

    /**
     * Les lignes d'un club, colonne par colonne, lues sous son propre contexte.
     *
     * @param array{club: Club, user: User, season: Season, holiday: string, team: string} $of
     *
     * @return list<array<string, mixed>>
     */
    private function linesOfClub(array $of): array
    {
        $this->scopeGucToClub($of['club']->getId());
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT * FROM plan_team WHERE club_id = ? ORDER BY id', [$of['club']->getId()]);
        $this->clearGuc();
        self::assertNotSame([], $rows, 'témoin : le club a ses lignes');

        return $rows;
    }

    /**
     * Un compte lu sous le contexte de chacun des deux clubs, additionné : ce qu'une écriture fautive aurait laissé,
     * quel que soit le club qu'elle porte. (La connexion d'administration ne voit pas la transaction du test.)
     *
     * @param list<string> $params
     */
    private function countUnscoped(string $sql, array $params): int
    {
        $count = 0;
        foreach ([$this->a, $this->b] as $club) {
            $this->scopeGucToClub($club['club']->getId());
            $count += (int) self::getContainer()->get(Connection::class)->fetchOne($sql, $params);
        }
        $this->clearGuc();

        return $count;
    }

    /**
     * SQLSTATE du refus d'une ligne du club `$as` vers `$planId`, jouée dans un point de sauvegarde annulé, ou null si elle passe.
     *
     * @param array{club: Club, user: User, season: Season, holiday: string, team: string} $as
     */
    private function sqlStateOf(Connection $connection, array $as, string $planId): ?string
    {
        $connection->beginTransaction();
        try {
            $connection->executeStatement(
                'INSERT INTO plan_team (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, team_id, participates, sessions_per_week, max_sessions_per_day, source)'
                . ' VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, ?, ?, true, 2, 1, NULL)',
                [$as['club']->getId(), $as['season']->getId(), $planId, $as['team']],
            );

            return null;
        } catch (DriverException $refusal) {
            return $refusal->getSQLState();
        } finally {
            $connection->rollBack();
        }
    }
}
