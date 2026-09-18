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
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentVenueAutoLocator;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2-54 PR-2b — l'auto-localisation d'un adversaire depuis le gymnase de salle ÉCRIT
 * dans son fichier FBI. Le cœur : égalité STRICTE du libellé fédéral, un hit UNIQUE,
 * une surcharge de trajet TENANT source AUTO portant le gymnase FÉDÉRAL — jamais le
 * texte du fichier, jamais une écriture au partagé, jamais un choix MANUAL touché.
 */
#[Group('integration')]
final class OpponentVenueAutoLocatorTest extends WebTestCase
{
    use TenantGucTrait;

    private const string CODE = 'ARA0069555';
    private const string POSTAL = '69100';

    private EntityManagerInterface $em;

    public function testAUniqueFederalSalleLocatesTheTeamAsAnAutoOverride(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Mateo - 1');
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());

        self::assertSame(1, $result['located']);
        self::assertSame(0, $result['ambiguous']);
        self::assertSame(0, $result['unmatched']);

        $row = $this->teamRow($club, $season, 'adverse mateo 1');
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(OpponentTravelSource::AUTO, $row->getSource());
        self::assertSame('166926604', $row->getOverrideVenueExternalRef());
        self::assertSame('GYMNASE MATEO', $row->getOverrideVenueLabel(), 'le libellé écrit est le FÉDÉRAL, jamais celui du fichier');
        self::assertSame(22, $row->getTravelMinutes(), '1320 s → 22 min (aller simple)');
        self::assertTrue($row->isTeamScoped());

