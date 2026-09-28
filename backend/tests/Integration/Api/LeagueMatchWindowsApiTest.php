<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\LeagueMatchWindow;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\ClubLeagueWindowSeeder;
use App\Service\LeagueResolver;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P4-272 ① — `GET /api/league-match-windows` sert désormais la COPIE club de
 * l'enveloppe (`club_league_window`, tenant + saison), plus le catalogue GLOBAL :
 * le placement, le radar et l'écran des contraintes lisent la MÊME maison. Le
 * champ `league` reste la ligue EFFECTIVE (celle du club si cataloguée, sinon la
 * défaut fédérale AURA). La copie est posée à la naissance du club depuis cette
 * ligue effective : deux clubs de la même ligue voient le MÊME contenu, mais leur
 * PROPRE copie (isolée par tenant).
 */
#[Group('phase1')]
#[Group('integration')]
final class LeagueMatchWindowsApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAuraClubGetsTheAuraEnvelope(): void
    {
        [$user] = $this->clubUser('AURA'); // ARA prefix → AURA league

        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('AURA', $data['league']);
        self::assertNotEmpty($data['items']);
        self::assertSame(['AURA'], array_values(array_unique(array_column($data['items'], 'league'))));
    }

    public function testUncataloguedLeagueFallsBackToAuraDefault(): void
    {
        // A club whose league (BOFC) has no catalogued windows → AURA default copy.
        [$user] = $this->clubUser('BFC');

        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('AURA', $data['league']);
        self::assertNotEmpty($data['items']);
    }

    public function testCopyIsPerClubAndTenantIsolated(): void
    {
        // Two clubs, same league, see the same CONTENT — but each its OWN copy
        // (distinct ids, no cross-tenant leak): the copy is per-club, not global.
        [$userA] = $this->clubUser('AURA');
        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($userA));
        $idsA = array_column($this->responseData()['items'], 'id');

        [$userB] = $this->clubUser('AURA');
        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($userB));
        $idsB = array_column($this->responseData()['items'], 'id');

        self::assertNotEmpty($idsA);
        self::assertNotEmpty($idsB);
        self::assertNotSame($idsA, $idsB, 'chaque club a sa PROPRE copie (ids distincts)');
        self::assertSame([], array_intersect($idsA, $idsB), 'aucune ligne d\'un club ne fuit chez l\'autre');
    }

    public function testResolvedTeamWindowsUseTheServerJoin(): void
    {
        // P1-4 PR E2 (dette iv): the response resolves each team's envelope with
        // the SAME LeagueEnvelopeResolver the solver uses — a « U13 »
        // DEPARTEMENTAL team maps to the U13 copy window, a category outside the
        // copy resolves to [] (unmapped = advisory on screen, no HARD).
        [$user, $clubId, $seasonId] = $this->clubUser('AURA');

        $sport = new Sport;
        $sport->setName('Basket ' . uniqid('', true));
        $sport->setSlug('basket-' . uniqid('', true));
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $mapped = $this->team($clubId, $seasonId, $sport->getId(), 'U13', TeamLevel::DEPARTEMENTAL);
        $loisir = $this->team($clubId, $seasonId, $sport->getId(), 'Loisir', TeamLevel::DEPARTEMENTAL);

        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();

        $u13Ids = array_column(array_values(array_filter($data['items'], static fn (array $item): bool => 'U13' === $item['category'])), 'id');
        self::assertNotEmpty($u13Ids);
        self::assertSame($u13Ids, $data['resolvedTeamWindows'][$mapped]);
        self::assertSame([], $data['resolvedTeamWindows'][$loisir]);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Deterministic global seed source: one AURA row + one GEST row. The copy
        // is seeded from it at club birth (below).
        $this->em->createQuery('DELETE FROM ' . LeagueMatchWindow::class . ' w')->execute();
        $this->window('AURA', 'U13', 6, '13:00', '18:00');
        $this->window('GEST', 'U13', 6, '14:00', '19:00');
        $this->em->flush();
    }

    private function team(string $clubId, string $seasonId, string $sportId, string $categoryName, TeamLevel $level): string
    {
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sportId);
        $category->setName($categoryName);
        $this->em->persist($category);
        $this->em->flush();

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('Équipe ' . $categoryName);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $team->setLevel($level);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    private function window(string $league, string $category, int $day, string $min, string $max): void
    {
        $w = new LeagueMatchWindow;
        $w->setLeague($league);
        $w->setCategory($category);
        $w->setLevel(LeagueMatchWindow::LEVEL_DEPARTEMENTAL);
        $w->setGender(null);
        $w->setDayOfWeek($day);
        $w->setKickoffMin(DateTimeImmutable::createFromFormat('!H:i', $min));
        $w->setKickoffMax(DateTimeImmutable::createFromFormat('!H:i', $max));
        $this->em->persist($w);
    }

    /**
     * @return array{0: User, 1: string, 2: string} [user, clubId, seasonId]
     */
    private function clubUser(string $ffbbPrefix): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $resolver = self::getContainer()->get(LeagueResolver::class);

        $club = new Club;
        $club->setName('Club catalog');
        $club->setSlug('club-catalog-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $ffbb = ('AURA' === $ffbbPrefix ? 'ARA' : $ffbbPrefix) . '00' . substr((string) crc32($uid), 0, 5);
        $club->setFfbbClubCode($ffbb);
        $club->setLeague($resolver->resolveFromFfbbCode($ffbb));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('catalog' . $uid . '@test.com');
        $user->setFirstName('Cat');
        $user->setLastName('Alog');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        // Saison ACTIVE courante + copie de l'enveloppe ligue (posée à la naissance
        // du club dans le vrai flux — ici reproduite via le seeder, foyer unique).
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        self::getContainer()->get(ClubLeagueWindowSeeder::class)
            ->seedForSeason($club->getId(), $season->getId(), $club->getLeague());
        $this->em->flush();

        return [$user, $club->getId(), $season->getId()];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
