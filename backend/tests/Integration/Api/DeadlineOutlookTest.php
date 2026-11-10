<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/matches/deadline-outlook — l'accueil : un compte GLOBAL `toConfirmCount` (domiciles
 * validables d'un championnat commencé), une SOUSTRACTION des validables du `toPlaceCount` par
 * fenêtre (un validable est UNPLACED, il n'est jamais « à placer »), et `swept`, le nombre de
 * rencontres que CETTE lecture a fait passer « Déjà dans FBI ». Le balayage (amicaux passés PUIS
 * championnats commencés) n'est déclenché QUE si l'utilisateur courant est GESTIONNAIRE (la route
 * est ouverte au Membre). Les gardes saison archivée / socle et la version périmée sont prouvées
 * dans `LeagueReadAutoValidationTest` (angle bloquant) ; ici, les compteurs servis.
 */
#[Group('integration')]
final class DeadlineOutlookTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * La lecture de l'accueil par un MEMBRE n'écrit rien et compte le domicile validable « à
     * confirmer » (jamais « à placer ») ; celle d'un GESTIONNAIRE le fait passer « Déjà dans FBI »
     * et le dit (`swept`), sans jamais toucher le domicile sans heure, qui reste seul « à placer ».
     */
    public function testAMemberReadCountsTheValidatableHomeButOnlyAManagerReadSweepsIt(): void
    {
        [$managerToken, $clubId, $seasonId] = $this->createClub();
        $memberToken = $this->addMember($clubId, 'member');
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);

        // Un championnat échu (échéance 2020-09-10) avec DEUX domiciles UNPLACED : l'un
        // validable (heure + gymnase), l'autre non (rien) — donc « à placer ».
        $competition = $this->createMaturedCompetition($clubId, $seasonId, $team->getId());
        $validatableId = $this->homeFixture($clubId, $seasonId, $team->getId(), $competition->getId(), '2099-03-14', $venue->getId(), '15:30');
        $this->homeFixture($clubId, $seasonId, $team->getId(), $competition->getId(), '2099-03-15', null, null);

        // (1) Le Membre lit : 1 à confirmer, 0 balayé, 1 à placer (2 UNPLACED − 1 validable).
        $this->client->request('GET', '/api/matches/deadline-outlook', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken]);
        self::assertResponseStatusCodeSame(200);
        $member = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $member['toConfirmCount'], 'un domicile validable, compté « à confirmer » pour le Membre');
        self::assertSame(0, $member['swept'] ?? -1, 'la lecture d\'un Membre ne balaie rien');
        self::assertCount(1, $member['windows']);
        self::assertSame(1, $member['windows'][0]['toPlaceCount']);
        self::assertSame(0, $member['windows'][0]['toEnterCount']);
        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $afterMember = $this->em->find(Fixture::class, $validatableId);
        self::assertInstanceOf(Fixture::class, $afterMember);
        self::assertSame(FixtureStatus::UNPLACED, $afterMember->getStatus(), 'un Membre ne fait jamais écrire la base');

        // (2) Le gestionnaire lit : le domicile passe « Déjà dans FBI », plus rien à confirmer,
        // le sans-heure reste seul « à placer ».
        $this->client->request('GET', '/api/matches/deadline-outlook', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $managerToken]);
        self::assertResponseStatusCodeSame(200);
        $manager = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $manager['swept'] ?? -1, 'la lecture du gestionnaire a fait passer 1 domicile « Déjà dans FBI »');
        self::assertSame(0, $manager['toConfirmCount'], 'plus rien à confirmer après le balayage');
        self::assertCount(1, $manager['windows']);
        self::assertSame(1, $manager['windows'][0]['toPlaceCount'], 'le domicile sans heure reste « à placer »');
        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $afterManager = $this->em->find(Fixture::class, $validatableId);
        self::assertInstanceOf(Fixture::class, $afterManager);
        self::assertSame(FixtureStatus::VALIDATED, $afterManager->getStatus());
        self::assertSame(FixturePlacementSource::MANUAL, $afterManager->getPlacementSource());
    }

    public function testAManagerReadSweepsPastFriendliesButAMemberDoesNot(): void
    {
        [$managerToken, $clubId, $seasonId] = $this->createClub();
        $memberToken = $this->addMember($clubId, 'member');
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);

        // (1) Un Membre lit : aucune écriture, l'amical passé reste UNPLACED.
        $memberFixtureId = $this->friendlyHome($clubId, $seasonId, $team->getId(), '2020-09-19');
        $this->client->request('GET', '/api/matches/deadline-outlook', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken]);
        self::assertResponseStatusCodeSame(200);
        $this->em->clear();
        $afterMember = $this->em->find(Fixture::class, $memberFixtureId);
        self::assertInstanceOf(Fixture::class, $afterMember);
        self::assertSame(FixtureStatus::UNPLACED, $afterMember->getStatus(), 'un Membre ne fait jamais écrire la base');

        // (2) Un gestionnaire lit : l'amical passé bascule VALIDATED, et la réponse le compte.
        $this->scopeGucToClub($clubId);
        $this->client->request('GET', '/api/matches/deadline-outlook', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $managerToken]);
        self::assertResponseStatusCodeSame(200);
        $manager = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $manager['swept'] ?? -1, '`swept` compte aussi les amicaux passés');
        $this->em->clear();
        $afterManager = $this->em->find(Fixture::class, $memberFixtureId);
        self::assertInstanceOf(Fixture::class, $afterManager);
        self::assertSame(FixtureStatus::VALIDATED, $afterManager->getStatus(), 'un gestionnaire déclenche le balayage des amicaux passés');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createMaturedCompetition(string $clubId, string $seasonId, string $teamId): Competition
    {
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('D2-' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $competition->setEntryDeadline(new DateTimeImmutable('2020-09-10'));
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    private function homeFixture(string $clubId, string $seasonId, string $teamId, string $competitionId, string $date, ?string $venueId, ?string $kickoff): string
    {
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competitionId);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        if (null !== $venueId) {
            $fixture->setVenueId($venueId);
        }
        if (null !== $kickoff) {
            $fixture->setKickoffTime(new DateTimeImmutable($kickoff));
        }
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    /** Un amical HOME (sans compétition) à une date donnée — cible du balayage. */
    private function friendlyHome(string $clubId, string $seasonId, string $teamId, string $date): string
    {
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId(null);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Amical Adv');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    /**
     * @return array{0: string, 1: string, 2: string} [managerToken, clubId, seasonId]
     */
    private function createClub(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('BC Outlook ' . $uid);
        $club->setSlug('bc-outlook-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('outlook' . $uid . '@test.com');
        $user->setFirstName('Outlook');
        $user->setLastName('User');
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

        $season = new Season;
        $season->setClubId($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        // Le balayage d'une lecture gestionnaire exige le socle en vigueur (comme le geste
        // « Déjà dans FBI » qu'elle remplace) : sans lui, rien ne s'écrit.
        $this->settleSeasonPlan($season);

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $club->getId(), $season->getId()];
    }

    private function addMember(string $clubId, string $role): string
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('M');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function createTeam(string $clubId, string $seasonId): Team
    {
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
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SF3');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createVenue(string $clubId, string $seasonId): Venue
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('Mateo');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }
}
