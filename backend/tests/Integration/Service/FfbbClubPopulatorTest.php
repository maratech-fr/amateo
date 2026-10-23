<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Basketball\FfbbCommittee;
use App\Entity\Basketball\FfbbLeague;
use App\Entity\Club;
use App\Repository\Basketball\FfbbCommitteeRepository;
use App\Repository\Basketball\FfbbLeagueRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbClubPopulator;
use App\Service\Basketball\FfbbLogoFetcher;
use App\Service\Basketball\LogoPaletteExtractor;
use App\Storage\LogoStorage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Lot C: FfbbClubPopulator maps the FFBB API JSON onto the club + shared
 * league/committee reference rows, reuses them cache-first, and is best-effort
 * (invalid code / miss → club untouched). Drives a MockHttpClient (no network).
 */
#[Group('phase1')]
final class FfbbClubPopulatorTest extends KernelTestCase
{
    private const CLUB_CODE = 'ARA0069036';
    // 1x1 transparent PNG — finfo detects image/png (accepted by FfbbLogoFetcher).
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private EntityManagerInterface $em;

    public function testPopulateMapsAllFieldsAndReferences(): void
    {
        $club = $this->seedClub(self::CLUB_CODE);
        $populator = $this->buildPopulator();

        self::assertTrue($populator->populate($club));

        $reloaded = $this->em->getRepository(Club::class)->find($club->getId());
        // NR (retour fondateur 2026-07-18) : FFBB fait autorité sur le NOM — le nom
        // provisoire du seed est remplacé par celui de la fédération.
        self::assertSame('B CHARPENNES CROIX LUIZET', $reloaded?->getName(), 'FFBB name overrides the seed name');
        self::assertSame('5 RUE EMILE DUNIERE', $reloaded?->getAddress());
        self::assertSame('69100', $reloaded?->getPostalCode());
        self::assertSame('VILLEURBANNE', $reloaded?->getCity());
        self::assertSame('0643720140', $reloaded?->getContactPhone());
        self::assertSame('contact@bccl.fr', $reloaded?->getContactEmail());
        self::assertSame('http://www.bccl.fr', $reloaded?->getWebsite());
        // P2-21 lot C : l'accent par défaut = gradient_color FFBB, normalisé en minuscules.
        self::assertSame('#c9102e', $reloaded?->getAccentColor());
        self::assertSame('0069', $reloaded?->getCommitteeCode());
        self::assertEqualsWithDelta(45.78017, (float) $reloaded?->getLatitude(), 0.0001);
        self::assertEqualsWithDelta(4.88467, (float) $reloaded?->getLongitude(), 0.0001);
        self::assertNotNull($reloaded?->getLogoUrl(), 'club logo rehosted');

        $committee = self::getContainer()->get(FfbbCommitteeRepository::class)->findByCode('0069');
        self::assertSame('69500', $committee?->getPostalCode());
        self::assertSame('cdrbb@basketrhone.com', $committee?->getEmail());
        self::assertSame('ARA', $committee?->getLeagueCode());
        self::assertSame('http://www.basketrhone.com', $committee?->getWebsite());
        self::assertNotNull($committee?->getLogoUrl());

        $league = self::getContainer()->get(FfbbLeagueRepository::class)->findByCode('ARA');
        self::assertSame('secretariat@aurabasketball.com', $league?->getEmail());
        self::assertStringContainsString('/api/ffbb-logos/league/ARA', (string) $league?->getLogoUrl());
        // Mesuré le 2026-08-04 : la FFBB rend l'URL de la ligue avec une espace FINALE — str() trime.
        self::assertSame('https://www.aurabasketball.com', $league?->getWebsite());
    }

