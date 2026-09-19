<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\VenueLabelNormalizer;
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
 * P2-54 (amendement 2026-09-20) — l'auto-appariement d'un LIBELLÉ de salle FBI vers un
 * gymnase FÉDÉRAL ({@see OpponentVenueLink}, grain `(code, libellé FBI normalisé)`). Le
 * cœur : égalité STRICTE du libellé, un hit UNIQUE, un lien source AUTO portant le gymnase
 * FÉDÉRAL (jamais le texte du fichier), aucune écriture au partagé, aucun trajet calculé
 * ici (le trajet est asynchrone), aucun choix MANUAL touché.
 */
#[Group('integration')]
final class OpponentVenueAutoLocatorTest extends WebTestCase
{
    use TenantGucTrait;

    private const string CODE = 'ARA0069555';
    private const string POSTAL = '69100';

    private EntityManagerInterface $em;

    public function testAUniqueFederalSalleLinksTheLabel(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Mateo - 1');
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());

        self::assertSame(1, $result['located']);
        self::assertSame(0, $result['ambiguous']);
        self::assertSame(0, $result['unmatched']);

        $link = $this->link($club, 'gymnase mateo');
        self::assertInstanceOf(OpponentVenueLink::class, $link);
        self::assertSame(OpponentVenueLinkSource::AUTO, $link->getSource());
        self::assertSame('166926604', $link->getVenueExternalRef());
        self::assertSame('GYMNASE MATEO', $link->getVenueLabel(), 'le libellé du gymnase écrit est le FÉDÉRAL, jamais celui du fichier');
        self::assertSame(45.78, $link->getLatitude());
        self::assertSame(4.88, $link->getLongitude());

