<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Plans\SoclePlan;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * NR BLOQUANT — axe backend↔engine contract (§7.1), contrat 1.10 : le plafond de séances par jour d'une équipe part au moteur
 * sous `teams[].maxSessionsPerDay`, SEULEMENT quand la valeur effective dépasse 1. Un club resté partout à 1 émet donc un payload
 * byte-identique à celui d'avant le champ (même empreinte de structure, aucune régénération forcée, mêmes goldens). En période,
 * la valeur émise est celle de la période quand elle est réglée, sinon celle de la saison. Le vrai moteur reçoit le champ sans
 * 422 (`TeamSchema` refuse tout champ inconnu).
 *
 * Étape du step « Blocking suite » de `.github/workflows/ci.yml` ET ligne de `docs/testing/blocking-tests.md` — gardé par
 * `BlockingTestsListMatchesCiTest`. Groupe `phase1` (`--group phase1`) + `contract` (job engine-semantics). Skip propre si le
 * moteur est absent.
 */
#[Group('phase1')]
#[Group('contract')]
final class MaxSessionsPerDayPayloadParityTest extends KernelTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string ENGINE_URL = 'http://engine:8000/generate';

    private const string SPORT_CATEGORY_ID = '99999999-9999-4999-8999-999999999999';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    private ?CacheItemPoolInterface $scheduleCache = null;

    /** Un club tout à 1 : aucune équipe ne porte le champ — le payload, donc son empreinte, ne bouge pas. */
    public function testAClubAllAtOneEmitsNoFieldAndKeepsItsFingerprint(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'SM1');
        $this->team($club, $season, 'U15');
        $this->em->flush();

        $payload = $this->build($club, $season);

        self::assertNotSame([], $payload['teams']);
        foreach ($payload['teams'] as $team) {
            self::assertArrayNotHasKey('maxSessionsPerDay', $team, 'plafond 1 : champ absent, payload identique à celui d\'avant');
        }
    }

    /** Plafond 2 en saison : émis pour cette équipe seulement, et le vrai moteur l'accepte. */
    public function testASeasonCapAboveOneIsEmittedForThatTeamOnlyAndAcceptedByTheEngine(): void
    {
        [$club, $season] = $this->seed();
        $two = $this->team($club, $season, 'SM1');
        $two->setMaxSessionsPerDay(2);
        $one = $this->team($club, $season, 'U15');
        $this->em->flush();

        $payload = $this->build($club, $season);

        self::assertSame(2, $this->teamOf($payload, $two->getId())['maxSessionsPerDay'] ?? null);
        self::assertArrayNotHasKey('maxSessionsPerDay', $this->teamOf($payload, $one->getId()));
        $this->assertTheEngineAccepts($payload);
    }

    /** En période : la valeur de la période quand elle est réglée, sinon celle de la saison ; une période à 1 n'émet rien. */
    public function testAPeriodEmitsItsOwnCapOrFallsBackToTheSeason(): void
    {
        [$club, $season] = $this->seed();
        $overridden = $this->team($club, $season, 'SM1');
        $overridden->setMaxSessionsPerDay(2);
        $inherited = $this->team($club, $season, 'SF1');
        $inherited->setMaxSessionsPerDay(2);
        $loweredToOne = $this->team($club, $season, 'U15');
        $loweredToOne->setMaxSessionsPerDay(2);
        $this->em->flush();
        $entry = $this->holidayPeriod($club, $season);
        $this->em->flush();
        $planId = $this->planIdOf($entry);
        $this->periodCap($club, $season, $planId, $overridden->getId(), 3);
        $this->periodCap($club, $season, $planId, $loweredToOne->getId(), 1);
        $this->em->flush();
        $this->scheduleCache?->clear();

        $payload = $this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $planId, $entry);

        self::assertSame(3, $this->teamOf($payload, $overridden->getId())['maxSessionsPerDay'] ?? null, 'la période l\'emporte');
        self::assertSame(2, $this->teamOf($payload, $inherited->getId())['maxSessionsPerDay'] ?? null, 'null = la saison');
        self::assertArrayNotHasKey('maxSessionsPerDay', $this->teamOf($payload, $loweredToOne->getId()), 'valeur effective 1 : rien n\'est émis');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $pool = self::getContainer()->get('cache.schedule');
        $this->scheduleCache = $pool instanceof CacheItemPoolInterface ? $pool : null;
    }

    /** @return array<string, mixed> */
    private function build(Club $club, Season $season): array
    {
        $this->scheduleCache?->clear();

        return $this->builder->buildForClubSeason($club->getId(), $season->getId());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function teamOf(array $payload, string $teamId): array
    {
        foreach ($payload['teams'] as $team) {
            if ($teamId === ($team['id'] ?? null)) {
                return $team;
            }
        }
        self::fail('équipe absente du payload : ' . $teamId);
    }

    /** @param array<string, mixed> $payload */
    private function assertTheEngineAccepts(array $payload): void
    {
        try {
            $response = HttpClient::create(['timeout' => 60])->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode(), 'le moteur reçoit le champ : ' . $response->getContent(false));
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }

    /** Le plafond d'une équipe pour ce plan de période (le réglage de l'onglet « Séances par jour » de la période). */
    private function periodCap(Club $club, Season $season, string $planId, string $teamId, int $cap): void
    {
        $this->scopeGucToClub($club->getId());
        $override = $this->em->getRepository(TeamPeriodOverride::class)->findOneBy(['schedulePlanId' => $planId, 'teamId' => $teamId])
            ?? (new TeamPeriodOverride)->setClubId($club->getId())->setSeasonId($season->getId())->setSchedulePlanId($planId)
                ->setTeamId($teamId)->setIsActive(true)->setSessionsPerWeek(null);
        $override->setMaxSessionsPerDay($cap);
        $this->em->persist($override);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)
            ->setSportCategoryId(self::SPORT_CATEGORY_ID)->setPriorityTierId(1)->setSessionsPerWeek(2);
        $this->em->persist($team);

        return $team;
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-02-22'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * Un club, sa saison, un gymnase ouvert lundi et mercredi (deux cases par jour) : de quoi rendre le payload recevable.
     *
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Plafond Payload Club')->setSlug('plafond-payload-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true)->setFfbbClubCode('PPC' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $user = (new User)->setEmail('plafond-payload-' . $uid . '@test.com')->setFirstName('P')->setLastName('P')->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')->setStartDate(new DateTimeImmutable('2025-09-01'))
            ->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Matéo');
        $this->em->persist($venue);
        $this->em->flush();
        foreach ([[1, '09:00'], [1, '18:00'], [3, '09:00'], [3, '18:00']] as [$day, $start]) {
            $this->em->persist((new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())->setSchedulePlanId(self::getContainer()->get(SoclePlan::class)->idOf($season->getId()))->setVenueId($venue->getId())
                ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(2));
        }
        $this->em->flush();

        return [$club, $season];
    }
}