    public function testCacheFirstReusesExistingReferences(): void
    {
        // Pre-seed the reference rows: a second club must NOT re-fetch the parents.
        $this->em->persist((new FfbbCommittee)->setCode('0069')->setName('Comité pré-existant'));
        $this->em->persist((new FfbbLeague)->setCode('ARA')->setName('Ligue pré-existante'));
        $this->em->flush();

        $club = $this->seedClub(self::CLUB_CODE);
        $populator = $this->buildPopulator($searchCount);

        self::assertTrue($populator->populate($club));

        // Only the club search happened — the parents were served from the DB.
        self::assertSame(1, $searchCount(), 'cache-first: parents not re-fetched');
        self::assertCount(1, self::getContainer()->get(FfbbCommitteeRepository::class)->findAll());
        self::assertSame('Comité pré-existant', self::getContainer()->get(FfbbCommitteeRepository::class)->findByCode('0069')?->getName());
    }

    public function testBestEffortOnInvalidCode(): void
    {
        $club = $this->seedClub('not-a-code');
        $populator = $this->buildPopulator();

        self::assertFalse($populator->populate($club));
        self::assertNull($this->em->getRepository(Club::class)->find($club->getId())?->getAddress());
    }

    public function testOversizedFfbbNameIsTruncatedNotFlushKilling(): void
    {
        // NR (revue #261 round 1) : un nom FFBB > 180 (Club.name) tronqué, jamais
        // laissé casser le flush — sinon adresse/logo/coordonnées partent avec.
        $club = $this->seedClub(self::CLUB_CODE);
        $longName = str_repeat('A', 250);
        $populator = $this->buildPopulator(clubHit: ['code' => self::CLUB_CODE, 'nom' => $longName, 'adresse' => '5 RUE EMILE DUNIERE']);

        self::assertTrue($populator->populate($club));
        $reloaded = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertSame(180, mb_strlen((string) $reloaded?->getName()), 'FFBB name capped to the column length');
        self::assertSame('5 RUE EMILE DUNIERE', $reloaded?->getAddress(), 'other FFBB fields still applied');
    }

    public function testNoExactCodeMatchDoesNotApplyStrangerData(): void
    {
        // Meilisearch is typo-tolerant: a search may return a neighbour with a
        // DIFFERENT code. That must NOT be written onto this club.
        $club = $this->seedClub('ARA0069999');
        $populator = $this->buildPopulator(clubHit: ['code' => 'ARA0069036', 'nom' => 'AUTRE CLUB', 'adresse' => 'ailleurs']);

        self::assertFalse($populator->populate($club));
        self::assertNull($this->em->getRepository(Club::class)->find($club->getId())?->getAddress());
    }

    public function testRefreshDoesNotWipeManuallyEnteredFields(): void
    {
        $club = $this->seedClub(self::CLUB_CODE);
        $club->setContactEmail('manuel@club.fr')->setAddress('Adresse manuelle')->setAccentColor('#123456');
        $this->em->flush();

        // FFBB hit for the right code but WITHOUT address/mail (gaps).
        $populator = $this->buildPopulator(clubHit: ['code' => self::CLUB_CODE, 'nom' => 'BCCL']);

        self::assertTrue($populator->populate($club));
        $reloaded = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertSame('manuel@club.fr', $reloaded?->getContactEmail(), 'manual email preserved');
        self::assertSame('Adresse manuelle', $reloaded?->getAddress(), 'manual address preserved');
        // Le nom, lui, reste FFBB-autoritaire même au refresh (présent dans le hit).
        self::assertSame('BCCL', $reloaded?->getName(), 'FFBB name applied on refresh');
        // P2-21 lot C : un accent CHOISI ne se fait jamais écraser par un ré-import.
        self::assertSame('#123456', $reloaded?->getAccentColor(), 'chosen accent preserved on refresh');
    }

