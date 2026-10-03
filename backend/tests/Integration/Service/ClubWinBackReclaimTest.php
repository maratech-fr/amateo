<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Service\ClubProvisioner;
use App\Service\Registration\ClubWinBackService;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Correctif revue sécurité — `ClubWinBackService::reprise` doit RÉUTILISER une
 * adhésion déjà présente plutôt que d'en insérer une seconde.
 *
 * Un ancien membre d'un club devenu SANS membre actif (sa ligne `club_user` existe,
 * désactivée) peut voir sa demande de reprise approuvée. Une reprise qui INSÉRAIT une
 * nouvelle adhésion violerait la contrainte unique `uniq_club_user_membership
 * (club_id, user_id)` (un 500 au moment de la reprise). Ce garde prouve que la ligne
 * existante est RÉACTIVÉE et promue Gestionnaire active, sans doublon.
 */
#[Group('integration')]
final class ClubWinBackReclaimTest extends KernelTestCase
{
    public function testRepriseReusesAnExistingInactiveMembershipInsteadOfDuplicating(): void
    {
        self::bootKernel();
        $em = $this->em();
        $provisioner = self::getContainer()->get(ClubProvisioner::class);
        $winBack = self::getContainer()->get(ClubWinBackService::class);
        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        \assert($provisioner instanceof ClubProvisioner);
        \assert($winBack instanceof ClubWinBackService);
        \assert($tenant instanceof TenantConnectionContext);

        // Un propriétaire O (actif) et un ancien membre U (adhésion inactive) du club.
        $owner = $this->newUser('owner-reclaim');
        $member = $this->newUser('member-reclaim');
        $em->flush();

        $clubId = '';
        $em->wrapInTransaction(function () use ($provisioner, $tenant, $owner, $member, $em, &$clubId): void {
            $club = $provisioner->createClub('Club reprise', 'ARA0000077');
            $em->flush();
            $clubId = $club->getId();
            $tenant->setClubId($clubId);
            $provisioner->createMembership($clubId, $owner->getId(), true, ClubRole::MANAGER);
            $provisioner->createMembership($clubId, $member->getId(), false, ClubRole::MEMBER);
            $provisioner->seedWorkspace($club);
            $em->flush();
        });
        $tenant->clear();

        // Le dernier membre actif (O) s'en va → le club est orphelin ; U garde sa ligne
        // INACTIVE. On réactive la saison déjà seedée pour éviter tout re-seed parasite.
        $tenant->setClubId($clubId);
        try {
            $em->getConnection()->executeStatement(
                'UPDATE club_user SET is_active = false WHERE club_id = :cid',
                ['cid' => $clubId],
            );
        } finally {
            $tenant->clear();
        }
        $em->clear();

        // La reprise de U : sa ligne EXISTE déjà (inactive) → doit être réactivée, jamais dupliquée.
        $club = $em->getRepository(Club::class)->find($clubId);
        $em->wrapInTransaction(function () use ($winBack, $club, $member): void {
            $winBack->reprise($club, $member->getId());
        });
        $tenant->clear();
        $em->clear();

        // Exactement UNE adhésion pour (club, U), active et Gestionnaire.
        $rows = $em->getRepository(ClubUser::class)->findBy(['clubId' => $clubId, 'userId' => $member->getId()]);
        self::assertCount(1, $rows, 'la reprise ne doit pas créer de seconde adhésion (contrainte unique)');
        self::assertTrue($rows[0]->getIsActive(), 'l\'adhésion reprise est active');
        self::assertSame(ClubRole::MANAGER->value, $rows[0]->getRole(), 'le repreneur est promu Gestionnaire');
    }

    private function newUser(string $prefix): User
    {
        $user = new User;
        $user->setEmail($prefix . '-' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('User');
        $user->setPasswordHash('x');
        $this->em()->persist($user);

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
