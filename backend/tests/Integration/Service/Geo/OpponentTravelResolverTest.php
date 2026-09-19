<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\ClubTravelCache;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbSalleResolver;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\Geo\TravelTimeCache;
use App\Service\SeasonResolver;
use App\Tests\Double\FrozenClock;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2-54 (amendement 2026-09-20) — le calcul AUTO des trajets adverses. Le trajet est une
 * CONSTANTE (siège du club → gymnase) mise en cache ({@see ClubTravelCache}) :
 * {@see OpponentTravelResolver::resolve} ne route QUE les paires MANQUANTES (gymnase d'un
 * lien apparié, sinon ville de l'annuaire). Ce service porte aussi la comptabilité du
 * compteur PARTAGÉ ({@see OpponentTravelResolver::accountManualChoice}).
 */
#[Group('integration')]
final class OpponentTravelResolverTest extends WebTestCase
{
    use TenantGucTrait;

    private const string OPPONENT_CODE = 'ARA0069123';

    private EntityManagerInterface $em;

    public function testResolveWarmsTheCacheFromSiegeToTheDirectoryForAnUnlinkedOpponent(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->seedDirectoryEntry(45.76, 4.86);

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved']);
        self::assertSame([], $result['unresolved']);
        self::assertSame(22, $this->cachedMinutes($club->getId(), 45.76, 4.86), '1320 s → 22 min (aller simple voiture) mis en cache');
    }

    public function testResolveWarmsTheCacheForALinkedGym(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->link($club, self::OPPONENT_CODE, 'SALLE A', 45.80, 5.00);

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved']);
        self::assertSame(22, $this->cachedMinutes($club->getId(), 45.80, 5.00), 'le gymnase du lien est routé et mis en cache');
    }

    public function testAnOpponentWithNoLocationYieldsNoPairToRoute(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent(); // aucun lien, aucun annuaire

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        // Ni lien, ni coordonnées d'annuaire → aucune paire à router (rien à calculer, ni
        // résolu ni « unresolved » : l'adversaire apparaîtra « sans gymnase » côté API).
        self::assertSame(0, $result['resolved']);
        self::assertSame([], $result['unresolved']);
        self::assertFalse($this->resolverWithIgn(1320)->hasUncomputedTravel($club->getId(), $season->getId()));
    }

    public function testACachedTravelIsNeverRecomputed(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->link($club, self::OPPONENT_CODE, 'SALLE A', 45.80, 5.00);
        // Le trajet est DÉJÀ en cache (une constante) : siège 45.70,4.90 → gymnase 45.80,5.00.
        self::getContainer()->get(TravelTimeCache::class)->store($club->getId(), IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, 45.80, 5.00, 88);

        $calls = 0;
        $result = $this->countingResolver($calls)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved'], 'la paire cachée compte comme résolue');
        self::assertSame(0, $calls, 'un trajet en cache ne déclenche AUCUN appel IGN');
        self::assertSame(88, $this->cachedMinutes($club->getId(), 45.80, 5.00), 'la valeur du cache est intacte');
    }

    /**
     * BCK-32 — cap dur : 61 adversaires géolocalisés (annuaire). {@see OpponentTravelResolver::MAX_OPPONENTS}
     * (60) en route 60, le 61ᵉ revient `unresolved` SANS aucun appel IGN pour l'excès.
     */
    public function testResolveCapsAtSixtyOpponentsWithoutNetworkForTheExcess(): void
    {
        [$club, $season] = $this->seedManyGeolocatedAwayOpponents(61);

        $calls = 0;
        $result = $this->countingResolver($calls)->resolve($club->getId(), $season->getId());

        self::assertSame(60, $result['resolved'], 'exactement 60 paires routées (cap dur)');
        self::assertCount(1, $result['unresolved'], 'le 61ᵉ revient non résolu');
        self::assertSame(60, $calls, 'aucun appel IGN pour l\'excès');
    }

    public function testHasUncomputedTravelIsTrueWithAMissAndFalseOnceCached(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->link($club, self::OPPONENT_CODE, 'SALLE A', 45.80, 5.00);

        $resolver = $this->resolverWithIgn(1320);
        self::assertTrue($resolver->hasUncomputedTravel($club->getId(), $season->getId()), 'une paire non cachée = du travail');

        $resolver->resolve($club->getId(), $season->getId());
        self::assertFalse($resolver->hasUncomputedTravel($club->getId(), $season->getId()), 'une fois tout en cache, plus rien à faire');
    }

    public function testWarmTravelComputesAndCachesOnePair(): void
    {
        [$club] = $this->seedClubWithAwayOpponent();

        $minutes = $this->resolverWithIgn(1320)->warmTravel($club->getId(), 45.80, 5.00);

        self::assertSame(22, $minutes);
        self::assertSame(22, $this->cachedMinutes($club->getId(), 45.80, 5.00));
    }

    /**
     * Un choix MANUAL référencé alimente la suggestion PARTAGÉE (+1) ; re-choisir le même
     * est neutre ; changer décrémente l'ancien ref et incrémente le nouveau.
     */
    public function testManualChoiceFeedsTheSharedSuggestionAndAChangeMovesTheCount(): void
    {
        [$club] = $this->seedClubWithAwayOpponent();
        $refA = '166900101';
        $refB = '166900102';
        $resolver = $this->resolverWithIgn(1320);
        $this->scopeGucToClub($club->getId());

        $resolver->accountManualChoice(self::OPPONENT_CODE, null, $refA, 45.76, 4.86);
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $refA), 'un choix référencé alimente la suggestion (+1)');

        $resolver->accountManualChoice(self::OPPONENT_CODE, $refA, $refA, 45.76, 4.86);
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $refA), 're-choisir le même gymnase ne double pas le compte');

        $resolver->accountManualChoice(self::OPPONENT_CODE, $refA, $refB, 45.60, 4.70);
        self::assertSame(0, $this->suggestionCount(self::OPPONENT_CODE, $refA), 'changer de choix décrémente l\'ancien ref');
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $refB), 'et incrémente le nouveau');
    }

    /** Un choix MANUAL SANS référence de salle reste tenant — il n'alimente PAS le partagé. */
    public function testManualChoiceWithoutRefFeedsNothingToTheSharedTable(): void
    {
        [$club] = $this->seedClubWithAwayOpponent();
        $this->scopeGucToClub($club->getId());
        $this->resolverWithIgn(1320)->accountManualChoice(self::OPPONENT_CODE, null, null, 45.76, 4.86);

        $shared = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code',
            ['code' => self::OPPONENT_CODE],
        );
        self::assertSame(0, $shared, 'un gymnase saisi sans référence FFBB ne partage rien (tenant seulement)');
    }

    /** Retirer un choix (nouveau ref null) décrémente l'ancien ref — le geste « retirer un lien MANUAL ». */
    public function testAccountingANullNewRefDecrementsThePreviousRef(): void
    {
        [$club] = $this->seedClubWithAwayOpponent();
        $ref = '166900201';
        $resolver = $this->resolverWithIgn(1320);
        $this->scopeGucToClub($club->getId());

        $resolver->accountManualChoice(self::OPPONENT_CODE, null, $ref, 45.76, 4.86);
        self::assertSame(1, $this->suggestionCount(self::OPPONENT_CODE, $ref));

        $resolver->accountManualChoice(self::OPPONENT_CODE, $ref, null, 0.0, 0.0);
        self::assertSame(0, $this->suggestionCount(self::OPPONENT_CODE, $ref), 'retirer le choix → −1 sur l\'ancien ref');
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeResolver(IgnRoutingClient $ign): OpponentTravelResolver
    {
        return new OpponentTravelResolver(
            $ign,
            self::getContainer()->get(OpponentVenueLinkRepository::class),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            $this->suggestionRepository(),
            $this->salleResolver(),
            self::getContainer()->get(ClubRepository::class),
            self::getContainer()->get(FixtureRepository::class),
            self::getContainer()->get(TravelTimeCache::class),
            new NullLogger,
        );
    }

    /** The resolver with an IGN returning a fixed duration (seconds) for every pair. */
    private function resolverWithIgn(int $durationSeconds, ?ClockInterface $clock = null): OpponentTravelResolver
    {
        $ign = new IgnRoutingClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => $durationSeconds])),
        ), $clock ?? new MockClock);

        return $this->makeResolver($ign);
    }

    /** A resolver whose IGN COUNTS every itinerary call (FrozenClock : no pacing sleep). */
    private function countingResolver(int &$calls): OpponentTravelResolver
    {
        $ign = new IgnRoutingClient(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) json_encode(['duration' => 600]));
        }), new FrozenClock);

        return $this->makeResolver($ign);
    }

    /**
     * A FfbbSalleResolver on a MockHttpClient: any `ffbbserver_salles` geo query returns a
     * fixed set of FEDERAL salles (the test refs), so a manual choice re-resolves its
     * federal label/coords server-side exactly like production — never the caller's text.
     */
    private function salleResolver(): FfbbSalleResolver
    {
        $salles = [];
        foreach (['166900101', '166900102', '166900201', '166900301'] as $numero) {
            $salles[] = [
                'numero' => $numero,
                'libelle' => 'GYMNASE FEDERAL ' . $numero,
                'cartographie' => ['ville' => 'Lyon', 'latitude' => 45.76, 'longitude' => 4.86],
                'commune' => ['codePostal' => '69001'],
            ];
        }
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use ($salles): MockResponse {
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';

            return new MockResponse((string) json_encode(['results' => [['hits' => str_contains($body, 'ffbbserver_salles') ? $salles : []]]]));
        });

        return new FfbbSalleResolver(new FfbbApiClient($mock, 'stub-token'));
    }

    private function suggestionRepository(): OpponentVenueSuggestionRepository
    {
        $repository = self::getContainer()->get(OpponentVenueSuggestionRepository::class);
        self::assertInstanceOf(OpponentVenueSuggestionRepository::class, $repository);

        return $repository;
    }

    private function suggestionCount(string $code, string $ref): ?int
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT chosen_by_count FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code AND venue_external_ref = :ref',
            ['code' => $code, 'ref' => $ref],
        );

        return false === $value ? null : (int) $value;
    }

    private function cachedMinutes(string $clubId, float $destLat, float $destLon): ?int
    {
        $this->scopeGucToClub($clubId);

        return self::getContainer()->get(TravelTimeCache::class)->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, $destLat, $destLon);
    }

    private function link(Club $club, string $code, string $fbiLabel, float $lat, float $lon): void
    {
        $this->scopeGucToClub($club->getId());
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        $link = (new OpponentVenueLink)
            ->setClubId($club->getId())->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)->setFbiLabelNorm($normalizer->normalize($fbiLabel))
            ->setVenueLabel($fbiLabel)->setLatitude($lat)->setLongitude($lon)
            ->setSource(OpponentVenueLinkSource::AUTO);
        $this->em->persist($link);
        $this->em->flush();
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedManyGeolocatedAwayOpponents(int $count): array
    {
        [$club, $season] = $this->seedClubSeason('cap');
        $this->scopeGucToClub($club->getId());
        for ($i = 0; $i < $count; ++$i) {
            $fixture = new Fixture;
            $fixture->setClubId($club->getId());
            $fixture->setSeasonId($season->getId());
            $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
            $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setOpponentLabel('Adverse cap ' . $i);
            $fixture->setOpponentOrganismeCode(\sprintf('ARA0069C%03d', $i));
            $this->em->persist($fixture);
        }
        $this->em->flush();

        // Directory entries are GLOBAL (no club) → seeded without a GUC. Coordonnées
        // DISTINCTES par adversaire (sinon la dédup par coordonnées réduirait les paires).
        for ($i = 0; $i < $count; ++$i) {
            $entry = new OpponentDirectoryEntry(\sprintf('ARA0069C%03d', $i), 'Adverse cap ' . $i, OpponentLocationPrecision::CITY);
            $entry->setLatitude(45.0 + $i / 1000)->setLongitude(4.0 + $i / 1000)->setCity('Lyon');
            $this->em->persist($entry);
        }
        $this->em->flush();

        return [$club, $season];
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubWithAwayOpponent(): array
    {
        [$club, $season] = $this->seedClubSeason('trajet');
        $this->scopeGucToClub($club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel('Adverse Trajet');
        $fixture->setOpponentOrganismeCode(self::OPPONENT_CODE);
        $this->em->persist($fixture);
        $this->em->flush();

        return [$club, $season];
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubSeason(string $suffix): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club ' . $suffix . ' ' . $uid);
        $club->setSlug('club-' . $suffix . '-' . $uid);
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
        $this->em->flush();

        return [$club, $season];
    }

    private function seedDirectoryEntry(float $lat, float $lon): void
    {
        $entry = new OpponentDirectoryEntry(self::OPPONENT_CODE, 'Adverse Trajet', OpponentLocationPrecision::CITY);
        $entry->setLatitude($lat)->setLongitude($lon)->setCity('Lyon');
        $this->em->persist($entry);
        $this->em->flush();
    }
}
