<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Plans\PlanComposition;
use App\Service\TenantConnectionContext;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * BCK-05: the custom State providers returned a bare array, so hydra:totalItems
 * reflected only the page size (30), never the real row count — a client could
 * not know how many pages exist. The base now returns a paginator; this locks
 * the real total in.
 */
#[Group('phase1')]
#[Group('integration')]
final class CollectionPaginationTest extends WebTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testCollectionExposesRealTotalItemsNotPageSize(): void
    {
        [$token, $clubId] = $this->register();
        $this->seedVenues($clubId, 31);

        $data = $this->get('/api/venues', $token);

        $total = $data['totalItems'] ?? $data['hydra:totalItems'] ?? null;
        self::assertSame(31, $total, 'totalItems must reflect the real count, not the 30-item page size');

        $member = $data['member'] ?? $data['hydra:member'] ?? [];
        self::assertCount(30, $member, 'the page itself is still capped at the 30-item page size');
    }

    /**
     * BCK-05: the ?isActive= wiring must filter only when the param is present.
     * An absent param must return ALL teams — regression guard for the
     * filter_var(null) → false trap that silently applied "isActive = false".
     */
    public function testTeamIsActiveFilter(): void
    {
        [$token, $clubId] = $this->register();
        $this->seedTeam($clubId, 'Active team', true);
        $this->seedTeam($clubId, 'Inactive team', false);

        self::assertSame(2, $this->total($this->get('/api/teams', $token)), 'no filter → all teams');
        self::assertSame(1, $this->total($this->get('/api/teams?isActive=true', $token)), 'only active');
        self::assertSame(1, $this->total($this->get('/api/teams?isActive=false', $token)), 'only inactive');
        self::assertSame(2, $this->total($this->get('/api/teams?isActive=notabool', $token)), 'garbage → filter skipped');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** @param array<string, mixed> $data */
    private function total(array $data): ?int
    {
        return $data['totalItems'] ?? $data['hydra:totalItems'] ?? null;
    }

    private function seedTeam(string $clubId, string $name, bool $isActive): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $container->get(TenantConnectionContext::class)->setClubId($clubId);

        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        $category = $em->getRepository(SportCategory::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season);
        self::assertInstanceOf(SportCategory::class, $category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name);
        $team->setIsActive($isActive);
        $em->persist($team);
        $em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2]]);
        $em->flush();
    }

    private function seedVenues(string $clubId, int $count): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $container->get(TenantConnectionContext::class)->setClubId($clubId);

        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Season::class, $season, 'register must have created a season');

        for ($i = 0; $i < $count; ++$i) {
            $venue = $this->newVenue($clubId, $season->getId(), \sprintf('Gymnase %02d', $i));
            $em->persist($venue);
        }
        $em->flush();
    }

    /**
     * @return array{0: string, 1: string} [token, clubId]
     */
    private function register(): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'pag' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'P', 'lastName' => 'Ag', 'ara' => strtoupper($suffix), 'club_name' => 'Pag Club', 'consent' => true,
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
