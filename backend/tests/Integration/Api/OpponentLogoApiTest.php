<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\User;
use App\Enum\OpponentLocationPrecision;
use App\Storage\LogoStorage;
use App\Tests\Double\RecordingFfbbLogoFetcher;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * C7 — la route MEMBRE `GET /api/opponents/{code}/logo` : sert le logo fédéral re-hébergé,
 * 404 sans logo connu, jamais accessible en anonyme (≠ la route PUBLIQUE des logos club/ligue).
 */
#[Group('integration')]
final class OpponentLogoApiTest extends WebTestCase
{
    use TenantGucTrait;

    // Un PNG 1×1 valide (finfo doit y lire image/png).
    private const string PNG_1X1 = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    public function testAnonymousIsRefused(): void
    {
        // Route MEMBRE : sans JWT, le firewall refuse (jamais publique).
        $this->client->request('GET', '/api/opponents/ARA0069AAA/logo');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testServesStoredBytesWithMimeAndPrivateCache(): void
    {
        self::getContainer()->get(LogoStorage::class)->store('ffbb-opponent-ARA0069AAA', self::PNG_1X1);
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/api/opponents/ARA0069AAA/logo');
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        // Symfony réordonne les directives Cache-Control — on vérifie leur PRÉSENCE.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('max-age=86400', $cacheControl);
    }

    public function testNoLogoKnownIs404(): void
    {
        // Une entrée d'annuaire SANS logo_id : rien à servir, rien à télécharger → 404.
        $entry = new OpponentDirectoryEntry('ARA0069BBB', 'Adverse sans logo', OpponentLocationPrecision::CITY);
        $entry->setCity('Lyon');
        $this->em->persist($entry);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/api/opponents/ARA0069BBB/logo');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnInvalidCodeIs404(): void
    {
        $this->client->loginUser($this->user);
        // Code trop long (> 24) : la contrainte de route ne matche pas → 404.
        $this->client->request('GET', '/api/opponents/THIS_CODE_IS_WAY_TOO_LONG_TO_MATCH/logo');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAFailedDownloadIsNotRetriedWhileTheNegativeMarkerLives(): void
    {
        // BCK-37 — une entrée AVEC logo_id dont le téléchargement ÉCHOUE : premier GET tente
        // une fois (404 + marqueur négatif posé), les GET suivants ne re-téléchargent plus.
        // disableReboot : le double fetcher (état en mémoire, non resettable) et son compteur
        // survivent aux deux GET. Auth par JWT Bearer (stateless, rejoué à chaque requête).
        $this->client->disableReboot();
        $code = 'ARA0069FAIL';
        $this->seedDirectoryWithLogo($code, 'cafecafe-dead-4beef-8bad-c0ffeec0ffee');
        $this->fetcher()->returns = null; // téléchargement en échec
        $auth = $this->authHeaders();

        $this->client->request('GET', \sprintf('/api/opponents/%s/logo', $code), [], [], $auth);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->fetcher()->calls, 'premier GET : une tentative de téléchargement');

        $this->client->request('GET', \sprintf('/api/opponents/%s/logo', $code), [], [], $auth);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->fetcher()->calls, 'second GET : le marqueur négatif évite un re-téléchargement');
    }

    public function testASuccessfulDownloadIsStoredThenServedFromStorageWithoutRedownloading(): void
    {
        // BCK-37 — un téléchargement RÉUSSI est stocké : le GET suivant sert les octets stockés
        // sans re-télécharger (un seul appel au fetcher).
        $this->client->disableReboot();
        $code = 'ARA0069OK';
        $this->seedDirectoryWithLogo($code, 'feedface-0000-4000-8000-feedface0000');
        $this->fetcher()->returns = self::PNG_1X1;
        $auth = $this->authHeaders();

        $this->client->request('GET', \sprintf('/api/opponents/%s/logo', $code), [], [], $auth);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(1, $this->fetcher()->calls);

        $this->client->request('GET', \sprintf('/api/opponents/%s/logo', $code), [], [], $auth);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->fetcher()->calls, 'servi depuis le stockage : aucun re-téléchargement');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Logo Test Club');
        $club->setSlug('logo-test-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $this->em->persist($club);

        $this->user = new User;
        $this->user->setEmail('logo' . $uid . '@test.com');
        $this->user->setFirstName('Logo');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('editor');
        $cu->setIsActive(true);
        $this->em->persist($cu);
        $this->em->flush();
    }

    private function seedDirectoryWithLogo(string $code, string $logoId): void
    {
        // Cache (Redis) non transactionnel : on efface le marqueur négatif résiduel d'un run
        // précédent (DEL ciblé, jamais FLUSHALL).
        $this->cache()->deleteItem('ffbb_logo_miss_' . $code);
        $this->storage()->delete(\sprintf('ffbb-opponent-%s', $code));

        $entry = new OpponentDirectoryEntry($code, 'Adverse avec logo', OpponentLocationPrecision::CITY);
        $entry->setCity('Lyon');
        $entry->setLogoId($logoId);
        $this->em->persist($entry);
        $this->em->flush();
        $this->fetcher()->reset();
    }

    private function fetcher(): RecordingFfbbLogoFetcher
    {
        $fetcher = self::getContainer()->get(RecordingFfbbLogoFetcher::class);
        \assert($fetcher instanceof RecordingFfbbLogoFetcher);

        return $fetcher;
    }

    private function cache(): CacheItemPoolInterface
    {
        $cache = self::getContainer()->get('cache.app');
        \assert($cache instanceof CacheItemPoolInterface);

        return $cache;
    }

    private function storage(): LogoStorage
    {
        $storage = self::getContainer()->get(LogoStorage::class);
        \assert($storage instanceof LogoStorage);

        return $storage;
    }

    /** @return array{HTTP_AUTHORIZATION: string} */
    private function authHeaders(): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
