<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'endpoint des stats d'utilisation des gymnases (P3-22) : sert le planning en
 * vigueur du club, borné par défaut à la saison, et JAMAIS les données d'un autre
 * club (isolation tenant). from/to invalides → 400.
 */
#[Group('phase1')]
#[Group('integration')]
final class VenueUsageStatsApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testReturnsOwnClubStatsOverTheDefaultSeasonWindow(): void
    {
        $ctx = $this->seedClubWithStats('VUA', 'A', 'Gymnase Mateo', TeamLevel::REGIONAL, 1, 120);

        $data = $this->getStats($ctx['user'], $ctx['clubId']);

        // Défaut de plage = la saison entière.
        self::assertSame(['from' => '2026-09-01', 'to' => '2027-06-30', 'today' => $data['range']['today']], $data['range']);
        self::assertSame('A', $data['zone']);

        $names = array_column($data['venues'], 'name');
        self::assertContains('Gymnase Mateo', $names);
        self::assertGreaterThan(0.0, $data['grandTotal']['total']);
        // La saison 2026-2027 est à venir → tout est « projeté », rien de réalisé.
        self::assertGreaterThan(0.0, $data['grandTotal']['projected']);

        // Ventilation par niveau : le libellé vient du serveur.
        $level = $this->rowBy($data['byLevel'], 'level', 'REGIONAL');
        self::assertSame('Régional', $level['label']);
        self::assertNotSame([], $data['totalByDay']);
    }

    public function testAnotherClubNeverSeesThisClubsData(): void
    {
        $this->seedClubWithStats('VUA', 'A', 'Gymnase Mateo', TeamLevel::REGIONAL, 1, 120);
        $b = $this->seedClubWithStats('VUB', 'B', 'Gymnase Barros', TeamLevel::LOISIR_ADULTE, 2, 90);

        $data = $this->getStats($b['user'], $b['clubId']);

        $names = array_column($data['venues'], 'name');
        self::assertContains('Gymnase Barros', $names);
        self::assertNotContains('Gymnase Mateo', $names, 'un club ne doit jamais voir le gymnase d\'un autre');

        $levels = array_column($data['byLevel'], 'level');
        self::assertContains('LOISIR_ADULTE', $levels);
        self::assertNotContains('REGIONAL', $levels);
    }

    public function testMalformedDateWindowReturns400(): void
    {
        $ctx = $this->seedClubWithStats('VUC', 'A', 'Gymnase Mateo', TeamLevel::REGIONAL, 1, 120);

        // Date calendaire inexistante : passe une regex naïve, doit être rejetée.
        $this->request($ctx['user'], $ctx['clubId'], '?from=2026-13-01');
        self::assertResponseStatusCodeSame(400);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);

        // Les vacances scolaires ne feraient que RÉDUIRE des heures — on isole la
        // table globale pour que « total > 0 » ne dépende pas du jeu seedé.
        $this->em->getConnection()->executeStatement('DELETE FROM school_holiday_period');
    }

    private function request(User $user, string $clubId, string $query = ''): void
    {
        $this->client->request('GET', '/api/venue-usage-stats' . $query, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getStats(User $user, string $clubId, string $query = ''): array
    {
        $this->request($user, $clubId, $query);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function rowBy(array $rows, string $key, mixed $value): array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }
        self::fail(\sprintf('No row with %s = %s', $key, var_export($value, true)));
    }

    /**
     * Un club/saison/plan SEASON POINTÉ, une équipe (avec niveau), un gymnase et un
     * créneau placé sur le planning en vigueur.
     *
     * @return array{user: User, clubId: string, seasonId: string}
     */
    private function seedClubWithStats(string $tag, string $zone, string $venueName, TeamLevel $level, int $day, int $duration): array
    {
        $suffix = $tag . '-' . bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('vus-' . strtolower($suffix))->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setSchoolZone($zone);
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper($tag) . substr(bin2hex(random_bytes(4)), 0, 6));
        $this->em->persist($club);

        $user = (new User)->setEmail('user-' . strtolower($suffix) . '@test.com')->setFirstName('M')->setLastName('VU');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $cu = (new ClubUser)->setClubId($clubId)->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($cu);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($venueName)->setSource('manual');
        $this->em->persist($venue);

        $sport = (new Sport)->setName('Basketball')->setSlug('bball-' . strtolower($suffix))->setIsActive(true);
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

        $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setSportCategoryId($category->getId())->setPriorityTierId($tier->getId())->setName('U13')->setSessionsPerWeek(2)->setLevel($level);
        $this->em->persist($team);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setName('Plan')->setStatus(ScheduleStatus::COMPLETED)->setScore(80);
        // Lie la version au plan SEASON puis la POINTE (chosen_schedule_id) = « en vigueur ».
        $this->choosePlanVersion($schedule);

        $slot = (new ScheduleSlotTemplate)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setScheduleId($schedule->getId())
            ->setTeamId($team->getId())
            ->setVenueId($venue->getId())
            ->setDayOfWeek($day)
            ->setStartTime(DateTimeImmutable::createFromFormat('!H:i', '18:00'))
            ->setDurationMinutes($duration);
        $this->em->persist($slot);
        $this->em->flush();

        // Le provisioner POINTE la version par un UPDATE SQL brut : l'identity map
        // (partagée avec le contrôleur en WebTestCase) garderait un plan périmé
        // chosen=null. En prod la lecture se fait dans une autre requête/EM ; ici
        // on vide la map pour que le contrôleur relise la base.
        $this->em->clear();

        return ['user' => $user, 'clubId' => $clubId, 'seasonId' => $seasonId];
    }
}
