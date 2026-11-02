<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Tests\Integration\Service\CoachWishCampaignTokenSyncTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261102120000;
use DoctrineMigrations\Version20261102121000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration de DONNÉES (charte docs/testing/tests-par-parcours.md §2, obligatoire tant que
 * les migrations n'ont pas tourné en prod) : les anciennes doléances gardent leur sens. Une absence
 * saisie par un coach devient SON absence ; un jour sans coach reste une fermeture d'équipe ; les
 * jetons des campagnes passées gardent exactement leur affichage.
 *
 * On joue les VRAIS `UPDATE` de {@see Version20261102120000} et {@see Version20261102121000}, lus
 * sur la migration (jamais recopiés), sur des lignes remises en état pré-migration. Seuls les
 * `UPDATE` s'exécutent (pas le DDL), d'où le contrat « chaque déplacement de données est un addSql
 * commençant par UPDATE ». La règle vivante (qui répond pour quoi) est gardée par
 * {@see CoachWishCampaignTokenSyncTest}.
 */
#[Group('integration')]
final class CoachWishStaffUnavailabilityMigrationTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private CalendarEntry $entry;

    private Team $team;

    private Coach $coach;

    public function testACoachsDaysMoveToHisOwnStaffLineAndTheTeamColumnEmpties(): void
    {
        $wish = $this->wish($this->team, $this->coach, [2, 4]);

        $this->runUpdates(new Version20261102120000($this->em->getConnection(), new NullLogger), 'up');

        $row = $this->row($wish);
        self::assertSame([], $row['unavailable'], 'la colonne équipe est vidée : ces jours n\'étaient pas une fermeture');
        self::assertSame([['coachId' => $this->coach->getId(), 'days' => [2, 4]]], $row['staff']);
    }

    public function testACoachWithoutDaysAndAWishWithoutCoachAreLeftUntouched(): void
    {
        $noDays = $this->wish($this->team, $this->coach, []);
        $noCoach = $this->wish($this->newTeam('U13'), null, [5]);

        $this->runUpdates(new Version20261102120000($this->em->getConnection(), new NullLogger), 'up');

        self::assertSame(['unavailable' => [], 'staff' => []], $this->row($noDays), 'rien à déplacer');
        self::assertSame(['unavailable' => [5], 'staff' => []], $this->row($noCoach), 'sans coach : le jour reste une fermeture d\'équipe');
    }

    public function testDownRestoresASingleOwnLineAndDeclaredlyLosesAMultiPersonLine(): void
    {
        $single = $this->wish($this->team, $this->coach, []);
        $multi = $this->wish($this->newTeam('U15'), $this->coach, []);
        $other = $this->newCoach('Emma');
        $this->setStaffColumn($single, [['coachId' => $this->coach->getId(), 'days' => [3]]]);
        $this->setStaffColumn($multi, [['coachId' => $this->coach->getId(), 'days' => [3]], ['coachId' => $other->getId(), 'days' => [4]]]);

        $this->runUpdates(new Version20261102120000($this->em->getConnection(), new NullLogger), 'down');

        self::assertSame([3], $this->row($single)['unavailable'], 'une seule entrée, celle du coach de la doléance : restaurée');
        self::assertSame([], $this->row($multi)['unavailable'], 'plusieurs personnes : perte déclarée, jamais une fermeture inventée');
    }

    public function testTokenBackfillKeepsTheOldMeaningAllRolesAndCampaignTeamsOnly(): void
    {
        $assisted = $this->newTeam('U15');
        $outside = $this->newTeam('SF1');
        $this->link($this->team, $this->coach, TeamCoachRole::MAIN);
        $this->link($assisted, $this->coach, TeamCoachRole::ASSISTANT);
        $this->link($outside, $this->coach, TeamCoachRole::MAIN);

        $campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->entry->getId())->setDeadline(new DateTimeImmutable('2020-01-06'))
            ->setWeeks(['2026-02-16'])->setTeamIds([$this->team->getId(), $assisted->getId()]);
        $this->em->persist($campaign);
        $token = (new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($this->coach->getId())->setClubId($this->club->getId());
        $this->em->persist($token);
        $this->em->flush();

        $this->runUpdates(new Version20261102121000($this->em->getConnection(), new NullLogger), 'up');

        $raw = $this->em->getConnection()->fetchOne('SELECT team_ids FROM coach_wish_token WHERE id = :id', ['id' => $token->getId()]);
        $teamIds = json_decode((string) $raw, true);
        self::assertIsArray($teamIds);
        sort($teamIds);
        $expected = [$this->team->getId(), $assisted->getId()];
        sort($expected);
        self::assertSame($expected, $teamIds, 'tout rôle, équipes de la campagne seulement : une campagne passée garde son affichage');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Mig doléances')->setSlug('mig-dol-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($this->entry);
        $this->team = $this->newTeam('SM1');
        $this->coach = $this->newCoach('Karim');
    }

    /** Joue les seuls `UPDATE` de la migration : le déplacement de données, jamais le DDL. */
    private function runUpdates(AbstractMigration $migration, string $direction): void
    {
        'up' === $direction ? $migration->up(new Schema) : $migration->down(new Schema);
        $ran = 0;
        foreach ($migration->getSql() as $query) {
            if (str_starts_with(ltrim($query->getStatement()), 'UPDATE')) {
                $this->em->getConnection()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                ++$ran;
            }
        }
        self::assertGreaterThan(0, $ran, 'la migration doit déplacer ses données par des addSql commençant par UPDATE');
        $this->em->clear();
    }

    /** @param list<int> $unavailable */
    private function wish(Team $team, ?Coach $coach, array $unavailable): CoachWish
    {
        $wish = (new CoachWish)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->entry->getId())->setWeekStart(new DateTimeImmutable('2026-02-16'))
            ->setTeamId($team->getId())->setCoachId($coach?->getId())->setSlotsWanted(1)->setUnavailableDays($unavailable);
        $this->em->persist($wish);
        $this->em->flush();

        return $wish;
    }

    /** @param list<array{coachId: string, days: list<int>}> $staff */
    private function setStaffColumn(CoachWish $wish, array $staff): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE coach_wish SET staff_unavailability = :s WHERE id = :id',
            ['s' => json_encode($staff, \JSON_THROW_ON_ERROR), 'id' => $wish->getId()],
        );
    }

    /** @return array{unavailable: list<int>, staff: list<array{coachId: string, days: list<int>}>} */
    private function row(CoachWish $wish): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT unavailable_days, staff_unavailability FROM coach_wish WHERE id = :id',
            ['id' => $wish->getId()],
        );
        self::assertIsArray($row);

        return [
            'unavailable' => json_decode((string) $row['unavailable_days'], true),
            'staff' => json_decode((string) $row['staff_unavailability'], true),
        ];
    }

    private function newTeam(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName($name);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function newCoach(string $firstName): Coach
    {
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setFirstName($firstName)->setLastName('Test');
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function link(Team $team, Coach $coach, TeamCoachRole $role): void
    {
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole($role));
        $this->em->flush();
    }
}
