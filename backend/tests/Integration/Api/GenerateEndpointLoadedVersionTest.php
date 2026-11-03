<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `/generate` ne réécrit jamais une version de saison terminée : c'est le rail du brouillon et de
 * la reprise après échec. Sans cette garde, le contournement de « Régénérer » écraserait une
 * version en place, chargée ou non (plan « version chargée », A7).
 */
#[Group('phase1')]
final class GenerateEndpointLoadedVersionTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    /** @return iterable<string, array{ScheduleStatus}> */
    public static function stillGeneratable(): iterable
    {
        yield 'brouillon' => [ScheduleStatus::DRAFT];
        yield 'en échec' => [ScheduleStatus::FAILED];
    }

    /** Une version de saison terminée est refusée, et reste terminée. */
    public function testACompletedSeasonVersionIsRefused(): void
    {
        $version = $this->version(ScheduleStatus::COMPLETED);

        $this->generate($version);

        self::assertResponseStatusCodeSame(409);
        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($version->getId())?->getStatus());
    }

    /** Brouillon et échec gardent leur rail : le refus ne vise que le terminé. */
    #[DataProvider('stillGeneratable')]
    public function testOtherStatusesStayGeneratable(ScheduleStatus $status): void
    {
        $version = $this->version($status);

        $this->generate($version);

        self::assertResponseStatusCodeSame(202);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Gen ' . $uid)->setSlug('gen-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('gen' . $uid . '@test.com')->setFirstName('G')->setLastName('E');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = (new Season)->setClubId($this->club->getId())->setName((string) $year)
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();
        $this->provisionSeasonPlan($this->season);

        $this->em->persist((new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('U15')
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1)->setSessionsPerWeek(1));
        $venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('G')->setCanSplit(false)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $this->em->persist((new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('19:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function generate(Schedule $version): void
    {
        $this->client->request('POST', '/api/schedules/' . $version->getId() . '/generate', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);
    }

    private function version(ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('V')->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }
}
