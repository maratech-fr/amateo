<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Geo;

use App\Service\Geo\BanGeocodingClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P4-246 — le géocodeur BAN borne la taille de réponse (1 Mio). Une réponse d'un ordre de
 * grandeur au-dessus du plafond signale un endpoint compromis ou déréglé, et la lire en
 * entier serait un vecteur d'épuisement mémoire : le téléchargement est AVORTÉ par
 * `on_progress`. Contrairement à l'IGN (best-effort → null), le BAN ne rattrape pas — la
 * panne de transport PROPAGE et le contrôleur en fait un 502, jamais un formulaire cassé.
 */
#[Group('unit')]
final class BanGeocodingClientTest extends TestCase
{
    public function testAnOversizedResponseIsAbortedAndPropagatesAsATransportFailure(): void
    {
        $client = new BanGeocodingClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse(str_repeat('x', 1_100_000))),
        );

        $this->expectException(TransportExceptionInterface::class);
        $client->geocodeTop('5 rue Emile Duniere Villeurbanne');
    }

    /**
     * Contre-preuve : le plafond ne casse pas le chemin normal — une réponse de taille
     * ordinaire est parsée comme avant (sinon le test ci-dessus « passerait » pour une
     * mauvaise raison).
     */
    public function testANormalResponseIsParsedUnaffectedByTheSizeGuard(): void
    {
        $body = (string) json_encode(['features' => [[
            'properties' => ['label' => '5 Rue Émile Dunière, Villeurbanne', 'score' => 0.9, 'postcode' => '69100', 'city' => 'Villeurbanne'],
            'geometry' => ['coordinates' => [4.85, 45.75]],
        ]]], \JSON_THROW_ON_ERROR);

        $client = new BanGeocodingClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse($body)),
        );

        $top = $client->geocodeTop('5 rue Emile Duniere Villeurbanne');
        self::assertNotNull($top);
        self::assertSame(45.75, $top['latitude']);
        self::assertSame(4.85, $top['longitude']);
        self::assertSame('69100', $top['postalCode']);
    }

    /**
     * Lot E — la PRÉCISION BAN (`properties.type`) voyage dans les candidats : `housenumber` pour un
     * numéro, `street` pour une rue entière (le front avertit « Position approximative » sur ce
     * dernier). Absente ⇒ null.
     */
    public function testGeocodeExposesTheBanPrecisionType(): void
    {
        $body = (string) json_encode(['features' => [
            [
                'properties' => ['label' => '12 Cours Émile Zola, Villeurbanne', 'score' => 0.95, 'type' => 'housenumber'],
                'geometry' => ['coordinates' => [4.87, 45.77]],
            ],
            [
                'properties' => ['label' => 'Cours Émile Zola, Villeurbanne', 'score' => 0.9, 'type' => 'street'],
                'geometry' => ['coordinates' => [4.88, 45.76]],
            ],
            [
                'properties' => ['label' => 'Villeurbanne', 'score' => 0.8], // type absent
                'geometry' => ['coordinates' => [4.89, 45.78]],
            ],
        ]], \JSON_THROW_ON_ERROR);

        $client = new BanGeocodingClient(new MockHttpClient(static fn (): MockResponse => new MockResponse($body)));

        $candidates = $client->geocode('cours emile zola');
        self::assertSame('housenumber', $candidates[0]['type'], 'un numéro de rue : type housenumber');
        self::assertSame('street', $candidates[1]['type'], 'une rue entière : type street (position approximative)');
        self::assertNull($candidates[2]['type'], 'type absent ⇒ null');
    }

    // ── reverse() — P4-271 (ajout fondateur) : best-effort, jamais d'exception ──────────

    public function testReverseReturnsTheClosestAddressLabel(): void
    {
        $body = (string) json_encode(['features' => [[
            'properties' => ['label' => '5 Rue Émile Dunière, Villeurbanne', 'score' => 0.9],
            'geometry' => ['coordinates' => [4.85, 45.75]],
        ]]], \JSON_THROW_ON_ERROR);

        $client = new BanGeocodingClient(new MockHttpClient(static fn (): MockResponse => new MockResponse($body)));

        self::assertSame('5 Rue Émile Dunière, Villeurbanne', $client->reverse(45.75, 4.85));
    }

    public function testReverseReturnsNullWhenNoFeatureIsReturned(): void
    {
        $client = new BanGeocodingClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('{"features":[]}')));

        self::assertNull($client->reverse(45.75, 4.85));
    }

    public function testReverseSwallowsTransportFailuresAndReturnsNull(): void
    {
        $client = new BanGeocodingClient(new MockHttpClient(static function (): MockResponse {
            throw new TransportException('boom');
        }));

        // Contrairement à geocode(), reverse() est best-effort : l'échec devient null, jamais une exception.
        self::assertNull($client->reverse(45.75, 4.85));
    }

    public function testReverseAbortsAnOversizedResponseAndReturnsNull(): void
    {
        $client = new BanGeocodingClient(new MockHttpClient(static fn (): MockResponse => new MockResponse(str_repeat('x', 1_100_000))));

        self::assertNull($client->reverse(45.75, 4.85));
    }

    public function testReverseRejectsOutOfRangeCoordinatesWithoutAnyCall(): void
    {
        // Un client qui EXPLOSE si on l'appelle : la garde de bornes doit court-circuiter avant.
        $client = new BanGeocodingClient(new MockHttpClient(static function (): MockResponse {
            throw new RuntimeException('le réseau ne doit pas être touché pour des coordonnées hors bornes');
        }));

        self::assertNull($client->reverse(200.0, 4.85));
        self::assertNull($client->reverse(45.75, 999.0));
    }
}
