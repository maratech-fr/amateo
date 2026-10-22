<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Clock\DevClockStore;
use App\Controller\LeagueValidatedFixturesController;
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
use App\Service\FbiFixtureImporter;
use App\Service\LeagueValidationOutlook;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Integration\Api\LeagueValidatedFixturesControllerTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR d'axe « constraint semantics » (§7.1) — LA promesse du chantier A PR 1 : AU DÉPÔT d'un
 * fichier FBI, tout championnat COMMENCÉ voit ses domiciles validables passer tout seuls
 * « validé ligue ». Le geste chiffré en lot ({@see LeagueValidatedFixturesController})
 * reste le RATTRAPAGE (l'échéance qui tombe APRÈS le dépôt) ; l'auto-validation ferme le cas
 * courant « un club démarre en cours de saison et importe des domiciles déjà joués ».
 *
 * La MAISON UNIQUE du prédicat et de l'écriture est partagée avec le geste manuel :
 * {@see LeagueValidationOutlook::fixturesToConfirm} (matured + heure + gymnase + sans
 * écart), puis source MANUAL + statut VALIDATED. Ce test prouve que le DÉPÔT emprunte EXACTEMENT
 * ce chemin — jamais une seconde copie du prédicat.
 *
 * ⚠ Doublon ASSUMÉ, point sensible (matchs / constraint semantics), angle NOMMÉ : le prédicat
 * « validable » (matured, heure, gymnase, pas d'écart) est déjà prouvé au MÊME endroit par le geste
 * MANUEL dans {@see LeagueValidatedFixturesControllerTest}
 * (testOutlookCountsOnlyMaturedValidatableHomeFixtures, testConfirmSwitchesEligibleToValidatedManualAndIsReplayable,
 * testAConfirmedFixtureBecomesAFixedAnchorForTheSolver). ICI l'angle est le CANAL DU DÉPÔT : que
 * l'import honore ce prédicat sans le re-dériver, et que son résultat soit BYTE-IDENTIQUE au POST
 * manuel (jumeau cité ci-dessus). Les cas « sans heure / sans gymnase / avec écart » rejouent le
 * prédicat par ce canal, non pour le re-prouver mais pour épingler que le DÉPÔT ne le court-circuite
 * pas.
 */
#[Group('integration')]
#[Group('phase1')]
final class ImportLeagueAutoValidationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /** Un samedi FUTUR : un championnat non commencé (aucune échéance, aucun match joué). */
    private const string FUTURE_DATE = '2099-03-14';

    /** Un samedi PASSÉ (fuseau club) : « premier match joué » → le championnat a démarré. */
    private const string PAST_DATE = '2020-09-19';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private FbiFixtureImporter $importer;

    /**
     * (a) Un domicile daté (heure + gymnase) d'un championnat COMMENCÉ passe VALIDATED + MANUAL
     * AU DÉPÔT, et son entrée dans la charge utile de placement (ancre FIXED) est BYTE-IDENTIQUE
     * à celle du même match validé par le POST manuel — l'id de la rencontre mis à part (seule chose
     * qui diffère entre deux domiciles identiques sur team/gymnase/date/heure). Jumeau du POST manuel :
     * {@see LeagueValidatedFixturesControllerTest::testAConfirmedFixtureBecomesAFixedAnchorForTheSolver}.
     */
    public function testDepositAutoValidatesAStartedChampionshipHomeIdenticallyToTheManualPost(): void
    {
        [$token, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase Auto', 'gymnase auto');

        // Témoin MANUEL : un domicile identique (même équipe, même gymnase, même date, même heure)
        // validé par le POST en lot — la référence byte-identique.
        $manual = $this->seedHomeFixture($clubId, $seasonId, $team->getId(), self::PAST_DATE, '15:30', $venue->getId(), '2020-09-10');
        $manualId = $manual->getId();
        $this->client->request('POST', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $manualAfter = $this->em->find(Fixture::class, $manualId);
        self::assertInstanceOf(Fixture::class, $manualAfter);
        self::assertSame(FixtureStatus::VALIDATED, $manualAfter->getStatus(), 'le POST manuel valide le témoin');
        self::assertSame(FixturePlacementSource::MANUAL, $manualAfter->getPlacementSource());

        // DÉPÔT : un domicile de championnat daté dans le passé (premier match joué), heure + salle
        // appariée à l'alias → au dépôt, il doit basculer VALIDATED + MANUAL, SANS aucun POST.
        $result = $this->deposit($clubId, $seasonId, [
            ['D CHAMP AUTO', 'AUTO-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::PAST_DATE), '15:30', 'GYMNASE AUTO'],
        ], $team->getId());
        self::assertSame(1, $result['leagueValidated'] ?? null, 'le dépôt valide tout seul le domicile du championnat commencé');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $auto = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'AUTO-1']);
        self::assertInstanceOf(Fixture::class, $auto);
        self::assertSame(FixtureStatus::VALIDATED, $auto->getStatus(), 'au dépôt, le domicile commencé est « validé ligue »');
        self::assertSame(FixturePlacementSource::MANUAL, $auto->getPlacementSource());
        self::assertSame($venue->getId(), $auto->getVenueId(), 'le domicile naît avec son gymnase (alias confirmé)');
        $autoId = $auto->getId();

        // Parité byte-identique dans la charge utile du solveur : les deux entrées sont des ancres
        // FIXED strictement égales (l'id de rencontre mis à part).
        $rows = $this->payloadMatchesById($clubId, $seasonId);
        self::assertArrayHasKey($manualId, $rows, 'le témoin manuel figure dans la charge utile');
        self::assertArrayHasKey($autoId, $rows, 'la rencontre auto-validée figure dans la charge utile');
        self::assertSame('FIXED', $rows[$autoId]['kind'] ?? null);
        $manualRow = $rows[$manualId];
        $autoRow = $rows[$autoId];
        unset($manualRow['id'], $autoRow['id']);
        self::assertSame($manualRow, $autoRow, 'l\'ancre du dépôt est byte-identique à celle du POST manuel');
    }

    /**
     * (b) Un championnat NON commencé (aucune échéance, aucun match joué — dates provisoires d'une
     * nouvelle vague) reste UNPLACED au dépôt et part en TO_PLACE au solveur : le verrouiller en
     * ancre fixe au moment où il faut pouvoir le bouger serait le piège que ce lot ferme.
     */
    public function testANotStartedChampionshipStaysUnplacedAndToPlace(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase Futur', 'gymnase futur');

        $result = $this->deposit($clubId, $seasonId, [
            ['D CHAMP FUTUR', 'FUT-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'GYMNASE FUTUR'],
        ], $team->getId());
        self::assertSame(0, $result['leagueValidated'] ?? null, 'un championnat non commencé ne valide rien');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'FUT-1']);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
        self::assertSame($venue->getId(), $fixture->getVenueId(), 'le gymnase est bien là — seule la maturité manque');

        $rows = $this->payloadMatchesById($clubId, $seasonId);
        self::assertSame('TO_PLACE', $rows[$fixture->getId()]['kind'] ?? null, 'non commencé → à placer, jamais une ancre');
    }

    /**
     * (c) Un domicile d'un championnat commencé SANS HEURE n'est jamais validé (NO_KICKOFF) — il
     * reste à placer, même avec son gymnase.
     */
    public function testAHomeWithoutKickoffIsNeverValidated(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase C', 'gymnase c');

        $result = $this->deposit($clubId, $seasonId, [
            ['D CHAMP C', 'NOHOUR-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::PAST_DATE), '', 'GYMNASE C'],
        ], $team->getId());
        self::assertSame(0, $result['leagueValidated'] ?? null, 'sans heure, rien n\'est validé');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'NOHOUR-1']);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
        self::assertNull($fixture->getKickoffTime());
    }

    /**
     * (d) Un domicile d'un championnat commencé portant un ÉCART EN ATTENTE n'est jamais validé au
     * dépôt — le prédicat l'exclut (`!hasPendingDeviations`). Doublon ASSUMÉ de l'angle manuel
     * {@see LeagueValidatedFixturesControllerTest::testOutlookCountsOnlyMaturedValidatableHomeFixtures}
     * ($withDeviation) : ici le témoin est que le CANAL DU DÉPÔT ne contourne pas l'exclusion.
     */
    public function testAHomeWithAPendingDeviationIsNeverValidated(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase D', 'gymnase d');

        // Un domicile mûr (échéance passée) parfaitement plaçable MAIS portant un écart en attente.
        $deviated = $this->seedHomeFixture($clubId, $seasonId, $team->getId(), self::FUTURE_DATE, '15:30', $venue->getId(), '2020-09-10');
        $deviated->putPendingDeviation(['field' => 'date', 'appValue' => self::FUTURE_DATE, 'sourceValue' => '2099-03-21', 'channel' => 'FBI_XLSX', 'seenAt' => '2026-09-01T00:00:00+00:00', 'autoApplied' => false]);
        $this->em->flush();
        $deviatedId = $deviated->getId();

        // Un dépôt (une ligne non commencée ailleurs) déclenche le balayage d'auto-validation.
        $result = $this->deposit($clubId, $seasonId, [
            ['D CHAMP D', 'DEV-OTHER', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::FUTURE_DATE), '15:30', 'GYMNASE D'],
        ], $team->getId());
        self::assertSame(0, $result['leagueValidated'] ?? null, 'un écart en attente n\'est jamais auto-validé');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fresh = $this->em->find(Fixture::class, $deviatedId);
        self::assertInstanceOf(Fixture::class, $fresh);
        self::assertSame(FixtureStatus::UNPLACED, $fresh->getStatus(), 'un écart en attente reste à traiter, jamais validé');
    }

    /**
     * (e) Un domicile d'un championnat commencé SANS GYMNASE n'est jamais validé (NO_VENUE) — la
     * salle du fichier n'étant appariée à aucun alias, aucun gymnase n'est rattaché.
     */
    public function testAHomeWithoutVenueIsNeverValidated(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        // Alias présent pour un AUTRE libellé : la salle du fichier ne l'apparie pas → pas de gymnase.
        $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase E', 'gymnase e');

        $result = $this->deposit($clubId, $seasonId, [
            ['D CHAMP E', 'NOVENUE-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::PAST_DATE), '15:30', 'SALLE SANS ALIAS'],
        ], $team->getId());
        self::assertSame(0, $result['leagueValidated'] ?? null, 'sans gymnase, rien n\'est validé (NO_VENUE)');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => 'NOVENUE-1']);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
        self::assertNull($fixture->getVenueId());
    }

    /**
     * (f) Le compteur `leagueValidated` du rapport d'import est EXACT : deux domiciles validables
     * (heure + gymnase, championnat commencé) comptent pour 2, un troisième sans heure ne compte pas.
     */
    public function testTheLeagueValidatedCounterIsExact(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase F', 'gymnase f');

        $result = $this->deposit($clubId, $seasonId, [
            ['D CHAMP F', 'F-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::PAST_DATE), '15:30', 'GYMNASE F'],
            ['D CHAMP F', 'F-2', $needle . ' - 1', 'AS Autre', $this->fbiDate('2020-09-26'), '16:00', 'GYMNASE F'],
            ['D CHAMP F', 'F-3', $needle . ' - 1', 'AS Tierce', $this->fbiDate('2020-10-03'), '', 'GYMNASE F'],
        ], $team->getId());

        self::assertSame(2, $result['leagueValidated'] ?? null, 'deux validables comptent 2, le sans-heure ne compte pas');
    }

    /**
     * (g) Un re-dépôt ne revalide pas : le prédicat exclut VALIDATED, un second passage du même
     * fichier ne bascule plus rien (idempotent, on ne dévalide jamais).
     */
    public function testReDepositDoesNotReValidate(): void
    {
        [, $clubId, $seasonId, $needle] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase G', 'gymnase g');

        $rows = [['D CHAMP G', 'G-1', $needle . ' - 1', 'AS Voisins', $this->fbiDate(self::PAST_DATE), '15:30', 'GYMNASE G']];

        $first = $this->deposit($clubId, $seasonId, $rows, $team->getId());
        self::assertSame(1, $first['leagueValidated'] ?? null, 'le premier dépôt valide');

        $second = $this->deposit($clubId, $seasonId, $rows, $team->getId());
        self::assertSame(0, $second['leagueValidated'] ?? null, 'le re-dépôt ne revalide pas (VALIDATED exclu)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le test enchaîne plusieurs requêtes/appels service : sans ça le noyau redémarre et
        // détache les entités (patron du jumeau LeagueValidatedFixturesControllerTest).
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $importer = self::getContainer()->get(FbiFixtureImporter::class);
        self::assertInstanceOf(FbiFixtureImporter::class, $importer);
        $this->importer = $importer;

        // Horloge figée AVANT l'octobre des scénarios : un domicile de 2020 est « premier match
        // joué » (championnat commencé), un de 2099 ne l'est pas — et treatOnArrival ne fait pas
        // naître REVIEWED « FBI fait foi » sur une date d'octobre au fil réel du calendrier.
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-16 10:00:00'));
    }

    protected function tearDown(): void
    {
        // Relâche l'horloge épinglée (Redis partagé, non rollbacké entre tests).
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    /**
     * Dépose un fichier FBI PAR LE SERVICE (le foyer où vit l'auto-validation, {@see FbiFixtureImporter::import}) :
     * mappings = chaque division des lignes → l'équipe donnée. Rend le rapport d'import.
     *
     * @param list<list<string>> $rows lignes de données (sans en-tête)
     *
     * @return array<string, mixed>
     */
    private function deposit(string $clubId, string $seasonId, array $rows, string $teamId): array
    {
        $this->scopeGucToClub($clubId);
        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $mappings = array_map(
            static fn (string $division): array => ['division' => $division, 'fbiTeamLabel' => null, 'teamId' => $teamId, 'competitionId' => null],
            array_values(array_unique(array_column($rows, 0))),
        );

        return $this->importer->import($this->xlsx($rows), $club, $mappings, [], $seasonId);
    }

    /**
     * La charge utile `/place-matches` du club, indexée par id de rencontre.
     *
     * @return array<string, array<string, mixed>>
     */
    private function payloadMatchesById(string $clubId, string $seasonId): array
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
        self::assertInstanceOf(MatchPlacementPayloadBuilder::class, $builder);
        $matches = $builder->build($club, $seasonId)['payload']['matches'];
        self::assertIsArray($matches);

        $byId = [];
        foreach ($matches as $row) {
            self::assertIsArray($row);
            $id = $row['id'] ?? null;
            if (\is_string($id)) {
                $byId[$id] = $row;
            }
        }

        return $byId;
    }

    /**
     * Un domicile UNPLACED prêt (gymnase + heure) rattaché à une compétition jetable dont l'échéance
     * est donnée — le patron du témoin manuel (cf. LeagueValidatedFixturesControllerTest::eligible).
     */
    private function seedHomeFixture(string $clubId, string $seasonId, string $teamId, string $date, string $kickoff, string $venueId, ?string $deadline): Fixture
    {
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('D SEED ' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        if (null !== $deadline) {
            $competition->setEntryDeadline(new DateTimeImmutable($deadline));
        }
        $this->em->persist($competition);
        $this->em->flush();

        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(new DateTimeImmutable($kickoff));
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function createVenueWithAlias(string $clubId, string $seasonId, string $name, string $normalizedAlias): Venue
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName($name);
        $venue->setSource('manual');
        $venue->setExternalLabels([$normalizedAlias]);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
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

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [token, clubId, seasonId, needle]
     */
    private function createClub(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $clubName = 'BC Import ' . $uid;
        $club = new Club;
        $club->setName($clubName);
        $club->setSlug('bc-import-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('import' . $uid . '@test.com');
        $user->setFirstName('Import');
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
        $this->settleSeasonPlan($season);

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $club->getId(), $season->getId(), strtoupper($clubName)];
    }

    private function fbiDate(string $iso): string
    {
        return date('d/m/Y', (int) strtotime($iso));
    }

    /** @param list<list<string>> $rows en-tête au format réel (« N° de match » avec son espace) */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(
            [['Division', 'N° de match ', 'Equipe 1', 'Equipe 2', 'Date de rencontre', 'Heure', 'Salle'], ...$rows],
            null,
            'A1',
        );
        $path = tempnam(sys_get_temp_dir(), 'fbi') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);

        return $path;
    }
}
