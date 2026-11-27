<?php

declare(strict_types=1);

namespace App\Tests\Integration\Matches;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Matches\FriendlyCompetitions;
use App\Plans\PlanComposition;
use App\Tests\ChoosesPlanVersionTrait;
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
 * Promesse : « Amicaux » existe et survit, et un amical a une seule définition quel que soit son chemin (lot 7,
 * PR 4a, §5 lignes 11 et 12). La compétition d'office d'une saison est unique — écrite une fois même si deux gestes la
 * demandent —, elle renaît à la demande après une remise à zéro, et l'amical de la fédération n'y entre jamais : il
 * va dans la compétition amicale de son libellé.
 *
 * Jumeaux de bout en bout : `placement-des-matchs.feature` (l'amical posé sans compétition est rangé dans
 * « Amicaux »), `supprimer-emporte-ce-qui-en-depend.feature` (« Amicaux » vidée reste),
 * `preparer-la-saison-suivante-reprend-la-structure.feature` (chaque saison a la sienne).
 */
#[Group('integration')]
final class FriendlyCompetitionsTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * La porte est idempotente : demander deux fois la compétition d'office et l'engagement d'une équipe donne une
     * compétition et un engagement (écriture immédiate qui absorbe le doublon, C42).
     */
    public function testEngagingTwiceInTheDefaultCompetitionGivesOneCompetitionAndOneEngagement(): void
    {
        [, $clubId] = $this->register();
        $team = $this->createTeam($clubId, 'U11 M');
        $friendlies = self::getContainer()->get(FriendlyCompetitions::class);

        $first = $friendlies->engageInDefault($clubId, $team->getSeasonId(), $team->getId());
        $second = $friendlies->engageInDefault($clubId, $team->getSeasonId(), $team->getId());

        self::assertSame($first->getId(), $second->getId(), 'la même compétition d\'office');
        self::assertSame($first->getId(), $friendlies->defaultFor($clubId, $team->getSeasonId())->getId(), 'celle que la porte rend pour la saison');
        $connection = $this->scoped($clubId);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM competition WHERE season_id = ? AND default_friendly', [$team->getSeasonId()]), 'une seule compétition d\'office par saison');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM engagement WHERE competition_id = ? AND team_id = ?', [$first->getId(), $team->getId()]), 'un seul engagement de l\'équipe');
    }

    /**
     * La remise à zéro purge la saison, « Amicaux » comprise : poser un amical ensuite rend 201, rangé dans une
     * « Amicaux » recréée à la demande (C37), jamais un 500 sur une compétition d'office disparue.
     */
    public function testAfterASeasonResetPostingAFriendlyRecreatesAmicaux(): void
    {
        [$token, $clubId] = $this->register();
        $this->createTeam($clubId, 'U13 M');
        $this->client->request('DELETE', '/api/reset-season', [], [], $this->auth($token));
        self::assertResponseIsSuccessful('témoin : la saison est remise à zéro');
        $team = $this->createTeam($clubId, 'U13 M après remise à zéro');

        $this->client->request('POST', '/api/fixtures', [], [], $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => $team->getId(),
            'matchDate' => new DateTimeImmutable('today')->modify('+20 days')->format('Y-m-d'),
            'homeAway' => 'AWAY',
            'opponentLabel' => 'Amical de reprise',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201, 'poser un amical après une remise à zéro passe');
        $connection = $this->scoped($clubId);
        self::assertSame(
            [['name' => 'Amicaux', 'competition_type' => 'FRIENDLY']],
            $connection->fetchAllAssociative('SELECT name, competition_type FROM competition WHERE id = ? AND default_friendly', [$this->json()['competitionId'] ?? '']),
            'l\'amical est rangé dans une « Amicaux » recréée',
        );
    }

    /**
     * Le canal API FFBB range l'amical de la fédération dans la compétition amicale de son libellé (« AMICAL PNM »),
     * née amicale, l'équipe engagée : jamais dans « Amicaux ».
     */
    public function testAFederationFriendlyFromTheApiChannelGoesToItsOwnFriendlyCompetitionNeverToAmicaux(): void
    {
        [$token, $clubId] = $this->register();
        $team = $this->createTeam($clubId, 'U18 M');

        $this->client->request('POST', '/api/ffbb/rencontres/apply', [], [], $this->auth($token) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'decisions' => [],
            'creations' => [['rencontreId' => FfbbHttpClientStub::RENCONTRE_AMICAL_ID, 'teamId' => $team->getId()]],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $connection = $this->scoped($clubId);
        $competitionId = $connection->fetchOne('SELECT competition_id FROM fixture WHERE ffbb_rencontre_id = ?', [FfbbHttpClientStub::RENCONTRE_AMICAL_ID]);
        self::assertIsString($competitionId, 'l\'amical de la fédération est créé, dans une compétition');
        self::assertSame(
            [['name' => 'AMICAL PNM', 'competition_type' => 'FRIENDLY', 'default_friendly' => false]],
            $connection->fetchAllAssociative('SELECT name, competition_type, default_friendly FROM competition WHERE id = ?', [$competitionId]),
            'il entre dans la compétition amicale de son libellé, jamais dans « Amicaux »',
        );
        self::assertSame([$team->getId()], $connection->fetchFirstColumn('SELECT team_id FROM engagement WHERE competition_id = ?', [$competitionId]), 'l\'équipe y est engagée');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function scoped(string $clubId): Connection
    {
        $this->scopeGucToClub($clubId);

        return $this->em->getConnection();
    }

    private function createTeam(string $clubId, string $name): Team
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();
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
        $suffix = 'amx' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Amicaux', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $suffix, 'consent' => true,
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
