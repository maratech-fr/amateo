<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Security\CoachDoubleBookingTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
 */
#[Group('phase1')]
#[Group('integration')]
final class GenerationRefusesACoachInTwoPlacesTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

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

    public function testTheSameVenueIsAMutualisationAndGenerationStarts(): void
    {
        $ctx = $this->seed(sameVenue: true);
        $schedule = $this->schedule($ctx, ScheduleStatus::DRAFT);

        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/generate");

        self::assertResponseStatusCodeSame(202);
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

    /** @param array{clubId: string, seasonId: string} $ctx */
    private function schedule(array $ctx, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Version')->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }
}
