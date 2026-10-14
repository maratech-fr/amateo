<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Axe *constraint semantics* — la règle de DÉFAUT de la sélection d'une période (décision
 * fondateur 2026-10-10, docblock de `PeriodConstraintSelector`) : une période de VACANCES a sa
 * propre vie (sa grille, les coachs présents), donc les contraintes de SAISON de portée ÉQUIPE,
 * COACH et GYMNASE (scope TEAM/COACH/FACILITY) sont ÉCARTÉES du solveur par défaut ; seule une
 * contrainte de CLUB reste héritée. Un override EXPLICITE dévie du défaut dans les deux sens. Une
 * FERMETURE, elle, hérite de TOUT.
 *
 * Niveau : Integration — `selectForPeriodPlan` lit les contraintes, les overrides et l'état
 * effectif des gymnases via Doctrine (aucune version pure sans base possible).
 *
 * Filet CITÉ — désormais SEUL — du scénario 2 de l'ancienne `la-semaine-de-reprise.feature`
 * (« une contrainte d'équipe de saison n'est pas partie au solveur en vacances ») : écrit AVANT la
 * refonte Behat par parcours, qui a depuis SUPPRIMÉ cette feature (PR4 du lot Tests & CI). Angle voisin, cross-référencé :
 * `Integration/MigrationHolidaySeasonConstraintsOffTest` prouve l'état APRÈS la migration de gel
 * (overrides is_active=true posés → TEAM/COACH gardées) — l'inverse de CE test, qui prouve le
 * DÉFAUT nu, sans aucun override ; et cette migration est destinée au retrait (passe de
 * réduction), ce défaut doit donc vivre dans son propre test.
 *
 * NON bloquant (pas de step `blocking-tests` ni de ligne `docs/testing/blocking-tests.md`) : c'est
 * une politique de SÉLECTION (correction), pas une frontière de sécurité/tenant ni une perte de
 * données ; le « une contrainte SAISIE est honorée par le solveur » qui doit gater l'axe constraint
 * semantics vit ailleurs (`Security/TeamTagScopeTest`, `Integration/HardLockSurvivesPayloadTest`,
 * sémantique moteur). Ce test tourne dans la suite complète (required check), sans bloquer
 * `build-docker`.
 */
