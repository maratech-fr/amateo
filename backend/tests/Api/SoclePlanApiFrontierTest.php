<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse : à la frontière de l'API, le socle reste « sans plan » jusqu'au lot 6 (écart E2 du plan du lot 4),
 * alors qu'en base chacune de ses lignes porte le plan de saison. Sur les six familles — grille, occupations,
 * réservations, groupes mutualisés et leurs membres, règles de bien-être — `schedulePlanId: null` est accepté en
 * entrée et rangé sur le plan de saison, et une ligne du socle se lit `schedulePlanId: null` en sortie. La
 * version d'un planning de saison, elle, rend le vrai identifiant de son plan (lu par l'écran des plannings de
 * la saison).
 *
 * Le décor passe par l'API (le geste du gestionnaire) ; la lecture en base n'est qu'un pointage.
 */
#[Group('integration')]
final class SoclePlanApiFrontierTest extends WebTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    /** Les tables des six familles, et la route qui écrit chacune. */
    private const array TABLES = ['venue_training_slot', 'venue_occupancy', 'reservation', 'shared_training_block', 'shared_training_block_team', 'implicit_rule_setting'];

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    private Team $teamA;

    private Team $teamB;

    private Venue $venue;

    public function testANullPlanAtTheApiIsStoredOnTheSeasonPlan(): void
    {
        $this->writeTheSocleThroughTheApi();

        $seasonPlan = $this->seasonPlanId();
        foreach (self::TABLES as $table) {
            $plans = $this->em->getConnection()->fetchFirstColumn(\sprintf('SELECT DISTINCT COALESCE(schedule_plan_id::text, \'nul\') FROM %s WHERE season_id = ?', $table), [$this->season->getId()]);
            self::assertSame([$seasonPlan], $plans, \sprintf('%s : la ligne écrite sans plan est rangée sur le plan de saison', $table));
        }
    }

    public function testASocleRowReadsNullPlanAtTheApi(): void
    {
        $ids = $this->writeTheSocleThroughTheApi();
        // Pointage : la base dans l'état que la conversion lui donne (chaque ligne du socle sur le plan de saison).
        foreach (self::TABLES as $table) {
            $this->em->getConnection()->executeStatement(\sprintf('UPDATE %s SET schedule_plan_id = ? WHERE season_id = ?', $table), [$this->seasonPlanId(), $this->season->getId()]);
        }

        foreach (['venue_training_slots' => $ids['slot'], 'venue_occupancies' => $ids['occupancy'], 'reservations' => $ids['reservation'], 'shared_training_blocks' => $ids['block']] as $route => $id) {
            $row = $this->memberOf('/api/' . $route, $id);
            self::assertArrayHasKey('schedulePlanId', $row, $route);
            self::assertNull($row['schedulePlanId'], \sprintf('%s : une ligne du socle se lit sans plan', $route));
        }
        $block = $this->memberOf('/api/shared_training_blocks', $ids['block']);
        self::assertCount(2, \is_array($block['teamIds'] ?? null) ? $block['teamIds'] : [], 'le groupe du socle se lit avec ses deux membres');

        $this->get('/api/implicit_rule_settings');
        $rules = $this->decode()['member'] ?? [];
        self::assertIsArray($rules);
        $coachRest = array_values(array_filter($rules, static fn (mixed $rule): bool => \is_array($rule) && 'coachRestDay' === ($rule['ruleKey'] ?? null)));
        self::assertSame(['PREFERRED', false], [$coachRest[0]['intensity'] ?? null, $coachRest[0]['isDefault'] ?? null], 'la règle de bien-être du socle se lit sans préciser de plan');
    }

    public function testASocleVersionReadsTheRealIdOfItsPlan(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $version = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('V')->setStatus(ScheduleStatus::COMPLETED)->setSchedulePlanId($this->seasonPlanId());
        $this->em->persist($version);
        $provisioner->linkSchedule($version);
        $this->em->flush();

        $this->get('/api/schedules/' . $version->getId());

        self::assertResponseStatusCodeSame(200);
        self::assertSame($this->seasonPlanId(), $this->decode()['schedulePlanId'] ?? null, 'la version du socle rend le vrai identifiant de son plan');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $this->club = (new Club)->setName('Frontière ' . $uid)->setSlug('frontiere-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true)->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($this->club);
        $this->user = (new User)->setEmail('frontiere' . $uid . '@test.com')->setFirstName('F')->setLastName('Rontière');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $startYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = (new Season)->setClubId($this->club->getId())->setName((string) $startYear)
            ->setStartDate(new DateTimeImmutable($startYear . '-08-01'))->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->teamA = $this->createTeam('U11F1');
        $this->teamB = $this->createTeam('U11F2');
        $this->venue = $this->newVenue($this->club->getId(), $this->season->getId(), 'Gymnase Nord');
        $this->venue->setCanSplit(true);
        $this->em->persist($this->venue);
        $this->em->flush();
    }

    /**
     * Le socle saisi comme le gestionnaire le saisit, `schedulePlanId: null` explicite : un créneau, une occupation,
     * une réservation, un groupe mutualisé de deux équipes, une règle de bien-être.
     *
     * @return array{slot: string, occupancy: string, reservation: string, block: string}
     */
    private function writeTheSocleThroughTheApi(): array
    {
        $ids = [
            'slot' => $this->post('/api/venue_training_slots', ['venueId' => $this->venue->getId(), 'dayOfWeek' => 2, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1, 'schedulePlanId' => null]),
            'occupancy' => $this->post('/api/venue_occupancies', ['venueId' => $this->venue->getId(), 'dayOfWeek' => 3, 'startTime' => '14:00', 'durationMinutes' => 60, 'label' => 'École', 'schedulePlanId' => null]),
            'reservation' => $this->post('/api/reservations', ['teamId' => $this->teamA->getId(), 'venueId' => $this->venue->getId(), 'dayOfWeek' => 4, 'startTime' => '18:00', 'durationMinutes' => 90, 'schedulePlanId' => null]),
            'block' => $this->post('/api/shared_training_blocks', ['teamIds' => [$this->teamA->getId(), $this->teamB->getId()], 'commonSessions' => 1, 'schedulePlanId' => null]),
        ];
        $this->client->request('PUT', '/api/implicit_rule_settings/coachRestDay', [], [], $this->headers(), json_encode(['intensity' => 'PREFERRED', 'minRestDays' => 3, 'schedulePlanId' => null], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->em->clear();

        return $ids;
    }

    /** @param array<string, mixed> $body */
    private function post(string $route, array $body): string
    {
        $this->client->request('POST', $route, [], [], $this->headers(), json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, $route . ' : ' . $this->client->getResponse()->getContent());
        $id = $this->decode()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** @return array<string, mixed> la ligne `$id` de la liste par défaut (le socle) de `$route` */
    private function memberOf(string $route, string $id): array
    {
        $this->get($route);
        self::assertResponseStatusCodeSame(200);
        $members = $this->decode()['member'] ?? [];
        self::assertIsArray($members);
        foreach ($members as $member) {
            if (\is_array($member) && $id === ($member['id'] ?? null)) {
                /* @var array<string, mixed> $member */
                return $member;
            }
        }
        self::fail(\sprintf('%s : la ligne du socle %s figure dans la liste du socle', $route, $id));
    }

    private function get(string $route): void
    {
        $this->client->request('GET', $route, [], [], $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function seasonPlanId(): string
    {
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($this->season->getId());
        self::assertIsString($planId);
        $this->em->flush();

        return $planId;
    }

    private function createTeam(string $name): Team
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $uid = uniqid('', true);
            $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = (new SportCategory)->setClubId($this->club->getId())->setSportId($sport->getId())->setName('U11-' . uniqid('', true));
        $this->em->persist($category);
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setSportCategoryId($category->getId())
            ->setPriorityTierId(3)->setName($name)->setSessionsPerWeek(2)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
