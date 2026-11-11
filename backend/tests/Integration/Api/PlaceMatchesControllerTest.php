<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\MatchPlacementRun;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\MatchPlacementRunStatus;
use App\Enum\SeasonStatus;
use App\Message\PlaceMatchesMessage;
use App\MessageHandler\PlaceMatchesHandler;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * POST /api/fixtures/place — le rail est désormais ASYNCHRONE : le contrôleur garde ses
 * gardes, enfile un {@see PlaceMatchesMessage} et répond 202 + id du run. Les cas qui solvent
 * vraiment tirent le message de la file et jouent le VRAI handler (engine réel du
 * docker-compose) ; s'il est indisponible, ils se skippent. La règle souveraine tient : une
 * ancre MANUELLE n'est JAMAIS réécrite (P1-4 PR D).
 *
 * Plus GET /api/fixtures/placement-run (lecture du dernier run, scopé tenant).
 */
#[Group('integration')]
final class PlaceMatchesControllerTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testNonManagementMemberIs403(): void
    {
        [, $clubId] = $this->createClub();
        $editorToken = $this->addMember($clubId, 'editor');

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSocleNotChosenIs409(): void
    {
        [$token] = $this->createClub(settleSocle: false);

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testInvalidWindowIs422(): void
    {
        [$token] = $this->createClub();

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], '{"from":"pas-une-date","to":"2026-10-04"}');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Semaine invalide', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], '{"from":"2026-10-10","to":"2026-10-04"}');
        self::assertResponseStatusCodeSame(422);
    }

    public function testEnqueuesAndReturnsAPendingRun(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(202);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('PENDING', $data['status'] ?? null);
        self::assertNotEmpty($data['runId'] ?? null);

        // Le run existe en base, PENDING ; un message a été enfilé.
        $this->scopeGucToClub($clubId);
        $run = $this->em->getRepository(MatchPlacementRun::class)->find($data['runId']);
        self::assertInstanceOf(MatchPlacementRun::class, $run);
        self::assertSame(MatchPlacementRunStatus::PENDING, $run->getStatus());
        self::assertNotNull($this->lastQueuedMessage());
    }

    public function testASecondDemandWhileARunIsOpenIs409(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        // Le verrou tenu (par une 1ʳᵉ demande, simulé ici en l'acquérant) → la 2ᵉ est refusée.
        $lock = self::getContainer()->get(MatchPlacementLock::class);
        $token2 = $lock->acquire($clubId, 120);
        self::assertNotNull($token2);

        try {
            $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertResponseStatusCodeSame(409);
            // Le JSON échappe l'unicode (é → é) : on vise une sous-chaîne ASCII du message.
            self::assertStringContainsString('en cours', (string) $this->client->getResponse()->getContent());
        } finally {
            $lock->release($clubId, $token2);
        }
    }

    public function testCreditExhaustedRefusesBeforeEnqueuing(): void
    {
        // Club NON démo (Découverte bridée) dont le pool est à sec → 403 au kernel.request,
        // AVANT le contrôleur : aucun run créé, aucun message enfilé.
        $max = $this->decouverteMaxGenerations();
        [$token, $clubId, $seasonId] = $this->createClub(isDemo: false, credits: $max);
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        $before = $this->queuedCount();
        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], '{"from":"2026-10-03","to":"2026-10-04"}');
        self::assertResponseStatusCodeSame(403);

        $this->scopeGucToClub($clubId);
        self::assertSame(0, $this->em->getRepository(MatchPlacementRun::class)->count([]), 'aucun run ne doit être créé');
        self::assertSame($before, $this->queuedCount(), 'rien ne doit être enfilé');
    }

    public function testAnotherClubNeverSeesTheRun(): void
    {
        [, $clubA, $seasonA, $userA] = $this->createClub();
        [$tokenB, $clubB] = $this->createClub();

        // Un run du club A.
        $this->scopeGucToClub($clubA);
        $run = new MatchPlacementRun($clubA, $seasonA, $userA, new DateTimeImmutable);
        $this->em->persist($run);
        $this->em->flush();

        // Le club B lit le dernier run : il ne voit JAMAIS celui de A → {run: null}.
        $this->client->request('GET', '/api/fixtures/placement-run', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('run', $data);
        self::assertNull($data['run'], 'un autre club ne voit pas le run (frontière tenant)');
        self::assertNotSame($clubA, $clubB);
    }

    public function testTheClubReadsItsOwnLatestRun(): void
    {
        [$token, $clubId, $seasonId, $userId] = $this->createClub();

        $this->scopeGucToClub($clubId);
        $run = new MatchPlacementRun($clubId, $seasonId, $userId, new DateTimeImmutable);
        $run->setStatus(MatchPlacementRunStatus::COMPLETED);
        $run->setStartedAt(new DateTimeImmutable);
        $run->setFinishedAt(new DateTimeImmutable);
        $run->setResultData(['placed' => 3, 'unplaced' => [], 'skipped' => 0]);
        $this->em->persist($run);
        $this->em->flush();

        $this->client->request('GET', '/api/fixtures/placement-run', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($run->getId(), $data['run']['id'] ?? null);
        self::assertSame('COMPLETED', $data['run']['status'] ?? null);
        self::assertSame(3, $data['run']['result']['placed'] ?? null);
        self::assertNotNull($data['run']['createdAt'] ?? null);
        self::assertNotNull($data['run']['finishedAt'] ?? null);
    }

    public function testPlacesTheSaturdayMatchAndNamesTheSundayOne(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $saturdayId = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03')->getId();
        $sundayId = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-04')->getId();

        // placeAndRunOrSkip vide l'EM (le worker clôt en nettoyant le GUC puis on re-scope) :
        // on RELIT les fixtures par id plutôt que refresh sur une entité détachée.
        $run = $this->placeAndRunOrSkip($token, $clubId);
        $result = (array) $run->getResultData();

        self::assertSame(1, $result['placed'] ?? null);
        self::assertCount(1, $result['unplaced'] ?? []);
        self::assertSame('no_access_window', $result['unplaced'][0]['reason'] ?? null);

        $saturday = $this->em->find(Fixture::class, $saturdayId);
        self::assertInstanceOf(Fixture::class, $saturday);
        self::assertSame(FixtureStatus::PLACED, $saturday->getStatus());
        self::assertSame(FixturePlacementSource::SOLVER, $saturday->getPlacementSource());
        self::assertSame($venue->getId(), $saturday->getVenueId());
        $kickoff = $saturday->getKickoffTime()?->format('H:i');
        self::assertNotNull($kickoff);
        self::assertGreaterThanOrEqual('14:00', $kickoff);
        self::assertLessThanOrEqual('16:30', $kickoff);

        $sunday = $this->em->find(Fixture::class, $sundayId);
        self::assertInstanceOf(Fixture::class, $sunday);
        self::assertSame(FixtureStatus::UNPLACED, $sunday->getStatus());
    }

    public function testAManualAnchorIsNeverRewritten(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '22:30');
        $anchor = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');
        $anchor->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $anchor->setPlacementSource(FixturePlacementSource::MANUAL);
        $anchor->setVenueId($venue->getId());
        $anchor->setKickoffTime(new DateTimeImmutable('20:30'));
        $other = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');
        $this->em->flush();
        [$anchorId, $otherId] = [$anchor->getId(), $other->getId()];

        $this->placeAndRunOrSkip($token, $clubId);

        // L'EM a été vidé par le rail : on relit par id (find réattache depuis la base).
        $anchor = $this->em->find(Fixture::class, $anchorId);
        self::assertInstanceOf(Fixture::class, $anchor);
        self::assertSame('20:30', $anchor->getKickoffTime()?->format('H:i'));
        self::assertSame(FixturePlacementSource::MANUAL, $anchor->getPlacementSource());

        $other = $this->em->find(Fixture::class, $otherId);
        self::assertInstanceOf(Fixture::class, $other);
        self::assertSame(FixtureStatus::PLACED, $other->getStatus());
        $kickoff = $other->getKickoffTime()?->format('H:i');
        self::assertNotNull($kickoff);
        self::assertLessThanOrEqual('19:00', $kickoff);
    }

    /**
     * « Placer automatiquement » sans aucune lecture préalable (page montée avant que le championnat
     * ne commence) : le guichet fait passer « Déjà dans FBI » le domicile daté d'un championnat
     * commencé AVANT de décider s'il y a quelque chose à placer — heure inchangée, ancre FIXED au
     * solveur, et rien n'est enfilé (« Aucun match à placer. »). Jumeau côté lecture :
     * `LeagueReadAutoValidationTest` ; ici, le guichet de placement.
     */
    public function testPlacementSweepsAStartedChampionshipHomeBeforeDecidingWhatToPlace(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '22:30');
        $home = $this->createFixture($clubId, $seasonId, $team->getId(), '2099-03-14');
        $competition = $this->em->find(Competition::class, (string) $home->getCompetitionId());
        self::assertInstanceOf(Competition::class, $competition);
        $competition->setEntryDeadline(new DateTimeImmutable('2020-09-10'));
        $home->setVenueId($venue->getId());
        $home->setKickoffTime(new DateTimeImmutable('20:30'));
        $this->em->flush();
        $homeId = $home->getId();
        $queuedBefore = $this->queuedCount();

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $fresh = $this->em->find(Fixture::class, $homeId);
        self::assertInstanceOf(Fixture::class, $fresh);
        self::assertSame(FixtureStatus::VALIDATED, $fresh->getStatus(), 'le guichet de placement balaie avant de placer');
        self::assertSame(FixturePlacementSource::MANUAL, $fresh->getPlacementSource());
        self::assertSame('20:30', $fresh->getKickoffTime()?->format('H:i'), 'l\'heure fédérale est conservée');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Aucun match', (string) $this->client->getResponse()->getContent());
        self::assertSame($queuedBefore, $this->queuedCount(), 'rien à placer : aucun run enfilé');

        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        $builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
        self::assertInstanceOf(MatchPlacementPayloadBuilder::class, $builder);
        $built = $builder->build($club, $seasonId);
        self::assertSame(0, $built['toPlaceCount']);
        $matches = $built['payload']['matches'];
        self::assertIsArray($matches);
        $kinds = array_column($matches, 'kind', 'id');
        self::assertSame('FIXED', $kinds[$homeId] ?? null, 'le domicile balayé part en ancre fixe');
    }

    /**
     * Promesse (B1-c, club de démonstration) : le balayage « Déjà dans FBI » du guichet de placement
     * reste à la date SIMULÉE du club — la rencontre balayée est datée (`reviewedAt`) du jour que
     * l'écran affiche, comme le fait la lecture. Seul l'horodatage du RUN passe à l'heure réelle
     * (`testRunCreatedAtFollowsTheRealClockEvenForADemoClub`). Falsifiable : l'horloge réelle à la
     * place de l'horloge décorée pour le balayage date la rencontre d'aujourd'hui réel.
     */
    public function testTheSweepStampsFixturesAtTheDemoClubDate(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $simulated = $this->setDemoDate($clubId, '07-05');
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '22:30');
        // Un domicile daté (heure + gymnase) d'un championnat dont l'échéance est passée À LA DATE
        // SIMULÉE seulement : à l'heure réelle, rien n'aurait mûri.
        $home = $this->createFixture($clubId, $seasonId, $team->getId(), $simulated->modify('-9 days')->format('Y-m-d'));
        $competition = $this->em->find(Competition::class, (string) $home->getCompetitionId());
        self::assertInstanceOf(Competition::class, $competition);
        $competition->setEntryDeadline($simulated->modify('-15 days'));
        $home->setVenueId($venue->getId());
        $home->setKickoffTime(new DateTimeImmutable('20:30'));
        $this->em->flush();
        $homeId = $home->getId();

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $fresh = $this->em->find(Fixture::class, $homeId);
        self::assertInstanceOf(Fixture::class, $fresh);
        self::assertSame(FixtureStatus::VALIDATED, $fresh->getStatus(), 'à la date simulée, le championnat a commencé : le guichet balaie');
        self::assertSame($simulated->format('Y-m-d'), $fresh->getReviewedAt()?->format('Y-m-d'), 'la rencontre balayée est datée du jour du club de démonstration, pas du jour réel');
    }

    /**
     * Promesse (B1-c) : un run de placement est daté à l'heure RÉELLE, même pour un club de
     * démonstration dont la date est simulée. `createdAt` sert des DURÉES (grâce de 5 min du
     * rattrapage, seuil d'e-mail de 2 min, ordre du « dernier run ») : daté dans le futur simulé,
     * le run serait vu « jamais orphelin » par le rattrapage et resterait le « dernier » à vie.
     */
    public function testRunCreatedAtFollowsTheRealClockEvenForADemoClub(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $simulated = $this->setDemoDate($clubId, '07-05');
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), $simulated->modify('+1 day')->modify('saturday')->format('Y-m-d'));

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(202);
        $runId = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['runId'];

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $run = $this->em->getRepository(MatchPlacementRun::class)->find($runId);
        self::assertInstanceOf(MatchPlacementRun::class, $run);
        self::assertLessThan(5, abs($run->getCreatedAt()->getTimestamp() - time()), 'le run est daté à l\'heure réelle, jamais à la date simulée du club (' . $run->getCreatedAt()->format('Y-m-d') . ')');
    }

    /**
     * Promesse (B1-d) : un enfilage qui échoue ne laisse jamais un run PENDING orphelin — le run déjà
     * écrit passe FAILED avec un motif lisible (l'écran le relit et rouvre le bouton), le verrou du
     * club est rendu, et l'erreur remonte (500).
     */
    public function testAFailedDispatchLeavesAFailedRunNotAPendingOne(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        // Noyau neuf (le bus du décor est déjà instancié) puis bus qui casse à l'enfilage. La
        // transaction du test (DAMA) survit au redémarrage : le décor reste visible.
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('Redis injoignable'));
        self::getContainer()->set('messenger.default_bus', $bus);

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(500);

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $runs = $this->em->getRepository(MatchPlacementRun::class)->findBy(['clubId' => $clubId]);
        self::assertCount(1, $runs, 'le run a bien été écrit avant l\'enfilage');
        self::assertSame(MatchPlacementRunStatus::FAILED, $runs[0]->getStatus(), 'un enfilage raté ne laisse pas un PENDING que personne ne traitera');
        self::assertSame('Le placement automatique n\'a pas pu être lancé. Réessayez.', $runs[0]->getResultData()['error'] ?? null);

        $lock = self::getContainer()->get(MatchPlacementLock::class);
        $probe = $lock->acquire($clubId, 5);
        self::assertNotNull($probe, 'le verrou du club est rendu');
        $lock->release($clubId, $probe);
    }

    /**
     * Promesse (D8, run EN FILE) : une seconde demande alors que le dernier run du club attend son
     * tour n'annonce aucun délai — le TTL posé à l'enfilage n'est pas une fin — et n'invite pas à
     * réessayer (l'écran passe en « en cours » juste après ; un reclic coûterait une unité de quota).
     * Les textes eux-mêmes : `PlacementConflictTextTest`.
     */
    public function testASecondRequestBehindAQueuedRunDoesNotPromiseADelay(): void
    {
        [$token, $clubId, $seasonId, $userId] = $this->createClub();
        $this->seedPlaceableMatch($clubId, $seasonId);
        $this->scopeGucToClub($clubId);
        $this->em->persist(new MatchPlacementRun($clubId, $seasonId, $userId, new DateTimeImmutable));
        $this->em->flush();

        $lock = self::getContainer()->get(MatchPlacementLock::class);
        $held = $lock->acquire($clubId, 150);
        self::assertNotNull($held);

        try {
            $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('Un placement est déjà demandé pour ce club — il démarrera dès que possible.', $this->errorOf());
        } finally {
            $lock->release($clubId, $held);
        }
    }

    /**
     * Promesse (D8, run EN COURS) : une seconde demande pendant un placement en cours dit au plus
     * combien de temps il peut encore prendre — le délai restant du verrou, armé au budget du run,
     * arrondi à la minute supérieure (150 s → « 3 minutes au plus »).
     */
    public function testSecondRequestSaysHowLongTheRunningPlacementMayStillTake(): void
    {
        [$token, $clubId, $seasonId, $userId] = $this->createClub();
        $this->seedPlaceableMatch($clubId, $seasonId);
        $this->scopeGucToClub($clubId);
        $running = new MatchPlacementRun($clubId, $seasonId, $userId, new DateTimeImmutable('-1 minute'));
        $running->setStatus(MatchPlacementRunStatus::RUNNING)->setStartedAt(new DateTimeImmutable('-50 seconds'));
        $this->em->persist($running);
        $this->em->flush();

        $lock = self::getContainer()->get(MatchPlacementLock::class);
        $held = $lock->acquire($clubId, 150);
        self::assertNotNull($held);

        try {
            $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('Un placement est déjà en cours — il se terminera dans 3 minutes au plus.', $this->errorOf());
        } finally {
            $lock->release($clubId, $held);
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function seedPlaceableMatch(string $clubId, string $seasonId): void
    {
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');
    }

    private function errorOf(): ?string
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($data) && \is_string($data['error'] ?? null) ? $data['error'] : null;
    }

    /**
     * Pose la date simulée d'un club de démonstration DANS sa saison (sinon la saison n'est plus
     * résolue et le guichet ne balaie rien) : `MM-JJ` de la seconde année de la saison courante.
     */
    private function setDemoDate(string $clubId, string $monthDay): DateTimeImmutable
    {
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $simulated = new DateTimeImmutable(($year + 1) . '-' . $monthDay);
        $club = $this->em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertTrue($club->isDemo(), 'décor : la date simulée est réservée aux clubs de démonstration');
        $club->setSimulatedToday($simulated);
        $this->em->flush();

        return $simulated;
    }

    /**
     * POST (202) puis joue le VRAI handler sur le message enfilé (engine réel). Skip si
     * l'engine est indisponible (le run revient FAILED « n'a pas répondu »).
     */
    private function placeAndRunOrSkip(string $token, string $clubId): MatchPlacementRun
    {
        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(202);
        $runId = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['runId'];

        $message = $this->lastQueuedMessage();
        self::assertInstanceOf(PlaceMatchesMessage::class, $message);

        self::getContainer()->get(PlaceMatchesHandler::class)->__invoke($message);

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $run = $this->em->getRepository(MatchPlacementRun::class)->find($runId);
        self::assertInstanceOf(MatchPlacementRun::class, $run);
        if (MatchPlacementRunStatus::FAILED === $run->getStatus()
            && str_contains((string) ($run->getResultData()['error'] ?? ''), 'n\'a pas répondu')) {
            self::markTestSkipped('Engine not available');
        }

        return $run;
    }

    private function lastQueuedMessage(): ?PlaceMatchesMessage
    {
        $transport = self::getContainer()->get('messenger.transport.placement_in_memory');
        \assert($transport instanceof InMemoryTransport);
        $sent = $transport->getSent();
        if ([] === $sent) {
            return null;
        }
        $message = end($sent)->getMessage();

        return $message instanceof PlaceMatchesMessage ? $message : null;
    }

    private function queuedCount(): int
    {
        $transport = self::getContainer()->get('messenger.transport.placement_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return \count($transport->getSent());
    }

    private function decouverteMaxGenerations(): int
    {
        $plan = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => 'decouverte']);
        \assert($plan instanceof SubscriptionPlan);

        return (int) $plan->getMaxGenerations();
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [adminToken, clubId, seasonId, userId]
     */
    private function createClub(bool $settleSocle = true, bool $isDemo = true, int $credits = 0): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('BC Place ' . $uid);
        $club->setSlug('bc-place-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo($isDemo);
        $club->setOutputCreditsUsed($credits);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('place' . $uid . '@test.com');
        $user->setFirstName('Place');
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
        if ($settleSocle) {
            $this->settleSeasonPlan($season);
        }

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $club->getId(), $season->getId(), $user->getId()];
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

    private function createWindow(string $clubId, string $seasonId, string $venueId, int $day, string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($clubId);
        $window->setSeasonId($seasonId);
        $window->setVenueId($venueId);
        $window->setDayOfWeek($day);
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function createFixture(string $clubId, string $seasonId, string $teamId, string $date): Fixture
    {
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('D2-' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
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
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
