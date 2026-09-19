<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\User;
use App\Enum\OpponentLocationPrecision;
use App\Storage\LogoStorage;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
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
}
