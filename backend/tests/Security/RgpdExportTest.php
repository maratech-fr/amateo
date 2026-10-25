<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Feedback;
use App\Entity\User;
use App\Storage\FeedbackScreenshotStorage;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * RGPD PR-2 non-regression : portabilité (art. 20).
 *
 * (a) /api/me/export = ses données de compte, JAMAIS le hash de mot de passe ;
 * (b) /api/club/export = le workspace du club COURANT uniquement (frontière
 *     tenant : aucune ligne d'un autre club ne fuit) ;
 * (c) /api/club/export est management-gated (SEC-07) : editor actif → 403.
 */
#[Group('phase1')]
#[Group('integration')]
final class RgpdExportTest extends WebTestCase
{
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testMeExportReturnsOwnDataWithoutPasswordHash(): void
    {
        [$token, $userId, $email] = $this->registerVerified('EXPA');

        $this->client->request('GET', '/api/me/export', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->assertNotCacheable('/api/me/export');

        $raw = (string) $this->client->getResponse()->getContent();
        $data = json_decode($raw, true);
        self::assertSame($userId, $data['user']['id']);
        self::assertSame($email, $data['user']['email']);
        self::assertNotEmpty($data['memberships']);
        self::assertStringNotContainsString('passwordHash', $raw);
        self::assertStringNotContainsString('password_hash', $raw);
    }

    public function testClubExportIsTenantScoped(): void
    {
        // Club A avec un coach nommé de façon unique ; club B pareillement.
        [$tokenA, $userA] = $this->registerVerified('EXPB');
        $clubA = $this->clubIdOf($userA);
        [, $userB] = $this->registerVerified('EXPC');
        $clubB = $this->clubIdOf($userB);

        $this->insertCoach($clubA, $this->seasonOf($clubA), 'CoachAlphaExport');
        $this->insertCoach($clubB, $this->seasonOf($clubB), 'CoachBravoExport');

        $this->client->request('GET', '/api/club/export', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA]);
        self::assertResponseIsSuccessful();
        $this->assertNotCacheable('/api/club/export');

        $raw = (string) $this->client->getResponse()->getContent();
        $data = json_decode($raw, true);
        self::assertSame($clubA, $data['club']['id'], 'export du club courant');
        self::assertStringContainsString('CoachAlphaExport', $raw, 'les données du club A sont là');
        self::assertStringNotContainsString('CoachBravoExport', $raw, 'AUCUNE donnée du club B (tenant)');
        self::assertStringNotContainsString($clubB, $raw, 'aucun id du club B');
        // Tables clés présentes dans le format.
        foreach (['season', 'team', 'coach', 'venue', 'constraint', 'schedule', 'members'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
    }

    public function testClubExportRequiresAManagementRole(): void
    {
        [, $userA, $emailA] = $this->registerVerified('EXPD');
        $clubA = $this->clubIdOf($userA);

        // Un editor actif du même club : accès au club mais pas à l'export.
        $editorToken = $this->createActiveMemberWithToken($clubA, 'editor-' . strtolower($emailA), 'editor');

        $this->client->request('GET', '/api/club/export', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Chantier F PR4 — l'export de portabilité (art. 20) contient le TEXTE du signalement
     * (donnée fournie par la personne) mais JAMAIS les octets de la CAPTURE : le blob vit hors
     * table (FeedbackScreenshotStorage), et l'export ne lit que les lignes — jamais le stockage.
     *
     * Garde de non-régression (point sensible : export). Falsification documentée : faire lire
     * le stockage par RgpdExportService ferait apparaître la sentinelle du blob → ROUGE.
     */
    public function testClubExportNeverContainsTheScreenshot(): void
    {
        [$token, $userId] = $this->registerVerified('EXPS');
        $clubId = $this->clubIdOf($userId);

        $uniqueMessage = 'signalement exportable ' . bin2hex(random_bytes(4));
        $feedbackId = $this->seedFeedbackWithScreenshot($clubId, $userId, $uniqueMessage);
        $sentinel = 'SCREENSHOT_SENTINEL_' . bin2hex(random_bytes(6));
        $this->storage()->store($feedbackId, "\x89PNG\r\n" . $sentinel);

        try {
            $this->client->request('GET', '/api/club/export', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertResponseIsSuccessful();
            $raw = (string) $this->client->getResponse()->getContent();

            self::assertStringContainsString($uniqueMessage, $raw, 'le texte du signalement reste exporté (art. 20)');
            self::assertStringNotContainsString($sentinel, $raw, 'les octets de la capture ne sont JAMAIS dans l\'export');
        } finally {
            $this->storage()->delete($feedbackId);
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * P4-359 (hygiène, point sensible : export) — un export RGPD (du compte OU du club) est
     * NOMINATIF : il n'est jamais mis en cache (navigateur ou cache partagé), comme le PDF
     * authentifié (P4-357). `Cache-Control` contient `no-store` ET `private`, jamais `public`.
     *
     * Falsifiable : sans directive explicite, Symfony calcule `no-cache, private` — `no-store` est
     * ABSENT → ROUGE. Avec `setPrivate()` + `no-store` dans le contrôleur → VERT.
     */
    private function assertNotCacheable(string $label): void
    {
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl, $label . ' : une réponse nominative ne doit jamais être stockée en cache');
        self::assertStringContainsString('private', $cacheControl, $label . ' : jamais mise en cache partagée');
        self::assertStringNotContainsString('public', $cacheControl, $label . ' : jamais « public »');
    }

    private function storage(): FeedbackScreenshotStorage
    {
        $storage = self::getContainer()->get(FeedbackScreenshotStorage::class);
        \assert($storage instanceof FeedbackScreenshotStorage);

        return $storage;
    }

    private function seedFeedbackWithScreenshot(string $clubId, string $userId, string $message): string
    {
        $this->scopeGucToClub($clubId);
        $em = $this->em();
        $feedback = new Feedback($clubId, $userId, 'bug', $message);
        $feedback->setHasScreenshot(true);
        $em->persist($feedback);
        $em->flush();
        $id = $feedback->getId();
        $em->clear();
        $this->clearGuc();

        return $id;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, email]
     */
    private function registerVerified(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Ex', 'lastName' => 'Port', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $email];
    }

    /** Membre actif non-management avec un vrai JWT (login impossible : hash arbitraire) → JWT minté. */
    private function createActiveMemberWithToken(string $clubId, string $email, string $role): string
    {
        $em = $this->em();
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Edi');
        $user->setLastName('Tor');
        $user->setPasswordHash('x');
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $em->persist($user);
        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();
        $this->clearGuc();

        return self::getContainer()
            ->get(JWTTokenManagerInterface::class)
            ->create($user);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function clubIdOf(string $userId): string
    {
        $clubId = $this->em()->getConnection()->fetchOne(
            'SELECT club_id FROM club_user WHERE user_id = :uid LIMIT 1',
            ['uid' => $userId],
        );
        self::assertIsString($clubId);

        return $clubId;
    }

    private function seasonOf(string $clubId): string
    {
        $this->scopeGucToClub($clubId);
        $seasonId = $this->em()->getConnection()->fetchOne(
            'SELECT id FROM season WHERE club_id = :cid LIMIT 1',
            ['cid' => $clubId],
        );
        self::assertIsString($seasonId);

        return $seasonId;
    }

    private function insertCoach(string $clubId, string $seasonId, string $name): void
    {
        $this->scopeGucToClub($clubId);
        $em = $this->em();
        $coach = new Coach;
        $coach->setClubId($clubId);
        $coach->setSeasonId($seasonId);
        $coach->setFirstName($name);
        $coach->setLastName('Test');
        $em->persist($coach);
        $em->flush();
        $em->clear();
        $this->clearGuc();
    }
}
