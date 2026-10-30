<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Security\CoachDoubleBookingTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Décision fondateur 2026-10-30 : un coach à deux endroits en même temps, sans autre coach pour tenir la
 * séance, BLOQUE (« il faut au moins un coach disponible »). Le récap le disait
 * ({@see CoachDoubleBookingTest}), mais le récap n'est qu'un écran : `POST /generate` et `POST /regenerate`
 * ne passent pas par lui. Cette garde porte la promesse côté serveur, sur les guichets eux-mêmes.
 *
 * TÉMOIN : les deux séances dans le MÊME gymnase sont une mutualisation permise, la génération part.
 *
 * « Tant qu'un coach tient la séance, c'est OK » traverse les guichets : un second coach ou un groupe
 * déclaré laissent partir, le coach seul reste refusé. Jumeaux VOULUS (point sensible génération) :
 * {@see CoachDoubleBookingTest} pour le détecteur seul, `OneCoachHoldsTheSessionGateTest` pour le moteur.
 */
#[Group('phase1')]
#[Group('integration')]
final class GenerationRefusesACoachInTwoPlacesTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    /** @return iterable<string, array{string, ScheduleStatus}> */
    public static function counters(): iterable
    {
        yield '/generate' => ['generate', ScheduleStatus::DRAFT];
        yield '/regenerate' => ['regenerate', ScheduleStatus::COMPLETED];
    }

    public function testGenerateIsRefusedAndNothingIsQueued(): void
    {
        $ctx = $this->seed(sameVenue: false);
        $schedule = $this->schedule($ctx, ScheduleStatus::DRAFT);

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/generate");

        $this->assertRefusedNamingTheCoach();
        $this->em->clear();
        self::assertSame(ScheduleStatus::DRAFT, $this->em->getRepository(Schedule::class)->find($schedule->getId())?->getStatus(), 'rien n\'est mis en file');
    }

    public function testRegenerateIsRefusedToo(): void
    {
        $ctx = $this->seed(sameVenue: false);
        $schedule = $this->schedule($ctx, ScheduleStatus::COMPLETED);

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/regenerate");

        $this->assertRefusedNamingTheCoach();
    }

    public function testFillingAPeriodIsRefusedToo(): void
    {
        $ctx = $this->seedPeriod(sameVenue: false);

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$ctx['scheduleId']}/fill");

        $this->assertRefusedNamingTheCoach();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Schedule::class)->findBy(['schedulePlanId' => $ctx['planId']]), 'aucune version créée');
    }

    /** TÉMOIN du comblement : même décor, même gymnase, le refus ne tombe pas pour une autre raison. */
    public function testFillingAPeriodStartsWhenTheCoachStaysInOneVenue(): void
    {
        $ctx = $this->seedPeriod(sameVenue: true);

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$ctx['scheduleId']}/fill");

        self::assertResponseStatusCodeSame(202);
    }

    public function testTheSameVenueIsAMutualisationAndGenerationStarts(): void
    {
        $ctx = $this->seed(sameVenue: true);
        $schedule = $this->schedule($ctx, ScheduleStatus::DRAFT);

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/generate");

        self::assertResponseStatusCodeSame(202);
    }

    /** Tant qu'un coach tient la séance, c'est OK : Bea tient Seniors B pendant qu'Ana entraîne Seniors A. */
    #[DataProvider('counters')]
    public function testASecondCoachWhoHoldsTheSessionLetsGenerationStart(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seed(sameVenue: false);
        $this->addMainCoach($ctx, 'Bea', 'Seniors B');

        self::assertSame(202, $this->post($ctx, $counter, $status));
    }

    /** Le coach SEUL à deux endroits refuse sur les deux guichets, à décor égal avec le test précédent. */
    #[DataProvider('counters')]
    public function testACoachAloneInTwoPlacesIsRefused(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seed(sameVenue: false);

        self::assertSame(422, $this->post($ctx, $counter, $status));
        $this->assertRefusedNamingTheCoach();
    }

    /** Groupe déclaré : Bea tient la séance commune d'Ana et d'elle, Ana est libre pour Seniors B ailleurs. */
    #[DataProvider('counters')]
    public function testADeclaredGroupRelaysAndGenerationStarts(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedSameCaseAsAnotherTeam(declaredGroup: true);

        self::assertSame(202, $this->post($ctx, $counter, $status));
    }

    /** TÉMOIN du relais : même case sans groupe, chacun ses coachs, Ana reste à deux endroits. */
    #[DataProvider('counters')]
    public function testTheSameCaseWithoutAGroupLendsNoCoach(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedSameCaseAsAnotherTeam(declaredGroup: false);

        self::assertSame(422, $this->post($ctx, $counter, $status));
        $this->assertRefusedNamingTheCoach();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function assertRefusedNamingTheCoach(): void
    {
        self::assertResponseStatusCodeSame(422);
        $error = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? '');
        self::assertStringContainsString('Ana', $error, 'le refus nomme le coach');
        self::assertStringContainsString('Gymnase Un', $error, 'le refus nomme les cases à reprendre');
        self::assertStringContainsString('Gymnase Deux', $error, 'le refus nomme les cases à reprendre');
    }

    /** @param array{user: User, clubId: string, seasonId: string} $ctx */
    private function post(array $ctx, string $counter, ScheduleStatus $status): int
    {
        $schedule = $this->schedule($ctx, $status);
        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/{$counter}");

        return $this->client->getResponse()->getStatusCode();
    }

    /** @param array{clubId: string, seasonId: string} $ctx */
    private function addMainCoach(array $ctx, string $firstName, string $teamName): void
    {
        $coach = (new Coach)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setFirstName($firstName)->setLastName('Morel');
        $this->em->persist($coach);
        $this->em->flush();
        $team = $this->em->getRepository(Team::class)->findOneBy(['seasonId' => $ctx['seasonId'], 'name' => $teamName]);
        self::assertInstanceOf(Team::class, $team);
        $this->em->persist((new TeamCoach)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
        $this->em->flush();
    }

    /**
     * Ana sur Seniors A (Gymnase Un) et Seniors B (Gymnase Deux), en même temps. Seniors C (Bea) partage la
     * case de Seniors A, dans un groupe déclaré ou par simple coïncidence.
     *
     * @return array{user: User, clubId: string, seasonId: string}
     */
    private function seedSameCaseAsAnotherTeam(bool $declaredGroup): array
    {
        $ctx = $this->seed(sameVenue: false);
        $repository = $this->em->getRepository(Team::class);
        $seniorsA = $repository->findOneBy(['seasonId' => $ctx['seasonId'], 'name' => 'Seniors A']);
        self::assertInstanceOf(Team::class, $seniorsA);
        $seniorsC = (new Team)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Seniors C')->setSessionsPerWeek(1)
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
        $this->em->persist($seniorsC);
        $this->em->flush();
        $this->addMainCoach($ctx, 'Bea', 'Seniors C');

        $reservationOfA = $this->em->getRepository(Reservation::class)->findOneBy(['teamId' => $seniorsA->getId()]);
        self::assertInstanceOf(Reservation::class, $reservationOfA);
        $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTeamId($seniorsC->getId())
            ->setVenueId($reservationOfA->getVenueId())->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));

        if ($declaredGroup) {
            $block = (new SharedTrainingBlock)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(null)->setCommonSessions(1);
            $this->em->persist($block);
            $this->em->flush();
            foreach ([$seniorsA, $seniorsC] as $member) {
                $this->em->persist((new SharedTrainingBlockTeam)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(null)->setBlockId($block->getId())->setTeamId($member->getId()));
            }
        }
        $this->em->flush();

        return $ctx;
    }

    /**
     * Un coach MAIN seul sur deux équipes, deux réservations simultanées.
     *
     * @return array{user: User, clubId: string, seasonId: string}
     */
    private function seed(bool $sameVenue): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Club double')->setSlug('double-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('DBL' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $user = (new User)->setEmail('double-' . $uid . '@test.com')->setFirstName('Gest')->setLastName('Ion')
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

        $venues = [];
        foreach (['Gymnase Un', 'Gymnase Deux'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $venues[] = $venue;
        }
        $coach = (new Coach)->setClubId($clubId)->setSeasonId($seasonId)->setFirstName('Ana')->setLastName('Berger');
        $this->em->persist($coach);
        $this->em->flush();

        foreach (['Seniors A', 'Seniors B'] as $index => $name) {
            $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSessionsPerWeek(1)
                ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
            $this->em->persist($team);
            $this->em->flush();
            $this->em->persist((new TeamCoach)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
            $this->em->persist((new Reservation)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($team->getId())
                ->setVenueId($venues[$sameVenue ? 0 : $index]->getId())->setDayOfWeek(2)
                ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        }
        $this->em->flush();

        return ['user' => $user, 'clubId' => $clubId, 'seasonId' => $seasonId];
    }

    /**
     * Socle en vigueur, période avec sa grille, version terminée, et les deux réservations au plan.
     *
     * @return array{user: User, scheduleId: string, planId: string}
     */
    private function seedPeriod(bool $sameVenue): array
    {
        $ctx = $this->seed($sameVenue);
        $season = $this->em->getRepository(Season::class)->find($ctx['seasonId']);
        self::assertInstanceOf(Season::class, $season);
        $this->settleSeasonPlan($season);

        $entry = (new CalendarEntry)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTitle('Vacances')
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)
            ->setStartDate(new DateTimeImmutable('2026-10-19'))->setEndDate(new DateTimeImmutable('2026-11-01'));
        $this->em->persist($entry);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Période')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule, $entry->getId());
        $this->em->flush();
        $planId = (string) $schedule->getSchedulePlanId();

        foreach ($this->em->getRepository(Reservation::class)->findBy(['seasonId' => $ctx['seasonId']]) as $reservation) {
            $reservation->setSchedulePlanId($planId);
            $this->em->persist((new VenueTrainingSlot)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setVenueId($reservation->getVenueId())
                ->setDayOfWeek($reservation->getDayOfWeek())->setStartTime($reservation->getStartTime())->setDurationMinutes(90)->setCapacity(2)
                ->setSchedulePlanId($planId));
        }
        $this->em->flush();

        return ['user' => $ctx['user'], 'scheduleId' => $schedule->getId(), 'planId' => $planId];
    }

    /** @param array{clubId: string, seasonId: string} $ctx */
    private function schedule(array $ctx, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Version')->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }
}
