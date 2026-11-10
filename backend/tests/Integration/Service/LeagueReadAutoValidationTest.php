<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Clock\DevClockStore;
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
use App\Enum\FixtureUnplacedReason;
use App\Enum\SeasonStatus;
use App\Service\FbiFixtureImporter;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Integration\Api\LeagueValidatedFixturesControllerTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — axes constraint semantics + auth & memberships (§7.1) : un championnat COMMENCÉ
 * (échéance de saisie atteinte, jour inclus, ou premier match joué) passe SEUL ses domiciles datés
 * « Déjà dans FBI » (VALIDATED + MANUAL ; « validé ligue » dans les steps Behat) dès qu'un
 * GESTIONNAIRE lit l'accueil — passés ET futurs, heures inchangées, ancres FIXED pour le solveur —
 * et cette écriture-à-la-lecture ne se produit JAMAIS hors de sa garde : saison archivée (rien ne
 * se modifie dans une saison archivée, amicaux compris), socle non choisi. Une version périmée
 * pendant le balayage ne casse pas la lecture : elle répond, et la suivante rattrape.
 *
 * ⚠ Doublon ASSUMÉ, point sensible (matchs / constraint semantics), angles NOMMÉS :
 *  - le prédicat « validable » par la vue « Déjà dans FBI » :
 *    {@see LeagueValidatedFixturesControllerTest} ;
 *  - le même balayage AU DÉPÔT du fichier : {@see ImportLeagueAutoValidationTest} ;
 *  - le Membre (zéro écriture) : `MemberReadsAreSideEffectFreeTest` (bloquant) et
 *    `DeadlineOutlookTest` ; le club étranger : `MatchTenantIsolationTest::testLeagueValidationNeverReadsOrWritesAForeignClubsFixtures`.
 * ICI l'angle est la LECTURE DE L'ACCUEIL entre deux dépôts (le parcours : import le 1/11, premier
 * match le 7/11, lecture le 10/11) et ses gardes.
 */
