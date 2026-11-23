<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le décor des séances d'un plan de vacances (lot 4, PR 3a, ligne 17 du plan) : un planning de saison en vigueur où
 * SM1 a 2 séances et U15 3 — dont une ajoutée à la main au-delà de ses 2 séances de saison — et des vacances d'une
 * semaine pleine, nées après. Chaque équipe a sa ligne dans les deux plans (la porte `ensureLines`).
 *
 * @phpstan-require-extends KernelTestCase
 */
trait PeriodSessionsDecorTrait
{
    use ChoosesPlanVersionTrait;
    use ComposesPlanTeamsTrait;
    use CreatesPeopleAndPlacesTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    /**
     * @return array{club: Club, season: Season, socle: Schedule, holiday: CalendarEntry, holidayPlan: string, sm1: string, u15: string}
     */
    private function buildPeriodSessionsDecor(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $club = (new Club)->setName('Séances ' . $uid)->setSlug('seances-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $em->persist($club);
        $em->flush();
        $this->scopeGucToClub($club->getId());
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $em->persist($season);
        $em->flush();
        SeasonPlanOf::id(static::getContainer(), $season->getId());

        $teams = [];
        foreach (['SM1' => 1, 'U15' => 3] as $name => $tier) {
            $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setSportCategoryId('33333333-3333-3333-3333-333333333333')
                ->setPriorityTierId($tier)->setName($name)->setIsActive(true);
            $em->persist($team);
            $em->flush();
            $this->setSocleTeam($season->getId(), $team->getId(), 2);
            $teams[$name] = $team->getId();
        }
        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Nord');
        $em->persist($venue);
        $em->flush();

        $socle = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Socle')->setStatus(ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($socle);
        foreach ([['SM1', 1, '18:00'], ['SM1', 3, '18:00'], ['U15', 2, '18:00'], ['U15', 4, '18:00'], ['U15', 5, '18:00']] as [$name, $day, $start]) {
            $em->persist((new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())->setScheduleId($socle->getId())
                ->setTeamId($teams[$name])->setVenueId($venue->getId())->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))
                ->setDurationMinutes(90)->setLockLevel(LockLevel::NONE));
        }
        $em->flush();

        // Semaine pleine, lundi → dimanche : tous les jours de la semaine sont dans la fenêtre.
        $holiday = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël')->setIsDisruptive(false)
            ->setStartDate(new DateTimeImmutable('2025-12-22'))->setEndDate(new DateTimeImmutable('2025-12-28'));
        $em->persist($holiday);
        $holidayPlan = $this->planIdOf($holiday);
        $this->layTeamLines($season->getId());
        // Les deux équipes jouent les vacances (la pré-sélection n'est pas le sujet).
        foreach ($teams as $teamId) {
            $this->teamLine($holidayPlan, $teamId)->setParticipating(true);
        }
        $em->flush();

        return ['club' => $club, 'season' => $season, 'socle' => $socle, 'holiday' => $holiday, 'holidayPlan' => $holidayPlan, 'sm1' => $teams['SM1'], 'u15' => $teams['U15']];
    }
}
