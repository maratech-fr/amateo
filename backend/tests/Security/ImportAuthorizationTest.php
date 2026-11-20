<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-04 non-regression: POST /api/clubs/{id}/import-teams requires an active
 * admin membership in the club named in the path (not just any authenticated
 * user). The tenant listener validates the header/JWT club, not the path {id}.
 */
#[Group('phase1')]
#[Group('integration')]
final class ImportAuthorizationTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testImportOnForeignClubReturns404(): void
    {
        [$tokenA] = $this->register('IMPA');
        [, , $clubB] = $this->register('IMPB');

        // A non-member gets 404 (not 403): the endpoint must not reveal that the
        // club id exists (no existence oracle, aligned with the Club PUT path).
        $this->client->request('POST', '/api/clubs/' . $clubB . '/import-teams', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(404, 'a non-member must not learn the club exists');
    }

    public function testImportAsActiveAdminReaches400WithoutFile(): void
    {
        [$tokenA, , $clubA] = $this->register('IMPC');

        // Guard passed → falls through to "No file uploaded" (400), proving the
        // admin membership check let the request through.
        $this->client->request('POST', '/api/clubs/' . $clubA . '/import-teams', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testImportAsOwnerReaches400WithoutFile(): void
    {
        [, , $clubA] = $this->register('IMPE');
        $ownerToken = $this->addActiveMember($clubA, 'admin');

        // 'admin' is a management role → guard passed → 400 (no file), not 403.
        $this->client->request('POST', '/api/clubs/' . $clubA . '/import-teams', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $ownerToken,
        ]);
        self::assertResponseStatusCodeSame(400, 'owner must be allowed to import');
    }

    public function testImportAsNonAdminMemberReturns403(): void
    {
        [, , $clubA] = $this->register('IMPD');
        $editorToken = $this->addActiveMember($clubA, 'member');

        // Active member of the club, but not a management role → 403 (not 400).
        $this->client->request('POST', '/api/clubs/' . $clubA . '/import-teams', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
        ]);
        self::assertResponseStatusCodeSame(403, 'a viewer/editor member must not import');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Admin');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function register(string $ara): array
    {
        // High-entropy IP: the register rate-limiter lives in Redis and is NOT
        // rolled back between test runs, so deterministic IPs eventually throttle.
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'I', 'lastName' => 'Import', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
