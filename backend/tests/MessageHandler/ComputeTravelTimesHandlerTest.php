<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\SeasonStatus;
use App\Enum\TravelComputeScope;
use App\Message\ComputeTravelTimesMessage;
use App\MessageHandler\ComputeTravelTimesHandler;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\FfbbSalleResolver;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelFailureStore;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\Geo\TravelTimeCache;
use App\Service\Geo\VenueTravelTimeAutofillService;
use App\Service\OpponentPairingKey;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Service\TravelComputeLock;
use App\Service\TravelProgressPublisher;
use App\Tests\Double\IgnRoutingHttpClientStub;
use App\Tests\Double\SteppingClock;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * C6 — NR TENANT du worker de calcul des trajets (axe §7.1 « tenant isolation »). Le
 * handler tourne SANS requête HTTP : aucun GUC posé par le listener. Il DOIT scoper la
 * connexion au club du message (et le clear après), sinon un calcul lancé pour un club
 * lirait/écrirait les trajets d'un autre. Gardé BLOQUANT (step nommé de `blocking-tests`
 * dans `ci.yml` + ligne dans `docs/testing/blocking-tests.md`).
 */
#[Group('phase1')]
#[Group('integration')]
final class ComputeTravelTimesHandlerTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testTheComputationIsScopedToTheMessageClubAndNeverTouchesAnother(): void
    {
        // Club A et Club B : chacun un adversaire AWAY localisable (annuaire), trajet non
        // encore en cache. Un calcul lancé pour A ne doit JAMAIS remplir le cache de B.
        [$clubA, $seasonA] = $this->seedClubWithGeolocatedAwayOpponent('AAA');
        [$clubB] = $this->seedClubWithGeolocatedAwayOpponent('BBB');
        $this->em->clear();

        // Le handler pose son PROPRE GUC (aucune requête HTTP ici) : on ne le pré-scope pas.
        $this->handler()->__invoke(new ComputeTravelTimesMessage($clubA->getId(), $seasonA->getId(), TravelComputeScope::OPPONENTS));

        $cache = $this->cache();
        // A : le trajet siège(45.70,4.90) → lieu de l'annuaire (45.76,4.86) est en cache (stub IGN).
        $this->scopeGucToClub($clubA->getId());
        self::assertSame(
            IgnRoutingHttpClientStub::DRIVING_MINUTES,
            $cache->lookup($clubA->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, 45.76, 4.86),
            'le trajet de A est calculé et mis en cache',
        );

        // B : son cache reste VIDE (jamais touché par le calcul de A — frontière tenant).
        $this->scopeGucToClub($clubB->getId());
        self::assertNull(
            $cache->lookup($clubB->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, 45.76, 4.86),
            'le cache d\'un autre club n\'est jamais rempli',
        );
    }

    public function testASecondComputationIsRefusedWhileTheLockIsHeld(): void
    {
        [$clubA, $seasonA] = $this->seedClubWithGeolocatedAwayOpponent('LOCK');
        // Un calcul tient déjà le verrou de ce club : le message repart en file (Recoverable).
        $token = self::getContainer()->get(TravelComputeLock::class)->acquire($clubA->getId(), 60);
        self::assertIsString($token);

        try {
            $this->expectException(RecoverableMessageHandlingException::class);
            $this->handler()->__invoke(new ComputeTravelTimesMessage($clubA->getId(), $seasonA->getId(), TravelComputeScope::OPPONENTS));
        } finally {
            self::getContainer()->get(TravelComputeLock::class)->release($clubA->getId(), $token);
        }
    }

    /**
     * D8 (retours Nico, BCCL 2026-10-27) — « relance manuelle, pas d'auto-chaînage ». Un run qui
     * LAISSE des paires « en attente de relance » (ici le budget worker s'épuise avant la fin) ne
     * doit RE-DISPATCHER aucun calcul : le gestionnaire relance lui-même. Le transport
     * `travel_in_memory` (où part tout {@see ComputeTravelTimesMessage}) reste VIDE après le run.
     */
    public function testARunLeavingAwaitingRetryDispatchesNoFollowUp(): void
    {
        [$club, $season] = $this->seedClubWithManyGeolocatedAwayOpponents('CHAIN', 3);
        $this->em->clear();
        $this->travelTransport()->reset();

        // Résolveur dont le budget MORD tôt (SteppingClock) : au moins une paire reste « en attente
        // de relance » — la condition exacte de D8.
        $this->budgetBitingHandler()->__invoke(new ComputeTravelTimesMessage($club->getId(), $season->getId(), TravelComputeScope::OPPONENTS));

        self::assertCount(0, $this->travelTransport()->getSent(), 'un run incomplet ne ré-enfile aucun calcul (pas d\'auto-chaînage)');
        $reasons = self::getContainer()->get(OpponentTravelFailureStore::class)->reasonsFor($club->getId(), $season->getId());
        self::assertContains(OpponentTravelFailureStore::REASON_AWAITING_RETRY, array_values($reasons), 'préalable : le run a bien laissé des paires « en attente de relance »');

        self::getContainer()->get(OpponentTravelFailureStore::class)->record($club->getId(), $season->getId(), []); // DEL ciblé
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function handler(): ComputeTravelTimesHandler
    {
        return self::getContainer()->get(ComputeTravelTimesHandler::class);
    }

    private function travelTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.travel_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    /**
     * Le vrai handler, mais câblé sur un résolveur dont le client IGN avance l'horloge à grands pas
     * ({@see SteppingClock}) : le budget de mur du worker s'épuise après la 1re paire, les autres
     * repartent « en attente de relance » sans vrai réseau. Tout le reste vient du conteneur.
     */
    private function budgetBitingHandler(): ComputeTravelTimesHandler
    {
        $ign = new IgnRoutingClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => 600, 'distance' => 3300])),
        ), new SteppingClock(null, 200));

        $resolver = new OpponentTravelResolver(
            $ign,
            self::getContainer()->get(OpponentVenueLinkRepository::class),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            self::getContainer()->get(OpponentVenueSuggestionRepository::class),
            self::getContainer()->get(FfbbSalleResolver::class),
            self::getContainer()->get(ClubRepository::class),
            self::getContainer()->get(FixtureRepository::class),
            self::getContainer()->get(TravelTimeCache::class),
            new OpponentPairingKey,
            self::getContainer()->get(OpponentTravelFailureStore::class),
            new NullLogger,
        );

        return new ComputeTravelTimesHandler(
            self::getContainer()->get(TenantConnectionContext::class),
            self::getContainer()->get(TravelComputeLock::class),
            self::getContainer()->get(TravelProgressPublisher::class),
            $resolver,
            self::getContainer()->get(VenueTravelTimeAutofillService::class),
            new NullLogger,
        );
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubWithManyGeolocatedAwayOpponents(string $suffix, int $count): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club worker ' . $suffix . ' ' . $uid);
        $club->setSlug('club-worker-' . strtolower($suffix) . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        for ($i = 0; $i < $count; ++$i) {
            $code = \sprintf('ARA0069%s%02d', $suffix, $i);
            $fixture = new Fixture;
            $fixture->setClubId($club->getId());
            $fixture->setSeasonId($season->getId());
            $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
            $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setOpponentLabel('Adverse ' . $suffix . ' ' . $i);
            $fixture->setOpponentOrganismeCode($code);
            $this->em->persist($fixture);
        }
        $this->em->flush();

        // Annuaire GLOBAL (hors tenant) → coordonnées DISTINCTES par adversaire (sinon la dédup
        // par coordonnées réduirait les paires sous le seuil).
        for ($i = 0; $i < $count; ++$i) {
            $code = \sprintf('ARA0069%s%02d', $suffix, $i);
            $entry = new OpponentDirectoryEntry($code, 'Adverse ' . $suffix . ' ' . $i, OpponentLocationPrecision::CITY);
            $entry->setLatitude(45.0 + $i / 1000)->setLongitude(4.0 + $i / 1000)->setCity('Lyon');
            $this->em->persist($entry);
        }
        $this->em->flush();

        return [$club, $season];
    }

    private function cache(): TravelTimeCache
    {
        return self::getContainer()->get(TravelTimeCache::class);
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubWithGeolocatedAwayOpponent(string $suffix): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club worker ' . $suffix . ' ' . $uid);
        $club->setSlug('club-worker-' . strtolower($suffix) . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel('Adverse ' . $suffix);
        $fixture->setOpponentOrganismeCode('ARA0069' . $suffix);
        $this->em->persist($fixture);
        $this->em->flush();

        // L'annuaire est GLOBAL (hors tenant) → seedé sans GUC.
        $entry = new OpponentDirectoryEntry('ARA0069' . $suffix, 'Adverse ' . $suffix, OpponentLocationPrecision::CITY);
        $entry->setLatitude(45.76)->setLongitude(4.86)->setCity('Lyon');
        $this->em->persist($entry);
        $this->em->flush();

        return [$club, $season];
    }
}
