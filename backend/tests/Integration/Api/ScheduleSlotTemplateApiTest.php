<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LockLevel;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * BL1 — the slots/diagnostics collections must be filterable by scheduleId and
 * return EVERY row of that schedule (no 30-item pagination cap), still club-scoped.
 */
#[Group('integration')]
final class ScheduleSlotTemplateApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    private Season $season;

    /**
     * Le rail de retouche est READ-ONLY : le CRUD brut d'écriture a disparu (le déplacement
     * passe sous le verdict moteur via /move, verrous/contraintes par manual-edit). POST sur
     * la collection et PUT/DELETE sur l'item ne sont plus des routes — même pour un manager,
     * c'est 405 Method Not Allowed (route absente), jamais un 403 de rôle.
     *
     * @return list<array{0: string, 1: bool}> [verbe HTTP, cible = item ?]
     */
    public static function removedWriteVerbs(): array
    {
        return [
            ['POST', false],
            ['PUT', true],
            ['DELETE', true],
        ];
    }

    public function testSlotsFilteredByScheduleIdReturnOnlyThatSchedule(): void
    {
        $this->client->loginUser($this->user);

        $scheduleA = $this->createSchedule('Planning A');
        $scheduleB = $this->createSchedule('Planning B');
        $this->createSlot($scheduleA);
        $this->createSlot($scheduleA);
        $this->createSlot($scheduleB);

        $members = $this->getMembers('/api/schedule_slot_templates?scheduleId=' . $scheduleA->getId());

        self::assertCount(2, $members);
        foreach ($members as $slot) {
            self::assertSame($scheduleA->getId(), $slot['scheduleId']);
        }
    }

    public function testSlotsFilterBypassesPaginationCap(): void
    {
        $this->client->loginUser($this->user);

        $schedule = $this->createSchedule('Big Planning');
        for ($i = 0; $i < 35; ++$i) {
            $this->createSlot($schedule);
        }

        // Without a bounded filter the default cap is 30; the scheduleId filter returns all 35.
        $members = $this->getMembers('/api/schedule_slot_templates?scheduleId=' . $schedule->getId());

        self::assertCount(35, $members);
    }

    public function testDiagnosticsFilteredByScheduleId(): void
    {
        $this->client->loginUser($this->user);

        $scheduleA = $this->createSchedule('Planning A');
        $scheduleB = $this->createSchedule('Planning B');
        $this->createDiagnostic($scheduleA);
        $this->createDiagnostic($scheduleA);
        $this->createDiagnostic($scheduleB);

        $members = $this->getMembers('/api/schedule_diagnostics?scheduleId=' . $scheduleA->getId());

        self::assertCount(2, $members);
        foreach ($members as $diag) {
            self::assertSame($scheduleA->getId(), $diag['scheduleId']);
        }
    }

    #[DataProvider('removedWriteVerbs')]
    public function testWriteRoutesAreRemoved(string $method, bool $item): void
    {
        $this->client->loginUser($this->user);

        $schedule = $this->createSchedule('Planning RO');
        $this->createSlot($schedule);
        $slotId = $this->getMembers('/api/schedule_slot_templates?scheduleId=' . $schedule->getId())[0]['id'];

        $url = '/api/schedule_slot_templates' . ($item ? '/' . $slotId : '');
        $this->client->request($method, $url, [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], '{"scheduleId":"' . $schedule->getId() . '","teamId":"' . Uuid::v4()->toRfc4122() . '","venueId":"' . Uuid::v4()->toRfc4122() . '","dayOfWeek":1,"startTime":"18:00"}');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [404, 405], \sprintf('%s %s doit être supprimé (404/405), reçu %d', $method, $url, $status));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Slot Test Club');
        $this->club->setSlug('slot-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('SLT' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('slot' . $uid . '@test.com');
        $this->user->setFirstName('Slot');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($passwordHasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->em->flush();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getMembers(string $url): array
    {
        $this->client->request('GET', $url, [], [], []);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('member', $data);

        return $data['member'];
    }

    private function createSchedule(string $name): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($this->club->getId());
        $schedule->setSeasonId($this->season->getId());
        $schedule->setName($name);
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        // lot D : schedule_plan_id/version_number NOT NULL — la version doit être liée à son
        // plan SEASON AVANT tout flush. linkSeededSchedule résout le plan, le pose et persiste ;
        // le flush écrit la ligne (nécessaire, l'API la relit + les slots pointent son id).
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function createSlot(Schedule $schedule): void
    {
        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($this->club->getId());
        $slot->setSeasonId($this->season->getId());
        $slot->setScheduleId($schedule->getId());
        $slot->setTeamId(Uuid::v4()->toRfc4122());
        $slot->setVenueId(Uuid::v4()->toRfc4122());
        $slot->setDayOfWeek(2);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setLockLevel(LockLevel::NONE);
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function createDiagnostic(Schedule $schedule): void
    {
        $diag = new ScheduleDiagnostic;
        $diag->setClubId($this->club->getId());
        $diag->setSeasonId($this->season->getId());
        $diag->setScheduleId($schedule->getId());
        $diag->setType('UNPLACED_TEAM');
        $diag->setSeverity(ScheduleDiagnosticSeverity::WARNING);
        $diag->setMessage('Team could not be placed.');
        $this->em->persist($diag);
        $this->em->flush();
    }
}
