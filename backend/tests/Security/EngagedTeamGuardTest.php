<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR BLOQUANT — le PÉRIMÈTRE ENGAGÉ (axe structurant §7.1).
 *
 * Valider le planning de la saison valide aussi un périmètre : les équipes qui font de
 * la compétition. Une fois leurs matchs déposés à la fédération, on n'y revient plus —
 * « une équipe qui joue ne peut pas être supprimée, ni avoir son niveau modifié ; elle
 * peut être déplacée ou changer de créneau ».
 *
 * Bloquant parce qu'une régression détruit des données RÉELLES et irrattrapables :
 * `EntityCascadeDeleter::purgeChildrenOfTeam` emporte les `Fixture` de l'équipe, donc
 * une suppression qui passe efface des matchs que la fédération connaît déjà.
 */
#[Group('phase1')]
#[Group('integration')]
final class EngagedTeamGuardTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private User $user;

    private Season $season;

    private Sport $sport;

    private SportCategory $sportCategory;

    private PriorityTier $priorityTier;

    private KernelBrowser $client;

    /**
     * ADR — périmètre engagé : une équipe qui joue est inscrite auprès de la
     * fédération. On ne la supprime plus (ses matchs partiraient avec elle via
     * purgeChildrenOfTeam) et son niveau ne bouge plus (c'est sous ce niveau
     * qu'elle est inscrite). Le reste — nom, tier, créneaux — suit le club.
     */
    public function testAnEngagedTeamCannotBeDeletedAndKeepsItsFixtures(): void
    {
        $client = $this->client;
        $team = $this->createTeam('U15 en championnat');
        $fixture = $this->fixture($team, FixtureStatus::PLACED);
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(409, 'une équipe qui joue ne se supprime pas');
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNotNull($this->em->getRepository(Team::class)->find($team->getId()));
        // Le vrai risque : la cascade détruit les Fixture. Le refus doit tomber AVANT.
        self::assertNotNull($this->em->getRepository(Fixture::class)->find($fixture->getId()), 'ses matchs engagés survivent');
    }

    public function testAnUnplacedFixtureEngagesTheTeamToo(): void
    {
        // LE test qui compte. L'import FBI crée TOUT en UNPLACED (`FbiFixtureImporter` :
        // « Status is always UNPLACED ») : une garde qui exigerait PLACED serait donc
        // inerte au moment précis où elle doit mordre — juste après l'import, quand la
        // fédération connaît déjà les rencontres. Décision fondateur : la correspondance
        // import↔équipe EST l'engagement, le statut ne dit rien de lui.
        $client = $this->client;
        $team = $this->createTeam('U13 fraîchement importée');
        $fixture = $this->fixture($team, FixtureStatus::UNPLACED);
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(409, 'un match importé, même non placé, engage l\'équipe');
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNotNull($this->em->getRepository(Fixture::class)->find($fixture->getId()), 'et l\'import FBI ne part pas avec elle');
    }

    public function testAFixtureBornFromTheFfbbApiChannelEngagesTheTeam(): void
    {
        // RMM-4 PR-3 — the FFBB-API channel is a NEW writer of fixtures (the amicaux
        // it materialises). Engagement is derived from a fixture's mere EXISTENCE
        // (TeamEngagementGuard: `SELECT 1 FROM fixture WHERE team_id`), never from
        // how it was created: an amical stamped with an ffbbRencontreId, still
        // UNPLACED, engages its team exactly like an FBI import.
        $client = $this->client;
        $team = $this->createTeam('U15 amical FFBB');
        $fixture = $this->fixture($team, FixtureStatus::UNPLACED);
        $this->scopeGucToClub($this->club->getId());
        $fixture->setFfbbRencontreId('renc-api-1');
        $this->em->flush();
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(409, 'un amical créé via l\'API FFBB engage l\'équipe');
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNotNull($this->em->getRepository(Fixture::class)->find($fixture->getId()), 'l\'amical ne part pas avec elle');
    }

    public function testATeamWithoutAnyFixtureIsDeletable(): void
    {
        // La garde ne bloque QUE ce qui joue : une équipe sans aucune rencontre reste
        // une ligne de travail ordinaire (un club qui range ses équipes avant la saison).
        $client = $this->client;
        $team = $this->createTeam('U13 sans match');
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testAnAwayFixtureEngagesTheTeamToo(): void
    {
        // Un match à l'extérieur engage aussi : l'équipe joue, la fédération le sait.
        // Il naît UNPLACED comme les autres — le croire PLACED (ce que prétendait le
        // docblock de FixtureStatus) faisait passer ce test sur un état que l'import ne
        // produit JAMAIS, donc sur rien.
        $client = $this->client;
        $team = $this->createTeam('U18 qui joue dehors');
        $this->fixture($team, FixtureStatus::UNPLACED, FixtureHomeAway::AWAY);
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testAnEngagedTeamCannotChangeLevel(): void
    {
        $client = $this->client;
        $team = $this->createTeam('U15 régionale');
        $team->setLevel(TeamLevel::REGIONAL);
        $this->em->flush();
        $this->fixture($team, FixtureStatus::SUBMITTED);
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15 régionale',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'level' => 'DEPARTEMENTAL',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(TeamLevel::REGIONAL, $this->em->getRepository(Team::class)->find($team->getId())?->getLevel());
    }

    public function testAnEngagedTeamCanStillBeRenamedAndRetiered(): void
    {
        // Le PUT renvoie le payload COMPLET : le niveau y est ré-écho à l'identique.
        // Refuser l'écho casserait un simple renommage. Et le tier reste libre — c'est
        // la perception interne du club, pas l'inscription fédérale.
        $client = $this->client;
        $team = $this->createTeam('U15 à renommer');
        $team->setLevel(TeamLevel::REGIONAL);
        // Des champs que le wizard n'envoie JAMAIS, et qui nourrissent le solveur.
        $team->setForcedVenueId('44444444-4444-4444-8444-444444444444');
        $team->setMatchDay(5);
        $team->setMinSessionsOverride(3);
        $team->setParentTeamId('55555555-5555-4555-8555-555555555555');
        $this->em->flush();
        $this->fixture($team, FixtureStatus::PLACED);
        $other = $this->otherTier();
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15 Élite Filles',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $other->getId(),
            'level' => 'REGIONAL',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $fresh = $this->em->getRepository(Team::class)->find($team->getId());
        self::assertSame('U15 Élite Filles', $fresh?->getName());
        self::assertSame($other->getId(), $fresh?->getPriorityTierId());
        // « Le reste se modifie librement » — encore faut-il que le reste SURVIVE. Le
        // wizard n'envoie ni gymnase forcé, ni jour de match, ni plancher de séances, ni
        // filiation de saison : un PUT qui les écrirait quand même les mettrait à NULL,
        // et ces quatre champs partent DIRECTEMENT au solveur. Renommer une équipe
        // déplacerait alors ses séances dans n'importe quel gymnase, n'importe quel jour.
        self::assertSame('44444444-4444-4444-8444-444444444444', $fresh?->getForcedVenueId(), 'le gymnase forcé survit à un renommage');
        self::assertSame(5, $fresh?->getMatchDay(), 'le jour de match survit');
        self::assertSame(3, $fresh?->getMinSessionsOverride(), 'le plancher de séances survit');
        self::assertSame('55555555-5555-4555-8555-555555555555', $fresh?->getParentTeamId(), 'la filiation de saison (posée par la transition N→N+1) survit');
    }

    public function testTheApiTellsTheClientWhichTeamsAreEngaged(): void
    {
        // Le front grise « Supprimer » et le niveau à partir de CE champ : sans lui, il
        // re-dériverait la règle de son côté et finirait par répondre autre chose que
        // le serveur — donc à offrir un geste toujours refusé.
        $client = $this->client;
        $engaged = $this->createTeam('Engagée');
        $this->fixture($engaged, FixtureStatus::PLACED);
        $free = $this->createTeam('Libre');
        $client->loginUser($this->user);

        $client->request('GET', '/api/teams', [], [], ['HTTP_X-Club-Id' => $this->club->getId()]);
        self::assertResponseIsSuccessful();
        $byId = [];
        foreach (json_decode((string) $client->getResponse()->getContent(), true)['member'] as $row) {
            $byId[$row['id']] = $row['isEngaged'];
        }

        self::assertTrue($byId[$engaged->getId()]);
        self::assertFalse($byId[$free->getId()]);
    }

    public function testAnEngagedTeamCannotHaveItsLevelRecordedAfterTheFact(): void
    {
        // Le niveau est figé, SANS exception — y compris quand il n'a jamais été
        // renseigné. Il se saisit AVANT de générer : il alimente le tag NIVEAU, donc les
        // contraintes, donc la photo de structure de la version. Le laisser bouger après
        // ferait diverger la photo (qui l'a figé) et la base — puis « Charger cette
        // version » ramènerait l'ancienne valeur en silence.
        $client = $this->client;
        $team = $this->createTeam('U15 sans niveau');
        self::assertNull($team->getLevel());
        $this->fixture($team, FixtureStatus::PLACED);
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15 sans niveau',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'level' => 'REGIONAL',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
    }

    /**
     * DOC-3 — CE TEST A CHANGÉ DE SENS. Avant : un PUT sans `level` sur une équipe
     * engagée = tentative d'EFFACEMENT → 409. Depuis « absent = on garde », omettre
     * `level` n'est plus un changement : le PUT passe et le niveau reste — un client
     * minimal peut renommer une équipe engagée sans se faire refuser pour un
     * effacement qu'il ne demandait pas. Le 409 vit toujours : sur un changement
     * EXPLICITE (témoin ci-dessous), gardé par testAnEngagedTeamCannotChangeLevel.
     */
    public function testAnEngagedTeamKeepsItsLevelWhenAbsentFromThePut(): void
    {
        $client = $this->client;
        $team = $this->createTeam('U15 inscrite');
        $team->setLevel(TeamLevel::REGIONAL);
        $this->em->flush();
        $this->fixture($team, FixtureStatus::PLACED);
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15 inscrite renommée',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $fresh = $this->em->getRepository(Team::class)->find($team->getId());
        self::assertSame(TeamLevel::REGIONAL, $fresh?->getLevel(), 'absent = on garde — le niveau de l\'équipe engagée n\'a pas bougé');
        self::assertSame('U15 inscrite renommée', $fresh?->getName(), 'et le renommage, lui, est passé');
    }

    public function testWritesAnswerIsEngagedLikeReadsDo(): void
    {
        // Le même champ ne peut pas répondre autrement selon le verbe : un client qui
        // fait confiance au corps du PUT ré-ouvrirait « Supprimer » sur une équipe que
        // le serveur refuse.
        $client = $this->client;
        $team = $this->createTeam('U15 engagée');
        $this->fixture($team, FixtureStatus::PLACED);
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U15 engagée renommée',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['isEngaged']);
    }

    public function testDeletingAFixtureIsNotDeletingTheTeamAndReleasesTheEngagement(): void
    {
        // NR P1-4 PR E : la boucle manuelle ouvre DELETE /api/fixtures/{id} dans
        // l'UI. Supprimer un MATCH n'est pas supprimer l'ÉQUIPE — la garde ne doit
        // pas mordre ; et l'engagement étant DÉRIVÉ (au moins un fixture), il se
        // relâche tout seul quand le dernier match part : aucune garde ne survit
        // à tort, aucune ne saute.
        $client = $this->client;
        $team = $this->createTeam('U15 dont le match s\'annule');
        $fixture = $this->fixture($team, FixtureStatus::PLACED);
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/fixtures/%s', $fixture->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);
        self::assertResponseStatusCodeSame(204, 'supprimer un match d\'une équipe engagée passe — ce n\'est pas le DELETE d\'équipe');

        // Stateless firewall: loginUser only arms ONE request — the second rides a real JWT.
        $token = self::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user);
        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseStatusCodeSame(204, 'sans plus aucun match, l\'équipe redevient supprimable');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Team Test Club');
        $this->club->setSlug('team-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('TMT' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->sport = new Sport;
        $this->sport->setName('Basketball');
        $this->sport->setSlug('bball-' . $uid);
        $this->sport->setIsActive(true);
        $this->em->persist($this->sport);

        $this->user = new User;
        $this->user->setEmail('team' . $uid . '@test.com');
        $this->user->setFirstName('Team');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($this->passwordHasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->sportCategory = new SportCategory;
        $this->sportCategory->setClubId($this->club->getId());
        $this->sportCategory->setSportId($this->sport->getId());
        $this->sportCategory->setName('U11');
        $this->sportCategory->setIsCustom(false);
        $this->sportCategory->setSortOrder(0);
        $this->em->persist($this->sportCategory);

        $existing = $this->em->getRepository(PriorityTier::class)->find(1);
        if ($existing instanceof PriorityTier) {
            $this->priorityTier = $existing;
        } else {
            $this->priorityTier = new PriorityTier;
            $this->priorityTier->setId(1);
            $this->priorityTier->setLabel('S');
            $this->priorityTier->setName('Senior');
            $this->priorityTier->setColor('#FF0000');
            $this->priorityTier->setOrToolsWeight(100);
            $this->priorityTier->setDefaultMinSessions(2);
            $this->em->persist($this->priorityTier);
        }

        $this->em->flush();
    }

    private function createClub(string $name = 'Test Club'): Club
    {
        $club = new Club;
        $club->setName($name . ' ' . uniqid());
        $club->setSlug('test-club-' . uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function createUser(): User
    {
        $user = new User;
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'Password123!'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createClubUser(Club $club, User $user): void
    {
        $this->scopeGucToClub($club->getId());
        $clubUser = new ClubUser;
        $clubUser->setClubId($club->getId());
        $clubUser->setUserId($user->getId());
        $clubUser->setRole('admin');
        $clubUser->setIsActive(true);

        $this->em->persist($clubUser);
        $this->em->flush();
    }

    private function createSeason(Club $club): Season
    {
        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);

        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function createSport(): Sport
    {
        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('basketball-' . uniqid());
        $sport->setIsActive(true);

        $this->em->persist($sport);
        $this->em->flush();

        return $sport;
    }

    private function createSportCategory(Club $club, Sport $sport): SportCategory
    {
        $this->scopeGucToClub($club->getId());
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function createPriorityTier(): PriorityTier
    {
        $existing = $this->em->getRepository(PriorityTier::class)->find(1);
        if ($existing instanceof PriorityTier) {
            return $existing;
        }

        $tier = new PriorityTier;
        $tier->setId(1);
        $tier->setLabel('S');
        $tier->setName('Senior');
        $tier->setColor('#FF0000');
        $tier->setOrToolsWeight(100);
        $tier->setDefaultMinSessions(2);

        $this->em->persist($tier);
        $this->em->flush();

        return $tier;
    }

    private function fixture(Team $team, FixtureStatus $status, FixtureHomeAway $homeAway = FixtureHomeAway::HOME): Fixture
    {
        $this->scopeGucToClub($this->club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('AS Voisins');
        $fixture->setStatus($status);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    /** Un SECOND rang, pour prouver qu'une équipe engagée peut encore en changer. */
    private function otherTier(): PriorityTier
    {
        $existing = $this->em->getRepository(PriorityTier::class)->find(2);
        if ($existing instanceof PriorityTier) {
            return $existing;
        }

        $tier = new PriorityTier;
        $tier->setId(2);
        $tier->setLabel('A');
        $tier->setName('Compétition');
        $tier->setColor('#00FF00');
        $tier->setOrToolsWeight(50);
        $tier->setDefaultMinSessions(2);
        $this->em->persist($tier);
        $this->em->flush();

        return $tier;
    }

    private function createTeam(string $name): Team
    {
        $this->scopeGucToClub($this->club->getId());
        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($this->sportCategory->getId());
        $team->setPriorityTierId($this->priorityTier->getId());
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);

        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
