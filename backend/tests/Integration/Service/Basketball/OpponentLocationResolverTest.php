<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Basketball;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueSuggestionRepository;
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
use Symfony\Component\Clock\MockClock;
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

    // Relance « - n » (P2-54 « adversaire multi-gymnases ») : un organisme joue via
    // plusieurs équipes numérotées ; le libellé de rencontre porte le « - n », l'index
    // fédéral connaît l'organisme sous son nom NU.
    private const string MULTI_1 = 'ADVERSE MULTI - 1';

    private const string MULTI_2 = 'ADVERSE MULTI - 2';

    private const string MULTI_STRIPPED = 'ADVERSE MULTI';

    private const string MULTI_CODE = 'ARA0069MUL';

    private const string SF_FULL = 'TEAM ALPHA - 1';

    private const string SF_CODE_FULL = 'ARA0069FUL';

    private const string SF_CODE_STRIP = 'ARA0069STR';

    private const string AMBIGU = 'AMBIGU - 1';

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

    /**
     * Régression 23505 — deux observations de NOMS différents (« - 1 »/« - 2 ») résolvent
     * le MÊME code organisme (relance sur le nom nu). L'upsert de l'annuaire doit les fondre
     * en UNE ligne via `ON CONFLICT`, sans jamais fermer le manager. Avant le correctif, le
     * 2ᵉ `findOneBy` ne voyait pas l'entité persistée non flushée → double persist → violation
     * d'unicité au flush : catchée, mais l'EntityManager restait FERMÉ (les passes suivantes de
     * l'orchestrateur mouraient alors en silence).
     */
    public function testTwoObservationsSharingACodeUpsertOnceWithoutClosingTheManager(): void
    {
        $resolver = $this->resolverWithControlledFfbb();

        $outcome = $resolver->resolveObservations([
            ['organismeCode' => null, 'name' => self::OPPONENT_NAME . ' - 1', 'directVenue' => null],
            ['organismeCode' => null, 'name' => self::OPPONENT_NAME . ' - 2', 'directVenue' => null],
        ]);

        self::assertTrue($this->em->isOpen(), 'aucun 23505 : le manager reste ouvert (l\'orchestrateur peut continuer)');
        self::assertSame(2, $outcome['resolved'], 'les deux observations sont localisées');

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM opponent_directory WHERE ffbb_organisme_code = :code',
            ['code' => self::XLSX_CODE],
        );
        self::assertSame(1, $count, 'un seul code organisme → une seule ligne d\'annuaire');
    }

    /**
     * BCK-32 — budget de mur épuisé (deadline dans le passé) : une observation qui SE
     * serait résolue revient en `unresolved` sans le moindre appel réseau ni écriture
     * annuaire — même canal que le reste, best-effort (relancer pour continuer).
     */
    public function testResolveObservationsStopsAtTheWallClockDeadline(): void
    {
        $resolver = $this->resolverWithControlledFfbb();

        $outcome = $resolver->resolveObservations([[
            'organismeCode' => self::API_CODE,
            'name' => 'ADVERSE BUDGET FC',
            'directVenue' => ['libelle' => 'GYM', 'city' => 'Lyon', 'postalCode' => '69003', 'latitude' => 45.76, 'longitude' => 4.86],
        ]], [], 1.0);

        self::assertSame(0, $outcome['resolved'], 'aucune résolution passé le budget de mur');
        self::assertSame(['ADVERSE BUDGET FC'], $outcome['unresolved']);
        self::assertNull($this->repository()->findOneByFfbbOrganismeCode(self::API_CODE), 'rien écrit à l\'annuaire');
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
     * P2-54 PR-2 — le canal API (directVenue autoritatif) dépose AUSSI une suggestion
     * PARTAGÉE FFBB_API pour ce gymnase de l'adversaire : sans référence de salle (le
     * hit rencontre ne porte pas le `numero`), dédupliquée par libellé, compte à 0
     * (une observation n'est pas un choix). Best-effort : le dépôt vit dans le même
     * try/catch que la résolution.
     */
    public function testApiChannelDepositsAnFfbbApiVenueSuggestion(): void
    {
        $resolver = $this->resolverWithControlledFfbb();

        $resolver->resolveObservations([[
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

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT source, venue_external_ref, venue_label, city, postal_code, chosen_by_count FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code',
            ['code' => self::API_CODE],
        );
        self::assertIsArray($row, 'le canal API a déposé une suggestion partagée');
        self::assertSame('FFBB_API', $row['source']);
        self::assertNull($row['venue_external_ref'], 'une suggestion FFBB_API n\'a pas de référence de salle (le hit ne la porte pas)');
        self::assertSame('GYMNASE AUTORITAIRE', $row['venue_label']);
        self::assertSame('Lyon', $row['city']);
        self::assertSame('69003', $row['postal_code']);
        self::assertSame(0, (int) $row['chosen_by_count'], 'une observation n\'est pas un choix : compte 0');
    }

    /** Le canal xlsx (directVenue null) ne dépose AUCUNE suggestion — le partage est réservé à l'API. */
    public function testXlsxChannelDepositsNoVenueSuggestion(): void
    {
        $resolver = $this->resolverWithControlledFfbb();

        $resolver->resolveObservations([[
            'organismeCode' => null,
            'name' => self::OPPONENT_NAME,
            'directVenue' => null,
        ]]);

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM opponent_venue_suggestion WHERE ffbb_organisme_code = :code',
            ['code' => self::XLSX_CODE],
        );
        self::assertSame(0, $count, 'un libellé club-fourni (xlsx) n\'alimente jamais les suggestions partagées');
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

    /**
     * P2-54 « adversaire multi-gymnases » — le rapprochement au nom RELANCE une fois
     * sans le suffixe d'équipe « - n » : « ADVERSE MULTI - 1 » et « - 2 » (l'organisme
     * fédéral s'appelle « ADVERSE MULTI ») résolvent le MÊME code, et les deux
     * rencontres sont estampillées (codeByName keyé sur le libellé COMPLET). Falsifié :
     * sans la relance, la passe stricte sur le nom complet échoue et rien n'est estampé.
     */
    public function testRelanceOnStrippedTeamNumberResolvesBothTeamsToTheSameCode(): void
    {
        $resolver = $this->resolverWithRelanceFfbb();

        $team1 = (new Fixture)->setHomeAway(FixtureHomeAway::AWAY)->setOpponentLabel(self::MULTI_1);
        $team2 = (new Fixture)->setHomeAway(FixtureHomeAway::AWAY)->setOpponentLabel(self::MULTI_2);

        $outcome = $resolver->resolveObservations(
            [
                ['organismeCode' => null, 'name' => self::MULTI_1, 'directVenue' => null],
                ['organismeCode' => null, 'name' => self::MULTI_2, 'directVenue' => null],
            ],
            [$team1, $team2],
        );

        self::assertSame(2, $outcome['stamped'], 'les deux équipes du même organisme sont estampillées');
        self::assertSame(self::MULTI_CODE, $team1->getOpponentOrganismeCode());
        self::assertSame(self::MULTI_CODE, $team2->getOpponentOrganismeCode(), 'la relance « - n » unifie les deux équipes sur le code de l\'organisme');
    }

    /**
     * La passe STRICTE d'abord : quand le libellé COMPLET apparie déjà un organisme, la
     * relance ne se déclenche jamais. Falsifié : le nom nu apparie un AUTRE code — si la
     * relance mordait à tort, on stamperait ce mauvais code.
     */
    public function testStrictMatchOnTheFullLabelWinsWithoutRelance(): void
    {
        $resolver = $this->resolverWithRelanceFfbb();

        $match = (new Fixture)->setHomeAway(FixtureHomeAway::AWAY)->setOpponentLabel(self::SF_FULL);

        $outcome = $resolver->resolveObservations(
            [['organismeCode' => null, 'name' => self::SF_FULL, 'directVenue' => null]],
            [$match],
        );

        self::assertSame(1, $outcome['stamped']);
        self::assertSame(self::SF_CODE_FULL, $match->getOpponentOrganismeCode(), 'la passe stricte sur le libellé complet gagne, la relance ne s\'est pas déclenchée');
        self::assertNotSame(self::SF_CODE_STRIP, $match->getOpponentOrganismeCode());
    }

    /**
     * La relance reste STRICTE : un nom nu qui apparie DEUX organismes est ambigu → pas
     * de clé, aucune rencontre estampée.
     */
    public function testRelanceStaysStrictWhenTheStrippedNameIsAmbiguous(): void
    {
        $resolver = $this->resolverWithRelanceFfbb();

        $match = (new Fixture)->setHomeAway(FixtureHomeAway::AWAY)->setOpponentLabel(self::AMBIGU);

        $outcome = $resolver->resolveObservations(
            [['organismeCode' => null, 'name' => self::AMBIGU, 'directVenue' => null]],
            [$match],
        );

        self::assertSame(0, $outcome['stamped']);
        self::assertNull($match->getOpponentOrganismeCode(), 'une relance à 2 organismes reste ambiguë → aucune clé');
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The real resolver on a QUERY-AWARE FFBB mock: the organisme index answers by the
     * exact `q` sent, so the strict pass on the FULL label fails while the relance on the
     * bare name succeeds (or is ambiguous / already-strict, per the fixtures).
     */
    private function resolverWithRelanceFfbb(): OpponentLocationResolver
    {
        $apiClient = new FfbbApiClient($this->relanceMock(), 'stub-token');

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
            $this->suggestions(),
            $this->em,
            new NullLogger,
            new MockClock,
        );
    }

    private function relanceMock(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, mixed> $data */
            $data = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true) ?: [];
            $query = \is_array($data['queries'][0] ?? null) ? $data['queries'][0] : [];
            $index = \is_string($query['indexUid'] ?? null) ? $query['indexUid'] : '';
            $q = \is_string($query['q'] ?? null) ? $query['q'] : '';

            if ('ffbbserver_organismes' !== $index) {
                return $this->hits([]); // no salle appariement in these scenarios
            }

            return match ($q) {
                // Multi-équipes : le libellé complet ne trouve rien, le nom nu trouve l'organisme.
                self::MULTI_STRIPPED => $this->hits([$this->organisme(self::MULTI_CODE, self::MULTI_STRIPPED)]),
                // Strict d'abord : le libellé COMPLET apparie un organisme (code « full »),
                // le nom nu en apparierait un AUTRE (code « strip ») — jamais atteint.
                self::SF_FULL => $this->hits([$this->organisme(self::SF_CODE_FULL, self::SF_FULL)]),
                'TEAM ALPHA' => $this->hits([$this->organisme(self::SF_CODE_STRIP, 'TEAM ALPHA')]),
                // Ambigu : le nom nu apparie DEUX organismes.
                'AMBIGU' => $this->hits([$this->organisme('ARA0069AM1', 'AMBIGU'), $this->organisme('ARA0069AM2', 'AMBIGU')]),
                default => $this->hits([]),
            };
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function organisme(string $code, string $nom): array
    {
        return [
            'code' => $code,
            'nom' => $nom,
            'commune' => ['libelle' => 'Lyon', 'codePostal' => '69001'],
            '_geo' => ['lat' => 45.76, 'lng' => 4.86],
        ];
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
            $this->suggestions(),
            $this->em,
            new NullLogger,
            new MockClock,
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

    private function suggestions(): OpponentVenueSuggestionRepository
    {
        $repository = self::getContainer()->get(OpponentVenueSuggestionRepository::class);
        self::assertInstanceOf(OpponentVenueSuggestionRepository::class, $repository);

        return $repository;
    }
}
