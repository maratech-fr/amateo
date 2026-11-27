<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\CompetitionType;
use App\Enum\FbiCorrectionField;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Plans\PlanComposition;
use App\Service\FbiCorrectionLedger;
use App\Service\FixtureVenueLossMarker;
use App\Service\TeamEngagementGuard;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesCompetitionsTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
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
    use ChoosesPlanVersionTrait;
    use CreatesCompetitionsTrait;
    use CreatesPeopleAndPlacesTrait;
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

    /** @return array<string, array{0: callable(Fixture): void}> */
    public static function knownToTheFederation(): array
    {
        $now = new DateTimeImmutable;

        return [
            'importé (numéro FBI)' => [static function (Fixture $fixture): void { $fixture->setExternalRef('FBI-4A-1'); }],
            'venu du canal API (rencontre fédérale)' => [static function (Fixture $fixture): void { $fixture->setFfbbRencontreId('renc-4a-1'); }],
            'créé à la main puis saisi' => [static function (Fixture $fixture) use ($now): void { $fixture->setStatus(FixtureStatus::SUBMITTED, $now); }],
            'créé à la main puis validé' => [static function (Fixture $fixture) use ($now): void { $fixture->setStatus(FixtureStatus::VALIDATED, $now); }],
        ];
    }

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
        ]);

        self::assertResponseStatusCodeSame(409, 'un match importé, même non placé, engage l\'équipe');
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNotNull($this->em->getRepository(Fixture::class)->find($fixture->getId()), 'et l\'import FBI ne part pas avec elle');
    }

    /**
     * CE TEST A CHANGÉ DE SENS (lot 7, PR 4a, décision du fondateur du 2026-11-23, Q3) : seuls les matchs d'une
     * compétition NON amicale engagent. Avant, tout match engageait, et un amical du canal API FFBB (numéro de
     * rencontre fédéral, non placé) rendait l'équipe indélébile. Désormais l'amical de la fédération, rangé dans sa
     * compétition amicale (« AMICAL PNM »), n'engage plus : l'équipe se supprime et l'amical part avec elle.
     */
    public function testAFriendlyFromTheFfbbApiChannelNoLongerEngagesTheTeam(): void
    {
        $client = $this->client;
        $team = $this->createTeam('U15 amical FFBB');
        $fixture = $this->fixture($team, FixtureStatus::UNPLACED, nature: CompetitionType::FRIENDLY);
        $this->scopeGucToClub($this->club->getId());
        $fixture->setFfbbRencontreId('renc-api-1');
        $this->em->flush();
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
        ]);

        self::assertResponseStatusCodeSame(204, 'un amical de la fédération n\'engage plus l\'équipe');
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNull($this->em->getRepository(Fixture::class)->find($fixture->getId()), 'l\'amical part avec elle');
    }

    /**
     * Lot 7, PR 4a (Q3) : une équipe qui n'a qu'un amical posé à la main, rangé dans « Amicaux », change de niveau
     * (200) puis se supprime (204) ; son amical et l'entrée « à corriger dans FBI » ouverte sur lui partent avec
     * elle, et « Amicaux », vidée, reste la compétition d'office de la saison.
     */
    public function testATeamWithOnlyAFriendlyInAmicauxChangesLevelAndIsDeletedAndAmicauxStays(): void
    {
        $this->settleMatches();
        $team = $this->createTeam('U13 qui ne fait que des amicaux');
        $team->setLevel(TeamLevel::REGIONAL);
        $this->em->flush();
        $fixtureId = $this->postFixture(['teamId' => $team->getId(), 'matchDate' => '2026-03-14', 'homeAway' => 'AWAY', 'opponentLabel' => 'Voisins']);
        $amicauxId = $this->amicauxId();
        self::assertSame($amicauxId, $this->em->getConnection()->fetchOne('SELECT competition_id FROM fixture WHERE id = ?', [$fixtureId]), 'témoin : l\'amical est rangé dans « Amicaux »');
        // Le noyau a redémarré avec la requête : l'entrée s'écrit par le gestionnaire d'entités du conteneur courant.
        $this->scopeGucToClub($this->club->getId());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fixture = $em->find(Fixture::class, $fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::getContainer()->get(FbiCorrectionLedger::class)->open($fixture, FbiCorrectionField::KICKOFF, '15:00', '14:00', new DateTimeImmutable);
        $em->flush();
        self::assertSame(1, $this->openCorrectionsOfClub(), 'témoin : une entrée « à corriger dans FBI » est ouverte sur l\'amical');

        $this->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [
            'name' => 'U13 qui ne fait que des amicaux',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'level' => 'DEPARTEMENTAL',
        ]);
        self::assertResponseIsSuccessful('une équipe qui n\'a que des amicaux change de niveau');
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['isEngaged'], 'elle n\'est pas servie engagée');

        $this->request('DELETE', \sprintf('/api/teams/%s', $team->getId()));
        self::assertResponseStatusCodeSame(204, 'une équipe qui n\'a que des amicaux se supprime');

        $connection = $this->em->getConnection();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM fixture WHERE id = ?', [$fixtureId]), 'son amical part avec elle');
        self::assertSame(0, $this->openCorrectionsOfClub(), 'et l\'entrée « à corriger dans FBI » de l\'amical aussi : le compte « à corriger » retombe');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM fbi_correction WHERE fixture_id = ?', [$fixtureId]), 'aucune entrée, ouverte ou close, ne pend sur un match supprimé');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM competition WHERE id = ? AND default_friendly', [$amicauxId]), '« Amicaux » reste');
    }

    /**
     * Lot 7, PR 4a (Q3) : championnat, coupe et brassage engagent toujours, même un match importé non placé — le
     * cas qui fait rougir une condition inversée (« seul l'amical engage »).
     */
    #[TestWith([CompetitionType::CHAMPIONSHIP])]
    #[TestWith([CompetitionType::CUP])]
    #[TestWith([CompetitionType::BRASSAGE])]
    public function testEveryNonFriendlyNatureEngagesEvenUnplaced(CompetitionType $nature): void
    {
        $client = $this->client;
        $team = $this->createTeam('U17 en ' . $nature->value);
        $fixture = $this->fixture($team, FixtureStatus::UNPLACED, nature: $nature);
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()));

        self::assertResponseStatusCodeSame(409, \sprintf('un match non placé d\'une compétition %s engage l\'équipe', $nature->value));
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNotNull($this->em->getRepository(Fixture::class)->find($fixture->getId()), 'et il ne part pas avec elle');
    }

    /**
     * Lot 7, PR 4a : le doute engage. Un match dont la compétition est introuvable protège son équipe — le cas qui
     * fait rougir une jointure interne vers `competition`. La base l'interdit (clé du match vers l'engagement, puis
     * vers la compétition) : le décor est forgé sur la connexion propriétaire, clés suspendues par
     * `session_replication_role` dans une transaction annulée à la fin, et la garde y lit par un gestionnaire
     * d'entités ouvert sur cette connexion (patron de `PlanEntitlementsTest`). Témoin, par le même montage : l'équipe
     * qui n'a qu'un match d'une compétition amicale existante n'est pas engagée (sans lui, « tout est engagé »
     * passerait pour « le doute engage »).
     */
    public function testAFixtureWhoseCompetitionIsGoneStillEngages(): void
    {
        $admin = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($admin instanceof Connection);
        $admin->beginTransaction();
        try {
            $admin->executeStatement('SET LOCAL lock_timeout = \'5s\'');
            $admin->executeStatement('SET LOCAL session_replication_role = replica');
            $admin->executeStatement('SELECT set_config(\'app.club_id\', ?, true)', [$this->club->getId()]);
            $adminEm = new EntityManager($admin, $this->em->getConfiguration());

            $friendly = new Competition()->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('AMICAL PNM')->setCompetitionType(CompetitionType::FRIENDLY);
            $adminEm->persist($friendly);
            $adminEm->flush();
            $orphanTeam = (string) $admin->fetchOne('SELECT gen_random_uuid()::text');
            $friendlyTeam = (string) $admin->fetchOne('SELECT gen_random_uuid()::text');
            $goneCompetition = (string) $admin->fetchOne('SELECT gen_random_uuid()::text');
            foreach ([$orphanTeam => $goneCompetition, $friendlyTeam => $friendly->getId()] as $teamId => $competitionId) {
                $fixture = new Fixture;
                $fixture->setClubId($this->club->getId());
                $fixture->setSeasonId($this->season->getId());
                $fixture->setTeamId($teamId);
                $fixture->setCompetitionId($competitionId);
                $fixture->setMatchDate(new DateTimeImmutable('2026-03-14'));
                $fixture->setHomeAway(FixtureHomeAway::AWAY);
                $fixture->setOpponentLabel('AS Voisins');
                $fixture->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
                $adminEm->persist($fixture);
            }
            $adminEm->flush();
            self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM competition WHERE id = ?', [$goneCompetition]), 'témoin : la compétition du match est introuvable');
            self::assertSame(2, (int) $admin->fetchOne('SELECT COUNT(*) FROM fixture WHERE team_id IN (?, ?)', [$orphanTeam, $friendlyTeam]), 'témoin : les deux matchs sont lus');

            $guard = new TeamEngagementGuard($adminEm);

            self::assertTrue($guard->isEngaged($orphanTeam), 'un match dont la compétition est introuvable engage son équipe');
            self::assertSame([$orphanTeam => true], $guard->engagedTeamIds([$orphanTeam, $friendlyTeam]), 'la lecture servie dit la même chose que la garde');
            self::assertFalse($guard->isEngaged($friendlyTeam), 'témoin : un match d\'une compétition amicale existante n\'engage pas');
        } finally {
            $admin->rollBack();
        }
    }

    /**
     * Abus du périmètre (lot 7, PR 4a, Q9) : un match connu de la fédération — numéro FBI, identifiant de rencontre
     * fédéral — ou déjà saisi ou validé ne change jamais de compétition vers une compétition amicale, ni vers
     * « Amicaux » (`""`) ni vers une amicale créée par `POST /api/competitions` : 422 au texte du plan, le match
     * reste dans son championnat et l'équipe reste engagée.
     *
     * @param callable(Fixture): void $known
     */
    #[DataProvider('knownToTheFederation')]
    public function testAMatchKnownToTheFederationNeverMovesToAFriendlyCompetition(callable $known): void
    {
        $this->settleMatches();
        $team = $this->createTeam('U15 en championnat FBI');
        // Un extérieur : ni gymnase ni accès match n'entrent dans le geste, seule la compétition change.
        $fixture = $this->fixture($team, FixtureStatus::UNPLACED, FixtureHomeAway::AWAY);
        $championshipId = (string) $fixture->getCompetitionId();
        $this->scopeGucToClub($this->club->getId());
        $known($fixture);
        $this->em->flush();
        $friendlyId = $this->postCompetition(['name' => 'AMICAL PNM', 'competitionType' => 'FRIENDLY', 'teamId' => $team->getId()]);

        foreach (['« Amicaux »' => '', 'une compétition amicale créée par l\'API' => $friendlyId] as $target => $competitionId) {
            $this->request('PUT', \sprintf('/api/fixtures/%s', $fixture->getId()), $this->fixtureBody($fixture, $competitionId));

            self::assertResponseStatusCodeSame(422, 'un match connu de la fédération ne passe pas dans ' . $target);
            self::assertStringContainsString('Ce match est connu de la fédération : il reste dans sa compétition.', (string) $this->client->getResponse()->getContent());
            self::assertSame($championshipId, $this->em->getConnection()->fetchOne('SELECT competition_id FROM fixture WHERE id = ?', [$fixture->getId()]), 'le match reste dans son championnat');
        }

        $this->request('DELETE', \sprintf('/api/teams/%s', $team->getId()));
        self::assertResponseStatusCodeSame(409, 'l\'équipe reste engagée');
    }

    /**
     * Le test qui attrape une garde trop large (lot 7, PR 4a, Q9 et C54) : placer à la main un amical de la
     * fédération en renvoyant la compétition qu'il a déjà rend 200 — la garde compare la compétition d'avant et
     * celle demandée, jamais la seule valeur reçue.
     */
    public function testPlacingAFederationFriendlyByHandWithItsOwnCompetitionPasses(): void
    {
        $this->settleMatches();
        $team = $this->createTeam('U15 amical fédéral');
        $venue = $this->homeVenue();
        $fixture = $this->fixture($team, FixtureStatus::UNPLACED, nature: CompetitionType::FRIENDLY);
        $this->scopeGucToClub($this->club->getId());
        $fixture->setExternalRef('FBI-4A-AMICAL');
        $this->em->flush();

        $this->request('PUT', \sprintf('/api/fixtures/%s', $fixture->getId()), [
            'venueId' => $venue,
            'kickoffTime' => '20:00',
            'status' => 'PLACED',
        ] + $this->fixtureBody($fixture, (string) $fixture->getCompetitionId()));

        self::assertResponseIsSuccessful('placer à la main un amical de la fédération, sa compétition renvoyée, passe');
        self::assertSame('PLACED', $this->em->getConnection()->fetchOne('SELECT status FROM fixture WHERE id = ?', [$fixture->getId()]));
    }

    public function testATeamWithoutAnyFixtureIsDeletable(): void
    {
        // La garde ne bloque QUE ce qui joue : une équipe sans aucune rencontre reste
        // une ligne de travail ordinaire (un club qui range ses équipes avant la saison).
        $client = $this->client;
        $team = $this->createTeam('U13 sans match');
        $client->loginUser($this->user);

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
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
        $this->em->flush();
        $this->fixture($team, FixtureStatus::PLACED);
        $other = $this->otherTier();
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
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
        // wizard n'envoie ni gymnase forcé, ni jour de match, ni plancher de séances : un
        // PUT qui les écrirait quand même les mettrait à NULL, et ces trois champs
        // nourrissent DIRECTEMENT le solveur. Renommer une équipe
        // déplacerait alors ses séances dans n'importe quel gymnase, n'importe quel jour.
        self::assertSame('44444444-4444-4444-8444-444444444444', $fresh?->getForcedVenueId(), 'le gymnase forcé survit à un renommage');
        self::assertSame(5, $fresh?->getMatchDay(), 'le jour de match survit');
        self::assertSame(3, $fresh?->getMinSessionsOverride(), 'le plancher de séances survit');
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

        $client->request('GET', '/api/teams', [], [], []);
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
        ]);
        self::assertResponseStatusCodeSame(204, 'supprimer un match d\'une équipe engagée passe — ce n\'est pas le DELETE d\'équipe');

        // Stateless firewall: loginUser only arms ONE request — the second rides a real JWT.
        $token = self::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user);
        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseStatusCodeSame(204, 'sans plus aucun match, l\'équipe redevient supprimable');
    }

    public function testAFixtureVenueLostByValidationStillEngagesItsTeam(): void
    {
        // P2-52 (RMM-10) — la gâchette de VALIDATION dépointe un match dont le gymnase a disparu :
        // il repasse « à placer » MAIS EXISTE toujours (venue_lost). L'engagement étant dérivé de
        // l'EXISTENCE d'un match, l'équipe reste engagée — DELETE → 409. Le dépointage ne doit
        // JAMAIS être une porte dérobée pour supprimer une équipe engagée.
        $client = $this->client;
        $team = $this->createTeam('U15 dont le gymnase ferme');
        $fixture = $this->fixture($team, FixtureStatus::SUBMITTED);

        $this->scopeGucToClub($this->club->getId());
        self::getContainer()->get(FixtureVenueLossMarker::class)->mark([$fixture->getId()]);
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $marked = $this->em->getRepository(Fixture::class)->find($fixture->getId());
        self::assertNotNull($marked, 'le match dépointé existe toujours');
        self::assertSame(FixtureStatus::UNPLACED, $marked->getStatus());
        self::assertSame(FixtureUnplacedReason::VENUE_LOST, $marked->getUnplacedReason());

        $client->loginUser($this->user);
        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
        ]);
        self::assertResponseStatusCodeSame(409, 'un match dépointé engage toujours son équipe');
        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertNotNull($this->em->getRepository(Fixture::class)->find($fixture->getId()), 'et il ne part pas avec elle');
    }

    public function testDeletingAVenueCarryingAnEngagedTeamsMatchIsRefused(): void
    {
        // PR4 « fermetures à la source » (décision fondateur 2026-10-22) — l'ANCIENNE porte
        // dérobée : supprimer le GYMNASE d'un match engagé le dépointait (venue_lost) sans toucher
        // à l'équipe. Elle est fermée : supprimer un gymnase porteur de matchs est REFUSÉ (422), le
        // match GARDE sa salle, et l'équipe reste donc engagée — DELETE équipe → 409. Angle
        // « périmètre engagé » (§7.1, domaine sensible) de la promesse prouvée côté API par
        // CascadeDeleteApiTest::testDeletingAVenueCarryingAMatchIsRefusedAndWritesNothing.
        $client = $this->client;
        $team = $this->createTeam('U18 dont la salle est convoitée');
        $this->scopeGucToClub($this->club->getId());
        $venue = $this->newVenue($this->club->getId(), $this->season->getId(), 'Gymnase convoité');
        $this->em->persist($venue);
        $this->em->flush();
        $fixture = $this->fixture($team, FixtureStatus::SUBMITTED);
        $this->scopeGucToClub($this->club->getId());
        $fixture->setVenueId($venue->getId());
        $this->em->flush();

        $token = self::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user);
        $client->request('DELETE', \sprintf('/api/venues/%s', $venue->getId()), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseStatusCodeSame(422, 'supprimer un gymnase porteur de matchs est refusé');

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        $survivor = $this->em->getRepository(Fixture::class)->find($fixture->getId());
        self::assertNotNull($survivor, 'le match ne bouge pas');
        self::assertSame($venue->getId(), $survivor->getVenueId(), 'il garde sa salle : zéro écriture');

        $client->request('DELETE', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseStatusCodeSame(409, 'l\'équipe dont le match a gardé sa salle reste engagée');
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
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());
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
        SeasonPlanOf::id(self::getContainer(), $season->getId());

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

    /**
     * Un match de l'équipe, rangé dans une compétition neuve de la nature donnée (championnat par défaut : depuis la
     * PR 4a du lot 7, seul un match d'une compétition non amicale engage), l'équipe engagée par la seule porte.
     */
    private function fixture(Team $team, FixtureStatus $status, FixtureHomeAway $homeAway = FixtureHomeAway::HOME, CompetitionType $nature = CompetitionType::CHAMPIONSHIP): Fixture
    {
        $competition = $this->competitionFor($team->getId(), \sprintf('%s %s', $nature->value, uniqid('', true)), $nature);
        $this->scopeGucToClub($this->club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-03-14'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('AS Voisins');
        $fixture->setStatus($status, new DateTimeImmutable);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    /** Le module matchs exige le planning de saison en vigueur (`SocleGuard`) : le décor le pointe. */
    private function settleMatches(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->settleSeasonPlan($this->season);
    }

    /**
     * Une requête du gestionnaire, saison désignée (la saison du décor n'est pas celle du calendrier).
     *
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, ?array $body = null): void
    {
        $token = self::getContainer()->get('lexik_jwt_authentication.jwt_manager')->create($this->user);
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_X_SEASON_ID' => $this->season->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $body */
    private function postFixture(array $body): string
    {
        $this->request('POST', '/api/fixtures', $body);
        self::assertResponseStatusCodeSame(201, 'témoin : le match est créé');

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
    }

    /** @param array<string, mixed> $body */
    private function postCompetition(array $body): string
    {
        $this->request('POST', '/api/competitions', $body);
        self::assertResponseStatusCodeSame(201, 'témoin : la compétition est créée');

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
    }

    /**
     * Le corps complet que l'écran renvoie à chaque geste (C54), seule la compétition désignée.
     *
     * @return array<string, mixed>
     */
    private function fixtureBody(Fixture $fixture, string $competitionId): array
    {
        return [
            'teamId' => $fixture->getTeamId(),
            'competitionId' => $competitionId,
            'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
            'homeAway' => $fixture->getHomeAway()->value,
            'opponentLabel' => $fixture->getOpponentLabel(),
            'status' => $fixture->getStatus()->value,
        ];
    }

    /** La compétition d'office « Amicaux » de la saison du décor. */
    private function amicauxId(): string
    {
        $this->scopeGucToClub($this->club->getId());
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM competition WHERE season_id = ? AND default_friendly', [$this->season->getId()]);
        self::assertIsString($id, 'la saison a sa compétition d\'office « Amicaux »');

        return $id;
    }

    /** Ce que compte « à corriger dans FBI » (`EntryDeadlineOutlook` : les entrées ouvertes du club). */
    private function openCorrectionsOfClub(): int
    {
        $this->scopeGucToClub($this->club->getId());

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM fbi_correction WHERE club_id = ? AND closed_at IS NULL', [$this->club->getId()]);
    }

    /** Un gymnase du club, sans fenêtre d'accès déclarée. */
    private function homeVenue(): string
    {
        $this->scopeGucToClub($this->club->getId());
        $venue = $this->newVenue($this->club->getId(), $this->season->getId(), 'Gymnase des amicaux ' . uniqid('', true));
        $this->em->flush();

        return $venue->getId();
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
        $team->setIsActive(true);

        $this->em->persist($team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);
        $this->em->flush();

        return $team;
    }
}