#[Group('integration')]
#[Group('phase1')]
final class LeagueReadAutoValidationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /** Décalages (jours) des 11 domiciles FUTURS par rapport au jour de lecture. */
    private const array FUTURE_OFFSETS = [11, 25, 39, 53, 67, 81, 95, 109, 123, 137, 150];

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** Le jour de lecture (J) : le 10 novembre de la saison courante. */
    private DateTimeImmutable $today;

    /**
     * Le parcours : le fichier FBI est déposé le 1/11 (championnat pas encore commencé — rien
     * n'est validé au dépôt), le premier match se joue le 7/11, et le 10/11 le gestionnaire ouvre
     * l'accueil : les 12 domiciles datés (1 passé, 11 futurs) passent « Déjà dans FBI », heures
     * inchangées, et partent FIXED au solveur. Le 13e (sans heure) reste à placer et nommé
     * NO_KICKOFF, l'écart en attente reste intact et nommé, le terrain neutre n'est ni validé ni
     * nommé, et ne part au moteur qu'en AWAY (sa date d'équipe). Relire ne réécrit rien.
     */
    public function testAManagerReadOfTheHomeSweepsEveryDatedHomeOfAStartedChampionship(): void
    {
        [$token, $clubId, $seasonId, $needle] = $this->createClub();
        $team = $this->createTeam($clubId, $seasonId);
        $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase U15', 'gymnase u15');

        // 1/11 : dépôt. Premier match le 7/11 → championnat PAS encore commencé.
        $this->pinClock($this->today->modify('-9 days'));
        $rows = [['U15 CHAMP', 'U15-00', $needle . ' - 1', 'AS Voisins', $this->fbiDate($this->today->modify('-3 days')), '15:30', 'GYMNASE U15']];
        foreach (self::FUTURE_OFFSETS as $i => $offset) {
            $rows[] = ['U15 CHAMP', \sprintf('U15-%02d', $i + 1), $needle . ' - 1', 'Adversaire ' . $i, $this->fbiDate($this->today->modify('+' . $offset . ' days')), '16:00', 'GYMNASE U15'];
        }
        $rows[] = ['U15 CHAMP', 'U15-NOHOUR', $needle . ' - 1', 'Sans Heure', $this->fbiDate($this->today->modify('+18 days')), '', 'GYMNASE U15'];
        $rows[] = ['U15 CHAMP', 'U15-DEV', $needle . ' - 1', 'Avec Ecart', $this->fbiDate($this->today->modify('+32 days')), '17:00', 'GYMNASE U15'];
        $rows[] = ['U15 CHAMP', 'U15-NEUTRE', $needle . ' - 1', 'Terrain Neutre', $this->fbiDate($this->today->modify('+46 days')), '17:00', 'GYMNASE ADVERSE'];
        $report = $this->deposit($clubId, $seasonId, $rows, $team->getId(), [['label' => 'GYMNASE ADVERSE', 'venueId' => FbiFixtureImporter::NEUTRAL]]);
        self::assertSame(0, $report['leagueValidated'] ?? null, 'témoin : au dépôt du 1/11, le championnat n\'a pas commencé — rien n\'est validé');

        // L'écart en attente (une date que FBI a changée, pas encore arbitrée).
        $this->scopeGucToClub($clubId);
        $deviated = $this->fixtureByRef('U15-DEV');
        $deviated->putPendingDeviation(['field' => 'date', 'appValue' => $deviated->getMatchDate()->format('Y-m-d'), 'sourceValue' => $this->today->modify('+39 days')->format('Y-m-d'), 'channel' => 'FBI_XLSX', 'seenAt' => $this->today->format('Y-m-d\TH:i:sP'), 'autoApplied' => false]);
        $this->em->flush();

        $dated = ['U15-00' => '15:30'];
        foreach (array_keys(self::FUTURE_OFFSETS) as $i) {
            $dated[\sprintf('U15-%02d', $i + 1)] = '16:00';
        }
        foreach (array_keys($dated) as $ref) {
            self::assertSame(FixtureStatus::UNPLACED, $this->fixtureByRef($ref)->getStatus(), 'témoin : ' . $ref . ' est « Sans date ni gymnase » avant la lecture');
        }

        // 10/11 : le gestionnaire ouvre l'accueil.
        $this->pinClock($this->today);
        $data = $this->managerReadsTheHome($token);
        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $idsByRef = [];
        foreach ($dated as $ref => $kickoff) {
            $fixture = $this->fixtureByRef($ref);
            self::assertSame(FixtureStatus::VALIDATED, $fixture->getStatus(), $ref . ' est « Déjà dans FBI » après la lecture du gestionnaire');
            self::assertSame(FixturePlacementSource::MANUAL, $fixture->getPlacementSource(), $ref . ' porte l\'ancre MANUAL');
            self::assertSame($kickoff, $fixture->getKickoffTime()?->format('H:i'), $ref . ' garde son heure');
            $idsByRef[$ref] = $fixture->getId();
        }
        self::assertSame(12, $data['swept'] ?? -1, 'la lecture dit combien de rencontres elle a fait passer « Déjà dans FBI »');
        self::assertSame(0, $data['toConfirmCount'], 'plus rien à confirmer');

        $noHour = $this->fixtureByRef('U15-NOHOUR');
        self::assertSame(FixtureStatus::UNPLACED, $noHour->getStatus(), 'sans heure : jamais validé');
        $deviatedAfter = $this->fixtureByRef('U15-DEV');
        self::assertSame(FixtureStatus::UNPLACED, $deviatedAfter->getStatus(), 'un écart en attente n\'est jamais tranché par une lecture');
        self::assertTrue($deviatedAfter->hasPendingDeviations());
        $neutral = $this->fixtureByRef('U15-NEUTRE');
        self::assertSame(FixtureStatus::UNPLACED, $neutral->getStatus(), 'un terrain neutre n\'est jamais validé');
        self::assertSame(FixtureUnplacedReason::NEUTRAL_GROUND, $neutral->getUnplacedReason());
        [$noHourId, $deviatedId, $neutralId] = [$noHour->getId(), $deviatedAfter->getId(), $neutral->getId()];

        // Le solveur : les 12 sont des ancres FIXED, aucune n'est à placer ; le 13e et l'écart
        // restent à placer ; le neutre part AWAY (il ne porte que sa date d'équipe, `team_dates`,
        // MatchPlacementPayloadBuilder) — jamais TO_PLACE, jamais une ancre FIXED.
        $payload = $this->payloadMatchesById($clubId, $seasonId);
        foreach ($idsByRef as $ref => $id) {
            self::assertSame('FIXED', $payload[$id]['kind'] ?? null, $ref . ' part FIXED au solveur, jamais TO_PLACE');
        }
        self::assertSame('TO_PLACE', $payload[$noHourId]['kind'] ?? null);
        self::assertSame('TO_PLACE', $payload[$deviatedId]['kind'] ?? null);
        self::assertSame('AWAY', $payload[$neutralId]['kind'] ?? null, 'un terrain neutre part AWAY au moteur (date d\'équipe seule) — jamais TO_PLACE ni FIXED');

        // La vue nomme ce qui reste à traiter (sans heure, écart) — jamais le neutre. Relire ne
        // réécrit rien : 0 balayé, empreinte des rencontres inchangée.
        $before = $this->fixtureFingerprint($clubId);
        $this->client->request('GET', '/api/fixtures/league-validation', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        $view = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $view['swept'] ?? -1, 'relire ne rebalaie rien');
        $reasonsById = array_column($view['toTreat'], 'reason', 'fixtureId');
        self::assertSame('NO_KICKOFF', $reasonsById[$noHourId] ?? null);
        self::assertSame('PENDING_DEVIATION', $reasonsById[$deviatedId] ?? null);
        self::assertArrayNotHasKey($neutralId, $reasonsById, 'un terrain neutre n\'est jamais nommé à traiter');

        $again = $this->managerReadsTheHome($token);
        self::assertSame(0, $again['swept'] ?? -1, 'relire l\'accueil ne rebalaie rien');
        self::assertSame($before, $this->fixtureFingerprint($clubId), 'une relecture n\'écrit aucune rencontre');
    }

    /**
     * Une version périmée PENDANT le balayage (un autre onglet modifie l'un des 12 entre la lecture
     * et l'écriture) : la lecture répond 200 (`swept` 0, rien d'écrit, la modification concurrente
     * conservée), et la lecture suivante bascule les 12. Le verrou optimiste est rattrapé, jamais
     * remonté en 500, et l'EntityManager reste utilisable (RLS conservée).
     */
    public function testAStaleVersionDuringTheChampionshipSweepAnswersAndTheNextReadSweepsTheRest(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->pinClock($this->today);
        $ids = $this->seedStartedChampionship($clubId, $seasonId, $team->getId(), $venue->getId());
        $raced = $ids[3];
        $versionBefore = $this->fixtureVersion($clubId, $raced);

        $this->bumpVersionDuringTheNextFlushOf($raced);
        $data = $this->managerReadsTheHome($token);
        self::assertSame(0, $data['swept'] ?? -1, 'le balayage perdu sur verrou périmé ne compte rien');

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        foreach ($ids as $id) {
            $fixture = $this->em->find(Fixture::class, $id);
            self::assertInstanceOf(Fixture::class, $fixture);
            self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus(), 'rien n\'est écrit quand le balayage perd la course');
        }
        self::assertSame($versionBefore + 1, $this->fixtureVersion($clubId, $raced), 'la modification concurrente n\'est pas gênée');

        $next = $this->managerReadsTheHome($token);
        self::assertSame(12, $next['swept'] ?? -1, 'la lecture suivante rattrape les 12');
    }

    /** Même rattrapage pour le balayage des AMICAUX passés : la lecture répond, la suivante bascule. */
    public function testAStaleVersionDuringTheFriendlySweepAnswersAndTheNextReadSweepsIt(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $team = $this->createTeam($clubId, $seasonId);
        $this->pinClock($this->today);
        $friendlyId = $this->seedPastFriendly($clubId, $seasonId, $team->getId());

        $this->bumpVersionDuringTheNextFlushOf($friendlyId);
        $data = $this->managerReadsTheHome($token);
        self::assertSame(0, $data['swept'] ?? -1);

        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $friendly = $this->em->find(Fixture::class, $friendlyId);
        self::assertInstanceOf(Fixture::class, $friendly);
        self::assertSame(FixtureStatus::UNPLACED, $friendly->getStatus());

        $next = $this->managerReadsTheHome($token);
        self::assertSame(1, $next['swept'] ?? -1, 'la lecture suivante rattrape l\'amical passé');
    }

    /**
     * La réponse d'une lecture dont le balayage a perdu la course ne compte QUE la saison lue :
     * le rattrapage du verrou périmé réinitialise l'EntityManager (`resetManager`), qui perd les
     * filtres Doctrine tenant + saison posés par `TenantFilterListener` ; les lectures `findBy([])`
     * de l'accueil qui suivent ne doivent pas pour autant ramener la saison précédente du club
     * (fenêtre d'échéance N-1, domicile PLACED N-1 compté « à saisir »).
     * Témoin : la même réponse, au même état, lue par un Membre (aucun balayage, filtres intacts).
     * Angle distinct du rattrapage lui-même ({@see testAStaleVersionDuringTheChampionshipSweepAnswersAndTheNextReadSweepsTheRest}) :
     * ici, ce que la réponse COMPTE après le rattrapage.
     */
    public function testAStaleVersionDuringTheSweepNeverMixesThePreviousSeasonIntoTheAnswer(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $memberToken = $this->addMember($clubId);
        $this->pinClock($this->today);
        $year = (int) $this->today->format('Y');

        // Saison PRÉCÉDENTE du même club : un championnat échu avec un domicile « à placer »
        // (sans heure) et un domicile PLACED « à saisir ».
        $previous = $this->createSeason($clubId, $year - 1);
        $previousId = $previous->getId();
        $previousTeam = $this->createTeam($clubId, $previousId);
        $previousVenue = $this->createVenue($clubId, $previousId);
        $previousCompetition = $this->createCompetition($clubId, $previousId, $previousTeam->getId(), 'N-1 CHAMP', new DateTimeImmutable(($year - 1) . '-09-10'));
        $this->homeFixture($clubId, $previousId, $previousTeam->getId(), $previousCompetition->getId(), new DateTimeImmutable(($year - 1) . '-11-20'), null, FixtureStatus::UNPLACED);
        $this->homeFixture($clubId, $previousId, $previousTeam->getId(), $previousCompetition->getId(), new DateTimeImmutable(($year - 1) . '-11-27'), $previousVenue->getId(), FixtureStatus::PLACED);

        // Saison COURANTE : une fenêtre d'échéance ouverte (domicile sans heure, « à placer »)
        // et un championnat commencé dont les 2 domiciles datés seront balayés.
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $current = $this->createCompetition($clubId, $seasonId, $team->getId(), 'N CHAMP', $this->today->modify('+3 days'));
        $this->homeFixture($clubId, $seasonId, $team->getId(), $current->getId(), $this->today->modify('+20 days'), null, FixtureStatus::UNPLACED);
        $ids = $this->seedStartedChampionship($clubId, $seasonId, $team->getId(), $venue->getId(), count: 2);

        // Témoin : le Membre lit le même état (aucun balayage) — la saison courante seule.
        $this->client->request('GET', '/api/matches/deadline-outlook', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken]);
        self::assertResponseStatusCodeSame(200);
        $baseline = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($baseline);
        self::assertCount(1, $baseline['windows'], 'témoin : une seule fenêtre, celle de la saison courante');
        self::assertSame(0, $baseline['fbiTodo']['toEnter'], 'témoin : aucun domicile PLACED dans la saison courante');
        self::assertSame(2, $baseline['toConfirmCount']);

        // Le gestionnaire lit ; le balayage perd la course (verrou périmé) et la lecture répond.
        $this->bumpVersionDuringTheNextFlushOf($ids[0]);
        $raced = $this->managerReadsTheHome($token);
        self::assertSame(0, $raced['swept'] ?? -1, 'témoin : le balayage a bien perdu la course');

        $windowNames = array_merge(...array_map(static fn (array $w): array => $w['competitionNames'], $raced['windows']));
        self::assertNotContains('N-1 CHAMP', $windowNames, 'la fenêtre d\'échéance de la saison précédente ne se mêle jamais à l\'accueil');
        self::assertSame(0, $raced['fbiTodo']['toEnter'], 'le domicile PLACED de la saison précédente n\'est jamais compté « à saisir »');
        self::assertSame($baseline, $raced, 'après un balayage perdu, la réponse est celle de la saison lue, à l\'identique');
    }

    /**
     * Rien ne se modifie dans une saison ARCHIVÉE, même par une lecture de gestionnaire : l'accueil
     * ouvert sur la saison N-1 (socle pourtant choisi) répond 200, `swept` 0, et ni le domicile
     * validable d'un championnat commencé ni l'amical passé de N-1 ne bougent.
     */
    public function testAnArchivedSeasonIsNeverWrittenEvenByAManagerRead(): void
    {
        [$token, $clubId] = $this->createClub();
        $this->pinClock($this->today);
        $archived = $this->createSeason($clubId, (int) $this->today->format('Y') - 1);
        $this->settleSeasonPlan($archived);
        $archivedId = $archived->getId();
        $team = $this->createTeam($clubId, $archivedId);
        $venue = $this->createVenue($clubId, $archivedId);
        $ids = $this->seedStartedChampionship($clubId, $archivedId, $team->getId(), $venue->getId(), count: 1);
        $friendlyId = $this->seedPastFriendly($clubId, $archivedId, $team->getId());

        $this->client->request('GET', '/api/matches/deadline-outlook', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_X-Season-Id' => $archivedId]);
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertStillUnplaced($clubId, [...$ids, $friendlyId], 'saison archivée');
        self::assertSame(0, $data['swept'] ?? -1, 'une saison archivée ne se balaie jamais');
    }

    /**
     * Socle non choisi (planning de saison pas en vigueur) : la lecture de l'accueil par un
     * gestionnaire répond 200, `swept` 0, et n'écrit rien — ni championnat, ni amical.
     */
    public function testASeasonWithoutChosenSocleIsNeverWrittenByAManagerRead(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub(settleSocle: false);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->pinClock($this->today);
        $ids = $this->seedStartedChampionship($clubId, $seasonId, $team->getId(), $venue->getId(), count: 1);
        $friendlyId = $this->seedPastFriendly($clubId, $seasonId, $team->getId());

        $data = $this->managerReadsTheHome($token);

        $this->assertStillUnplaced($clubId, [...$ids, $friendlyId], 'socle non choisi');
        self::assertSame(0, $data['swept'] ?? -1, 'sans socle en vigueur, rien ne se balaie');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le test enchaîne requêtes et appels service sur le même conteneur (listener, builder).
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->today = new DateTimeImmutable($year . '-11-10 10:00:00');
    }

    protected function tearDown(): void
    {
        // Relâche l'horloge épinglée (Redis partagé, non rollbacké entre tests — DEL ciblé, jamais FLUSHALL).
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    private function pinClock(DateTimeImmutable $at): void
    {
        self::getContainer()->get(DevClockStore::class)->set($at);
    }

    /**
     * GET /api/matches/deadline-outlook par le gestionnaire : 200 et la réponse décodée.
     *
     * @return array<string, mixed>
     */
    private function managerReadsTheHome(string $token): array
    {
        $this->client->request('GET', '/api/matches/deadline-outlook', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200, mb_substr((string) $this->client->getResponse()->getContent(), 0, 300));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * Simule l'autre onglet : au PROCHAIN flush qui met à jour la rencontre visée, un UPDATE direct
     * incrémente sa version AVANT que l'ORM écrive (onFlush précède la transaction du flush) — l'ORM
     * écrit alors sur une version périmée et lève le verrou optimiste. Ne tire qu'une fois.
     */
    private function bumpVersionDuringTheNextFlushOf(string $fixtureId): void
    {
        $listener = new class($fixtureId) {
            private bool $fired = false;

            public function __construct(private readonly string $fixtureId) {}

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($this->fired) {
                    return;
                }
                $em = $args->getObjectManager();
                foreach ($em->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Fixture && $entity->getId() === $this->fixtureId) {
                        $this->fired = true;
                        $em->getConnection()->executeStatement('UPDATE fixture SET version = version + 1 WHERE id = ?', [$this->fixtureId]);

                        return;
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $listener);
    }

    /**
     * Un championnat COMMENCÉ par son premier match joué (J-3), sans échéance : `$count` domiciles
     * UNPLACED datés (heure + gymnase) — le premier à J-3, les suivants dans le futur.
     *
     * @return list<string> les ids des domiciles
     */
    private function seedStartedChampionship(string $clubId, string $seasonId, string $teamId, string $venueId, int $count = 12): array
    {
        $this->scopeGucToClub($clubId);
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('U15 SEED ' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        $offsets = [-3, ...self::FUTURE_OFFSETS];
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $fixture = new Fixture;
            $fixture->setClubId($clubId);
            $fixture->setSeasonId($seasonId);
            $fixture->setTeamId($teamId);
            $fixture->setCompetitionId($competition->getId());
            $fixture->setMatchDate($this->today->modify(($offsets[$i] >= 0 ? '+' : '') . $offsets[$i] . ' days')->setTime(0, 0));
            $fixture->setHomeAway(FixtureHomeAway::HOME);
            $fixture->setOpponentLabel('Adversaire ' . $i);
            $fixture->setVenueId($venueId);
            $fixture->setKickoffTime(new DateTimeImmutable('16:00'));
            $this->em->persist($fixture);
            $this->em->flush();
            $ids[] = $fixture->getId();
        }

        return $ids;
    }

    /** Un amical domicile (sans compétition) joué il y a 10 jours, encore UNPLACED. */
    private function seedPastFriendly(string $clubId, string $seasonId, string $teamId): string
    {
        $this->scopeGucToClub($clubId);
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId(null);
        $fixture->setMatchDate($this->today->modify('-10 days')->setTime(0, 0));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Amical passé');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    private function createCompetition(string $clubId, string $seasonId, string $teamId, string $name, DateTimeImmutable $entryDeadline): Competition
    {
        $this->scopeGucToClub($clubId);
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $competition->setEntryDeadline($entryDeadline);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    /** Un domicile de championnat ; avec gymnase, il porte une heure (16:00) et l'ancre MANUAL. */
    private function homeFixture(string $clubId, string $seasonId, string $teamId, string $competitionId, DateTimeImmutable $date, ?string $venueId, FixtureStatus $status): void
    {
        $this->scopeGucToClub($clubId);
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competitionId);
        $fixture->setMatchDate($date->setTime(0, 0));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        if (null !== $venueId) {
            $fixture->setVenueId($venueId);
            $fixture->setKickoffTime(new DateTimeImmutable('16:00'));
            $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
        }
        $fixture->setStatus($status, $this->today);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    /** Un Membre (rôle `member`) du club : la route lui est ouverte, sa lecture ne balaie jamais. */
    private function addMember(string $clubId): string
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid('member', true);
        $user = new User;
        $user->setEmail($uid . '@test.com');
        $user->setFirstName('Lecture');
        $user->setLastName('Membre');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole('member');
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @param list<string> $ids */
    private function assertStillUnplaced(string $clubId, array $ids, string $case): void
    {
        $this->scopeGucToClub($clubId);
        $connection = $this->em->getConnection();
        foreach ($ids as $id) {
            $row = $connection->fetchAssociative('SELECT status, placement_source FROM fixture WHERE id = ?', [$id]);
            self::assertIsArray($row);
            self::assertSame('UNPLACED', $row['status'], $case . ' : une lecture de gestionnaire a écrit la rencontre ' . $id);
            self::assertNull($row['placement_source'], $case . ' : une source de placement a été posée');
        }
    }

    private function fixtureVersion(string $clubId, string $fixtureId): int
    {
        $this->scopeGucToClub($clubId);

        return (int) $this->em->getConnection()->fetchOne('SELECT version FROM fixture WHERE id = ?', [$fixtureId]);
    }

    /** Empreinte des rencontres du club : nombre, somme des versions, dernière mise à jour. */
    private function fixtureFingerprint(string $clubId): string
    {
        $this->scopeGucToClub($clubId);

        return (string) $this->em->getConnection()->fetchOne(
            'SELECT count(*)::text || \'@\' || coalesce(sum(version), 0)::text || \'@\' || coalesce(max(updated_at)::text, \'-\') FROM fixture WHERE club_id = ?',
            [$clubId],
        );
    }

    private function fixtureByRef(string $externalRef): Fixture
    {
        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['externalRef' => $externalRef]);
        self::assertInstanceOf(Fixture::class, $fixture, 'rencontre ' . $externalRef . ' introuvable');

        return $fixture;
    }

    /**
     * Dépose un fichier FBI par le service (patron {@see ImportLeagueAutoValidationTest::deposit}).
     *
     * @param list<list<string>>                          $rows
     * @param list<array{label: string, venueId: string}> $venueMappings
     *
     * @return array<string, mixed>
     */
    private function deposit(string $clubId, string $seasonId, array $rows, string $teamId, array $venueMappings = []): array
    {
        $this->scopeGucToClub($clubId);
        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $mappings = array_map(
            static fn (string $division): array => ['division' => $division, 'fbiTeamLabel' => null, 'teamId' => $teamId, 'competitionId' => null],
            array_values(array_unique(array_column($rows, 0))),
        );
        $importer = self::getContainer()->get(FbiFixtureImporter::class);
        self::assertInstanceOf(FbiFixtureImporter::class, $importer);

        return $importer->import($this->xlsx($rows), $club, $mappings, [], $seasonId, $venueMappings);
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

    private function createVenueWithAlias(string $clubId, string $seasonId, string $name, string $normalizedAlias): Venue
    {
        $this->scopeGucToClub($clubId);
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

    private function createVenue(string $clubId, string $seasonId): Venue
    {
        return $this->createVenueWithAlias($clubId, $seasonId, 'Gymnase Lecture', 'gymnase lecture');
    }

    private function createTeam(string $clubId, string $seasonId): Team
    {
        $this->scopeGucToClub($clubId);
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
        $category->setName('U15-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U15');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createSeason(string $clubId, int $startYear): Season
    {
        $this->scopeGucToClub($clubId);
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    /**
     * Un club (démo : jamais bridé), un gestionnaire, la saison courante (celle du 10/11 épinglé),
     * socle choisi par défaut.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} [token, clubId, seasonId, needle]
     */
    private function createClub(bool $settleSocle = true): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $clubName = 'BC Lecture ' . $uid;
        $club = new Club;
        $club->setName($clubName);
        $club->setSlug('bc-lecture-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('lecture' . $uid . '@test.com');
        $user->setFirstName('Lecture');
        $user->setLastName('Gestionnaire');
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
        $this->em->flush();

        $season = $this->createSeason($club->getId(), (int) $this->today->format('Y'));
        if ($settleSocle) {
            $this->settleSeasonPlan($season);
        }

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $club->getId(), $season->getId(), strtoupper($clubName)];
    }

    private function fbiDate(DateTimeImmutable $date): string
    {
        return $date->format('d/m/Y');
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
