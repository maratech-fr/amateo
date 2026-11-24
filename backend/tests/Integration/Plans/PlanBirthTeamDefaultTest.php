<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\PlanTeamDefault;
use App\Enum\SchedulePlanType;
use App\Enum\SeasonStatus;
use App\Plans\PlanComposition;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse (lot 4, PR 3a, ligne 19 du plan) : la pré-sélection des équipes d'un plan se pose à sa naissance, par le
 * serveur. Des vacances naissent avec l'équipe fanion et les équipes importantes cochées (les deux premiers rangs), à
 * défaut celles du meilleur rang présent ; une fermeture naît avec tout le club. Chaque ligne porte les séances et le
 * plafond du socle du moment, cochée ou non. « Tout cocher » ensuite n'est jamais défait.
 *
 * Jumeaux, cités : l'écran n'écrit plus rien à l'ouverture de l'étape (`frontend/src/features/wizard/steps/
 * PeriodTeams.test.tsx`) ; un plan de vacances existant reçoit la même pré-sélection à la conversion s'il n'a aucune
 * version (`Integration/Migration/Lot4MigrationChainTest::testTheConversionGivesEveryTeamALineInEveryPlan`).
 */
#[Group('integration')]
final class PlanBirthTeamDefaultTest extends KernelTestCase
{
    use ComposesPlanTeamsTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    /** La nature d'un plan dit sa pré-sélection : vacances, fanion et importantes ; socle et fermeture, toutes. */
    public function testTheNatureOfAPlanSaysItsDefaultSelection(): void
    {
        self::assertSame(PlanTeamDefault::ALL, SchedulePlanType::SEASON->defaultTeamSelection());
        self::assertSame(PlanTeamDefault::ALL, SchedulePlanType::CLOSURE->defaultTeamSelection());
        self::assertSame(PlanTeamDefault::FLAGSHIP_AND_IMPORTANT, SchedulePlanType::HOLIDAY->defaultTeamSelection());
    }

    public function testAHolidayPlanIsBornWithTheFlagshipAndImportantTeams(): void
    {
        $teams = [
            'SM1' => $this->team('SM1', 1, 2, 2),
            'SF1' => $this->team('SF1', 1, 2),
            'U17M1' => $this->team('U17M1', 2, 3),
            'U15M1' => $this->team('U15M1', 3, 2),
            'Loisir' => $this->team('Loisir', 5, 1),
        ];

        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');

        self::assertSame(
            ['SM1' => [true, 2, 2], 'SF1' => [true, 2, 1], 'U17M1' => [true, 3, 1], 'U15M1' => [false, 2, 1], 'Loisir' => [false, 1, 1]],
            $this->linesByName($holiday, $teams),
            'rangs 1 et 2 cochés, les autres non ; séances et plafond du socle sur chaque ligne',
        );
    }

    public function testWithoutFlagshipNorImportantTeamsTheBestRankPresentIsKept(): void
    {
        $teams = [
            'U15M1' => $this->team('U15M1', 3, 2),
            'U15F1' => $this->team('U15F1', 3, 2),
            'U13M1' => $this->team('U13M1', 4, 2),
        ];

        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');

        self::assertSame(
            ['U15M1' => [true, 2, 1], 'U15F1' => [true, 2, 1], 'U13M1' => [false, 2, 1]],
            $this->linesByName($holiday, $teams),
            'sans rang 1 ni 2, le meilleur rang présent reprend — jamais personne',
        );
    }

    public function testAClosureIsBornWithTheWholeClub(): void
    {
        $teams = ['SM1' => $this->team('SM1', 1, 2), 'Loisir' => $this->team('Loisir', 5, 1)];

        $closure = $this->periodPlan(CalendarEntryPeriodType::CLOSURE, '2025-11-03', '2025-11-09');

        self::assertSame(['SM1' => [true, 2, 1], 'Loisir' => [true, 1, 1]], $this->linesByName($closure, $teams), 'une fermeture garde tout le club');
    }

    /** Le gestionnaire coche tout dans des vacances neuves : rien ne le défait, ni la porte rappelée, ni une équipe ajoutée. */
    public function testCheckingEveryTeamAfterBirthIsNeverUndone(): void
    {
        $teams = ['SM1' => $this->team('SM1', 1, 2), 'Loisir' => $this->team('Loisir', 5, 1)];
        $holiday = $this->periodPlan(CalendarEntryPeriodType::HOLIDAY, '2025-12-22', '2025-12-28');
        self::assertFalse($this->linesByName($holiday, $teams)['Loisir'][0], 'témoin : Loisir est née décochée');

        $this->teamLine($holiday, $teams['Loisir'])->setParticipating(true);
        $this->em->flush();
        $teams['U9'] = $this->team('U9', 5, 1);
        $this->em->clear();

        self::assertSame(['SM1' => [true, 2, 1], 'Loisir' => [true, 1, 1], 'U9' => [true, 1, 1]], $this->linesByName($holiday, $teams), 'tout reste coché ; une équipe ajoutée depuis l\'est aussi');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $this->club = (new Club)->setName('Naissance ' . $uid)->setSlug('naissance-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());
        $this->ensureProductionPriorityTiers();
    }

    /** Une équipe du socle, comme l'étape Équipes la saisit. */
    private function team(string $name, int $tier, int $sessionsPerWeek, int $maxSessionsPerDay = 1): string
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId($tier)->setName($name)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        $this->setSocleTeam($this->season->getId(), $team->getId(), $sessionsPerWeek, $maxSessionsPerDay);

        return $team->getId();
    }

    /** Une période et son plan, nés du geste (`provisionPeriodPlan`) : la naissance pose la pré-sélection. */
    private function periodPlan(CalendarEntryPeriodType $type, string $start, string $end): string
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType($type)->setTitle($type->value . ' ' . $start)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);

        return $this->planIdOf($entry);
    }

    /**
     * Les lignes d'un plan relues par la porte de lecture, par nom d'équipe.
     *
     * @param array<string, string> $teams nom => identifiant
     *
     * @return array<string, array{0: bool, 1: int, 2: int}> participe, séances, plafond
     */
    private function linesByName(string $planId, array $teams): array
    {
        $lines = self::getContainer()->get(PlanComposition::class)->teamsOf($planId);
        $byName = [];
        foreach ($teams as $name => $id) {
            self::assertArrayHasKey($id, $lines, \sprintf('%s a sa ligne dans le plan', $name));
            $byName[$name] = [$lines[$id]->isParticipating(), $lines[$id]->getSessionsPerWeek(), $lines[$id]->getMaxSessionsPerDay()];
        }

        return $byName;
    }
}
