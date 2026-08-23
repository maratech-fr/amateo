<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SchedulePlanType;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Period-editable structure: the TeamPeriodOverride API stamps the tenant/season
 * server-side, scopes the collection to a period, and round-trips activation +
 * sessions-per-week.
 */
#[Group('phase1')]
final class TeamPeriodOverrideApiTest extends WebTestCase
{
    use TenantGucTrait;
    private const TEAM = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    /** Ancre opaque : ces cas testent le cloisonnement par plan, pas le plan lui-même. */
    // P4-34 — l'ancre doit EXISTER (422 sinon) : plus d'uuid décoratif, on
    // provisionne un vrai plan de période au setUp, comme en production.
    private string $planId;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    public function testCreateStampsTenantAndListScopesToPeriod(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'teamId' => self::TEAM, 'isActive' => false, 'sessionsPerWeek' => 1]);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($created['isActive']);
        self::assertSame(1, $created['sessionsPerWeek']);

        // Another period's override must not appear when listing this period.
        $this->post(['schedulePlanId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'teamId' => self::TEAM, 'isActive' => true, 'sessionsPerWeek' => null]);

        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->planId, [], [], $this->headers());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $members = $body['member'] ?? [];
        self::assertCount(1, $members, 'the collection is scoped to the requested period');
        self::assertSame(self::TEAM, $members[0]['teamId']);
    }

    public function testUpdateRoundTripsActivation(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'teamId' => self::TEAM, 'isActive' => false, 'sessionsPerWeek' => null]);

        $this->client->request('PUT', '/api/team_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode(['schedulePlanId' => $this->planId, 'teamId' => self::TEAM, 'isActive' => true, 'sessionsPerWeek' => 3], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['isActive']);
        self::assertSame(3, $body['sessionsPerWeek']);
    }

    public function testDuplicateOverrideIsRejectedWithValidationNotServerError(): void
    {
        $this->post(['schedulePlanId' => $this->planId, 'teamId' => self::TEAM, 'isActive' => false, 'sessionsPerWeek' => null]);
        self::assertResponseStatusCodeSame(201);

        // A second POST for the same (period, team) → clean 422, not a 500 from the DB unique index.
        $this->post(['schedulePlanId' => $this->planId, 'teamId' => self::TEAM, 'isActive' => true, 'sessionsPerWeek' => 2]);
        self::assertResponseStatusCodeSame(422);
        // P4-126 — le motif voyage jusque dans le corps (un 422 muet rendrait `violations: []`).
        self::assertStringContainsString('Cette équipe a déjà un réglage pour cette période — modifiez-le.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * ADR-0002 lot C: the seed guard lives on the PLAN (inv. 5 — the settings hang off
     * the plan, not off the calendar event), so the first override marks the plan.
     */
    public function testFirstOverrideMarksThePlanTeamSelectionInitialized(): void
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Reprise')
            ->setStartDate(new DateTimeImmutable('2026-01-05'))->setEndDate(new DateTimeImmutable('2026-01-11'));
        $this->em->persist($entry);
        $this->em->flush();
        // En prod le geste (POST /api/calendar_entries) crée le plan ; l'entrée est
        // fabriquée à la main ici, on rejoue donc le geste explicitement.
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());
        self::assertIsString($planId);
        self::assertFalse($this->planById($planId)->isTeamSelectionInitialized(), 'a fresh plan is not yet configured');

        $this->post(['schedulePlanId' => $planId, 'teamId' => self::TEAM, 'isActive' => false, 'sessionsPerWeek' => null]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/schedule_plans/' . $planId, [], [], $this->headers());
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['teamSelectionInitialized'], 'the first override marks the plan configured');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('TPO ' . $uid)->setSlug('tpo-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('tpo' . $uid . '@test.com')->setFirstName('T')->setLastName('O');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);

        // Un plan de période RÉEL, ancre de tous les cas ci-dessus.
        $plan = (new SchedulePlan)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setType(SchedulePlanType::HOLIDAY)->setName('Période TPO')
            ->setStartDate(new DateTimeImmutable('2026-02-15'))->setEndDate(new DateTimeImmutable('2026-02-28'));
        $this->em->persist($plan);
        $this->em->flush();
        $this->planId = $plan->getId();
    }

    private function planById(string $planId): SchedulePlan
    {
        $this->em->clear();
        $plan = $this->em->getRepository(SchedulePlan::class)->find($planId);
        self::assertInstanceOf(SchedulePlan::class, $plan);

        return $plan;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $this->client->request('POST', '/api/team_period_overrides', [], [], $this->headers(), json_encode($payload, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
