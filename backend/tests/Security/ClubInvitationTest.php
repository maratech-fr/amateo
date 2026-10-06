<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubInvitation;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR d'axes « auth & memberships » + « tenant isolation » (§7.1) — P4-299 : un gestionnaire
 * invite une adresse e-mail, l'invité entre membre ACTIF du club par un lien à usage unique.
 *
 * Gardes falsifiées (désactiver la garde → rouge), dans les deux sens où c'est un sens :
 *  - un club ne liste/révoque JAMAIS une invitation d'un autre club (RLS + check de club) ;
 *  - un jeton inconnu / malformé / EXPIRÉ / révoqué rend un 404 BYTE-IDENTIQUE (aucun oracle) ;
 *  - single-use : un second accept du même jeton rend 404, et une seule adhésion existe ;
 *  - l'adhésion naît ACTIVE au rôle invité, le compte créé naît VÉRIFIÉ ;
 *  - une invitation ABSORBE une adhésion PENDING (jamais deux lignes club_user) ;
 *  - un compte connecté dont l'e-mail n'est pas l'adresse invitée est REFUSÉ (403) ;
 *  - refus démo des deux côtés : émetteur démo (403) ET adresse démo (422) ;
 *  - accepter ANNULE le préavis orphelin (cancelFor) ;
 *  - P4-304 : l'expiration se mesure sur `app.clock.real`, jamais l'horloge simulée d'un club
 *    démo porté par un JWT — réinjecter l'horloge décorée dans ClubInvitationManager ferait
 *    paraître vivant un lien expiré HIER.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubInvitationTest extends WebTestCase
{
    private KernelBrowser $client;

    public function testListingAndRevokeAreTenantScoped(): void
    {
        [$clubA, , $jwtA] = $this->seedClubWithManager();
        [, , $jwtB] = $this->seedClubWithManager();

        $invitationId = $this->seedInvitation($clubA, 'invitee-a@test.fr', ClubRole::MEMBER, $this->raw(), $this->anyUuid());

        // A voit son invitation ; B ne la voit jamais (RLS + scope club).
        $listA = $this->send('GET', '/api/invitations', $jwtA);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertContainsEmail('invitee-a@test.fr', $listA['invitations']);

        $listB = $this->send('GET', '/api/invitations', $jwtB);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNotContainsEmail('invitee-a@test.fr', $listB['invitations']);

        // B ne peut pas révoquer l'invitation de A (introuvable dans SON club → 404).
        $this->send('DELETE', '/api/invitations/' . $invitationId, $jwtB);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->invitationExists($invitationId), 'l\'invitation de A survit à la tentative de B');

        // A la révoque.
        $this->send('DELETE', '/api/invitations/' . $invitationId, $jwtA);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->invitationExists($invitationId));
    }

    public function testUnknownExpiredAndRevokedTokensAllReturnIdentical404(): void
    {
        [$clubA] = $this->seedClubWithManager();

        $invented = str_repeat('0', 64);
        $expiredRaw = $this->raw();
        $this->seedInvitation($clubA, 'expired@test.fr', ClubRole::MEMBER, $expiredRaw, $this->anyUuid(), '-1 day');

        $bodyInvented = $this->publicGetBody($invented);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $bodyExpired = $this->publicGetBody($expiredRaw);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'un lien expiré rend 404 (pas 410) : rien ne le distingue d\'un jeton inventé');

        // Révoqué = supprimé : même 404 byte-identique.
        $revokedRaw = $this->raw();
        $revokedId = $this->seedInvitation($clubA, 'revoked@test.fr', ClubRole::MEMBER, $revokedRaw, $this->anyUuid());
        $this->removeInvitation($revokedId);
        $bodyRevoked = $this->publicGetBody($revokedRaw);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        self::assertSame($bodyInvented, $bodyExpired, '404 byte-identique entre inventé et expiré');
        self::assertSame($bodyInvented, $bodyRevoked, '404 byte-identique entre inventé et révoqué');
    }

    public function testSingleUseTokenCannotBeReplayedAndCreatesOneActiveMembership(): void
    {
        [$clubA] = $this->seedClubWithManager();
        $raw = $this->raw();
        $this->seedInvitation($clubA, 'paul@test.fr', ClubRole::MANAGER, $raw, $this->anyUuid());

        $this->publicAccept($raw, ['firstName' => 'Paul', 'lastName' => 'Durand', 'password' => 'Password123!', 'consent' => true]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Compte né VÉRIFIÉ + adhésion ACTIVE au rôle invité (Gestionnaire = admin).
        $em = $this->em();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'paul@test.fr']);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getEmailVerifiedAt(), 'le compte créé par invitation naît vérifié');
        $memberships = $this->membershipsFor($clubA, $user->getId());
        self::assertCount(1, $memberships);
        self::assertTrue($memberships[0]->getIsActive());
        self::assertSame(ClubRole::MANAGER->value, $memberships[0]->getRole());

        // Rejouer le même jeton → 404, aucune seconde adhésion.
        $this->publicAccept($raw, ['firstName' => 'Paul', 'lastName' => 'Durand', 'password' => 'Password123!', 'consent' => true]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->membershipsFor($clubA, $user->getId()), 'single-use : jamais deux adhésions');
    }

    public function testAcceptAbsorbsAPendingMembershipWithoutCreatingASecondRow(): void
    {
        [$clubA] = $this->seedClubWithManager();
        $userId = $this->seedUser('pending@test.fr', verified: true, demo: false);
        $this->seedMembership($clubA, $userId, 'member', isActive: false); // pending (deactivatedAt null)
        $raw = $this->raw();
        $this->seedInvitation($clubA, 'pending@test.fr', ClubRole::MANAGER, $raw, $this->anyUuid());

        $jwt = $this->jwtFor($userId);
        $this->send('POST', '/api/invitations/' . $raw . '/accept', $jwt);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $memberships = $this->membershipsFor($clubA, $userId);
        self::assertCount(1, $memberships, 'la pending est absorbée, jamais une seconde ligne club_user');
        self::assertTrue($memberships[0]->getIsActive());
        self::assertNull($memberships[0]->getDeactivatedAt());
        self::assertSame(ClubRole::MANAGER->value, $memberships[0]->getRole());
    }

    public function testConnectedUserWhoseEmailIsNotTheInvitedAddressIsRefused(): void
    {
        [$clubA] = $this->seedClubWithManager();
        $this->seedInvitation($clubA, 'alice@test.fr', ClubRole::MEMBER, $raw = $this->raw(), $this->anyUuid());

        $bobId = $this->seedUser('bob@test.fr', verified: true, demo: false);
        $this->send('POST', '/api/invitations/' . $raw . '/accept', $this->jwtFor($bobId));
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'le jeton identifie l\'ADRESSE, le login le COMPTE — il faut les deux');
        self::assertCount(0, $this->membershipsFor($clubA, $bobId));
    }

    public function testDemoManagerCannotInvite(): void
    {
        [, , $demoJwt] = $this->seedClubWithManager(managerDemo: true);

        $this->send('POST', '/api/invitations', $demoJwt, ['email' => 'target@test.fr', 'role' => 'member']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'un gestionnaire démo ne peut pas inviter (SEC-28)');
    }

    public function testDemoAddressCannotBeInvited(): void
    {
        [, , $jwt] = $this->seedClubWithManager();
        $this->seedUser('demo-target@test.fr', verified: true, demo: true);

        $this->send('POST', '/api/invitations', $jwt, ['email' => 'demo-target@test.fr', 'role' => 'member']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'une adresse de compte démo ne gagne aucune adhésion réelle');
    }

    public function testAlreadyActiveMemberIsRefusedWithAClearMessage(): void
    {
        [$clubA, , $jwt] = $this->seedClubWithManager();
        $memberId = $this->seedUser('member@test.fr', verified: true, demo: false);
        $this->seedMembership($clubA, $memberId, 'member', isActive: true);

        $this->send('POST', '/api/invitations', $jwt, ['email' => 'member@test.fr', 'role' => 'member']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'déjà membre actif de CE club → 422 clair');
    }

    public function testAcceptCancelsAnOrphanNotice(): void
    {
        [$clubA] = $this->seedClubWithManager();
        $orphanId = $this->seedUser('orphan@test.fr', verified: true, demo: false);
        $this->stampOrphanNotice($orphanId);
        $this->seedInvitation($clubA, 'orphan@test.fr', ClubRole::MEMBER, $raw = $this->raw(), $this->anyUuid());

        $this->send('POST', '/api/invitations/' . $raw . '/accept', $this->jwtFor($orphanId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $em = $this->em();
        $em->clear();
        $orphan = $em->getRepository(User::class)->find($orphanId);
        self::assertInstanceOf(User::class, $orphan);
        self::assertNull($orphan->getOrphanNoticeSentAt(), 'regagner une adhésion active annule le préavis orphelin (cancelFor)');
    }

    public function testExpiryUsesTheRealClockUnderASimulatedDemoClock(): void
    {
        // P4-304 — un JWT de club démo (horloge simulée 2000-01-01) porté sur la route
        // publique fait poser le `_club_id` du démo par le listener, donc l'horloge décorée
        // renverrait 2000 ; l'expiration doit néanmoins se mesurer à l'instant RÉEL. Une
        // invitation de CE club démo, expirée HIER (réel), est donc morte → 404. Falsifié :
        // réinjecter l'horloge décorée (`clock`) dans ClubInvitationManager la ferait
        // paraître encore vivante (2000 < expiration) → 200.
        [$demoClub, , $demoJwt] = $this->seedClubWithManager(demo: true, simulatedToday: '2000-01-01');
        $raw = $this->raw();
        $this->seedInvitation($demoClub, 'late@test.fr', ClubRole::MEMBER, $raw, $this->anyUuid(), '-1 day');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/invitations/public/' . $raw, [], [], [
            'REMOTE_ADDR' => $this->ip(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $demoJwt,
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'lien expiré à l\'heure RÉELLE → 404, jamais prolongé par la date simulée du club démo');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** @param list<array<string, mixed>> $invitations */
    private function assertContainsEmail(string $email, array $invitations): void
    {
        self::assertContains($email, array_column($invitations, 'email'));
    }

    /** @param list<array<string, mixed>> $invitations */
    private function assertNotContainsEmail(string $email, array $invitations): void
    {
        self::assertNotContains($email, array_column($invitations, 'email'));
    }

    /**
     * Club réel (ou démo) + gestionnaire actif ; rend [clubId, managerId, jwt].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function seedClubWithManager(bool $demo = false, ?string $simulatedToday = null, bool $managerDemo = false): array
    {
        $em = $this->em();
        $hasher = $this->hasher();
        $uid = substr(md5(uniqid('', true)), 0, 8);

        $club = new Club;
        $club->setName('Club ' . $uid);
        $club->setSlug('club-' . $uid);
        $club->setIsDemo($demo);
        if (null !== $simulatedToday) {
            $club->setSimulatedToday(new DateTimeImmutable($simulatedToday));
        }
        $em->persist($club);

        $manager = new User;
        $manager->setEmail('mgr-' . $uid . '@test.fr');
        $manager->setFirstName('Ges');
        $manager->setLastName('Tionnaire');
        $manager->setPasswordHash($hasher->hashPassword($manager, 'Password123!'));
        $manager->setIsDemo($managerDemo);
        $em->persist($manager);
        $em->flush();

        $this->underTenant($club->getId(), function () use ($em, $club, $manager): void {
            $membership = new ClubUser;
            $membership->setClubId($club->getId());
            $membership->setUserId($manager->getId());
            $membership->setRole('admin');
            $membership->setIsActive(true);
            $em->persist($membership);
            $em->flush();
        });

        $jwt = $this->jwtManager()->create($manager);
        $clubId = $club->getId();
        $managerId = $manager->getId();
        $em->clear();

        return [$clubId, $managerId, $jwt];
    }

    private function seedUser(string $email, bool $verified, bool $demo): string
    {
        $em = $this->em();
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPasswordHash($this->hasher()->hashPassword($user, 'Password123!'));
        $user->setIsDemo($demo);
        if ($verified) {
            $user->setEmailVerifiedAt(new DateTimeImmutable('now'));
        }
        $em->persist($user);
        $em->flush();
        $id = $user->getId();
        $em->clear();

        return $id;
    }

    private function seedMembership(string $clubId, string $userId, string $role, bool $isActive): void
    {
        $em = $this->em();
        $this->underTenant($clubId, function () use ($em, $clubId, $userId, $role, $isActive): void {
            $membership = new ClubUser;
            $membership->setClubId($clubId);
            $membership->setUserId($userId);
            $membership->setRole($role);
            $membership->setIsActive($isActive);
            $em->persist($membership);
            $em->flush();
        });
        $em->clear();
    }

    private function seedInvitation(string $clubId, string $email, ClubRole $role, string $raw, string $invitedBy, string $expiresModify = '+7 days'): string
    {
        $em = $this->em();
        $id = '';
        $this->underTenant($clubId, function () use ($em, $clubId, $email, $role, $raw, $invitedBy, $expiresModify, &$id): void {
            $invitation = new ClubInvitation;
            $invitation->setClubId($clubId);
            $invitation->setEmail($email);
            $invitation->setRole($role->value);
            $invitation->setTokenHash(hash('sha256', $raw));
            $invitation->setExpiresAt(new DateTimeImmutable('now')->modify($expiresModify));
            $invitation->setInvitedByUserId($invitedBy);
            $em->persist($invitation);
            $em->flush();
            $id = $invitation->getId();
        });
        $em->clear();

        return $id;
    }

    /** Existence en SQL brut HORS tenant (branche SELECT hybride, GUC vide) — hors filtre Doctrine. */
    private function invitationExists(string $id): bool
    {
        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        \assert($tenant instanceof TenantConnectionContext);

        return (bool) $tenant->runWithoutTenant(fn (): bool => (bool) $this->em()->getConnection()->fetchOne(
            'SELECT 1 FROM club_invitation WHERE id = ?',
            [$id],
        ));
    }

    private function removeInvitation(string $id): void
    {
        $em = $this->em();
        $invitation = $em->getRepository(ClubInvitation::class)->find($id);
        if ($invitation instanceof ClubInvitation) {
            $this->underTenant((string) $invitation->getClubId(), function () use ($em, $invitation): void {
                $em->remove($invitation);
                $em->flush();
            });
        }
        $em->clear();
    }

    private function stampOrphanNotice(string $userId): void
    {
        $em = $this->em();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        $user->setOrphanNoticeSentAt(new DateTimeImmutable('now'));
        $em->flush();
        $em->clear();
    }

    /** @return list<ClubUser> */
    private function membershipsFor(string $clubId, string $userId): array
    {
        $em = $this->em();
        $em->clear();
        /** @var list<ClubUser> $rows */
        $rows = $this->underTenant($clubId, fn (): array => $em->getRepository(ClubUser::class)->findBy(['clubId' => $clubId, 'userId' => $userId]));

        return $rows;
    }

    private function jwtFor(string $userId): string
    {
        $em = $this->em();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);

        return $this->jwtManager()->create($user);
    }

    private function raw(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function anyUuid(): string
    {
        return $this->seedUser('inviter-' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr', verified: true, demo: false);
    }

    private function ip(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, string $jwt, array $body = []): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], [] === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    private function publicGetBody(string $token): string
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/invitations/public/' . $token, [], [], [
            'REMOTE_ADDR' => $this->ip(),
        ]);

        return (string) $this->client->getResponse()->getContent();
    }

    /** @param array<string, mixed> $body */
    private function publicAccept(string $token, array $body): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/invitations/public/' . $token . '/accept', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip(),
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function decode(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (array) json_decode($content, true);
    }

    /** @template T */
    private function underTenant(string $clubId, callable $fn): mixed
    {
        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        \assert($tenant instanceof TenantConnectionContext);
        $tenant->setClubId($clubId);
        try {
            return $fn();
        } finally {
            $tenant->clear();
        }
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        return $hasher;
    }

    private function jwtManager(): JWTTokenManagerInterface
    {
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($jwt instanceof JWTTokenManagerInterface);

        return $jwt;
    }
}
