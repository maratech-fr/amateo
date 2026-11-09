<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\ClubUser;
use App\Entity\PeriodReminderLog;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * QW-4: DELETE /api/reset-season wipes all of the caller's club/season data
 * (teams, venues, coaches, constraints, schedules). It is a management action —
 * gated on an active management membership, like ImportController (SEC-04).
 */
#[Group('phase1')]
#[Group('integration')]
final class ResetSeasonControllerTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testResetWipesClubDataForAnAdmin(): void
    {
        [$token, , $clubId] = $this->register('RSTA');
        $this->seedVenues($clubId, 2);

        // Sanity: the seeded venues are visible before the reset.
        $before = $this->getJson('/api/venues', $token);
        self::assertSame(2, $this->totalItems($before), 'seed should expose 2 venues');

        $this->client->request('DELETE', '/api/reset-season', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(2, $body['deleted'], 'the 2 venues must be deleted');

        $after = $this->getJson('/api/venues', $token);
        self::assertSame(0, $this->totalItems($after), 'the club data must be wiped');
    }

    public function testResetWipesTrainingSlotsAndCockpitData(): void
    {
        [$token, , $clubId] = $this->register('RSTD');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($clubId);
        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season);

        // Venue + its weekly slot (venueId is a plain string column, no FK cascade).
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase');
        $venue->setSource('manual');
        $em->persist($venue);
        $em->flush();
        $slot = new VenueTrainingSlot;
        $slot->setClubId($clubId);
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek(2);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $em->persist($slot);

        // Le socle en vigueur (version pointée par le plan SEASON) + une période
        // avec son log de rappel.
        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Socle');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        // lot D : la version ne doit PAS être persistée/flushée avant le choose —
        // choosePlanVersion la lie (plan AVANT persist), la numérote puis la pointe.
        $this->choosePlanVersion($schedule);
        // Un nom choisi par le gestionnaire, pour prouver qu'il survit au reset.
        $em->getConnection()->executeStatement(
            'UPDATE schedule_plan SET name = \'Planning de la montée\' WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $season->getId()],
        );

        $entry = new CalendarEntry;
        $entry->setClubId($clubId);
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Fermeture');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $em->persist($entry);
        $em->flush();
        $em->persist((new PeriodReminderLog)->setCalendarEntryId($entry->getId())->setThreshold(14));
        $em->flush();
        // Rappel des vacances scolaires : le journal des paliers envoyés est clé par SAISON — le
        // reset le vide, sinon une saison réinitialisée resterait muette sur ses vacances.
        $em->getConnection()->executeStatement(
            'INSERT INTO holiday_reminder_log (id, season_id, school_holiday_id, threshold, sent_at) VALUES (gen_random_uuid(), :sid, gen_random_uuid(), 30, now())',
            ['sid' => $season->getId()],
        );
        $slotId = $slot->getId();
        $entryId = $entry->getId();
        $seasonId = $season->getId();

        $this->client->request('DELETE', '/api/reset-season', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $this->scopeGucToClub($clubId);
        self::assertNull($em->getRepository(VenueTrainingSlot::class)->find($slotId), 'training slots must be wiped (no FK to venue)');
        self::assertNull($em->getRepository(CalendarEntry::class)->find($entryId), 'calendar entries must be wiped');
        self::assertSame([], $em->getRepository(PeriodReminderLog::class)->findBy(['calendarEntryId' => $entryId]), 'reminder logs must be wiped');
        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM holiday_reminder_log WHERE season_id = :sid',
            ['sid' => $seasonId],
        ), 'the holiday reminder ledger of the season must be wiped');
        $freshSeason = $em->getRepository(Season::class)->find($seasonId);
        self::assertInstanceOf(Season::class, $freshSeason);
        // Le pointeur ne survit pas au reset : sa version est supprimée, et un plan
        // qui nommerait un planning disparu laisserait le cockpit « déverrouillé »
        // sans rien derrière.
        self::assertNull($this->chosenPlanVersion($freshSeason), 'the plan must point at nothing — its version is gone');
        // Le nom du planning est le choix du gestionnaire : réinitialiser les DONNÉES
        // de la saison ne le rebaptise pas. Il ne vit plus que sur le plan, que la purge
        // supprime — sans capture explicite il repartirait au nom par défaut, en silence.
        self::assertSame('Planning de la montée', $em->getConnection()->fetchOne(
            'SELECT name FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        ), 'le reset garde le nom que le gestionnaire a donné à son planning');

        // ADR-0002: the reset wipes the season's SchedulePlan rows but re-provisions
        // the empty SEASON plan — a surviving season always owns its SEASON plan.
        self::assertSame(
            1,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'', ['sid' => $seasonId]),
            'the SEASON plan must be re-provisioned after a reset',
        );
    }

    public function testResetRequiresAManagementRole(): void
    {
        [, , $clubId] = $this->register('RSTB');
        $editorToken = $this->addActiveMember($clubId, 'editor');

        // Active member of the club, but not a management role → 403.
        $this->client->request('DELETE', '/api/reset-season', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403, 'a non-management member must not reset the club');
    }

    public function testResetAsOwnerIsAllowed(): void
    {
        [, , $clubId] = $this->register('RSTC');
        $ownerToken = $this->addActiveMember($clubId, 'owner');

        $this->client->request('DELETE', '/api/reset-season', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $ownerToken]);
        self::assertResponseIsSuccessful();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function seedVenues(string $clubId, int $count): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($clubId);
        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season);

        for ($i = 0; $i < $count; ++$i) {
            $venue = new Venue;
            $venue->setClubId($clubId);
            $venue->setSeasonId($season->getId());
            $venue->setName(\sprintf('Gymnase %d', $i));
            $venue->setSource('manual');
            $em->persist($venue);
        }
        $em->flush();
    }

    /** @param array<string, mixed> $json */
    private function totalItems(array $json): int
    {
        $total = $json['totalItems'] ?? $json['hydra:totalItems'] ?? null;
        if (\is_int($total)) {
            return $total;
        }
        $member = $json['member'] ?? $json['hydra:member'] ?? [];

        return \is_array($member) ? \count($member) : 0;
    }

    /** @return array<string, mixed> */
    private function getJson(string $uri, string $token): array
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'R', 'lastName' => 'Reset', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
