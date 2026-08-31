<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Exception\ScheduleGenerationInProgressException;
use App\Service\ClubGenerationLock;
use App\Service\EngineClient;
use App\Service\MoveSlotService;
use App\Service\RequestIdContext;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * NR — P2-51 D11, axes *planning lifecycle* ET *constraint semantics* : LE DÉPLACEMENT D'UNE
 * SÉANCE DE BLOC ENTIÈRE passe sous UN SEUL VERDICT et s'écrit en UNE transaction (tout-ou-rien).
 *
 * D11 interdit de déplacer un membre seul : le rail simple refuserait le deuxième appel séquentiel
 * (`shared_block_broken`), laissant le bloc cassé à mi-chemin. Ce rail atomique répond au danger.
 * Le moteur est MOQUÉ — on teste que le BACKEND respecte le verdict et l'atomicité, pas que le
 * moteur sait refuser (ça, c'est `ValidateAssignmentsContractSchemaTest` + le smoke sémantique) :
 *
 *  1. verdict « oui » → les N créneaux des membres bougent ENSEMBLE, en un flush, marqueur posé ;
 *  2. verdict « non » (`shared_block_broken`) → AUCUN des N créneaux ne bouge (atomicité) —
 *     falsification (a)/(b) : implémenter N déplacements séquentiels laisserait le premier écrit ;
 *  3. le payload porte N candidats + N références et exclut les N sources de la baseline
 *     (contrat 2.18, un verdict pour N déplacements) ;
 *  4. une génération en cours → 409 (exception), le moteur n'est JAMAIS appelé.
 */
#[Group('phase1')]
#[Group('integration')]
final class SlotMoveGroupVerdictTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string ACCEPT = '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    /** Verdict de refus NOMMÉ : retirer un membre casserait le bloc (D11). */
    private const string REFUSE_BLOCK_BROKEN = '{"valid":false,"violations":[{"rule":"shared_block_broken","message":"Ce déplacement casse le bloc de mutualisation.","teamId":null,"venueId":null,"dayOfWeek":4,"startTime":"20:00"}],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private EntityManagerInterface $em;

    /** Verdict « oui » : les DEUX créneaux du bloc bougent vers la cible, marqueur posé. */
    public function testAcceptedGroupMoveMovesAllMembersInOneFlush(): void
    {
        $ctx = $this->seed();

        $service = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT, ['http_code' => 200])));
        $result = $service->moveGroup(
            $ctx['schedule'],
            $ctx['block'],
            2,
            DateTimeImmutable::createFromFormat('!H:i', '18:00'),
            $ctx['venue1'],
            4,
            DateTimeImmutable::createFromFormat('!H:i', '20:00'),
            $ctx['venue2'],
        );

        self::assertTrue($result['valid']);
        self::assertCount(2, $result['movedSlotIds'] ?? []);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        foreach ($ctx['sourceIds'] as $id) {
            $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($id);
            self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
            self::assertSame(4, $reloaded->getDayOfWeek(), 'chaque membre du bloc doit avoir bougé');
            self::assertSame('20:00', $reloaded->getStartTime()->format('H:i'));
            self::assertSame($ctx['venue2'], $reloaded->getVenueId());
        }

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertTrue($schedule->isManuallyEditedSinceGeneration());
    }

    /**
     * Falsification (a)/(b) — L'ATOMICITÉ : verdict « non » → AUCUN des deux créneaux ne bouge, le
     * marqueur reste à false. Une implémentation en N déplacements séquentiels aurait écrit le
     * premier avant que le second soit refusé — cette assertion sur les DEUX sources la ferait
     * rougir.
     */
    public function testRefusedGroupMoveMovesNothing(): void
    {
        $ctx = $this->seed();

        $service = $this->service(new MockHttpClient(new MockResponse(self::REFUSE_BLOCK_BROKEN, ['http_code' => 200])));
        $result = $service->moveGroup(
            $ctx['schedule'],
            $ctx['block'],
            2,
            DateTimeImmutable::createFromFormat('!H:i', '18:00'),
            $ctx['venue1'],
            4,
            DateTimeImmutable::createFromFormat('!H:i', '20:00'),
            $ctx['venue2'],
        );

        self::assertFalse($result['valid']);
        self::assertSame('shared_block_broken', $result['violations'][0]['rule']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        foreach ($ctx['sourceIds'] as $id) {
            $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($id);
            self::assertInstanceOf(ScheduleSlotTemplate::class, $reloaded);
            self::assertSame(2, $reloaded->getDayOfWeek(), 'un refus ne déplace AUCUN membre du bloc');
            self::assertSame('18:00', $reloaded->getStartTime()->format('H:i'));
            self::assertSame($ctx['venue1'], $reloaded->getVenueId());
        }

        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertFalse($schedule->isManuallyEditedSinceGeneration());
    }

    /** Le payload porte N candidats + N références et exclut les N sources de la baseline (contrat 2.18). */
    public function testPayloadCarriesNCandidatesAndExcludesAllSources(): void
    {
        $ctx = $this->seed();

        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(self::ACCEPT, ['http_code' => 200]);
        });

        $this->service($client)->moveGroup(
            $ctx['schedule'],
            $ctx['block'],
            2,
            DateTimeImmutable::createFromFormat('!H:i', '18:00'),
            $ctx['venue1'],
            4,
            DateTimeImmutable::createFromFormat('!H:i', '20:00'),
            $ctx['venue2'],
        );

        self::assertIsArray($captured);
        self::assertCount(2, $captured['candidates'], 'un verdict, N candidats (contrat 2.18)');
        self::assertCount(2, $captured['references'], 'N références appariées');
        foreach ($captured['candidates'] as $candidate) {
            self::assertSame($ctx['venue2'], $candidate['venueId'], 'chaque candidat vise la case CIBLE');
            self::assertSame(4, $candidate['dayOfWeek']);
        }
        $ids = array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $captured['slotTemplates']);
        foreach ($ctx['sourceIds'] as $sourceId) {
            self::assertNotContains($sourceId, $ids, 'les N sources doivent être retirées de la baseline');
        }
    }

    /** Une génération en cours → 409 (exception), le moteur n'est JAMAIS appelé. */
    public function testGenerationInProgressRefusesBeforeEngine(): void
    {
        $ctx = $this->seed();
        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($ctx['clubId'], 60);
        self::assertIsString($token, 'le verrou de génération doit être pris pour ce test');

        $client = new MockHttpClient(static function (): MockResponse {
            throw new RuntimeException('le moteur ne doit JAMAIS être appelé si une génération tourne');
        });

        try {
            $this->service($client)->moveGroup(
                $ctx['schedule'],
                $ctx['block'],
                2,
                DateTimeImmutable::createFromFormat('!H:i', '18:00'),
                $ctx['venue1'],
                4,
                DateTimeImmutable::createFromFormat('!H:i', '20:00'),
                $ctx['venue2'],
            );
            self::fail('une génération en cours doit lever avant tout appel moteur');
        } catch (ScheduleGenerationInProgressException) {
            // attendu
        } finally {
            $lock->release($ctx['clubId'], $token);
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(MockHttpClient $client): MoveSlotService
    {
        $container = self::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        return new MoveSlotService(
            $this->em,
            $container->get(ClubGenerationLock::class),
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(SchedulePlanProvisioner::class),
            new EngineClient($client, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new NullLogger,
        );
    }

    /**
     * Un club/saison/plan SEASON, un planning éditable, un bloc {t1,t2} 1 séance commune (socle
     * saison), les DEUX créneaux membres à la case source (venue1, mardi 18h), et une fenêtre cible
     * capacité 2 (venue2, jeudi 20h) pour les accueillir ensemble.
     *
     * @return array{clubId: string, seasonId: string, scheduleId: string, schedule: Schedule, block: SharedTrainingBlock, venue1: string, venue2: string, sourceIds: list<string>}
     */
    private function seed(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('smg-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venueIds = [];
        foreach (['Gymnase Un', 'Gymnase Deux'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $venueIds[] = $venue->getId();
        }

        // Fenêtre CIBLE capacité 2 : les deux membres du bloc doivent y tenir ensemble.
        $window = (new VenueTrainingSlot)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setVenueId($venueIds[1])
            ->setDayOfWeek(4)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '20:00'))
            ->setDurationMinutes(90)
            ->setCapacity(2);
        $this->em->persist($window);
        $this->em->flush();

        $sport = (new Sport)->setName('Basketball')->setSlug('bball-' . $suffix)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        $category = (new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName('U13')->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = (new PriorityTier)->setId(1)->setLabel('S')->setName('Senior')->setColor('#FF0000')->setOrToolsWeight(100)->setDefaultMinSessions(2);
            $this->em->persist($tier);
            $this->em->flush();
        }

        $teamIds = [];
        foreach (['U13F1', 'U13F2'] as $name) {
            $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setSportCategoryId($category->getId())->setPriorityTierId($tier->getId())->setName($name)->setSessionsPerWeek(2);
            $this->em->persist($team);
            $this->em->flush();
            $teamIds[] = $team->getId();
        }

        // Le bloc {t1,t2} 1 séance commune, socle saison (schedulePlanId null, patron D10).
        $block = (new SharedTrainingBlock)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId(null)->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        foreach ($teamIds as $teamId) {
            $membership = (new SharedTrainingBlockTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId(null)->setBlockId($block->getId())->setTeamId($teamId);
            $this->em->persist($membership);
        }
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Plan')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        // Les DEUX créneaux du bloc à la même case source : venue1, mardi (2), 18:00.
        $sourceIds = [];
        foreach ($teamIds as $teamId) {
            $slot = (new ScheduleSlotTemplate)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setScheduleId($schedule->getId())
                ->setTeamId($teamId)
                ->setVenueId($venueIds[0])
                ->setDayOfWeek(2)
                ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '18:00'))
                ->setDurationMinutes(90);
            $this->em->persist($slot);
            $this->em->flush();
            $sourceIds[] = $slot->getId();
        }

        return [
            'clubId' => $clubId, 'seasonId' => $seasonId, 'scheduleId' => $schedule->getId(),
            'schedule' => $schedule, 'block' => $block,
            'venue1' => $venueIds[0], 'venue2' => $venueIds[1], 'sourceIds' => $sourceIds,
        ];
    }
}
