<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Season;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Service\PeriodConstraintSelector;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261010160000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — la migration qui FIGE l'état effectif des plans de période de VACANCES déjà nés quand le
 * défaut bascule (PR C). Elle matérialise des overrides `is_active = true` pour les permanentes
 * TEAM/COACH d'un plan HOLIDAY, de sorte que leur état effectif ne change PAS sous bascule.
 *
 * On exécute le VRAI SQL de la migration (via `getSql()`, jamais une copie), sous GUC de club :
 * la RLS borne alors l'INSERT…SELECT au seul club de test, et la jointure club+saison fait le
 * reste. Trois promesses : la portée (type d'entrée, scope, saison, datées, autre club),
 * l'idempotence, et l'état effectif réellement inchangé (le sélecteur garde TEAM+COACH+CLUB).
 */
#[Group('integration')]
final class MigrationHolidaySeasonConstraintsOffTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string TEAM_TARGET = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string COACH_TARGET = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private const string FACILITY_TARGET = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    private EntityManagerInterface $em;

    public function testHolidayPlanIsFrozenForTeamAndCoachScopesOnly(): void
    {
        [$club, $season] = $this->makeClubAndSeason();
        $otherSeason = $this->makeSeason($club, '2024-2025', '2024-09-01', '2025-06-30');

        $holiday = $this->periodEntry($club, $season, CalendarEntryPeriodType::HOLIDAY, '2026-02-09', '2026-02-15');
        $holidayPlanId = $this->planIdOf($holiday);
        $closure = $this->periodEntry($club, $season, CalendarEntryPeriodType::CLOSURE, '2026-03-02', '2026-03-08');
        $closurePlanId = $this->planIdOf($closure);

        $clubC = $this->permanent($club, $season, ConstraintScope::CLUB, null);
        $teamC = $this->permanent($club, $season, ConstraintScope::TEAM, self::TEAM_TARGET);
        $coachC = $this->permanent($club, $season, ConstraintScope::COACH, self::COACH_TARGET);
        $facilityC = $this->permanent($club, $season, ConstraintScope::FACILITY, self::FACILITY_TARGET);
        // Une DATÉE (posée DANS le plan) et une permanente d'une AUTRE saison : ni l'une ni l'autre
        // ne doit recevoir d'override — le défaut ne les concerne pas / la jointure saison les écarte.
        $this->dated($club, $season, ConstraintScope::TEAM, self::TEAM_TARGET, $holiday);
        $this->permanent($club, $otherSeason, ConstraintScope::TEAM, self::TEAM_TARGET);
        $this->em->flush();

        $this->runMigration();
        $this->em->clear();

        // Le plan HOLIDAY porte EXACTEMENT un override « gardée » pour la TEAM et la COACH.
        $frozen = $this->overridesOf($holidayPlanId);
        $expectedFrozen = [$teamC, $coachC];
        sort($expectedFrozen);
        self::assertSame($expectedFrozen, $this->sortedKeys($frozen), 'seules les permanentes TEAM et COACH du plan vacances sont figées');
        foreach ($frozen as $override) {
            self::assertTrue($override->isActive(), 'l\'override posé garde la contrainte (is_active = true)');
        }

        // Le plan de FERMETURE ne reçoit RIEN (défaut inchangé : il hérite déjà tout).
        self::assertSame([], $this->overridesOf($closurePlanId), 'une fermeture n\'est pas figée — son défaut ne change pas');

        // CLUB et FACILITY n'ont PAS d'override (CLUB reste ON par défaut, FACILITY était DÉJÀ OFF).
        self::assertNotContains($clubC, $this->sortedKeys($frozen), 'le scope CLUB n\'est pas figé (il reste hérité par défaut)');

        // État effectif INCHANGÉ : le sélecteur, après migration, garde CLUB + TEAM + COACH
        // (permanentes) — exactement ce que l'ancien défaut vacances gardait — et laisse la
        // FACILITY dehors. (La datée TEAM, elle, est gardée de toute façon : hors sujet ici.)
        $entry = $this->em->getRepository(CalendarEntry::class)->find($holiday->getId());
        self::assertInstanceOf(CalendarEntry::class, $entry);
        $kept = array_map(static fn (Constraint $c): string => $c->getId(), self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $holidayPlanId, $entry)->kept);
        self::assertContains($clubC, $kept, 'le CLUB reste appliqué');
        self::assertContains($teamC, $kept, 'la TEAM permanente reste appliquée (figée par la migration)');
        self::assertContains($coachC, $kept, 'la COACH permanente reste appliquée (figée par la migration)');
        self::assertNotContains($facilityC, $kept, 'le GYMNASE reste OFF (non figé)');
    }

    public function testMigrationIsIdempotent(): void
    {
        [$club, $season] = $this->makeClubAndSeason();
        $holiday = $this->periodEntry($club, $season, CalendarEntryPeriodType::HOLIDAY, '2026-02-09', '2026-02-15');
        $holidayPlanId = $this->planIdOf($holiday);
        $this->permanent($club, $season, ConstraintScope::TEAM, self::TEAM_TARGET);
        $this->permanent($club, $season, ConstraintScope::COACH, self::COACH_TARGET);
        $this->em->flush();

        $this->runMigration();
        $this->runMigration();
        $this->em->clear();

        self::assertCount(2, $this->overridesOf($holidayPlanId), 'rejouer la migration ne duplique aucun override (NOT EXISTS + ON CONFLICT)');
    }

    public function testAnotherClubIsNotTouchedByAClubScopedRun(): void
    {
        [$clubA, $seasonA] = $this->makeClubAndSeason();
        $holidayA = $this->periodEntry($clubA, $seasonA, CalendarEntryPeriodType::HOLIDAY, '2026-02-09', '2026-02-15');
        $holidayPlanA = $this->planIdOf($holidayA);
        $this->permanent($clubA, $seasonA, ConstraintScope::TEAM, self::TEAM_TARGET);
        $this->em->flush();

        // Un second club, avec son propre plan vacances et sa propre contrainte d'équipe.
        [$clubB, $seasonB] = $this->makeClubAndSeason();
        $holidayB = $this->periodEntry($clubB, $seasonB, CalendarEntryPeriodType::HOLIDAY, '2026-02-09', '2026-02-15');
        $holidayPlanB = $this->planIdOf($holidayB);
        $this->permanent($clubB, $seasonB, ConstraintScope::TEAM, self::TEAM_TARGET);
        $this->em->flush();

        // On ne joue la migration QUE pour le club A (GUC A borne l'INSERT…SELECT).
        $this->scopeGucToClub($clubA->getId());
        $this->runMigration();
        $this->em->clear();

        $this->scopeGucToClub($clubA->getId());
        self::assertCount(1, $this->overridesOf($holidayPlanA), 'le club visé est bien figé');
        $this->scopeGucToClub($clubB->getId());
        self::assertSame([], $this->overridesOf($holidayPlanB), 'un run borné au club A ne pose aucun override chez le club B');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function makeClubAndSeason(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club gel vacances ' . $uid);
        $club->setSlug('gel-vac-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('GEL' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = $this->makeSeason($club, '2025-2026', '2025-09-01', '2026-06-30');

        return [$club, $season];
    }

    private function makeSeason(Club $club, string $name, string $start, string $end): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName($name);
        $season->setStartDate(new DateTimeImmutable($start));
        $season->setEndDate(new DateTimeImmutable($end));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function periodEntry(Club $club, Season $season, CalendarEntryPeriodType $type, string $start, string $end): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Période gel ' . $type->value);
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $entry->setPeriodType($type);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function permanent(Club $club, Season $season, ConstraintScope $scope, ?string $target): string
    {
        return $this->makeConstraint($club, $season, $scope, $target, null);
    }

    private function dated(Club $club, Season $season, ConstraintScope $scope, ?string $target, CalendarEntry $entry): string
    {
        return $this->makeConstraint($club, $season, $scope, $target, $entry->getId());
    }

    private function makeConstraint(Club $club, Season $season, ConstraintScope $scope, ?string $target, ?string $calendarEntryId): string
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setScope($scope);
        $c->setScopeTargetId($target);
        $c->setFamily(ConstraintScope::COACH === $scope ? ConstraintFamily::COACH_AVAILABILITY : (ConstraintScope::FACILITY === $scope ? ConstraintFamily::FACILITY : ConstraintFamily::TIME));
        $c->setRuleType(ConstraintRuleType::PREFERRED);
        $c->setName('Gel ' . $scope->value . ' ' . uniqid());
        $c->setConfig([]);
        $c->setIsActive(true);
        $c->setSortOrder(0);
        $c->setCalendarEntryId($calendarEntryId);
        $this->em->persist($c);

        return $c->getId();
    }

    /** @return list<ConstraintPeriodOverride> */
    private function overridesOf(string $planId): array
    {
        return $this->em->getRepository(ConstraintPeriodOverride::class)->findBy(['schedulePlanId' => $planId]);
    }

    /**
     * @param list<ConstraintPeriodOverride> $overrides
     *
     * @return list<string>
     */
    private function sortedKeys(array $overrides): array
    {
        $ids = array_map(static fn (ConstraintPeriodOverride $o): string => $o->getConstraintId(), $overrides);
        sort($ids);

        return $ids;
    }

    private function runMigration(): void
    {
        $migration = new Version20261010160000($this->em->getConnection(), new NullLogger);
        $migration->up(new Schema);
        $connection = $this->em->getConnection();
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