        // Aucune écriture au partagé : une auto-localisation n'est pas un CHOIX.
        self::assertSame(0, $this->sharedRows());
    }

    /**
     * BCK-32 — budget de mur épuisé (deadline dans le passé) : le libellé qui SE serait
     * apparié est compté `skipped` sans aucun appel réseau. Best-effort (relancer pour finir).
     */
    public function testLocateStopsAtTheWallClockDeadline(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Mateo - 1');
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId(), 1.0);

        self::assertSame(0, $result['located'], 'rien apparié passé le budget de mur');
        self::assertSame(1, $result['skipped'], 'le libellé restant est compté skipped');
        self::assertNull($this->link($club, 'gymnase mateo'), 'aucun lien écrit');
    }

    public function testNoStrictMatchLeavesTheLabelUnpaired(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE INCONNU', 'Adverse X - 1');
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['unmatched']);
        self::assertNull($this->link($club, 'gymnase inconnu'));
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

    /** Un libellé matchant DEUX salles fédérales = ≥ 2 hits → ambigu, rien n'est posé. */
    public function testALabelMatchingTwoFederalSallesIsAmbiguous(): void
    {
        [$club, $season] = $this->seedClubWithAway('SALLE DOUBLE', 'Adverse Double - 1');
        $this->seedDirectory();

        $result = $this->locator([
            $this->salle('100000001', 'SALLE DOUBLE', 45.70, 4.80),
            $this->salle('100000002', 'SALLE DOUBLE', 45.71, 4.81),
        ])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['ambiguous']);
        self::assertNull($this->link($club, 'salle double'));
    }

    /** FFBB muet (aucune salle rendue) → aucun appariement, jamais une erreur. */
    public function testAMuteFfbbLeavesEverythingUnpaired(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Muet - 1');
        $this->seedDirectory();

        $result = $this->locator([])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['unmatched']);
    }

    /**
     * Deux LIBELLÉS de fichier DISTINCTS du même club adverse, chacun matchant une salle
     * unique, donnent DEUX liens indépendants (le gymnase se rattache au libellé, pas à
     * l'équipe : deux salles = deux liens).
     */
    public function testTwoDistinctLabelsEachYieldTheirOwnLink(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE A', 'Adverse - 1');
        $this->awayFixture($club, $season, self::CODE, 'Adverse - 2', 'GYMNASE B');
        $this->seedDirectory();

        $result = $this->locator([
            $this->salle('100000001', 'GYMNASE A', 45.70, 4.80),
            $this->salle('100000002', 'GYMNASE B', 45.71, 4.81),
        ])->locate($club->getId(), $season->getId());

        self::assertSame(2, $result['located'], 'deux libellés distincts → deux liens');
        self::assertSame('100000001', $this->link($club, 'gymnase a')?->getVenueExternalRef());
        self::assertSame('100000002', $this->link($club, 'gymnase b')?->getVenueExternalRef());
    }

    /** Un lien MANUAL est SOUVERAIN : jamais recalculé, compté `skipped`. */
    public function testAnExistingManualLinkIsNeverTouched(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Manuel - 1');
        $this->seedDirectory();

        $this->scopeGucToClub($club->getId());
        $manual = (new OpponentVenueLink)
            ->setClubId($club->getId())->setOpponentOrganismeCode(self::CODE)
            ->setFbiLabel('GYMNASE MATEO')->setFbiLabelNorm('gymnase mateo')
            ->setVenueExternalRef('999999999')->setVenueLabel('Mon vrai gymnase')
            ->setLatitude(45.5)->setLongitude(4.5)->setSource(OpponentVenueLinkSource::MANUAL);
        $this->em->persist($manual);
        $this->em->flush();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());

        self::assertSame(0, $result['located']);
        self::assertSame(1, $result['skipped'], 'le lien MANUAL est sauté');

        $this->em->clear();
        $link = $this->link($club, 'gymnase mateo');
        self::assertInstanceOf(OpponentVenueLink::class, $link);
        self::assertSame(OpponentVenueLinkSource::MANUAL, $link->getSource(), 'le choix MANUAL survit intact');
        self::assertSame('Mon vrai gymnase', $link->getVenueLabel());
        self::assertSame('999999999', $link->getVenueExternalRef());
    }

    /** Un lien AUTO pointant une AUTRE salle est ré-apparié (le gymnase fédéral change). */
    public function testAnExistingAutoLinkIsReVerified(): void
    {
        [$club, $season] = $this->seedClubWithAway('GYMNASE MATEO', 'Adverse Auto - 1');
        $this->seedDirectory();

        $this->scopeGucToClub($club->getId());
        $auto = (new OpponentVenueLink)
            ->setClubId($club->getId())->setOpponentOrganismeCode(self::CODE)
            ->setFbiLabel('GYMNASE MATEO')->setFbiLabelNorm('gymnase mateo')
            ->setVenueExternalRef('000000000')->setVenueLabel('Ancien gymnase')
            ->setLatitude(45.0)->setLongitude(4.0)->setSource(OpponentVenueLinkSource::AUTO);
        $this->em->persist($auto);
        $this->em->flush();

        $result = $this->locator([$this->salle('166926604', 'GYMNASE MATEO', 45.78, 4.88)])->locate($club->getId(), $season->getId());
        self::assertSame(1, $result['located']);

        $this->em->clear();
        $link = $this->link($club, 'gymnase mateo');
        self::assertInstanceOf(OpponentVenueLink::class, $link);
        self::assertSame(OpponentVenueLinkSource::AUTO, $link->getSource());
        self::assertSame('166926604', $link->getVenueExternalRef(), 'la salle a été re-appariée, pas laissée à l\'ancienne');
    }

    /**
     * Borne de fan-out fédéral : au-delà de 200 groupes, la passe s'arrête proprement —
     * au plus 200 recherches de salle, les groupes en excès comptés `skipped`.
     */
    public function testTheGroupCapBoundsTheFederalFanOut(): void
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club cap ' . $uid);
        $club->setSlug('club-auto-cap-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
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

        // 201 adversaires AWAY distincts (codes distincts), chacun un groupe (code|libellé).
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
     * Le libellé normalisé (clé du lien) est tronqué à 180 (colonne VARCHAR(180)) avant
     * écriture : un libellé de salle très long est apparié sans erreur DB avalée.
     */
    public function testTheLabelNormIsTruncatedToTheColumnLength(): void
    {
        // 180 ligatures « œ » : la translittération ASCII les DILATE (« œ » → « oe »),
        // le libellé normalisé fait 360 caractères → tronqué à 180 avant écriture.
        $longLabel = str_repeat("\u{0153}", 180);
        [$club, $season] = $this->seedClubWithAway($longLabel, 'Adverse Long - 1');
        $this->seedDirectory();

        $result = $this->locator([$this->salle('166926604', $longLabel, 45.78, 4.88)])->locate($club->getId(), $season->getId());
        self::assertSame(1, $result['located']);

        $this->scopeGucToClub($club->getId());
        $repository = self::getContainer()->get(OpponentVenueLinkRepository::class);
        self::assertInstanceOf(OpponentVenueLinkRepository::class, $repository);
        $links = $repository->findByClub($club->getId());
        self::assertCount(1, $links);
        self::assertSame(180, mb_strlen($links[0]->getFbiLabelNorm()), 'le libellé normalisé est tronqué à la longueur de colonne (180)');
        self::assertSame(str_repeat('oe', 90), $links[0]->getFbiLabelNorm());
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

        return $this->buildLocator($ffbb);
    }

    /**
     * @param list<array<string, mixed>> $salles the federal salles any ffbbserver_salles query returns
     */
    private function locator(array $salles): OpponentVenueAutoLocator
    {
        $ffbb = new MockHttpClient(static function (string $method, string $url, array $options) use ($salles): MockResponse {
            if (str_contains($url, 'api.ffbb.com')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 'stub-token']]));
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            $hits = str_contains($body, 'ffbbserver_salles') ? $salles : [];

            return new MockResponse((string) json_encode(['results' => [['hits' => $hits]]]));
        });

        return $this->buildLocator($ffbb);
    }

    private function buildLocator(MockHttpClient $ffbb): OpponentVenueAutoLocator
    {
        return new OpponentVenueAutoLocator(
            $this->em,
            self::getContainer()->get(FixtureRepository::class),
            self::getContainer()->get(OpponentVenueLinkRepository::class),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            new FfbbApiClient($ffbb, 'stub-token'),
            self::getContainer()->get(VenueLabelNormalizer::class),
            new MockClock,
            new NullLogger,
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

    private function link(Club $club, string $fbiLabelNorm): ?OpponentVenueLink
    {
        $this->scopeGucToClub($club->getId());
        $repository = self::getContainer()->get(OpponentVenueLinkRepository::class);
        self::assertInstanceOf(OpponentVenueLinkRepository::class, $repository);

        return $repository->findOneByKey($club->getId(), self::CODE, $fbiLabelNorm);
    }

    private function sharedRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code', ['code' => self::CODE]);
    }
}
