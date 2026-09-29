<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Club identity accent: PATCH /api/club/appearance updates accentColor + palette
 * on the caller's club, validating hex, and /api/me exposes them.
 */
#[Group('integration')]
final class ClubAppearanceTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    public function testAccentColourAndPaletteAreWritableAndExposed(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('PATCH', '/api/club/appearance', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['accentColor' => '#e11d48', 'accentColorDark' => '#f59e0b', 'accentPalette' => ['#e11d48', '#1e293b', '#f59e0b']], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('#e11d48', $data['accentColor']);
        self::assertSame('#f59e0b', $data['accentColorDark']);
        self::assertSame(['#e11d48', '#1e293b', '#f59e0b'], $data['accentPalette']);

        // /api/me exposes both accents so the theme applies the right one per mode.
        // The stateless JWT firewall needs a Bearer on this request (loginUser's
        // session is ignored there).
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user);
        $this->client->request('GET', '/api/me', [], [], ['HTTP_X-Club-Id' => $this->club->getId(), 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('#e11d48', $me['club']['accentColor']);
        self::assertSame('#f59e0b', $me['club']['accentColorDark']);
    }

    public function testInvalidDarkHexIsRejected(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('PATCH', '/api/club/appearance', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['accentColorDark' => 'noir'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidHexIsRejected(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('PATCH', '/api/club/appearance', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['accentColor' => 'rouge'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testLogoUploadAndPublicServe(): void
    {
        $this->client->loginUser($this->user);

        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($tmp, $png);
        $file = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

        $this->client->request('POST', '/api/club/logo', [], ['file' => $file], ['HTTP_X-Club-Id' => $this->club->getId()]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $base = '/api/clubs/' . $this->club->getId() . '/logo';
        // URL carries a content-hash cache-buster (?v=...) so the browser refetches on change.
        self::assertStringStartsWith($base . '?v=', (string) $data['logoUrl']);

        // Served publicly with the right content type (query ignored by the route).
        $this->client->request('GET', (string) $data['logoUrl']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * P4-245 — le contrôleur logo ne lit plus l'en-tête `X-Club-Id` : un upload AUTHENTIFIÉ
     * SANS cet en-tête résout quand même le club, car le listener tenant pose `_club_id`
     * depuis l'adhésion active du porteur du JWT (repli d'appartenance). Le front n'envoie
     * jamais `X-Club-Id` (`CLAUDE.md` §10.3) : c'est bien `_club_id` du listener qui suffit.
     */
    public function testLogoUploadResolvesTheClubFromMembershipWithoutTheClubIdHeader(): void
    {
        $this->client->loginUser($this->user);

        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($tmp, $png);
        $file = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

        // Aucun en-tête X-Club-Id : le club vient de l'unique adhésion active du gestionnaire.
        $this->client->request('POST', '/api/club/logo', [], ['file' => $file], []);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringStartsWith('/api/clubs/' . $this->club->getId() . '/logo?v=', (string) $data['logoUrl']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Accent Test Club');
        $this->club->setSlug('accent-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('ACC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('accent' . $uid . '@test.com');
        $this->user->setFirstName('Accent');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($this->club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $this->em->flush();
    }
}
