<?php

declare(strict_types=1);

namespace App\Tests\Integration\Matches;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\FixtureHomeAway;
use App\Plans\PlanComposition;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesCompetitionsTrait;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse : l'ordre d'écriture tient (lot 7, PR 3b, plan « ordre d'écriture » et C36) — compétition, engagement et
 * match naissent ensemble sans que les clés (engagement → compétition, match → engagement) ne refusent le flush, et la
 * suppression d'une équipe emporte son engagement puis la compétition vidée, dans l'ordre inverse.
 *
 * Un ordre faux ne se voit qu'au flush : la base refuse (clé étrangère) et le geste du gestionnaire tombe en 500. Le
 * canal API (`POST /api/ffbb/rencontres/apply`, stub fédéral de `services_test.yaml`) est le seul écrivain qui crée
 * les trois dans un même geste ; la suppression passe par `DELETE /api/teams/{id}` (cascade réelle).
 *
 * Jumeau de bout en bout : `supprimer-emporte-ce-qui-en-depend.feature` (l'équipe sans match, son engagement et sa
 * compétition vidée, Exemples).
 */
#[Group('integration')]
final class CompetitionWriteOrderTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesCompetitionsTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Une rencontre de coupe non appariée, créée par le canal API : sa coupe, l'engagement de l'équipe et le match
     * sont écrits dans le même geste, et le match désigne le couple (coupe, équipe) engagé.
     */
    public function testTheApiChannelWritesTheCompetitionItsEngagementAndTheMatchTogether(): void
    {
        [$token, $clubId] = $this->register();
        $team = $this->createTeam($clubId, 'U18 M');

        $this->client->request('POST', '/api/ffbb/rencontres/apply', [], [], $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'decisions' => [],
            'creations' => [['rencontreId' => FfbbHttpClientStub::RENCONTRE_COUPE_1_ID, 'teamId' => $team->getId()]],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful('le geste aboutit : l\'ordre des écritures tient sous les clés');
        self::assertSame(1, $this->json()['created'] ?? null);
        $connection = $this->scoped($clubId);
        $fixture = $connection->fetchAssociative('SELECT competition_id, team_id FROM fixture WHERE ffbb_rencontre_id = ?', [FfbbHttpClientStub::RENCONTRE_COUPE_1_ID]);
        self::assertIsArray($fixture);
        self::assertSame($team->getId(), $fixture['team_id']);
        self::assertSame(
            [['name' => FfbbHttpClientStub::COUPE_NAME, 'competition_type' => 'CUP']],
            $connection->fetchAllAssociative('SELECT name, competition_type FROM competition WHERE id = ?', [$fixture['competition_id']]),
            'la coupe est créée',
        );
        self::assertSame(
            [$team->getId()],
            $connection->fetchFirstColumn('SELECT team_id FROM engagement WHERE competition_id = ?', [$fixture['competition_id']]),
            'l\'équipe y est engagée, une fois : le match désigne un engagement qui existe',
        );
    }

    /**
     * Supprimer une équipe sans match emporte son engagement, puis sa compétition, vidée ; la compétition d'une autre
     * équipe, qui porte un match, reste avec son engagement.
     */
    public function testDeletingATeamWithoutMatchTakesItsEngagementAndItsEmptiedCompetition(): void
    {
        [$token, $clubId] = $this->register();
        $leaving = $this->createTeam($clubId, 'U11 M');
        $staying = $this->createTeam($clubId, 'U13 M');
        $this->scopeGucToClub($clubId);
        $emptied = $this->competitionFor($leaving->getId(), 'Championnat U11');
        $kept = $this->competitionFor($staying->getId(), 'Championnat U13');
        $this->persistAway($staying, $kept->getId());

        $this->client->request('DELETE', '/api/teams/' . $leaving->getId(), [], [], $this->auth($token));

        self::assertResponseStatusCodeSame(204, 'une équipe sans match se supprime, sa compétition et son engagement avec elle');
        $connection = $this->scoped($clubId);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM engagement WHERE team_id = ?', [$leaving->getId()]), 'son engagement part');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM competition WHERE id = ?', [$emptied->getId()]), 'sa compétition, sans engagement ni match, part');
        self::assertSame([$staying->getId()], $connection->fetchFirstColumn('SELECT team_id FROM engagement WHERE competition_id = ?', [$kept->getId()]), 'la compétition de l\'autre équipe reste, avec son engagement');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function persistAway(Team $team, string $competitionId): void
    {
        $fixture = new Fixture;
        $fixture->setClubId($team->getClubId());
        $fixture->setSeasonId($team->getSeasonId());
        $fixture->setTeamId($team->getId());
        $fixture->setCompetitionId($competitionId);
        $fixture->setMatchDate(new DateTimeImmutable('today')->modify('+20 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel('Adversaire');
        $this->em->persist($fixture);
        $this->em->flush();
    }

    private function scoped(string $clubId): Connection
    {
        $this->scopeGucToClub($clubId);

        return $this->em->getConnection();
    }

    private function createTeam(string $clubId, string $name): Team
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season, 'l\'inscription sème la saison');
        if (null === $this->chosenPlanVersion($season)) {
            $this->settleSeasonPlan($season);
        }
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        self::assertInstanceOf(Sport::class, $sport, 'l\'inscription sème le sport');
        $category = (new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName($name . ' ' . uniqid('', true));
        $this->em->persist($category);
        $team = (new Team)->setClubId($clubId)->setSeasonId($season->getId())->setSportCategoryId($category->getId())
            ->setPriorityTierId(3)->setName($name)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);
        $this->em->flush();

        return $team;
    }

    /** @return array{0: string, 1: string} [jeton, club] — club inscrit, au code fédéral du stub */
    private function register(): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'cwo' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Ordre', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $suffix, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);
        $this->client->request('GET', '/api/me', [], [], $this->auth($token));
        $clubId = (string) ($this->json()['club']['id'] ?? '');
        $club = $this->em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        $club->setFfbbClubCode(FfbbHttpClientStub::CLUB_CODE);
        $this->em->flush();

        return [$token, $clubId];
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
