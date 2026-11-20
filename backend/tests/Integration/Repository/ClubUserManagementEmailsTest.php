<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findManagementEmails returns a club's active owner/admin emails via raw DBAL —
 * tenant-scoped by the WHERE (not the filter), so it works with an empty GUC (the
 * cron context) and never leaks another club's managers.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubUserManagementEmailsTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ClubUserRepository $repo;

    public function testReturnsActiveManagersOnly(): void
    {
        $club = $this->club('MG1');
        $this->member($club, 'owner@c.fr', 'admin', true);
        $this->member($club, 'admin@c.fr', 'admin', true);
        $this->member($club, 'editor@c.fr', 'member', true);
        $this->member($club, 'viewer@c.fr', 'member', true);
        $this->member($club, 'exadmin@c.fr', 'admin', false);

        // The cron has no tenant context → query under an empty GUC.
        $this->clearGuc();
        $emails = $this->repo->findManagementEmails($club->getId());

        sort($emails);
        self::assertSame(['admin@c.fr', 'owner@c.fr'], $emails);
    }

    public function testDoesNotLeakOtherClubManagers(): void
    {
        $clubA = $this->club('MG2A');
        $clubB = $this->club('MG2B');
        $this->member($clubA, 'a@c.fr', 'admin', true);
        $this->member($clubB, 'b@c.fr', 'admin', true);

        $this->clearGuc();
        self::assertSame(['a@c.fr'], $this->repo->findManagementEmails($clubA->getId()));
        self::assertSame(['b@c.fr'], $this->repo->findManagementEmails($clubB->getId()));
    }

    public function testNoManagerReturnsEmpty(): void
    {
        $club = $this->club('MG3');
        $this->member($club, 'viewer@c.fr', 'member', true);

        $this->clearGuc();
        self::assertSame([], $this->repo->findManagementEmails($club->getId()));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(ClubUserRepository::class);
    }

    private function member(Club $club, string $email, string $role, bool $active): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('X');
        $user->setLastName('Y');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole($role);
        $cu->setIsActive($active);
        $this->em->persist($cu);
        $this->em->flush();
    }

    private function club(string $tag): Club
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }
}
