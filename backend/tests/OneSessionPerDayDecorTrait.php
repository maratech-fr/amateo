<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use DateTimeImmutable;

/**
 * Le décor des tests « une séance par jour » : deux gymnases nommés (Armand, JDR), des cases le
 * mardi et le jeudi, trois équipes (U15, U18, U20) à 3 séances pour qu'aucune sur-réservation ne
 * vienne brouiller le motif, et une version éditable, de saison ou de période.
 *
 * @phpstan-type Decor array{user: User, clubId: string, seasonId: string, armand: string, jdr: string, u15: string, u18: string, u20: string, entryId: ?string, planId: ?string, scheduleId: string}
 */
trait OneSessionPerDayDecorTrait
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const int TUESDAY = 2;

    private const int THURSDAY = 4;

    /** @return Decor */
    private function seasonDecor(): array
    {
        $ctx = $this->club();
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Version')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return [...$ctx, 'entryId' => null, 'planId' => null, 'scheduleId' => $schedule->getId()];
    }

    /**
     * Le socle en vigueur, une fermeture et sa version : la grille est copiée à la naissance du plan.
     *
     * @return Decor
     */
    private function periodDecor(): array
    {
        $ctx = $this->club();
        $season = $this->em->getRepository(Season::class)->find($ctx['seasonId']);
        self::assertInstanceOf(Season::class, $season);
        $this->settleSeasonPlan($season);

        $entry = (new CalendarEntry)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTitle('Fermeture')
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setStartDate(new DateTimeImmutable('2026-10-19'))->setEndDate(new DateTimeImmutable('2026-11-01'));
        $this->em->persist($entry);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Période')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule, $entry->getId());
        $this->em->flush();

        return [...$ctx, 'entryId' => $entry->getId(), 'planId' => (string) $schedule->getSchedulePlanId(), 'scheduleId' => $schedule->getId()];
    }

    /**
     * @param Decor $ctx
     */
    private function session(array $ctx, string $teamId, string $venueId, int $day, string $start, LockLevel $lock = LockLevel::NONE, ?string $blockId = null, ?string $scheduleId = null): ScheduleSlotTemplate
    {
        $slot = (new ScheduleSlotTemplate)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setScheduleId($scheduleId ?? $ctx['scheduleId'])
            ->setTeamId($teamId)->setVenueId($venueId)->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)
            ->setLockLevel($lock)->setSharedTrainingBlockId($blockId);
        if (LockLevel::NONE !== $lock) {
            $slot->setLockOrigin(LockOrigin::MANUAL);
        }
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    /**
     * @param Decor $ctx
     */
    private function reservation(array $ctx, string $teamId, string $venueId, int $day, string $start): string
    {
        $reservation = (new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($ctx['planId'])
            ->setTeamId($teamId)->setVenueId($venueId)->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90);
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation->getId();
    }

    /**
     * Un groupe mutualisé de la portée du décor.
     *
     * @param Decor        $ctx
     * @param list<string> $teamIds
     */
    private function group(array $ctx, array $teamIds, int $commonSessions = 1): string
    {
        $block = (new SharedTrainingBlock)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($ctx['planId'])->setCommonSessions($commonSessions);
        $this->em->persist($block);
        $this->em->flush();
        foreach ($teamIds as $teamId) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($ctx['planId'])->setBlockId($block->getId())->setTeamId($teamId));
        }
        $this->em->flush();

        return $block->getId();
    }

    /** @return array{user: User, clubId: string, seasonId: string, armand: string, jdr: string, u15: string, u18: string, u20: string} */
    private function club(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Club une séance')->setSlug('jour-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('JOR' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $user = (new User)->setEmail('jour-' . $uid . '@test.com')->setFirstName('Gest')->setLastName('Ion')
            ->setPasswordHash('x')->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($user);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);
        $this->em->persist((new ClubUser)->setClubId($clubId)->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))
            ->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venueIds = [];
        foreach (['Armand', 'JDR'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $venueIds[$name] = $venue->getId();
        }
        $windows = [
            ['Armand', self::TUESDAY, '18:00'], ['JDR', self::TUESDAY, '20:30'],
            ['Armand', self::THURSDAY, '19:00'], ['JDR', self::THURSDAY, '20:30'],
            ['Armand', 3, '18:00'],
        ];
        foreach ($windows as [$name, $day, $start]) {
            $this->em->persist((new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setVenueId($venueIds[$name])
                ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(3));
        }

        $teamIds = [];
        foreach (['U15', 'U18', 'U20'] as $name) {
            $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSessionsPerWeek(3)
                ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
            $this->em->persist($team);
            $this->em->flush();
            $teamIds[$name] = $team->getId();
        }

        return ['user' => $user, 'clubId' => $clubId, 'seasonId' => $seasonId, 'armand' => $venueIds['Armand'], 'jdr' => $venueIds['JDR'], 'u15' => $teamIds['U15'], 'u18' => $teamIds['U18'], 'u20' => $teamIds['U20']];
    }
}
