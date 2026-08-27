<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Basketball;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\FfbbRencontreReader;
use App\Service\Basketball\OpponentLocationResolver;
use App\Service\FbiFixtureImporter;
use App\Service\Geo\BanGeocodingClient;
use App\Tests\Security\OpponentDirectoryShareTest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NR — axe §7.1 tenant isolation, revue sécurité 2026-08-28. La table GLOBALE
 * hors-tenant `opponent_directory` est PARTAGÉE byte-identique entre tous les clubs
 * (voir {@see OpponentDirectoryShareTest}). Ce test verrouille le
 * correctif : la précision VENUE est RÉSERVÉE au canal API autoritatif (`directVenue`,
 * les coordonnées exactes portées par le hit rencontre FFBB) ; le canal xlsx/rattrapage,
 * dont chaque libellé est un TEXTE LIBRE fourni par le club, ne peut JAMAIS établir un
 * VENUE dans la table partagée — au mieux CITY.
 *
 * POURQUOI ce test existe : avant le correctif, `resolveOne` appariait le libellé de
 * salle xlsx (`fbiVenueLabel`, contrôlé par l'attaquant) contre l'index des salles et
 * écrivait une ligne VENUE aux coordonnées de son choix, keyée sur le code fédéral EXACT
 * d'un organisme — un empoisonnement PERMANENT (premier-VENUE-gagne) lu par tous les
 * clubs. L'étage « appariement franc par nom de salle » a été retiré. Falsifié dans les
 * deux sens : (a) une observation canal xlsx (directVenue null) → CITY, jamais VENUE,
 * MÊME quand l'index des salles rendrait un appariement franc parfait ; (b) une
 * observation canal API (directVenue peuplé) → VENUE (le canal autoritatif garde son
 * étage 1 gratuit).
 */
#[Group('phase1')]
#[Group('integration')]
#[Group('security')]
final class OpponentLocationResolverTest extends WebTestCase
{
    private const string XLSX_CODE = 'ARA0069991';

    private const string API_CODE = 'ARA0069992';

    private const string OPPONENT_NAME = 'ADVERSE POISON FC';

    private EntityManagerInterface $em;

    public function testXlsxChannelCanNeverProduceVenuePrecisionOnTheSharedTable(): void
    {
        $resolver = $this->resolverWithControlledFfbb();

        // Canal xlsx/rattrapage : pas de code, pas de directVenue autoritatif. Le nom
        // résout un organisme (→ sa ville) ; l'index des salles rendrait POURTANT un
        // appariement franc parfait (le stub ci-dessous), mais le résolveur ne le
        // consulte plus — le libellé xlsx est club-fourni, il ne peut établir un VENUE.
        $outcome = $resolver->resolveObservations([[
            'organismeCode' => null,
            'name' => self::OPPONENT_NAME,
            'directVenue' => null,
        ]]);

        self::assertSame(1, $outcome['resolved'], 'l\'adversaire est localisé (à la ville)');

        $entry = $this->repository()->findOneByFfbbOrganismeCode(self::XLSX_CODE);
        self::assertNotNull($entry);
        self::assertSame(
            OpponentLocationPrecision::CITY,
            $entry->getPrecision(),
            'le canal xlsx (libellé club-fourni) ne DOIT JAMAIS écrire un VENUE dans la table partagée',
        );
        self::assertNull($entry->getVenueLabel(), 'une résolution ville ne porte aucun libellé de salle');
    }

    public function testApiChannelStillProducesVenuePrecisionFromItsAuthoritativeDirectVenue(): void
    {
        $resolver = $this->resolverWithControlledFfbb();

        // Canal API : le hit rencontre porte le code fédéral ET la salle exacte
        // (coordonnées autoritatives). L'étage 1 VENUE gratuit est préservé.
        $outcome = $resolver->resolveObservations([[
            'organismeCode' => self::API_CODE,
            'name' => 'ADVERSE AUTORITAIRE FC',
            'directVenue' => [
                'libelle' => 'GYMNASE AUTORITAIRE',
                'city' => 'Lyon',
                'postalCode' => '69003',
                'latitude' => 45.76,
                'longitude' => 4.86,
            ],
        ]]);

        self::assertSame(1, $outcome['resolved']);

        $entry = $this->repository()->findOneByFfbbOrganismeCode(self::API_CODE);
        self::assertNotNull($entry);
        self::assertSame(
            OpponentLocationPrecision::VENUE,
            $entry->getPrecision(),
            'le canal API autoritatif garde sa précision VENUE (directVenue = coordonnées exactes du hit)',
        );
        self::assertSame('GYMNASE AUTORITAIRE', $entry->getVenueLabel());
    }

    /**
     * P2-54 PR-3 — the resolved organisme code is STAMPED back onto the AWAY
     * fixtures of that opponent (the join key toward the directory + the tenant
     * travel). Falsifiable: a fixture of a DIFFERENT opponent is left untouched.
     */
    public function testResolvedCodeIsStampedOntoTheMatchingAwayFixtures(): void
    {
        $resolver = $this->resolverWithControlledFfbb();

        $match = (new Fixture)->setHomeAway(FixtureHomeAway::AWAY)->setOpponentLabel(self::OPPONENT_NAME);
        $other = (new Fixture)->setHomeAway(FixtureHomeAway::AWAY)->setOpponentLabel('AUTRE CLUB SANS RESO');

        $outcome = $resolver->resolveObservations(
            [['organismeCode' => null, 'name' => self::OPPONENT_NAME, 'directVenue' => null]],
            [$match, $other],
        );

        self::assertSame(1, $outcome['stamped']);
        self::assertSame(self::XLSX_CODE, $match->getOpponentOrganismeCode(), 'the opponent\'s code is stamped as the join key');
        self::assertNull($other->getOpponentOrganismeCode(), 'a fixture of another (unresolved) opponent is left untouched');
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The real resolver, but its FFBB client rides a MockHttpClient shaped so that
     * BOTH the organisme search (→ a city for the poison name) AND the salle search
     * (→ a perfect frank match, the poison vector) would succeed — proving the xlsx
     * channel stays CITY not because FFBB failed, but because the label→VENUE étage
     * is gone. Repository/importer/geocoder are the real container services.
     */
    private function resolverWithControlledFfbb(): OpponentLocationResolver
    {
        $apiClient = new FfbbApiClient($this->ffbbMock(), 'stub-token');

        $importer = self::getContainer()->get(FbiFixtureImporter::class);
        self::assertInstanceOf(FbiFixtureImporter::class, $importer);
        $geocoder = self::getContainer()->get(BanGeocodingClient::class);
        self::assertInstanceOf(BanGeocodingClient::class, $geocoder);

        return new OpponentLocationResolver(
            new FfbbRencontreReader($apiClient),
            $apiClient,
            $geocoder,
            $importer,
            $this->repository(),
            $this->em,
            new NullLogger,
        );
    }

    private function ffbbMock(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';

            // Organisme by name → exactly one strict hit carrying a code + a city.
            if (str_contains($body, 'ffbbserver_organismes')) {
                return $this->hits([[
                    'code' => self::XLSX_CODE,
                    'nom' => self::OPPONENT_NAME,
                    'commune' => ['libelle' => 'Villeurbanne', 'codePostal' => '69100'],
                    '_geo' => ['lat' => 45.771, 'lng' => 4.89],
                ]]);
            }

            // Salle by name → a PERFECT frank match (the historical poison vector).
            // The resolver must ignore it entirely on the xlsx channel.
            if (str_contains($body, 'ffbbserver_salles')) {
                return $this->hits([[
                    'libelle' => 'GYMNASE EMPOISONNE',
                    'commune' => ['libelle' => 'Paris', 'codePostal' => '75001'],
                    '_geo' => ['lat' => 48.8566, 'lng' => 2.3522],
                ]]);
            }

            return $this->hits([]);
        });
    }

    /** @param list<array<string, mixed>> $hits */
    private function hits(array $hits): MockResponse
    {
        return new MockResponse((string) json_encode(['results' => [['hits' => $hits]]]));
    }

    private function repository(): OpponentDirectoryEntryRepository
    {
        $repository = self::getContainer()->get(OpponentDirectoryEntryRepository::class);
        self::assertInstanceOf(OpponentDirectoryEntryRepository::class, $repository);

        return $repository;
    }
}
