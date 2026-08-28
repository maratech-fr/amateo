<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\SeasonResolver;
use App\Tests\Double\SteppingClock;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2-54 RMM-9 PR-3 — le calcul AUTO des trajets adverses depuis le siège du club.
 * Best-effort (IGN muet → minutes null) et, LE cœur du patron, une valeur MANUAL
 * n'est JAMAIS écrasée par la passe AUTO.
 */
#[Group('integration')]
final class OpponentTravelResolverTest extends WebTestCase
{
    use TenantGucTrait;

    private const string OPPONENT_CODE = 'ARA0069123';

    private EntityManagerInterface $em;

    public function testAutoComputesTravelFromTheClubSiegeToTheDirectoryLocation(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->seedDirectoryEntry(45.76, 4.86);

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved']);
        self::assertSame([], $result['unresolved']);

        $this->scopeGucToClub($club->getId());
        $row = $this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(22, $row->getTravelMinutes(), '1320 s → 22 min (aller simple voiture)');
        self::assertSame(OpponentTravelSource::AUTO, $row->getSource());
        self::assertFalse($row->hasOverride());
    }

    public function testManualRowIsNeverOverwrittenByTheAutoPass(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->seedDirectoryEntry(45.76, 4.86);

        $this->scopeGucToClub($club->getId());
        $manual = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode(self::OPPONENT_CODE)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setTravelMinutes(7)
            ->setOverrideVenueLabel('Le vrai gymnase')
            ->setOverrideLatitude(45.5)
            ->setOverrideLongitude(4.5)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($manual);
        $this->em->flush();

        $result = $this->resolverWithIgn(9999)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['skippedManual']);
        self::assertSame(0, $result['resolved']);

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $row = $this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(7, $row->getTravelMinutes(), 'la valeur MANUAL survit à la passe AUTO');
        self::assertSame(OpponentTravelSource::MANUAL, $row->getSource());
        self::assertSame('Le vrai gymnase', $row->getOverrideVenueLabel());
    }

    public function testAnOpponentWithNoDirectoryLocationComesBackUnresolved(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        // No directory entry seeded → nothing to route to.

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(0, $result['resolved']);
        self::assertSame([self::OPPONENT_CODE], $result['unresolved']);

        $this->scopeGucToClub($club->getId());
        self::assertNull($this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE));
    }

    /**
     * BCK-22 régression : un code que le budget n'a JAMAIS tenté ne doit pas être
     * écrasé — une bonne valeur AUTO déjà en base survit, le code revient seulement
     * `unresolved` (la relance le résoudra). Avant le correctif, l'absence de la clé
     * dans `minutes` valait `setTravelMinutes(null)` et détruisait la valeur.
     */
    public function testABudgetSkippedCodeKeepsItsExistingAutoValue(): void
    {
        $club = new Club;
        $club->setName('Club budget ' . uniqid('', true));
        $club->setSlug('club-budget-opp-' . uniqid('', true));
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

        // 9 adversaires AWAY géolocalisés (> une fenêtre de 8), chacun avec une bonne
        // ligne AUTO déjà en base (99 min). Aucun MANUAL, aucun sans localisation :
        // le SEUL motif possible d'`unresolved` sera donc le budget.
        for ($i = 0; $i < 9; ++$i) {
            $code = \sprintf('ARA00699%03d', $i);

            $fixture = new Fixture;
            $fixture->setClubId($club->getId());
            $fixture->setSeasonId($season->getId());
            $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
            $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setOpponentLabel('Adverse ' . $i);
            $fixture->setOpponentOrganismeCode($code);
            $this->em->persist($fixture);

            $existing = (new OpponentTravel)
                ->setClubId($club->getId())
                ->setSeasonId($season->getId())
                ->setOpponentOrganismeCode($code)
                ->setSource(OpponentTravelSource::AUTO)
                ->setTravelMinutes(99)
                ->setResolvedAt(new DateTimeImmutable);
            $this->em->persist($existing);
        }
        $this->em->flush();

        // Directory entries are GLOBAL (no club) → seeded without a GUC.
        for ($i = 0; $i < 9; ++$i) {
            $entry = new OpponentDirectoryEntry(\sprintf('ARA00699%03d', $i), 'Adverse ' . $i, OpponentLocationPrecision::CITY);
            $entry->setLatitude(45.76)->setLongitude(4.86)->setCity('Lyon');
            $this->em->persist($entry);
        }
        $this->em->flush();

        // Step 100 s ≫ the 30 s budget : after window 0 (8 codes) the next clock read
        // is past the deadline, so the 9th code's window is never dispatched.
        $result = $this->resolverWithIgn(600, new SteppingClock(stepSeconds: 100))->resolve($club->getId(), $season->getId());

        self::assertNotSame([], $result['unresolved'], 'le budget doit avoir coupé au moins un code');

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        foreach ($result['unresolved'] as $code) {
            $row = $this->travelRepository()->findOneByCode($season->getId(), $code);
            self::assertInstanceOf(OpponentTravel::class, $row, "la ligne du code budget-coupé {$code} existe toujours");
            self::assertSame(99, $row->getTravelMinutes(), "la bonne valeur AUTO survit au code budget-coupé {$code}");
            self::assertSame(OpponentTravelSource::AUTO, $row->getSource());
        }
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The real resolver, but its IGN client rides a MockHttpClient returning a
     * fixed duration (seconds) for every pair. The clock defaults to a still
     * MockClock (budget never bites); a SteppingClock forces the budget to expire.
     */
    private function resolverWithIgn(int $durationSeconds, ?ClockInterface $clock = null): OpponentTravelResolver
    {
        $ign = new IgnRoutingClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => $durationSeconds])),
        ), $clock ?? new MockClock);

        return new OpponentTravelResolver(
            $this->em,
            $ign,
            $this->travelRepository(),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            self::getContainer()->get(ClubRepository::class),
            self::getContainer()->get(FixtureRepository::class),
        );
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubWithAwayOpponent(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club trajet ' . $uid);
        $club->setSlug('club-trajet-' . $uid);
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
        $fixture->setOpponentLabel('Adverse Trajet');
        $fixture->setOpponentOrganismeCode(self::OPPONENT_CODE);
        $this->em->persist($fixture);
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

    private function travelRepository(): OpponentTravelRepository
    {
        $repository = self::getContainer()->get(OpponentTravelRepository::class);
        self::assertInstanceOf(OpponentTravelRepository::class, $repository);

        return $repository;
    }
}