#[Group('integration')]
final class PeriodConstraintSelectorTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string TEAM_TARGET = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string COACH_TARGET = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private const string FACILITY_TARGET = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    private EntityManagerInterface $em;

    public function testHolidayDropsTeamCoachAndFacilityByDefaultButKeepsClub(): void
    {
        [$club, $season] = $this->makeClubAndSeason();
        $holiday = $this->periodEntry($club, $season, CalendarEntryPeriodType::HOLIDAY);
        $holidayPlanId = $this->planIdOf($holiday);

        $clubC = $this->permanent($club, $season, ConstraintScope::CLUB, null);
        $teamC = $this->permanent($club, $season, ConstraintScope::TEAM, self::TEAM_TARGET);
        $coachC = $this->permanent($club, $season, ConstraintScope::COACH, self::COACH_TARGET);
        $facilityC = $this->permanent($club, $season, ConstraintScope::FACILITY, self::FACILITY_TARGET);
        $this->em->flush();

        $kept = $this->keptIdsFor($club, $season, $holidayPlanId, $holiday);

        self::assertContains($clubC, $kept, 'vacances : le scope CLUB reste hérité');
        self::assertNotContains($teamC, $kept, 'vacances : une contrainte d\'ÉQUIPE de saison est écartée par défaut');
        self::assertNotContains($coachC, $kept, 'vacances : une contrainte de COACH de saison est écartée par défaut');
        self::assertNotContains($facilityC, $kept, 'vacances : une contrainte de GYMNASE de saison est écartée par défaut');
    }

    public function testAnExplicitOverrideBringsATeamConstraintBackInHoliday(): void
    {
        [$club, $season] = $this->makeClubAndSeason();
        $holiday = $this->periodEntry($club, $season, CalendarEntryPeriodType::HOLIDAY);
        $holidayPlanId = $this->planIdOf($holiday);

        $teamC = $this->permanent($club, $season, ConstraintScope::TEAM, self::TEAM_TARGET);
        $this->em->flush();

        // Sans override : écartée (le défaut vacances). Avec un override ACTIF : ramenée.
        self::assertNotContains($teamC, $this->keptIdsFor($club, $season, $holidayPlanId, $holiday), 'pré-condition : écartée par défaut');

        $this->override($club, $season, $holidayPlanId, $teamC, true);
        $this->em->flush();
        $this->em->clear();

        $entry = $this->reload($holiday);
        self::assertContains($teamC, $this->keptIds($club, $season, $holidayPlanId, $entry), 'un override explicite ramène la contrainte d\'équipe dans la sélection');
    }

    public function testAnExplicitOverrideDropsAClubConstraintInHoliday(): void
    {
        [$club, $season] = $this->makeClubAndSeason();
        $holiday = $this->periodEntry($club, $season, CalendarEntryPeriodType::HOLIDAY);
        $holidayPlanId = $this->planIdOf($holiday);

        $clubC = $this->permanent($club, $season, ConstraintScope::CLUB, null);
        $this->em->flush();

        self::assertContains($clubC, $this->keptIdsFor($club, $season, $holidayPlanId, $holiday), 'pré-condition : CLUB hérité par défaut');

        $this->override($club, $season, $holidayPlanId, $clubC, false);
        $this->em->flush();
        $this->em->clear();

        $entry = $this->reload($holiday);
        self::assertNotContains($clubC, $this->keptIds($club, $season, $holidayPlanId, $entry), 'un override explicite OFF écarte la contrainte de club');
    }

    public function testClosureInheritsEveryScope(): void
    {
        [$club, $season] = $this->makeClubAndSeason();
        $closure = $this->periodEntry($club, $season, CalendarEntryPeriodType::CLOSURE);
        $closurePlanId = $this->planIdOf($closure);

        $clubC = $this->permanent($club, $season, ConstraintScope::CLUB, null);
        $teamC = $this->permanent($club, $season, ConstraintScope::TEAM, self::TEAM_TARGET);
        $coachC = $this->permanent($club, $season, ConstraintScope::COACH, self::COACH_TARGET);
        $facilityC = $this->permanent($club, $season, ConstraintScope::FACILITY, self::FACILITY_TARGET);
        $this->em->flush();

        $kept = $this->keptIdsFor($club, $season, $closurePlanId, $closure);

        self::assertContains($clubC, $kept, 'fermeture : CLUB hérité');
        self::assertContains($teamC, $kept, 'fermeture : ÉQUIPE héritée (tout est repris)');
        self::assertContains($coachC, $kept, 'fermeture : COACH hérité (tout est repris)');
        self::assertContains($facilityC, $kept, 'fermeture : GYMNASE hérité (tout est repris)');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return list<string> */
    private function keptIdsFor(Club $club, Season $season, string $planId, CalendarEntry $entry): array
    {
        return $this->keptIds($club, $season, $planId, $entry);
    }

    /** @return list<string> */
    private function keptIds(Club $club, Season $season, string $planId, CalendarEntry $entry): array
    {
        $selection = self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        return array_map(static fn (Constraint $c): string => $c->getId(), $selection->kept);
    }

    private function reload(CalendarEntry $entry): CalendarEntry
    {
        $reloaded = $this->em->getRepository(CalendarEntry::class)->find($entry->getId());
        self::assertInstanceOf(CalendarEntry::class, $reloaded);

        return $reloaded;
    }

    /** @return array{0: Club, 1: Season} */
    private function makeClubAndSeason(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club sélection période ' . $uid);
        $club->setSlug('sel-periode-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('SEL' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    private function periodEntry(Club $club, Season $season, CalendarEntryPeriodType $type): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Période ' . $type->value);
        $entry->setStartDate(new DateTimeImmutable('2026-02-09'));
        $entry->setEndDate(new DateTimeImmutable('2026-02-15'));
        $entry->setPeriodType($type);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function permanent(Club $club, Season $season, ConstraintScope $scope, ?string $target): string
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setScope($scope);
        $c->setScopeTargetId($target);
        $c->setFamily(ConstraintScope::COACH === $scope ? ConstraintFamily::COACH_AVAILABILITY : (ConstraintScope::FACILITY === $scope ? ConstraintFamily::FACILITY : ConstraintFamily::TIME));
        $c->setRuleType(ConstraintRuleType::PREFERRED);
        $c->setName('Sélection ' . $scope->value . ' ' . uniqid());
        $c->setConfig([]);
        $c->setIsActive(true);
        $c->setSortOrder(0);
        $c->setCalendarEntryId(null);
        $this->em->persist($c);

        return $c->getId();
    }

    private function override(Club $club, Season $season, string $planId, string $constraintId, bool $isActive): void
    {
        $override = new ConstraintPeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setConstraintId($constraintId);
        $override->setIsActive($isActive);
        $this->em->persist($override);
    }
}
