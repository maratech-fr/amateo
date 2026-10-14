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
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Exception\EvictTargetIsGroupException;
use App\Exception\EvictTargetLockedException;
use App\Exception\ScheduleGenerationInProgressException;
use App\Exception\SwapCrossScheduleException;
use App\Exception\SwapWithSelfException;
use App\Service\ClubGenerationLock;
use App\Service\EngineClient;
use App\Service\MoveSlotService;
use App\Service\PeriodConstraintSelector;
use App\Service\RequestIdContext;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Service\TeamCaseOccupancyGuard;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;

/**
 * NR — P4-327, axes *planning lifecycle* ET *constraint semantics* : L'ÉCHANGE ATOMIQUE DE DEUX
 * SÉANCES passe sous UN SEUL VERDICT APPARIÉ et s'écrit en UNE transaction (tout-ou-rien). C'est le
 * pendant « sans perte » de l'éviction : personne n'est supprimé, les deux côtés troquent leurs cases.
 *
 * Le moteur est MOQUÉ — on teste que le BACKEND respecte le verdict, l'atomicité et les refus
 * NOMMÉS avant le moteur, pas que le moteur sait refuser (ça, c'est le contrat cross-stack) :
 *
 *  1. verdict « oui », équipe↔équipe → les deux créneaux échangent leurs cases, marqueur posé ;
 *  2. verdict « oui », groupe-source↔équipe → les N membres du bloc prennent la case de l'occupant,
 *     l'occupant prend la case du groupe, le BLOC survit, marqueur posé ;
 *  3. verdict « non » → AUCUN des deux côtés ne bouge (atomicité), marqueur à false ;
 *  4. le payload porte des candidates/references APPARIÉS et exclut LES DEUX côtés de la baseline ;
 *  5. `dryRun` → verdict rendu, RIEN écrit ;
 *  6. une génération en cours → 409 (exception), le moteur n'est JAMAIS appelé ;
 *  7. les refus NOMMÉS avant le moteur (abus) : cross-tenant (slot d'un autre club), deux plannings
 *     différents, occupant verrouillé (D4), échange avec soi-même, occupant lui-même un groupe.
 */