    public function testPopulateNeverOverwritesAManuallyChosenSiege(): void
    {
        // Un club qui a DÉJÀ des coordonnées (siège choisi à la main) : « Actualiser depuis la
        // FFBB » ne doit plus écraser son adresse/CP/ville/lat/lon (only-fill-when-empty).
        $club = $this->seedClub(self::CLUB_CODE);
        $club->setAddress('12 RUE DU CHOIX MANUEL')->setPostalCode('69003')->setCity('LYON')->setLatitude(45.5)->setLongitude(4.5);
        $this->em->flush();

        self::assertTrue($this->buildPopulator()->populate($club));

        $reloaded = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertSame('12 RUE DU CHOIX MANUEL', $reloaded?->getAddress(), 'le siège saisi survit à « Actualiser depuis la FFBB »');
        self::assertSame('69003', $reloaded?->getPostalCode());
        self::assertSame('LYON', $reloaded?->getCity());
        self::assertEqualsWithDelta(45.5, (float) $reloaded?->getLatitude(), 0.0001);
        self::assertEqualsWithDelta(4.5, (float) $reloaded?->getLongitude(), 0.0001);
        // Le contact, lui, reste rafraîchi par la fédération (jamais gardé).
        self::assertSame('0643720140', $reloaded?->getContactPhone());
    }

    public function testBlackAndWhiteLogoSetsTheDominantAsAccentAndAReadableDarkAccent(): void
    {
        // Promesses 1 + 2 : la dominante du logo (sombre) devient l'accent — JAMAIS
        // le `gradient_color` FFBB tant que le logo est extractible — et la 2e couleur
        // (claire) devient l'accent sombre car elle clear AA (4,5:1) face aux DEUX
        // surfaces du thème sombre (bg #151311, card #1f1d1a, cf. shared/lib/color.ts).
        // Reproduit le cas réel de Rillieux (#1d1d1d / #eeeeee).
        $club = $this->seedClub(self::CLUB_CODE);
        $populator = $this->buildPopulator(clubHit: $this->hitWithLogo('#c9102e'), clubLogoBytes: self::darkWithLightCorner());

        self::assertTrue($populator->populate($club));

        $reloaded = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertNotSame('#c9102e', $reloaded?->getAccentColor(), 'le logo extractible l’emporte sur le gradient FFBB');
        self::assertHexNear('#101010', (string) $reloaded?->getAccentColor(), 'dominante sombre → accent');
        self::assertHexNear('#f0f0f0', (string) $reloaded?->getAccentColorDark(), 'seconde claire lisible → accent sombre');
    }

    public function testSecondColourTooDarkForTheDarkThemeLeavesTheDarkAccentNull(): void
    {
        // Promesse 2 (envers) : une 2e couleur trop SOMBRE échoue l'AA face aux surfaces
        // sombres → `accentColorDark` reste null (le front garde sa dérivation par contraste).
        $club = $this->seedClub(self::CLUB_CODE);
        $populator = $this->buildPopulator(clubHit: $this->hitWithLogo('#c9102e'), clubLogoBytes: self::lightWithDarkCorner());

        self::assertTrue($populator->populate($club));

        $reloaded = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertHexNear('#f0f0f0', (string) $reloaded?->getAccentColor(), 'dominante claire → accent');
        self::assertNull($reloaded?->getAccentColorDark(), 'seconde trop sombre pour le thème sombre → pas d’accent sombre');
    }

    public function testAChosenAccentIsNeverOverwrittenByLogoExtractionEvenOnRefresh(): void
    {
        // Promesse 3 : un accent choisi par le club n'est JAMAIS écrasé, même quand
        // « Actualiser depuis la FFBB » rapporte un logo extractible. Complète
        // testRefreshDoesNotWipeManuallyEnteredFields (lui sans logo) par le chemin AVEC logo.
        $club = $this->seedClub(self::CLUB_CODE);
        $club->setAccentColor('#123456');
        $this->em->flush();

        $populator = $this->buildPopulator(clubHit: $this->hitWithLogo('#c9102e'), clubLogoBytes: self::darkWithLightCorner());
        self::assertTrue($populator->populate($club, refresh: true));

        $reloaded = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertSame('#123456', $reloaded?->getAccentColor(), 'accent choisi préservé malgré un logo extractible');
        self::assertNull($reloaded?->getAccentColorDark(), 'le bloc d’extraction est entièrement sauté quand un accent existe');
    }

