<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\VerifiesRegistration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR d'axe « auth & memberships » (§7.1) : le code FFBB d'un club est son identité
 * fédérale et reste IMMUABLE après la naissance du club.
 *
 * Ce que ce garde tient :
 *  - un PUT /api/clubs/{id} qui CHANGE `ffbbClubCode` → 422 (message nommé), le code
 *    en base ne bouge pas. Aucune voie d'exception : corriger un code relève du support.
 *  - renvoyer le MÊME code est ACCEPTÉ (idempotent — un PUT porte souvent la ressource
 *    entière, le front réémet le code lu).
 *  - un PUT sans `ffbbClubCode` reste un no-op sur ce champ (les autres champs passent).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubIdentityImmutabilityTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testPutChangingFfbbCodeIsRefused(): void
    {
        [$token, $clubId, $ara] = $this->register('IMMA');

        $this->request('PUT', '/api/clubs/' . $clubId, $token, $this->body(['ffbbClubCode' => $ara . 'Z']));
        self::assertResponseStatusCodeSame(422);
        // Le corps est du problem+json (é/apostrophe échappés en \uXXXX) → on décode.
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $message = $body['violations'][0]['message'] ?? ($body['detail'] ?? '');
        self::assertStringContainsString('ne peut pas être modifié', (string) $message);

        // Le code en base n'a pas bougé (exposé au gestionnaire sur /api/me).
        $me = $this->get('/api/me', $token);
        self::assertSame($ara, $me['club']['ffbbClubCode'] ?? null, 'le code FFBB est resté inchangé');
    }

    public function testPutSameFfbbCodeIsAcceptedIdempotently(): void
    {
        [$token, $clubId, $ara] = $this->register('IMMB');

        $this->request('PUT', '/api/clubs/' . $clubId, $token, $this->body(['name' => 'Renommé', 'ffbbClubCode' => $ara]));
        self::assertResponseIsSuccessful();
    }

    public function testPutWithoutFfbbCodeIsAccepted(): void
    {
        [$token, $clubId] = $this->register('IMMC');

        $this->request('PUT', '/api/clubs/' . $clubId, $token, $this->body(['name' => 'Autre nom']));
        self::assertResponseIsSuccessful();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * PUT body avec les champs REQUIS du ClubInput (slug/timezone/locale sont
     * NotBlank) + les surcharges du scénario.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function body(array $overrides): array
    {
        return array_merge([
            'name' => 'Club immuable',
            'slug' => 'immutable-' . substr(md5(uniqid('', true)), 0, 8),
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
        ], $overrides);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, clubId, ara]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $sentAra = strtoupper($suffix);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'I', 'lastName' => 'Mmutable', 'ara' => $sentAra, 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $me = $this->get('/api/me', $token);

        return [$token, $me['club']['id'], $sentAra];
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $uri, string $token, array $body = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], [] === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function get(string $uri, string $token): array
    {
        $this->request('GET', $uri, $token);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