#[Group('phase1')]
#[Group('integration')]
final class SlotSwapVerdictTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string ACCEPT = '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    /** Verdict de refus NOMMÉ : l'échange casse une règle dure (ici un chevauchement d'équipe). */
    private const string REFUSE = '{"valid":false,"violations":[{"rule":"team_no_overlap","message":"Ce déplacement chevauche une autre séance.","teamId":null,"venueId":null,"dayOfWeek":4,"startTime":"20:00"}],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private EntityManagerInterface $em;

    /** Verdict « oui », équipe↔équipe : les deux créneaux échangent leurs cases, marqueur posé. */
    public function testAcceptedTeamSwapExchangesBothCases(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');
        $target = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Bravo')->getId(), $ctx['venueT'], 4, '20:00');

        $result = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT, ['http_code' => 200])))->swap($source, $target);

        self::assertTrue($result['valid']);
        self::assertEqualsCanonicalizing([$source->getId(), $target->getId()], $result['movedSlotIds'] ?? []);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $movedSource = $this->em->getRepository(ScheduleSlotTemplate::class)->find($source->getId());
        self::assertSame(4, $movedSource?->getDayOfWeek(), 'la source a pris la case de l\'occupant');
        self::assertSame('20:00', $movedSource?->getStartTime()->format('H:i'));
        self::assertSame($ctx['venueT'], $movedSource?->getVenueId());

        $movedTarget = $this->em->getRepository(ScheduleSlotTemplate::class)->find($target->getId());
        self::assertSame(2, $movedTarget?->getDayOfWeek(), 'l\'occupant a pris la case de la source');
        self::assertSame('18:00', $movedTarget?->getStartTime()->format('H:i'));
        self::assertSame($ctx['venueS'], $movedTarget?->getVenueId());

        self::assertTrue($this->em->getRepository(Schedule::class)->find($ctx['scheduleId'])?->isManuallyEditedSinceGeneration());
    }

    /**
     * Verdict « oui », groupe-source↔équipe : les N membres du bloc prennent la case de l'occupant
     * ENSEMBLE, l'occupant prend la case du groupe, le BLOC survit, marqueur posé.
     */
    public function testAcceptedGroupSourceSwapMovesAllMembersAndBlockSurvives(): void
    {
        $ctx = $this->seed();
        $g1 = $this->makeTeam($ctx, 'Groupe-U1');
        $g2 = $this->makeTeam($ctx, 'Groupe-U2');
        $block = $this->makeBlock($ctx, [$g1->getId(), $g2->getId()]);
        $memberSlot1 = $this->makeSlot($ctx, $g1->getId(), $ctx['venueS'], 2, '18:00', LockLevel::HARD, $block->getId());
        $memberSlot2 = $this->makeSlot($ctx, $g2->getId(), $ctx['venueS'], 2, '18:00', LockLevel::HARD, $block->getId());
        $occupant = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Bravo')->getId(), $ctx['venueT'], 4, '20:00');

        // La source désigne le bloc par L'UN de ses créneaux membres à la case.
        $result = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT, ['http_code' => 200])))->swap($memberSlot1, $occupant);

        self::assertTrue($result['valid']);
        self::assertEqualsCanonicalizing([$memberSlot1->getId(), $memberSlot2->getId(), $occupant->getId()], $result['movedSlotIds'] ?? []);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        foreach ([$memberSlot1->getId(), $memberSlot2->getId()] as $id) {
            $moved = $this->em->getRepository(ScheduleSlotTemplate::class)->find($id);
            self::assertSame(4, $moved?->getDayOfWeek(), 'chaque membre du groupe a pris la case de l\'occupant');
            self::assertSame($ctx['venueT'], $moved?->getVenueId());
        }
        $movedOccupant = $this->em->getRepository(ScheduleSlotTemplate::class)->find($occupant->getId());
        self::assertSame(2, $movedOccupant?->getDayOfWeek(), 'l\'occupant a pris la case du groupe');
        self::assertSame($ctx['venueS'], $movedOccupant?->getVenueId());

        self::assertNotNull($this->em->getRepository(SharedTrainingBlock::class)->find($block->getId()), 'le bloc de mutualisation survit à l\'échange');
        self::assertTrue($this->em->getRepository(Schedule::class)->find($ctx['scheduleId'])?->isManuallyEditedSinceGeneration());
    }

    /**
     * Falsification de l'atomicité : verdict « non » → AUCUN des deux côtés ne bouge, marqueur à
     * false. Une écriture partielle (un côté déplacé avant le refus de l'autre) ferait rougir.
     */
    public function testRefusedSwapMovesNothing(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');
        $target = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Bravo')->getId(), $ctx['venueT'], 4, '20:00');

        $result = $this->service(new MockHttpClient(new MockResponse(self::REFUSE, ['http_code' => 200])))->swap($source, $target);

        self::assertFalse($result['valid']);
        self::assertSame('team_no_overlap', $result['violations'][0]['rule']);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        $stillSource = $this->em->getRepository(ScheduleSlotTemplate::class)->find($source->getId());
        self::assertSame(2, $stillSource?->getDayOfWeek(), 'un refus ne déplace pas la source');
        self::assertSame($ctx['venueS'], $stillSource?->getVenueId());
        $stillTarget = $this->em->getRepository(ScheduleSlotTemplate::class)->find($target->getId());
        self::assertSame(4, $stillTarget?->getDayOfWeek(), 'un refus ne déplace pas l\'occupant');
        self::assertSame($ctx['venueT'], $stillTarget?->getVenueId());

        self::assertFalse($this->em->getRepository(Schedule::class)->find($ctx['scheduleId'])?->isManuallyEditedSinceGeneration());
    }

    /** Le payload porte des candidates/references APPARIÉS et exclut LES DEUX côtés de la baseline. */
    public function testPayloadPairsBothSidesAndExcludesThemFromBaseline(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');
        $target = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Bravo')->getId(), $ctx['venueT'], 4, '20:00');

        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(self::ACCEPT, ['http_code' => 200]);
        });

        $this->service($client)->swap($source, $target);

        self::assertIsArray($captured);
        self::assertCount(2, $captured['candidates'], 'un verdict apparié : deux côtés = deux candidats');
        self::assertCount(2, $captured['references'], 'autant de références que de candidats (appariées par index)');
        // La source vise la case de l'occupant, l'occupant la case de la source (positions serveur).
        self::assertSame($ctx['venueT'], $captured['candidates'][0]['venueId'], 'la source atterrit à la case de l\'occupant');
        self::assertSame($ctx['venueS'], $captured['candidates'][1]['venueId'], 'l\'occupant atterrit à la case de la source');
        $ids = array_map(static fn (array $t): string => (string) ($t['id'] ?? ''), $captured['slotTemplates']);
        self::assertNotContains($source->getId(), $ids, 'la source est retirée de la baseline');
        self::assertNotContains($target->getId(), $ids, 'l\'occupant est retiré de la baseline');
    }

    /** `dryRun` : le verdict est rendu, mais RIEN n'est écrit (ni échange, ni marqueur). */
    public function testDryRunWritesNothing(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');
        $target = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Bravo')->getId(), $ctx['venueT'], 4, '20:00');

        $result = $this->service(new MockHttpClient(new MockResponse(self::ACCEPT, ['http_code' => 200])))->swap($source, $target, true);

        self::assertTrue($result['valid']);
        self::assertTrue($result['dryRun'] ?? false);

        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertSame(2, $this->em->getRepository(ScheduleSlotTemplate::class)->find($source->getId())?->getDayOfWeek(), 'un essai ne déplace pas la source');
        self::assertSame(4, $this->em->getRepository(ScheduleSlotTemplate::class)->find($target->getId())?->getDayOfWeek(), 'un essai ne déplace pas l\'occupant');
        self::assertFalse($this->em->getRepository(Schedule::class)->find($ctx['scheduleId'])?->isManuallyEditedSinceGeneration(), 'un essai ne pose pas le marqueur');
    }

    /** Une génération en cours → 409 (exception), le moteur n'est JAMAIS appelé. */
    public function testGenerationInProgressRefusesBeforeEngine(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');
        $target = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Bravo')->getId(), $ctx['venueT'], 4, '20:00');

        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($ctx['clubId'], 60);
        self::assertIsString($token);

        try {
            $this->service($this->engineMustNotBeCalled())->swap($source, $target);
            self::fail('une génération en cours doit lever avant tout appel moteur');
        } catch (ScheduleGenerationInProgressException) {
            // attendu
        } finally {
            $lock->release($ctx['clubId'], $token);
        }
    }

    /**
     * Abus — cross-tenant : un créneau d'un AUTRE club ne peut pas être échangé. Il vit dans un AUTRE
     * planning (celui de son club), donc l'échange est refusé AVANT le moteur (`swap_cross_schedule`) ;
     * côté API le filtre tenant le rendrait de toute façon introuvable (404). Le moteur n'est jamais appelé.
     */
    public function testSwapWithForeignClubSlotRefusedBeforeEngine(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');

        $foreign = $this->seed(); // un second club, son propre planning.
        $foreignSlot = $this->makeSlot($foreign, $this->makeTeam($foreign, 'Étranger')->getId(), $foreign['venueT'], 4, '20:00');

        $this->scopeGucToClub($ctx['clubId']);
        $this->expectException(SwapCrossScheduleException::class);
        $this->service($this->engineMustNotBeCalled())->swap($source, $foreignSlot);
    }

    /** Abus — les deux créneaux sont dans DEUX plannings différents (même club) → `swap_cross_schedule`. */
    public function testSwapAcrossSchedulesRefusedBeforeEngine(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');

        $this->scopeGucToClub($ctx['clubId']);
        $other = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Autre version')->setStatus(ScheduleStatus::COMPLETED)->setScore(50);
        $this->linkSeededSchedule($other);
        $this->em->flush();
        $otherSlot = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Bravo')->getId(), $ctx['venueT'], 4, '20:00', scheduleId: $other->getId());

        $this->expectException(SwapCrossScheduleException::class);
        $this->service($this->engineMustNotBeCalled())->swap($source, $otherSlot);
    }

    /** Abus — l'occupant (équipe) est VERROUILLÉ → `target_locked` (D4) AVANT le moteur. */
    public function testSwapWithLockedOccupantRefusedBeforeEngine(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');
        $locked = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Bravo')->getId(), $ctx['venueT'], 4, '20:00', LockLevel::HARD);

        $this->expectException(EvictTargetLockedException::class);
        $this->service($this->engineMustNotBeCalled())->swap($source, $locked);
    }

    /** Abus — échange d'un créneau avec LUI-MÊME → `swap_with_self` AVANT le moteur. */
    public function testSwapWithItselfRefusedBeforeEngine(): void
    {
        $ctx = $this->seed();
        $slot = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');

        $this->expectException(SwapWithSelfException::class);
        $this->service($this->engineMustNotBeCalled())->swap($slot, $slot);
    }

    /**
     * Abus — l'occupant est lui-même une SÉANCE DE GROUPE (sharedTrainingBlockId posé) →
     * `evict_target_is_group` AVANT le moteur, et AVANT le contrôle de verrou (la séance de groupe
     * porte un verrou d'ancrage HARD, mais c'est le refus « groupe » qui doit tomber, pas target_locked).
     */
    public function testSwapWithGroupOccupantRefusedBeforeEngineAndBeforeLock(): void
    {
        $ctx = $this->seed();
        $source = $this->makeSlot($ctx, $this->makeTeam($ctx, 'Alpha')->getId(), $ctx['venueS'], 2, '18:00');

        $g1 = $this->makeTeam($ctx, 'Groupe-U1');
        $block = $this->makeBlock($ctx, [$g1->getId()]);
        $groupOccupant = $this->makeSlot($ctx, $g1->getId(), $ctx['venueT'], 4, '20:00', LockLevel::HARD, $block->getId());

        $this->expectException(EvictTargetIsGroupException::class);
        $this->service($this->engineMustNotBeCalled())->swap($source, $groupOccupant);
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
            new TeamCaseOccupancyGuard($this->em),
            $container->get(PeriodConstraintSelector::class),
        );
    }

    /**
     * Un club/saison/plan SEASON, un planning éditable, DEUX fenêtres de gymnase (capacité 2) : la
     * case SOURCE (venueS, mardi 18h) et la case CIBLE (venueT, jeudi 20h). Les créneaux et équipes
     * sont posés par chaque test selon le scénario (équipe↔équipe, groupe-source↔équipe…).
     *
     * @return array{clubId: string, seasonId: string, scheduleId: string, schedule: Schedule, venueS: string, venueT: string, categoryId: string, tierId: int}
     */
    private function seed(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('sws-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
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
        foreach (['Gymnase Source', 'Gymnase Cible'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $venueIds[] = $venue->getId();
        }

        // Deux fenêtres capacité 2 : la source (venueS, mardi 18h) et la cible (venueT, jeudi 20h).
        foreach ([[$venueIds[0], 2, '18:00'], [$venueIds[1], 4, '20:00']] as [$venueId, $day, $start]) {
            $window = (new VenueTrainingSlot)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setVenueId($venueId)
                ->setDayOfWeek($day)
                ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $start))
                ->setDurationMinutes(90)
                ->setCapacity(2);
            $this->em->persist($window);
            $this->em->flush();
        }

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

        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Plan')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return [
            'clubId' => $clubId, 'seasonId' => $seasonId, 'scheduleId' => $schedule->getId(),
            'schedule' => $schedule, 'venueS' => $venueIds[0], 'venueT' => $venueIds[1],
            'categoryId' => $category->getId(), 'tierId' => $tier->getId(),
        ];
    }

    /**
     * Une équipe jetable neuve pour ce contexte.
     *
     * @param array<string, mixed> $ctx
     */
    private function makeTeam(array $ctx, string $name): Team
    {
        $this->scopeGucToClub((string) $ctx['clubId']);
        $team = (new Team)
            ->setClubId((string) $ctx['clubId'])
            ->setSeasonId((string) $ctx['seasonId'])
            ->setSportCategoryId((string) $ctx['categoryId'])
            ->setPriorityTierId((int) $ctx['tierId'])
            ->setName($name . ' ' . bin2hex(random_bytes(3)))
            ->setSessionsPerWeek(1);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /**
     * Un créneau posé à une case, éventuellement verrouillé et/ou rattaché à un bloc.
     *
     * @param array<string, mixed> $ctx
     */
    private function makeSlot(array $ctx, string $teamId, string $venueId, int $day, string $start, LockLevel $lock = LockLevel::NONE, ?string $blockId = null, ?string $scheduleId = null): ScheduleSlotTemplate
    {
        $this->scopeGucToClub((string) $ctx['clubId']);
        $slot = (new ScheduleSlotTemplate)
            ->setClubId((string) $ctx['clubId'])
            ->setSeasonId((string) $ctx['seasonId'])
            ->setScheduleId($scheduleId ?? (string) $ctx['scheduleId'])
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $start))
            ->setDurationMinutes(90)
            ->setLockLevel($lock)
            ->setSharedTrainingBlockId($blockId);
        if (LockLevel::NONE !== $lock) {
            $slot->setLockOrigin(LockOrigin::MANUAL);
        }
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    /**
     * Un bloc de mutualisation (socle saison, schedulePlanId null) avec ses adhésions.
     *
     * @param array<string, mixed> $ctx
     * @param list<string>         $teamIds
     */
    private function makeBlock(array $ctx, array $teamIds): SharedTrainingBlock
    {
        $this->scopeGucToClub((string) $ctx['clubId']);
        $block = (new SharedTrainingBlock)->setClubId((string) $ctx['clubId'])->setSeasonId((string) $ctx['seasonId'])->setSchedulePlanId(null)->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        foreach ($teamIds as $teamId) {
            $membership = (new SharedTrainingBlockTeam)->setClubId((string) $ctx['clubId'])->setSeasonId((string) $ctx['seasonId'])->setSchedulePlanId(null)->setBlockId($block->getId())->setTeamId($teamId);
            $this->em->persist($membership);
        }
        $this->em->flush();

        return $block;
    }

    /** Un client moteur qui ÉCHOUE le test s'il est appelé : les refus pré-moteur ne doivent jamais le joindre. */
    private function engineMustNotBeCalled(): MockHttpClient
    {
        return new MockHttpClient(static function (): MockResponse {
            self::fail('un refus doit être tranché AVANT tout appel moteur');
        });
    }
}