    public function testAnUnextractableLogoKeepsTheFfbbGradientFallback(): void
    {
        // Promesse 4 : extraction impossible (logo 1×1 transparent, aucun pixel opaque)
        // → on garde le repli actuel `gradient_color`, sans bruit. Même chemin que
        // testPopulateMapsAllFieldsAndReferences (logo transparent du décor BCCL), nommé ici.
        $club = $this->seedClub(self::CLUB_CODE);
        $populator = $this->buildPopulator(clubHit: $this->hitWithLogo('#00AA55'));

        self::assertTrue($populator->populate($club));

        $reloaded = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertSame('#00aa55', $reloaded?->getAccentColor(), 'repli gradient FFBB quand le logo n’est pas extractible');
        self::assertNull($reloaded?->getAccentColorDark());
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<string, mixed> club search hit carrying a logo id + gradient fallback */
    private function hitWithLogo(string $gradient): array
    {
        return ['code' => self::CLUB_CODE, 'nom' => 'CLUB AU LOGO', 'logo' => ['id' => '076ee1ea-3e09-43b5-9552-d029a95a4a35', 'gradient_color' => $gradient]];
    }

    /** PNG majoritairement sombre (#101010) avec un coin clair (#f0f0f0) minoritaire. */
    private static function darkWithLightCorner(): string
    {
        return self::png(40, 40, static function ($img, int $w, int $h): void {
            imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($img, 16, 16, 16));
            imagefilledrectangle($img, 0, 0, $w - 1, (int) ($h / 10) - 1, (int) imagecolorallocate($img, 240, 240, 240));
        });
    }

