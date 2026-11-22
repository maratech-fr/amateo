<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Season;
use App\Entity\Venue;
use App\Service\TenantConnectionContext;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The venues collection is the single source every screen inherits its order
 * from (wizard list, venue selects, planning grid columns) — none of them
 * re-sorts. Without an explicit ORDER BY the provider falls back to the UUID
 * tiebreaker, i.e. an order that means nothing to a human. The API must hand
 * venues out alphabetically (case-insensitive), whatever the insertion order.
 */
#[Group('phase1')]
#[Group('integration')]
final class VenueCollectionOrderTest extends WebTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testVenuesCollectionIsAlphabeticallyOrderedCaseInsensitive(): void
    {
        [$token, $clubId] = $this->register();
        foreach (['Zola', 'alpha', 'Mermoz', 'COSEC', 'Boucle', 'terrain sud'] as $name) {
            $this->seedVenue($clubId, $name);
        }

        $data = $this->get('/api/venues', $token);
        $member = $data['member'] ?? $data['hydra:member'] ?? [];
        $names = array_map(static fn (array $venue): string => $venue['name'], $member);

        self::assertSame(
            ['alpha', 'Boucle', 'COSEC', 'Mermoz', 'terrain sud', 'Zola'],
            $names,
            'venues must come alphabetically ordered (case-insensitive), whatever the insertion order',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function seedVenue(string $clubId, string $name): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $container->get(TenantConnectionContext::class)->setClubId($clubId);

        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season, 'register must have created a season');

        $venue = $this->newVenue($clubId, $season->getId(), $name);
        $em->persist($venue);
        $em->flush();
    }

    /**
     * @return array{0: string, 1: string} [token, clubId]
     */
    private function register(): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'vor' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'V', 'lastName' => 'Or', 'ara' => strtoupper($suffix), 'club_name' => 'Vor Club', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $me = $this->get('/api/me', $token);

        return [$token, $me['club']['id']];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri, string $token): array
    {
        $this->client->request('GET', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
