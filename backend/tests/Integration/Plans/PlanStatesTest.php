<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Place;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
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
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\Plans\PlanStates;
use App\Service\DevScheduleReportWriter;
use App\Service\EngineClient;
use App\Service\PeriodPlanSiblingCopier;
use App\Service\PeriodPlanTranscriber;
use App\Service\RequestIdContext;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Tests\BuildsServiceWithDoublesTrait;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Throwable;

/**
 * Promesse (lot 5, PR 2, ligne 6 du plan) : toute version terminée porte la copie du froid de son plan, écrite à sa
 * naissance — génération de saison ou de période, transcription du socle, copie d'un plan sœur — et cette copie est
 * l'état du plan à cet instant (`PlanStates::of(version)` ne diffère en rien de `PlanStates::capture(plan)`). La copie
 * ne porte que le froid : un nom changé ne la fait pas différer, une séance de plus la fait différer et supprimerait
 * des plans de période ; elle ne contient aucun nom de personne ni d'équipe (RGPD : rien de libre n'y est recopié).
 * Une version sans copie n'est jamais lue comme vide : `PlanStates::of` lève.
 *
 * Angle jumeau, cité : `Security/FamilyDeclarationSweepTest::testEverySeasonAndPlanFamilyDeclaresWhetherItIsColdOrFree`
 * (ce qui est déclaré froid) ; l'écriture bloquante, elle, est prouvée par
 * `MessageHandler/GenerateScheduleFailureTest::testAGenerationWhoseCopyCannotBeWrittenFailsWithoutCredit`.
 */
#[Group('integration')]
final class PlanStatesTest extends KernelTestCase
{
    use BuildsServiceWithDoublesTrait;
    use ChoosesPlanVersionTrait;
    use CreatesPeopleAndPlacesTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string TEAM_NAME = 'U13 Filles Copie';

    private const string COACH_LAST_NAME = 'Fillonneau';

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $soclePlanId;

    private string $teamId;

    /** Une génération de saison laisse sa version avec la copie du froid de son plan, celle du moment. */
    public function testASeasonGenerationLeavesItsVersionWithTheColdStateOfItsPlan(): void
    {
        $versionId = $this->pendingVersionIn($this->soclePlanId);

        $this->generate($versionId);

        self::assertSame(ScheduleStatus::COMPLETED, $this->statusOf($versionId), 'témoin : la génération a abouti');
        $this->assertCarriesTheStateOfItsPlan($versionId, $this->soclePlanId);
    }

    /** Une génération de période aussi : la copie n'est plus réservée au plan de saison. */
    public function testAPeriodGenerationLeavesItsVersionWithTheColdStateOfItsPlan(): void
    {
        $planId = $this->planIdOf($this->periodEntry('Vacances de Noël', '2025-12-22', '2025-12-28'));
        $versionId = $this->pendingVersionIn($planId);

        $this->generate($versionId);

        self::assertSame(ScheduleStatus::COMPLETED, $this->statusOf($versionId), 'témoin : la génération de période a abouti');
        $this->assertCarriesTheStateOfItsPlan($versionId, $planId);
    }

    /** Une version née sans solve, par la transcription du socle, porte sa copie. */
    public function testATranscribedVersionCarriesItsCopy(): void
    {
        $this->settleSeasonPlan($this->season);
        $planId = $this->planIdOf($this->periodEntry('Fermeture du Nord', '2025-11-03', '2025-11-09', CalendarEntryPeriodType::CLOSURE));

        $versionId = self::getContainer()->get(PeriodPlanTranscriber::class)->transcribe($planId)->scheduleId;

        $this->assertCarriesTheStateOfItsPlan($versionId, $planId);
    }

    /** Une version née de la copie d'un plan sœur porte sa copie, celle de son propre plan. */
    public function testASiblingCopiedVersionCarriesItsCopy(): void
    {
        $sourceEntry = $this->periodEntry('Toussaint', '2025-10-20', '2025-10-26');
        $source = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Toussaint V1')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($source, $sourceEntry->getId());
        $this->choosePlanVersion($source);
        $targetEntry = $this->periodEntry('Hiver', '2026-02-16', '2026-02-22');
        $targetPlanId = $this->planIdOf($targetEntry);

        self::getContainer()->get(PeriodPlanSiblingCopier::class)->copyInto($source->getSchedulePlanId(), $targetEntry->getId());

        $versionId = $this->em->getConnection()->fetchOne('SELECT id FROM schedule WHERE schedule_plan_id = ?', [$targetPlanId]);
        self::assertIsString($versionId, 'témoin : la copie du plan sœur a fait naître une version');
        $this->assertCarriesTheStateOfItsPlan($versionId, $targetPlanId);
    }

