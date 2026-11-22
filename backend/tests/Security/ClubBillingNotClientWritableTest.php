<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-15: plan / billing / quota fields are server-managed — a club admin must NOT be
 * able to self-assign a plan, a paid season or reset the generation quota via PUT
 * /api/clubs/{id}. The fields are absent from ClubInput, so any such body key is ignored
 * (entity unchanged, no subscription line written). The database half of the same promise
 * (the application role can neither create nor change a subscription line):
 * RlsIsolationTest::testTheApplicationRoleCanNeitherCreateNorChangeASubscriptionLineEvenOnItsOwnClub.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubBillingNotClientWritableTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testPlanAndQuotaFieldsAreIgnoredOnClubPut(): void
    {
        [$user, $club] = $this->seed();
        $originalBillingCycle = $club->getBillingCycle();
        $originalPlanExpiresAt = $club->getPlanExpiresAt();
        $clubId = $club->getId();

        $this->client->loginUser($user);
        $this->client->request('PUT', "/api/clubs/{$clubId}", [], [], ['CONTENT_TYPE' => 'application/ld+json'], json_encode([
            'name' => 'Renamed Club',
            'slug' => $club->getSlug(),
            'timezone' => 'Europe/Paris',
            'locale' => 'fr',
            // Attacker payload: try to self-assign a plan + a paid season + billing state + wipe the quota.
            'planId' => '00000000-0000-4000-8000-000000000002',
            'paidSeasonYear' => 2099,
            'subscription' => ['planCode' => 'beta', 'seasonYear' => 2026],
            'billingCycle' => 'annual',
            'planExpiresAt' => '2099-12-31T00:00:00+00:00',
            'generationCountSeason' => 0,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Club::class)->find($clubId);
        self::assertSame('Renamed Club', $reloaded?->getName(), 'the legit field IS applied');
        // Read on BOTH connections the request could write through (DAMA: one transaction per
        // connection — the runtime one under the club's tenant scoped in seed(), the owner one used by
        // the subscription writer): a line born from the request would be visible on one of them.
        $admin = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($admin instanceof Connection);
        foreach (['runtime' => $this->em->getConnection(), 'owner' => $admin] as $name => $connection) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM club_subscription WHERE club_id = ?', [$clubId]), \sprintf('planId / paidSeasonYear / subscription must be ignored: no subscription line is born from a club PUT (%s connection)', $name));
        }
        self::assertSame($originalBillingCycle, $reloaded?->getBillingCycle(), 'billingCycle must be ignored');
        self::assertEquals($originalPlanExpiresAt, $reloaded?->getPlanExpiresAt(), 'planExpiresAt must be ignored');
        self::assertSame(7, $reloaded?->getGenerationCountSeason(), 'quota counter must be ignored (stays 7)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Club} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $club = new Club;
        $club->setName('Billing ' . $uid);
        $club->setSlug('billing-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('BILL' . strtoupper(substr(md5($uid), 0, 7)));
        $club->setGenerationCountSeason(7);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('bill-' . $uid . '@test.com');
        $user->setFirstName('B');
        $user->setLastName('Ill');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return [$user, $club];
    }
}
