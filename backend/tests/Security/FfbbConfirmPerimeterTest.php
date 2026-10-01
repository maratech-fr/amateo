<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR de l'axe §7.1 « périmètre engagé » sur la résorption des jumelles au confirm
 * d'appariement FFBB (`POST /api/ffbb/engagements/confirm`, décision fondateur
 * 2026-10-01). Le confirm nettoie désormais les refs d'une compétition JUMELLE de la
 * MÊME équipe (défaut corrigé : avant, seule une équipe DIFFÉRENTE l'était) et SUPPRIME
 * celles qui, ayant perdu leurs refs, ne portent AUCUN match. L'invariant engagé exige,
 * dans les DEUX sens :
 *
 *  - une jumelle VIDE (0 fixture) qui portait les refs est bien supprimée — la cible
 *    récupère les refs, et les matchs de la cible ne bougent pas ;
 *  - une compétition qui PORTE des fixtures n'est JAMAIS supprimée au confirm (même si
 *    elle perd ses refs), et AUCUNE fixture ne change d'équipe.
 *
 * Sans ce gate, un élargissement de la résorption (supprimer une compétition non vide,
 * ou ré-affecter ses rencontres) effacerait un périmètre déjà engagé en silence —
 * invisible aux autres checks. Backend FFBB = le stub déterministe (services_test.yaml).
 */
#[Group('phase1')]
#[Group('integration')]
final class FfbbConfirmPerimeterTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAnEmptyTwinIsResorbedWhileTheTargetKeepsItsMatches(): void
    {
        [$token, , $clubId] = $this->register('FCPA');
        $this->useStubClubCode($clubId);
        $seasonId = $this->seasonOf($clubId)->getId();
        $team = $this->createTeam($clubId);

        // La cible : la compétition xlsx qui PORTE déjà des matchs, sans refs encore.
        $target = $this->createCompetition($clubId, $seasonId, $team->getId(), 'PNM');
        $fixture = $this->createFixture($clubId, $seasonId, $team->getId(), $target->getId());
        $fixtureId = $fixture->getId();
        // La jumelle VIDE : elle porte les refs d'un confirm antérieur, 0 match.
        $twin = $this->createCompetition($clubId, $seasonId, $team->getId(), 'Pré test masculine', FfbbHttpClientStub::COMPETITION_ID);
        $twinId = $twin->getId();

        $this->confirm($token, FfbbHttpClientStub::COMPETITION_ID, $team->getId(), $target->getId());
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubId);
        $this->em->clear();

        $reloadedTarget = $this->em->getRepository(Competition::class)->find($target->getId());
        self::assertInstanceOf(Competition::class, $reloadedTarget);
        self::assertSame(FfbbHttpClientStub::COMPETITION_ID, $reloadedTarget->getFfbbCompetitionId(), 'the refs land on the fixture-bearing competition');

        self::assertNull($this->em->getRepository(Competition::class)->find($twinId), 'the empty twin is resorbed (deleted)');

        $reloadedFixture = $this->em->getRepository(Fixture::class)->find($fixtureId);
        self::assertInstanceOf(Fixture::class, $reloadedFixture, 'the target match survives the confirm');
        self::assertSame($team->getId(), $reloadedFixture->getTeamId(), 'a fixture never changes team at confirm');
        self::assertSame($target->getId(), $reloadedFixture->getCompetitionId(), 'the match stays on its own competition');
    }

    public function testACompetitionCarryingFixturesIsNeverDeletedWhenItLosesItsRefs(): void
    {
        [$token, , $clubId] = $this->register('FCPB');
        $this->useStubClubCode($clubId);
        $seasonId = $this->seasonOf($clubId)->getId();
        $team = $this->createTeam($clubId);

        // L'ancienne compétition appariée porte les refs ET deux matchs : le re-confirm
        // vers une AUTRE compétition de la même équipe lui retire les refs — elle doit
        // SURVIVRE (périmètre engagé), ses matchs restant sur elle et sur la même équipe.
        $engaged = $this->createCompetition($clubId, $seasonId, $team->getId(), 'OLD', FfbbHttpClientStub::COMPETITION_ID);
        $engagedId = $engaged->getId();
        $f1 = $this->createFixture($clubId, $seasonId, $team->getId(), $engagedId)->getId();
        $f2 = $this->createFixture($clubId, $seasonId, $team->getId(), $engagedId)->getId();
        $target = $this->createCompetition($clubId, $seasonId, $team->getId(), 'PNM');

        $this->confirm($token, FfbbHttpClientStub::COMPETITION_ID, $team->getId(), $target->getId());
        self::assertResponseStatusCodeSame(200);

        $this->scopeGucToClub($clubId);
        $this->em->clear();

        $reloadedEngaged = $this->em->getRepository(Competition::class)->find($engagedId);
        self::assertInstanceOf(Competition::class, $reloadedEngaged, 'a competition carrying matches is NEVER deleted at confirm');
        self::assertNull($reloadedEngaged->getFfbbCompetitionId(), 'it loses the refs (they moved to the target)');

        $reloadedTarget = $this->em->getRepository(Competition::class)->find($target->getId());
        self::assertInstanceOf(Competition::class, $reloadedTarget);
        self::assertSame(FfbbHttpClientStub::COMPETITION_ID, $reloadedTarget->getFfbbCompetitionId());

        foreach ([$f1, $f2] as $fixtureId) {
            $reloaded = $this->em->getRepository(Fixture::class)->find($fixtureId);
            self::assertInstanceOf(Fixture::class, $reloaded, 'an engaged match is never destroyed by the resorption');
            self::assertSame($team->getId(), $reloaded->getTeamId(), 'a fixture never changes team at confirm');
            self::assertSame($engagedId, $reloaded->getCompetitionId(), 'the match stays on its original competition');
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function confirm(string $token, string $ffbbCompetitionId, string $teamId, string $competitionId): void
    {
        $this->client->request('POST', '/api/ffbb/engagements/confirm', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['pairings' => [[
            'ffbbCompetitionId' => $ffbbCompetitionId,
            'teamId' => $teamId,
            'competitionId' => $competitionId,
        ]]], \JSON_THROW_ON_ERROR));
    }

    private function seasonOf(string $clubId): Season
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season);

        return $season;
    }

    private function createCompetition(string $clubId, string $seasonId, string $teamId, string $name, ?string $ffbbCompetitionId = null): Competition
    {
        $this->scopeGucToClub($clubId);
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        if (null !== $ffbbCompetitionId) {
            $competition->setFfbbCompetitionId($ffbbCompetitionId);
        }
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    private function createFixture(string $clubId, string $seasonId, string $teamId, string $competitionId): Fixture
    {
        $this->scopeGucToClub($clubId);
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competitionId);
        $fixture->setMatchDate(new DateTimeImmutable('+7 days'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('AS TEST NORD');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function useStubClubCode(string $clubId): void
    {
        $club = $this->em->getRepository(Club::class)->find($clubId);
        \assert($club instanceof Club);
        $club->setFfbbClubCode(FfbbHttpClientStub::CLUB_CODE);
        $this->em->flush();
    }

    private function createSeason(string $clubId, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->settleSeasonPlan($season);

        return $season;
    }

    private function createTeam(string $clubId): Team
    {
        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId])
            ?? $this->createSeason($clubId, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        if (null === $this->chosenPlanVersion($season)) {
            $this->settleSeasonPlan($season);
        }

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('Seniors-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SM-Test');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** @return array{0: string, 1: string, 2: string} [token, userId, clubId] */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Perimeter', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