    /**
     * La copie ne porte que le froid : renommer l'équipe ne la fait pas différer de l'état du plan, une séance de plus
     * la fait différer (et supprimerait des plans de période) ; elle ne contient ni le nom de l'équipe ni celui du
     * coach. Une version sans copie fait lever `PlanStates::of`, jamais une copie vide.
     */
    public function testTheCopyHoldsTheColdOnlyAndAVersionWithoutCopyIsRefused(): void
    {
        $states = self::getContainer()->get(PlanStates::class);
        $version = $this->completedVersionIn($this->soclePlanId);
        $states->store($version, $states->capture($this->soclePlanId));

        $stored = (string) $this->em->getConnection()->fetchOne('SELECT data::text FROM schedule_state WHERE schedule_id = ?', [$version->getId()]);
        self::assertNotSame('', $stored, 'témoin : la copie est écrite');
        self::assertStringNotContainsString(self::TEAM_NAME, $stored, 'le nom d\'une équipe est libre : il n\'entre pas dans la copie');
        self::assertStringNotContainsString(self::COACH_LAST_NAME, $stored, 'le nom d\'une personne n\'entre jamais dans la copie');

        $this->em->getConnection()->executeStatement('UPDATE team SET name = ? WHERE id = ?', ['Renommée', $this->teamId]);
        $this->em->clear();
        self::assertTrue($states->of($version->getId())->changesTo($states->capture($this->soclePlanId))->isEmpty(), 'renommer une équipe ne change rien au froid');

        $this->em->getConnection()->executeStatement(
            'UPDATE plan_team SET sessions_per_week = sessions_per_week + 1 WHERE schedule_plan_id = ? AND team_id = ?',
            [$this->soclePlanId, $this->teamId],
        );
        $this->em->clear();
        $changes = $states->of($version->getId())->changesTo($states->capture($this->soclePlanId));
        self::assertFalse($changes->isEmpty(), 'une séance de plus est un changement froid');
        self::assertTrue($changes->destroys(), 'un champ froid qui diffère supprimerait les plans de période pas commencés');

        $withoutCopy = $this->completedVersionIn($this->soclePlanId);
        $refused = null;
        try {
            $states->of($withoutCopy->getId());
        } catch (Throwable $thrown) {
            $refused = $thrown;
        }
        self::assertInstanceOf(Throwable::class, $refused, 'une version sans copie fait lever PlanStates::of, jamais rendre un état vide');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $this->club = (new Club)->setName('Copie ' . $uid)->setSlug('copie-etat-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();
        $this->seedColdAndFree();
        $this->soclePlanId = SeasonPlanOf::id(self::getContainer(), $this->season->getId());
        $this->seedSoclePlanRows();
    }

    /** Une équipe (nom libre, séances froides), son coach principal (nom de personne libre), une règle d'équipe permanente. */
    private function seedColdAndFree(): void
    {
        $clubId = $this->club->getId();
        $seasonId = $this->season->getId();
        $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName(self::TEAM_NAME)
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(3);
        $this->em->persist($team);
        $coach = $this->newCoach($clubId, $seasonId, 'Camille', self::COACH_LAST_NAME);
        $this->em->flush();
        $this->teamId = $team->getId();
        $this->em->persist((new TeamCoach)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($this->teamId)
            ->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
        $rule = (new Constraint)->setClubId($clubId)->setSeasonId($seasonId)->setName('Pas avant 18h')
            ->setScope(ConstraintScope::TEAM)->setScopeTargetId($this->teamId)->setFamily(ConstraintFamily::TIME)
            ->setRuleType(ConstraintRuleType::HARD)->setConfig(['minStartTime' => '18:00']);
        $this->em->persist($rule);
        $this->em->flush();
    }

    /** La grille et une réservation du socle. */
    private function seedSoclePlanRows(): void
    {
        $clubId = $this->club->getId();
        $seasonId = $this->season->getId();
        $place = (new Place)->setClubId($clubId);
        $this->em->persist($place);
        $venue = new Venue($place)->setClubId($clubId)->setSeasonId($seasonId)->setName('Gymnase Nord')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $this->em->persist((new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setVenueId($venue->getId())->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->persist((new Reservation)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setTeamId($this->teamId)->setVenueId($venue->getId())->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)->setLabel('Créneau du mardi'));
        $this->em->flush();
    }

    private function periodEntry(string $title, string $start, string $end, CalendarEntryPeriodType $type = CalendarEntryPeriodType::HOLIDAY): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType($type)->setTitle($title)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** Une version PENDING liée à son plan, comme la laisse un guichet de génération avant la mise en file. */
    private function pendingVersionIn(string $planId): string
    {
        $version = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('À générer')->setStatus(ScheduleStatus::PENDING)->setSchedulePlanId($planId);
        $this->em->persist($version);
        $this->em->flush();
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);
        $version->setStatus(ScheduleStatus::PENDING);
        $this->em->flush();
        $this->em->clear();
        $this->clearGuc();

        return $version->getId();
    }

    private function completedVersionIn(string $planId): Schedule
    {
        $version = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Terminée')->setStatus(ScheduleStatus::COMPLETED)->setSchedulePlanId($planId);
        $this->em->persist($version);
        $this->em->flush();
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);
        $this->em->flush();

        return $version;
    }

    /** Le handler réel, moteur simulé (résultat valide, aucune séance), hub muet. */
    private function generate(string $versionId): void
    {
        $engine = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode(['status' => 'completed', 'score' => 0, 'slots' => [], 'diagnostics' => []], \JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        ));
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');

        $handler = $this->buildWithDoubles(GenerateScheduleHandler::class, [
            EntityManagerInterface::class => $this->em,
            EngineClient::class => new EngineClient($engine, new RequestIdContext),
            ScheduleProgressPublisher::class => new ScheduleProgressPublisher($hub),
            DevScheduleReportWriter::class => null,
            LoggerInterface::class => new NullLogger,
        ]);
        $handler(new GenerateScheduleMessage(scheduleId: $versionId, clubId: $this->club->getId()));
    }

    private function statusOf(string $versionId): ?ScheduleStatus
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();

        return $this->em->getRepository(Schedule::class)->find($versionId)?->getStatus();
    }

    private function assertCarriesTheStateOfItsPlan(string $versionId, string $planId): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->clear();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_state WHERE schedule_id = ?', [$versionId]), 'la version née porte une copie, une seule');
        $states = self::getContainer()->get(PlanStates::class);
        self::assertTrue($states->of($versionId)->changesTo($states->capture($planId))->isEmpty(), 'la copie est l\'état froid du plan au moment de la naissance');
    }
}
