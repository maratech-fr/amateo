<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\GenerationBlockersGuard;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CrossStack\SharedBlockPreSolveMirrorParityTest;
use App\Tests\Security\RecapReservedGroupIsNotAFailureTest;
use App\Tests\TenantGucTrait;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR (axes generation pipeline et constraint semantics) : ce que le Récap dit bloquant, les guichets `/generate`,
 * `/regenerate` et `/fill` le refusent aussi, sans passer par l'écran : 422 `{error, blocker: true}`, le motif du Récap tel
 * quel, rien en file. Bloc inservable pris en exemple parce que sa parité avec le moteur est prouvée ailleurs.
 *
 * Jumeaux VOULUS (point sensible génération) : {@see SharedBlockPreSolveMirrorParityTest} (ce qui bloque, le moteur l'aurait
 * refusé) ; {@see GenerationRefusesACoachInTwoPlacesTest} (l'autre famille de blocage, la garde voisine) ;
 * {@see RecapReservedGroupIsNotAFailureTest} (ce qui ne bloque pas).
 */
#[Group('phase1')]
#[Group('integration')]
final class GenerationRefusesARecapBlockerTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string UNSERVABLE = 'aucun créneau où au moins un coach du groupe n\'est disponible pour une séance commune — la génération échouera.';

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    /** @return iterable<string, array{string, ScheduleStatus}> */
    public static function seasonCounters(): iterable
    {
        yield '/generate' => ['generate', ScheduleStatus::DRAFT];
        yield '/regenerate' => ['regenerate', ScheduleStatus::COMPLETED];
    }

    /** Groupe sans aucun coach disponible : refusé avant le moteur, le motif du Récap tel quel, le statut n'a pas bougé. */
    #[DataProvider('seasonCounters')]
    public function testASeasonCounterRefusesAndNothingIsQueued(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedGroup(coachesAvailable: false);
        $schedule = $this->version($ctx, $status);
        $before = $this->em->getRepository(Schedule::class)->count(['seasonId' => $ctx['seasonId']]);

        $this->post($ctx, $schedule->getId(), $counter);

        $this->assertRefusedNamingTheGroup();
        $this->em->clear();
        self::assertSame($status, $this->em->getRepository(Schedule::class)->find($schedule->getId())?->getStatus(), 'rien n\'est mis en file');
        self::assertSame($before, $this->em->getRepository(Schedule::class)->count(['seasonId' => $ctx['seasonId']]), 'aucune version créée');
    }

    /** TÉMOIN : même décor, les coachs présents, les guichets partent. C'est donc bien le blocage qui refusait. */
    #[DataProvider('seasonCounters')]
    public function testTheSameDecorWithCoachesAvailableStarts(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);

        $this->post($ctx, $this->version($ctx, $status)->getId(), $counter);

        self::assertResponseStatusCodeSame(202);
    }

    /** Le guichet dit mot pour mot ce que dit le Récap : une seule vérité, pas de seconde phrase. */
    public function testTheRefusalIsTheRecapSentence(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: false);
        $scheduleId = $this->version($ctx, ScheduleStatus::DRAFT)->getId();
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($ctx['user'])];

        $this->client->request('POST', '/api/constraints/validate', [], [], $bearer);
        $recap = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->client->request('POST', "/api/schedules/{$scheduleId}/generate", [], [], $bearer);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($recap['blockers'][0] ?? '(le Récap ne bloque pas)', $body['error'] ?? null);
        self::assertStringNotContainsString('cette période', (string) $body['error'], 'Récap de saison : aucune mention de période');
    }

    /** Comblement d'une période : même refus, aucune version V+1. */
    public function testFillingAPeriodRefusesAndCreatesNoVersion(): void
    {
        $ctx = $this->seedPeriod($this->seedGroup(coachesAvailable: false), planGroup: true);

        $this->post($ctx, $ctx['scheduleId'], 'fill');

        $this->assertRefusedNamingTheGroup();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Schedule::class)->findBy(['schedulePlanId' => $ctx['planId']]), 'aucune version créée');
    }

    /** Une réservation sans créneau bloque aussi sur la régénération : la garde des épingles ne regarde que les périodes. */
    public function testAnOrphanReservationRefusesRegeneration(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTeamId($ctx['teamA'])
            ->setVenueId($ctx['venueId'])->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('21:00'))->setDurationMinutes(90));
        $this->em->flush();

        $this->post($ctx, $this->version($ctx, ScheduleStatus::COMPLETED)->getId(), 'regenerate');

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        self::assertStringContainsString('ne correspond à aucun créneau', (string) ($body['error'] ?? ''));
    }

    /** Un créneau libre sur la dernière place d'une case : la case reste occupée, ni le Récap ni le guichet ne s'en plaignent. */
    public function testAFreeSlotOnTheLastPlaceOfACaseIsNotAnOrphan(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $this->em->persist((new VenueTrainingSlot)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setVenueId($ctx['venueId'])
            ->setDayOfWeek(4)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setLabel('Loto du club')
            ->setVenueId($ctx['venueId'])->setDayOfWeek(4)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $this->em->flush();
        $version = $this->version($ctx, ScheduleStatus::COMPLETED)->getId();

        $this->post($ctx, $version, 'regenerate');
        self::assertResponseStatusCodeSame(202);

        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($ctx['clubId'], $ctx['seasonId']);
        $days = array_column($payload['venues'][0]['trainingSlots'], 'dayOfWeek');
        self::assertNotContains(4, $days, 'la case reste occupée : le solveur ne peut rien y poser');
    }

    /**
     * La sur-réservation se lit sur le volume qui part au solveur : la période réduit l'équipe à 1 séance, 2 réservations
     * bloquent. TÉMOIN : au volume de saison (2), les mêmes réservations passent, côté saison.
     */
    public function testOverbookingIsReadOnThePeriodVolume(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $this->em->getRepository(Team::class)->find($ctx['teamA'])?->setSessionsPerWeek(2);
        $this->em->flush();
        $ctx = $this->seedPeriod($ctx, planGroup: false);

        $override = (new TeamPeriodOverride)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($ctx['planId'])->setTeamId($ctx['teamA']);
        $override->setIsActive(true);
        $override->setSessionsPerWeek(1);
        $this->em->persist($override);
        foreach ([2, 3] as $day) {
            $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($ctx['planId'])->setTeamId($ctx['teamA'])
                ->setVenueId($ctx['venueId'])->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        }
        $this->em->flush();

        $this->post($ctx, $ctx['scheduleId'], 'fill');

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        self::assertStringContainsString('U11 A a 2 réservations pour 1 séance par semaine', (string) ($body['error'] ?? ''));
    }

    /**
     * Récap vert, mais les placements de la version source que le comblement épingle se contredisent : groupe sur 2 cases
     * pour 1 séance commune. Le comblement est refusé, c'est le seul guichet qui voit ces épingles.
     */
    public function testFillingWithContradictoryPinsIsRefused(): void
    {
        $ctx = $this->seedPeriod($this->seedGroup(coachesAvailable: true), planGroup: true);
        $this->placeTogether($ctx, [2, 3]);

        $this->post($ctx, $ctx['scheduleId'], 'fill');

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        self::assertStringContainsString('placé sur 2 cases pour 1 séance commune', (string) ($body['error'] ?? ''));
        self::assertStringNotContainsString('Retirez une réservation', (string) $body['error'], 'aucune réservation ici : le geste nommé est celui du comblement');
    }

    /** TÉMOIN des épingles : le groupe réuni sur une seule case, le comblement part. */
    public function testFillingWithConsistentPinsStarts(): void
    {
        $ctx = $this->seedPeriod($this->seedGroup(coachesAvailable: true), planGroup: true);
        $this->placeTogether($ctx, [2]);

        $this->post($ctx, $ctx['scheduleId'], 'fill');

        self::assertResponseStatusCodeSame(202);
    }

    /** Un build qui lève ne ferme pas les guichets : le moteur tranche, comme le Récap reste ouvert quand son volet capacité tombe. */
    public function testABuildFailureLetsTheEngineDecide(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: false);
        $schedule = $this->version($ctx, ScheduleStatus::DRAFT);

        $working = self::getContainer()->get(GenerationBlockersGuard::class);
        $broken = new ReflectionClass(ScheduleConstraintBuilder::class)->newInstanceWithoutConstructor();
        $guard = new ReflectionClass(GenerationBlockersGuard::class)->newInstanceWithoutConstructor();
        Closure::bind(function () use ($working, $broken): void {
            foreach (get_object_vars($working) as $name => $value) {
                $this->{$name} = $value instanceof ScheduleConstraintBuilder ? $broken : $value;
            }
        }, $guard, GenerationBlockersGuard::class)();

        self::assertNull($guard->firstBlockerMessage($schedule));
    }

    /** Sans payload, le Récap garde la sur-réservation : elle se lit alors sur le volume de l'équipe. */
    public function testOverbookingStaysVisibleOnEntitiesWithoutAPayload(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $reservations = array_map(fn (int $day): Reservation => (new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTeamId($ctx['teamA'])
            ->setVenueId($ctx['venueId'])->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90), [2, 3]);

        $found = self::getContainer()->get(GenerationBlockersGuard::class)->overBookedOnEntities($reservations, $ctx['clubId'], $ctx['seasonId']);

        self::assertCount(1, $found);
        self::assertStringContainsString('U11 A a 2 réservations pour 1 séance par semaine', $found[0]);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function assertRefusedNamingTheGroup(): void
    {
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false, 'le front reconnaît un blocage à ce drapeau');
        self::assertStringContainsString(self::UNSERVABLE, (string) ($body['error'] ?? ''));
        self::assertStringContainsString('U11 A', (string) $body['error']);
        self::assertStringContainsString('U11 B', (string) $body['error']);
    }

    /** @param array{user: User} $ctx */
    private function post(array $ctx, string $scheduleId, string $counter): void
    {
        $this->client->loginUser($ctx['user']);
        $this->client->request('POST', "/api/schedules/{$scheduleId}/{$counter}");
    }

    /**
     * Un club : U11 A (Ana) et U11 B (Bea) en groupe, un gymnase ouvert mardi et mercredi 18:00, capacité 2.
     *
     * @return array{user: User, clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, coachIds: list<string>}
     */
    private function seedGroup(bool $coachesAvailable): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Club bloqué')->setSlug('recap-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('RCP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $user = (new User)->setEmail('recap-' . $uid . '@test.com')->setFirstName('Gest')->setLastName('Ion')
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

        $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName('Gymnase Un')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        foreach ([2, 3] as $day) {
            $this->em->persist((new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setVenueId($venue->getId())
                ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(2));
        }

        $teamIds = [];
        $coachIds = [];
        foreach (['U11 A' => 'Ana', 'U11 B' => 'Bea'] as $teamName => $coachName) {
            $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName($teamName)->setSessionsPerWeek(1)
                ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
            $coach = (new Coach)->setClubId($clubId)->setSeasonId($seasonId)->setFirstName($coachName)->setLastName('Berger');
            $this->em->persist($team);
            $this->em->persist($coach);
            $this->em->flush();
            $this->em->persist((new TeamCoach)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
            if (!$coachesAvailable) {
                $off = new Constraint;
                $off->setClubId($clubId)->setSeasonId($seasonId);
                $off->setName('Indispo ' . $coachName)->setScope(ConstraintScope::COACH)->setScopeTargetId($coach->getId());
                $off->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD);
                $off->setConfig(['unavailableDays' => [1, 2, 3, 4, 5, 6, 7]]);
                $off->setIsActive(true);
                $this->em->persist($off);
            }
            $teamIds[] = $team->getId();
            $coachIds[] = $coach->getId();
        }
        $this->em->flush();
        $this->declareGroup($clubId, $seasonId, null, $teamIds);

        return ['user' => $user, 'clubId' => $clubId, 'seasonId' => $seasonId, 'venueId' => $venue->getId(), 'teamA' => $teamIds[0], 'teamB' => $teamIds[1], 'coachIds' => $coachIds];
    }

    /** @param list<string> $teamIds */
    private function declareGroup(string $clubId, string $seasonId, ?string $planId, array $teamIds): void
    {
        $block = (new SharedTrainingBlock)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        foreach ($teamIds as $teamId) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($teamId));
        }
        $this->em->flush();
    }

    /**
     * Le socle en vigueur, une FERMETURE (elle garde les règles coach et hérite du groupe), sa version terminée.
     *
     * @param array{user: User, clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, coachIds: list<string>} $ctx
     *
     * @return array{user: User, clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, coachIds: list<string>, scheduleId: string, planId: string}
     */
    private function seedPeriod(array $ctx, bool $planGroup): array
    {
        $season = $this->em->getRepository(Season::class)->find($ctx['seasonId']);
        self::assertInstanceOf(Season::class, $season);
        $this->settleSeasonPlan($season);

        if (!$planGroup) {
            foreach ($this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['seasonId' => $ctx['seasonId']]) as $member) {
                $this->em->remove($member);
            }
            foreach ($this->em->getRepository(SharedTrainingBlock::class)->findBy(['seasonId' => $ctx['seasonId']]) as $block) {
                $this->em->remove($block);
            }
            $this->em->flush();
        }

        $entry = (new CalendarEntry)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTitle('Fermeture')
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setStartDate(new DateTimeImmutable('2026-10-19'))->setEndDate(new DateTimeImmutable('2026-11-01'));
        $this->em->persist($entry);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Période')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule, $entry->getId());
        $this->em->flush();

        return [...$ctx, 'scheduleId' => $schedule->getId(), 'planId' => (string) $schedule->getSchedulePlanId()];
    }

    /**
     * Les deux équipes placées ensemble sur la version source, ces jours-là.
     *
     * @param array{clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, scheduleId: string} $ctx
     * @param list<int>                                                                                                  $days
     */
    private function placeTogether(array $ctx, array $days): void
    {
        foreach ($days as $day) {
            foreach ([$ctx['teamA'], $ctx['teamB']] as $teamId) {
                $this->em->persist((new ScheduleSlotTemplate)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setScheduleId($ctx['scheduleId'])
                    ->setTeamId($teamId)->setVenueId($ctx['venueId'])->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
            }
        }
        $this->em->flush();
    }

    /** @param array{clubId: string, seasonId: string} $ctx */
    private function version(array $ctx, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Version')->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }
}
