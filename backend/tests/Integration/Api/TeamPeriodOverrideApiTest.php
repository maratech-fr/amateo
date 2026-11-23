<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
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
    use ComposesPlanTeamsTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    /** L'équipe réglée : une vraie équipe de la saison, avec sa ligne dans chaque plan (lot 4, PR 3a). */
    private string $team;

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
        $created = $this->post(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => false, 'sessionsPerWeek' => 1]);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($created['isActive']);
        self::assertSame(1, $created['sessionsPerWeek']);

        // Another period's override must not appear when listing this period.
        $this->post(['schedulePlanId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'teamId' => $this->team, 'isActive' => true, 'sessionsPerWeek' => null]);

        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->planId, [], [], $this->headers());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $members = $body['member'] ?? [];
        self::assertCount(1, $members, 'the collection is scoped to the requested period');
        self::assertSame($this->team, $members[0]['teamId']);
    }

    public function testUpdateRoundTripsActivation(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => false, 'sessionsPerWeek' => null]);

        $this->client->request('PUT', '/api/team_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => true, 'sessionsPerWeek' => 3], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['isActive']);
        self::assertSame(3, $body['sessionsPerWeek']);
    }

    /**
     * Le plafond de séances par jour d'une période fait l'aller-retour : absent ou `null` = celui de la saison, une valeur l'écrase,
     * le remplacement complet le remet à `null`.
     */
    public function testTheDailyCapOfAPeriodRoundTripsAndNullMeansTheSeason(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => true, 'sessionsPerWeek' => null]);
        self::assertResponseStatusCodeSame(201);
        self::assertArrayHasKey('maxSessionsPerDay', $created, 'le champ est exposé');
        self::assertNull($created['maxSessionsPerDay'], 'absent = la valeur de la saison');

        $this->client->request('PUT', '/api/team_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => true, 'sessionsPerWeek' => null, 'maxSessionsPerDay' => 3], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame(3, json_decode((string) $this->client->getResponse()->getContent(), true)['maxSessionsPerDay'] ?? null);

        $this->client->request('PUT', '/api/team_period_overrides/' . $created['id'], [], [], $this->headers(), json_encode(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => true, 'sessionsPerWeek' => null, 'maxSessionsPerDay' => null], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        // `??` prendrait un `null` rendu pour une absence : on exige la clé, puis sa valeur nulle (= la saison).
        $back = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('maxSessionsPerDay', $back, 'le champ est toujours rendu');
        self::assertNull($back['maxSessionsPerDay'], 'null = retour à la valeur de la saison');
    }

    /** Le plafond d'une période est borné à 1..4 comme celui de la saison : 0 ou 5 → 422, rien n'est écrit. */
    public function testTheDailyCapOfAPeriodIsBounded(): void
    {
        foreach ([0, 5] as $cap) {
            $this->post(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => true, 'sessionsPerWeek' => null, 'maxSessionsPerDay' => $cap]);
            self::assertResponseStatusCodeSame(422, \sprintf('%d séances par jour', $cap));
        }
        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->planId, [], [], $this->headers());
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? null, 'rien n\'est écrit');
    }

    /** Isolation : le gestionnaire d'un AUTRE club ne change pas le plafond de période de ce club — 404, la valeur reste. */
    public function testAnotherClubCannotChangeTheDailyCapOfAPeriod(): void
    {
        $created = $this->post(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => true, 'sessionsPerWeek' => null, 'maxSessionsPerDay' => 2]);
        self::assertResponseStatusCodeSame(201);

        $uid = uniqid('', true);
        $other = (new Club)->setName('TPO autre ' . $uid)->setSlug('tpo-autre-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($other);
        $intruder = (new User)->setEmail('tpo-autre' . $uid . '@test.com')->setFirstName('I')->setLastName('N')->setPasswordHash('x');
        $this->em->persist($intruder);
        $this->em->flush();
        $this->scopeGucToClub($other->getId());
        $this->em->persist((new ClubUser)->setClubId($other->getId())->setUserId($intruder->getId())->setRole('admin')->setIsActive(true));
        $this->em->flush();
        $intruderToken = self::getContainer()->get(JWTTokenManagerInterface::class)->create($intruder);

        $this->client->request('PUT', '/api/team_period_overrides/' . $created['id'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $intruderToken, 'CONTENT_TYPE' => 'application/ld+json'], json_encode(['schedulePlanId' => $this->planId, 'teamId' => $this->team, 'isActive' => true, 'sessionsPerWeek' => null, 'maxSessionsPerDay' => 4], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/team_period_overrides/' . $created['id'], [], [], $this->headers());
        self::assertSame(2, json_decode((string) $this->client->getResponse()->getContent(), true)['maxSessionsPerDay'] ?? null, 'la valeur reste');
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

        // Une vraie équipe (2 séances au socle) et un plan de période RÉEL, né du geste : l'équipe y a sa ligne.
        SeasonPlanOf::id($container, $this->season->getId());
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)->setName('SM1 TPO')->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        $this->team = $team->getId();
        $this->setSocleTeam($this->season->getId(), $this->team, 2);
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Période TPO')
            ->setStartDate(new DateTimeImmutable('2026-02-15'))->setEndDate(new DateTimeImmutable('2026-02-28'));
        $this->em->persist($entry);
        $this->planId = $this->planIdOf($entry);
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
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