        // Aucune écriture au partagé : une auto-localisation n'est pas un CHOIX.
        self::assertSame(0, $this->sharedRows());
    }

    /**
     * BCK-32 — budget de mur épuisé (deadline dans le passé) : le groupe qui SE serait
     * localisé est compté `skipped` sans aucun appel réseau — même canal que le cap
     * {@see OpponentVenueAutoLocator::MAX_GROUPS}. Best-effort (relancer pour finir).
     */
    public function testLocateStopsAtTheWallClockDeadline(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Mateo - 1');
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId(), 1.0);

        self::assertSame(0, $result['located'], 'rien localisé passé le budget de mur');
        self::assertSame(1, $result['skipped'], 'le groupe restant est compté skipped');
        self::assertNull($this->teamRow($club, $season, 'adverse mateo 1'), 'aucune surcharge écrite');
    }

    public function testNoStrictMatchLeavesTheTeamUnlocated(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE INCONNU', 'Adverse X - 1');
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['unmatched']);
        self::assertNull($this->teamRow($club, $season, 'adverse x 1'));
    }

    /** « MATEO » ≠ « GYMNASE MATEO » (invariant d'égalité stricte : jamais une inclusion). */
    public function testAStrictlyDifferentLabelIsNeverMatched(): void
    {
        [$club, $season] = $this->seedClubWithAway('MATEO', 'Adverse Mateo - 1');
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['unmatched'], '« MATEO » n\'égale pas STRICTEMENT « GYMNASE MATEO »');
    }

    /** Deux salles fédérales de MÊME libellé pour le fichier = ≥ 2 hits → rien (non localisé). */
    public function testTwoFederalSallesWithTheSameLabelYieldNothing(): void
    {
        [$club, $season] = $this->seedClubWithAway('SALLE DOUBLE', 'Adverse Double - 1');
        $this->seedDirectory();

        $result = $this->locator([
            $this->salle('100000001', 'SALLE DOUBLE', 45.70, 4.80),
            $this->salle('100000002', 'SALLE DOUBLE', 45.71, 4.81),
        ])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['unmatched']);
        self::assertNull($this->teamRow($club, $season, 'adverse double 1'));
    }

    /** FFBB muet (aucune salle rendue) → aucune localisation, jamais une erreur. */
    public function testAMuteFfbbLeavesEverythingUnlocated(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Muet - 1');
        $this->seedDirectory();

        $result = $this->locator([])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['unmatched']);
    }

    /**
     * Deux libellés de fichier DISTINCTS pour la MÊME équipe désignant deux salles
     * fédérales différentes = AMBIGU → rien n'est posé.
     */
    public function testTwoDistinctFileLabelsPointingToTwoSallesIsAmbiguous(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE A', 'Adverse Ambigu - 1');
        // Une seconde rencontre de la MÊME équipe adverse, libellé de salle DIFFÉRENT.
        $this->awayFixture($club, $season, self::CODE, 'Adverse Ambigu - 1', 'GYMNASE B');
        $this->seedDirectory();

        $result = $this->locator([
            $this->salle('100000001', 'GYMNASE A', 45.70, 4.80),
            $this->salle('100000002', 'GYMNASE B', 45.71, 4.81),
        ])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['ambiguous']);
        self::assertNull($this->teamRow($club, $season, 'adverse ambigu 1'));
    }

    /** Une ligne équipe MANUAL est SOUVERAINE : jamais recalculée, comptée `skipped`. */
    public function testAnExistingManualTeamRowIsNeverTouched(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Manuel - 1');
        $this->seedDirectory();

        $this->scopeGucToClub($club->getId());
        $manual = (new OpponentTravel)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setOpponentOrganismeCode(self::CODE)
            ->setOpponentTeamKey('adverse manuel 1')
            ->setSource(OpponentTravelSource::MANUAL)->setTravelMinutes(5)
            ->setOverrideVenueLabel('Mon vrai gymnase')->setOverrideVenueExternalRef('999999999')
            ->setOverrideLatitude(45.5)->setOverrideLongitude(4.5)->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($manual);
        $this->em->flush();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['skipped'], 'la ligne MANUAL est sautée');

        $this->em->clear();
        $row = $this->teamRow($club, $season, 'adverse manuel 1');
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(OpponentTravelSource::MANUAL, $row->getSource(), 'le choix MANUAL survit intact');
        self::assertSame('Mon vrai gymnase', $row->getOverrideVenueLabel());
        self::assertSame(5, $row->getTravelMinutes());
    }

    /** Une ligne équipe AUTO existante est RE-vérifiée (minutes recalculées) — décision A3/PR-2b. */
    public function testAnExistingAutoTeamRowIsReLocated(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Auto - 1');
        $this->seedDirectory();

        $this->scopeGucToClub($club->getId());
        $auto = (new OpponentTravel)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setOpponentOrganismeCode(self::CODE)
            ->setOpponentTeamKey('adverse auto 1')
            ->setSource(OpponentTravelSource::AUTO)->setTravelMinutes(99)
            ->setOverrideVenueLabel('GYMNASE MATEO')->setOverrideVenueExternalRef('166926604')
            ->setOverrideLatitude(45.78)->setOverrideLongitude(4.88)->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($auto);
        $this->em->flush();

        $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)], 600)->locate($club->getId(), $season->getId());

        $this->em->clear();
        $row = $this->teamRow($club, $season, 'adverse auto 1');
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(OpponentTravelSource::AUTO, $row->getSource());
        self::assertSame(10, $row->getTravelMinutes(), '600 s → 10 min : la ligne AUTO a été recalculée, pas laissée à 99');
    }

    /**
     * Borne de fan-out fédéral : au-delà de 200 groupes, la passe s'arrête proprement —
     * au plus 200 recherches de salle, les groupes en excès comptés `skipped`, aucun
     * appel réseau au-delà (un simple upload d'import ne déclenche jamais un fan-out illimité).
     */
    public function testTheGroupCapBoundsTheFederalFanOut(): void
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club cap ' . $uid);
        $club->setSlug('club-auto-cap-' . $uid);
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

        // 201 adversaires AWAY distincts, chacun localisable (annuaire + libellé de fichier).
        for ($i = 0; $i < 201; ++$i) {
            $code = \sprintf('ARA006C%04d', $i);
            $this->scopeGucToClub($club->getId());
            $fixture = new Fixture;
            $fixture->setClubId($club->getId());
            $fixture->setSeasonId($season->getId());
            $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
            $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setOpponentLabel('Adverse cap ' . $i);
            $fixture->setOpponentOrganismeCode($code);
            $fixture->setFbiVenueLabel('GYMNASE CAP');
            $this->em->persist($fixture);
            $entry = new OpponentDirectoryEntry($code, 'Adverse cap ' . $i, OpponentLocationPrecision::CITY);
            $entry->setCity('Villeurbanne')->setPostalCode(self::POSTAL)->setLatitude(45.78)->setLongitude(4.88);
            $this->em->persist($entry);
        }
        $this->em->flush();

        $calls = 0;
        $result = $this->countingLocator($calls)->locate($club->getId(), $season->getId());

        self::assertLessThanOrEqual(200, $calls, 'au plus 200 recherches de salle — le cap borne le fan-out fédéral');
        self::assertSame(1, $result['skipped'], 'le 201ᵉ groupe est sauté (aucun réseau)');
        self::assertSame(200, $result['located'] + $result['ambiguous'] + $result['unmatched'], '200 groupes traités au total');
    }

    /**
     * Le libellé normalisé (teamKey) est tronqué à 180 (colonne VARCHAR(180)) avant
     * écriture : un adversaire au libellé très long est localisé sans erreur DB avalée.
     */
    public function testTheTeamKeyIsTruncatedToTheColumnLength(): void
    {
        // 180 ligatures « œ » tiennent dans `opponent_label` (VARCHAR(180), en CHARACTÈRES)
        // mais la translittération ASCII les DILATE (« œ » → « oe ») : le libellé normalisé
        // fait 360 caractères → il DOIT être tronqué à 180 avant écriture.
        $longLabel = str_repeat("\u{0153}", 180);
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', $longLabel);
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());
        self::assertSame(1, $result['located']);

        $this->scopeGucToClub($club->getId());
        $repository = self::getContainer()->get(OpponentTravelRepository::class);
        self::assertInstanceOf(OpponentTravelRepository::class, $repository);
        $teamRows = array_values(array_filter(
            $repository->findBySeason($season->getId()),
            static fn (OpponentTravel $row): bool => null !== $row->getOpponentTeamKey(),
        ));
        self::assertCount(1, $teamRows);
        $teamKey = (string) $teamRows[0]->getOpponentTeamKey();
        self::assertSame(180, mb_strlen($teamKey), 'le teamKey est tronqué à la longueur de colonne (180)');
        self::assertSame(str_repeat('oe', 90), $teamKey);
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A locator whose FFBB client returns empty salles but COUNTS every salle search,
     * so the fan-out cap can be falsified.
     */
    private function countingLocator(int &$calls): OpponentVenueAutoLocator
    {
        $ffbb = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            if (str_contains($url, 'api.ffbb.com')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 'stub-token']]));
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            if (str_contains($body, 'ffbbserver_salles')) {
                ++$calls;
            }

            return new MockResponse((string) json_encode(['results' => [['hits' => []]]]));
        });
        $ign = new IgnRoutingClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => 1320])),
        ), new MockClock);

        return new OpponentVenueAutoLocator(
            $this->em,
            self::getContainer()->get(FixtureRepository::class),
            self::getContainer()->get(OpponentTravelRepository::class),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            new FfbbApiClient($ffbb, 'stub-token'),
            $ign,
            self::getContainer()->get(VenueLabelNormalizer::class),
            self::getContainer()->get(ClubRepository::class),
            new NullLogger,
            new MockClock,
        );
    }

    /**
     * @param list<array<string, mixed>> $salles the federal salles any ffbbserver_salles query returns
     */
    private function locator(array $salles, int $ignSeconds = 1320): OpponentVenueAutoLocator
    {
        $ffbb = new MockHttpClient(static function (string $method, string $url, array $options) use ($salles): MockResponse {
            if (str_contains($url, 'api.ffbb.com')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 'stub-token']]));
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            $hits = str_contains($body, 'ffbbserver_salles') ? $salles : [];

            return new MockResponse((string) json_encode(['results' => [['hits' => $hits]]]));
        });
        $ign = new IgnRoutingClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => $ignSeconds])),
        ), new MockClock);

        return new OpponentVenueAutoLocator(
            $this->em,
            self::getContainer()->get(FixtureRepository::class),
            self::getContainer()->get(OpponentTravelRepository::class),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            new FfbbApiClient($ffbb, 'stub-token'),
            $ign,
            self::getContainer()->get(VenueLabelNormalizer::class),
            self::getContainer()->get(ClubRepository::class),
            new NullLogger,
            new MockClock,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function salle(string $numero, string $libelle, float $lat, float $lon): array
    {
        return ['numero' => $numero, 'libelle' => $libelle, 'cartographie' => ['ville' => 'Villeurbanne', 'latitude' => $lat, 'longitude' => $lon], 'commune' => ['codePostal' => self::POSTAL]];
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubWithAway(string $fbiVenueLabel, string $opponentLabel): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club auto ' . $uid);
        $club->setSlug('club-auto-' . $uid);
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

        $this->awayFixture($club, $season, self::CODE, $opponentLabel, $fbiVenueLabel);

        return [$club, $season];
    }

    private function awayFixture(Club $club, Season $season, string $code, string $opponentLabel, string $fbiVenueLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponentLabel);
        $fixture->setOpponentOrganismeCode($code);
        $fixture->setFbiVenueLabel($fbiVenueLabel);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    private function seedDirectory(): void
    {
        $entry = new OpponentDirectoryEntry(self::CODE, 'Adverse', OpponentLocationPrecision::CITY);
        $entry->setCity('Villeurbanne')->setPostalCode(self::POSTAL)->setLatitude(45.78)->setLongitude(4.88);
        $this->em->persist($entry);
        $this->em->flush();
    }

    private function teamRow(Club $club, Season $season, string $teamKey): ?OpponentTravel
    {
        $this->scopeGucToClub($club->getId());
        $repository = self::getContainer()->get(OpponentTravelRepository::class);
        self::assertInstanceOf(OpponentTravelRepository::class, $repository);

        return $repository->findOneByCode($season->getId(), self::CODE, $teamKey);
    }

    private function sharedRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code', ['code' => self::CODE]);
    }
}