    /** PNG majoritairement clair (#f0f0f0) avec un coin sombre (#202020) minoritaire. */
    private static function lightWithDarkCorner(): string
    {
        return self::png(40, 40, static function ($img, int $w, int $h): void {
            imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($img, 240, 240, 240));
            imagefilledrectangle($img, 0, 0, $w - 1, (int) ($h / 10) - 1, (int) imagecolorallocate($img, 32, 32, 32));
        });
    }

    /**
     * Construit un PNG en mémoire avec GD (aucun binaire en dépôt).
     *
     * @param callable(\GdImage, int, int): void $painter
     */
    private static function png(int $w, int $h, callable $painter): string
    {
        $img = imagecreatetruecolor($w, $h);
        $painter($img, $w, $h);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private static function assertHexNear(string $expected, string $actual, string $message = ''): void
    {
        $e = (int) hexdec(ltrim($expected, '#'));
        $a = (int) hexdec(ltrim($actual, '#'));
        $delta = max(
            abs((($e >> 16) & 0xFF) - (($a >> 16) & 0xFF)),
            abs((($e >> 8) & 0xFF) - (($a >> 8) & 0xFF)),
            abs(($e & 0xFF) - ($a & 0xFF)),
        );
        self::assertLessThanOrEqual(48, $delta, \sprintf('%s (%s attendu, %s obtenu)', $message, $expected, $actual));
    }

    private function seedClub(string $code): Club
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setFfbbClubCode($code);
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    /**
     * @param array<string, mixed>|null $clubHit       override the club search hit (else the BCCL fixture)
     * @param string|null                $clubLogoBytes override the bytes the logo fetcher returns (else a 1×1 transparent PNG)
     *
     * @param-out callable(): int $searchCount
     */
    private function buildPopulator(mixed &$searchCount = null, ?array $clubHit = null, ?string $clubLogoBytes = null): FfbbClubPopulator
    {
        $searches = 0;
        $api = new FfbbApiClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$searches, $clubHit): MockResponse {
            if (str_contains($url, '/items/configuration')) {
                return new MockResponse((string) json_encode(['data' => ['key_ms' => 'test-token']]));
            }
            ++$searches;
            $body = (string) ($options['body'] ?? '');
            $hit = (null !== $clubHit && !str_contains($body, 'COMITE') && !str_contains($body, 'LIGUE')) ? $clubHit : $this->hitFor($body);

            return new MockResponse((string) json_encode(['results' => [['hits' => [$hit]]]]));
        }));
        $searchCount = static function () use (&$searches): int {
            return $searches;
        };

        $bytes = $clubLogoBytes ?? (string) base64_decode(self::PNG, true);
        $logo = new FfbbLogoFetcher(new MockHttpClient(
            fn (): MockResponse => new MockResponse($bytes, ['response_headers' => ['content-type' => 'image/png']]),
        ), new NullLogger);

        return new FfbbClubPopulator(
            $api,
            $logo,
            $this->inMemoryStorage(),
            self::getContainer()->get(FfbbLeagueRepository::class),
            self::getContainer()->get(FfbbCommitteeRepository::class),
            $this->em,
            new NullLogger,
            new LogoPaletteExtractor,
        );
    }

    /** @return array<string, mixed> */
    private function hitFor(string $searchBody): array
    {
        if (str_contains($searchBody, 'COMITE')) {
            return ['code' => '0069', 'nom' => 'COMITE DU RHONE', 'adresse' => '3 RUE DU COLONEL CHAMBONNET', 'telephone' => '0478740634', 'mail' => 'cdrbb@basketrhone.com', 'urlSiteWeb' => 'http://www.basketrhone.com', 'commune' => ['libelle' => 'BRON', 'codePostal' => '69500'], 'logo' => ['id' => 'b0be226e-b2c7-42bc-85bb-05282ecd75b4'], 'type' => 'Comité'];
        }
        if (str_contains($searchBody, 'LIGUE')) {
            return ['code' => 'ARA', 'nom' => 'LIGUE AURA', 'adresse' => '3 AVENUE COLONEL CHAMBONNET', 'telephone' => '0977423620', 'mail' => 'secretariat@aurabasketball.com', 'urlSiteWeb' => 'https://www.aurabasketball.com ', 'commune' => ['libelle' => 'BRON', 'codePostal' => '69500'], 'logo' => ['id' => '4e73cd36-6058-44e8-b66c-79da4923b4c6'], 'type' => 'Ligue'];
        }

        return [
            'code' => self::CLUB_CODE, 'nom' => 'B CHARPENNES CROIX LUIZET', 'adresse' => '5 RUE EMILE DUNIERE',
            'mail' => 'contact@bccl.fr', 'telephone' => '0643720140', 'urlSiteWeb' => 'http://www.bccl.fr',
            'cartographie' => ['codePostal' => '69100', 'ville' => 'Villeurbanne'],
            'commune' => ['libelle' => 'VILLEURBANNE', 'codePostal' => '69100', 'departement' => 'Rhône'],
            '_geo' => ['lat' => 45.78017, 'lng' => 4.88467],
            'logo' => ['id' => '076ee1ea-3e09-43b5-9552-d029a95a4a35', 'gradient_color' => '#C9102E'],
            'organisme_id_pere' => [
                'id' => '2093', 'nom' => 'COMITE DU RHONE ET METROPOLE DE LYON', 'adresse' => '3 RUE DU COLONEL CHAMBONNET', 'code' => '0069',
                'organisme_id_pere' => ['id' => '200000002677104', 'nom' => 'LIGUE AUVERGNE RHONE ALPES', 'code' => 'ARA'],
            ],
        ];
    }

    private function inMemoryStorage(): LogoStorage
    {
        return new class implements LogoStorage {
            /** @var array<string, string> */
            private array $store = [];

            public function store(string $clubId, string $bytes): void
            {
                $this->store[$clubId] = $bytes;
            }

            public function read(string $clubId): ?string
            {
                return $this->store[$clubId] ?? null;
            }

            public function delete(string $clubId): void
            {
                unset($this->store[$clubId]);
            }
        };
    }
}
