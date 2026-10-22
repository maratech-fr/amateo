<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PROMESSE — `GET /api/schedule_plans` sert `startDate`/`endDate` en DATE SEULE (Y-m-d),
 * item ET collection, pour un plan SEASON comme pour un plan de PÉRIODE.
 *
 * Pourquoi c'est une garde et pas un détail : le front dérive les semaines d'une collecte
 * de doléances de `plan.startDate` (`coach-wishes/campaignWeeks.ts` → `mondayOf`, qui
 * `split("-")`). Un `startDate` sérialisé en RFC 3339 (« 2026-12-28T00:00:00+00:00 », le
 * DateTimeImmutable brut du Resource) fait tomber ce découpage sur « NaN-NaN-NaN », une
 * semaine impossible à cocher — l'onglet Réglages de la collecte affiche « NaN » et la
 * création échoue (retour terrain Rillieux, 2026-10-21). Le CalendarEntryResource sert déjà
 * ses dates en Y-m-d (CalendarEntryResource::fromEntity) ; ce plan doit en faire autant.
 */
#[Group('phase1')]
#[Group('integration')]
final class SchedulePlanDateFormatTest extends WebTestCase
{
    use TenantGucTrait;

    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private SchedulePlanProvisioner $provisioner;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    /** La COLLECTION sert chaque plan (SEASON + PÉRIODE) avec des dates Y-m-d, jamais du RFC 3339. */
    public function testCollectionServesDateOnlyStartAndEndDates(): void
    {
        [$user, $club, $season] = $this->seed('SPD1');
        $this->provisionClosurePeriodPlan($club, $season, '2026-12-28', '2027-01-03');

        $plans = $this->members($this->request($user, '/api/schedule_plans'));
        self::assertNotEmpty($plans, 'la saison porte au moins son plan SEASON');

        foreach ($plans as $plan) {
            $this->assertDateOnly($plan);
        }

        // Le plan de période (Noël) porte EXACTEMENT les dates de son entrée calendrier.
        $period = $this->onlyPeriodPlan($plans);
        self::assertSame('2026-12-28', $period['startDate']);
        self::assertSame('2027-01-03', $period['endDate']);
    }

    /** L'ITEM (GET /api/schedule_plans/{id}) sert lui aussi ses dates en Y-m-d. */
    public function testItemServesDateOnlyStartAndEndDates(): void
    {
        [$user, $club, $season] = $this->seed('SPD2');
        $planId = $this->provisionClosurePeriodPlan($club, $season, '2026-12-28', '2027-01-03');

        $plan = $this->request($user, "/api/schedule_plans/{$planId}");
        $this->assertDateOnly($plan);
        self::assertSame('2026-12-28', $plan['startDate']);
        self::assertSame('2027-01-03', $plan['endDate']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->provisioner = $container->get(SchedulePlanProvisioner::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * Crée un plan de PÉRIODE par le geste réel (une entrée calendrier CLOSURE disruptive
     * → `provisionPeriodPlan`), avec des dates connues, et rend son id.
     */
    private function provisionClosurePeriodPlan(Club $club, Season $season, string $start, string $end): string
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $entry->setTitle('Vacances de Noël');
        $entry->setIsDisruptive(true);
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        $planId = $this->provisioner->provisionPeriodPlan($entry->getId());
        self::assertIsString($planId, 'le plan de période naît du geste');
        $this->em->flush();

        return $planId;
    }

    /**
     * Les deux dates du plan sont servies en date seule (Y-m-d), jamais en RFC 3339.
     *
     * @param array<string, mixed> $plan
     */
    private function assertDateOnly(array $plan): void
    {
        foreach (['startDate', 'endDate'] as $field) {
            $value = $plan[$field] ?? null;
            self::assertIsString($value, "{$field} est une chaîne");
            self::assertMatchesRegularExpression(self::DATE_ONLY, $value, "{$field} servi en date seule (Y-m-d)");
        }
    }

    /**
     * @param list<array<string, mixed>> $plans
     *
     * @return array<string, mixed>
     */
    private function onlyPeriodPlan(array $plans): array
    {
        // Un plan de PÉRIODE porte un type CLOSURE/HOLIDAY (jamais SEASON) — cf. SchedulePlanType.
        $periods = array_values(array_filter($plans, static fn (array $p): bool => 'SEASON' !== ($p['type'] ?? null)));
        self::assertCount(1, $periods, 'un seul plan de période dans ce décor');

        return $periods[0];
    }

    /**
     * Les membres d'une collection JSON-LD (API Platform 4.4) vivent sous `member` ;
     * chacun est une ressource sérialisée (tableau associatif).
     *
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $body): array
    {
        $raw = $body['member'] ?? null;
        self::assertIsArray($raw, 'la collection JSON-LD porte ses membres sous `member`');

        $members = [];
        foreach ($raw as $member) {
            self::assertIsArray($member);
            /* @var array<string, mixed> $member */
            $members[] = $member;
        }

        return $members;
    }

    /**
     * GET authentifié (firewall stateless → Bearer à chaque appel) ; rend le corps décodé.
     *
     * @return array<string, mixed>
     */
    private function request(User $user, string $uri): array
    {
        $this->client->request('GET', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        /* @var array<string, mixed> $body */
        return $body;
    }

    /** @return array{0: User, 1: Club, 2: Season} */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('S');
        $user->setLastName('P');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2026-2027');
        $season->setStartDate(new DateTimeImmutable('2026-09-01'));
        $season->setEndDate(new DateTimeImmutable('2027-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $this->provisioner->ensureSeasonPlan($season);
        $this->em->flush();

        return [$user, $club, $season];
    }
}
