<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * La session /api/me expose `club.isDemo` — le drapeau sur lequel le front adosse la
 * pastille « Démo » de l'en-tête (décision fondateur : une démo se dit). Vrai pour un
 * club is_demo, faux pour un vrai club.
 */
#[Group('integration')]
final class MeIsDemoTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    public function testDemoClubExposesIsDemoTrue(): void
    {
        $admin = $this->seedClubWithAdmin(isDemo: true);

        $me = $this->get('/api/me', $this->tokenFor($admin));

        self::assertArrayHasKey('isDemo', $me['club'], 'la session expose le drapeau démo');
        self::assertTrue($me['club']['isDemo'], 'un club is_demo → isDemo=true');
    }

    public function testRealClubExposesIsDemoFalse(): void
    {
        $admin = $this->seedClubWithAdmin(isDemo: false);

        $me = $this->get('/api/me', $this->tokenFor($admin));

        self::assertArrayHasKey('isDemo', $me['club']);
        self::assertFalse($me['club']['isDemo'], 'un vrai club → isDemo=false');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function seedClubWithAdmin(bool $isDemo): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $club = new Club;
        $club->setName('Me isDemo ' . $uid);
        $club->setSlug('me-isdemo-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo($isDemo);
        $this->em->persist($club);

        $admin = new User;
        $admin->setEmail('admin-' . $uid . '@test.fr');
        $admin->setFirstName('N');
        $admin->setLastName('Tester');
        $admin->setPasswordHash($hasher->hashPassword($admin, 'Password123!'));
        $this->em->persist($admin);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($admin->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();
        $this->clearGuc();

        return $admin;
    }

    private function tokenFor(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri, string $token): array
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
