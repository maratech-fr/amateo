<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubUser;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Service\ClubApprovalService;
use App\Service\EmailVerifier;
use App\Service\OrphanAccountNotifier;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * P4-301 non-regression (axe auth & memberships) : un compte qui a perdu son
 * dernier club est prévenu, puis supprimé 30 jours après.
 *
 * (a) le retrait du dernier accès (désactivation) → mail + stamp ;
 * (b) réactivation avant l'échéance → stamp annulé, le cron ne supprime rien ;
 * (c) le cron à J+30 → le compte est anonymisé (même routine que DELETE /api/me) ;
 * (d) revalidation : un compte redevenu actif, stamp échu, est SAUTÉ (jamais effacé) ;
 * (e) jamais effacé tant que le stamp a moins de 30 j ;
 * (f) un compte PENDING et un compte DÉMO ne sont jamais concernés ;
 * (g) le refus d'une demande de création déclenche le rail (décision Q1) ;
 * (h) un club purgé → ses membres orphelins sont notifiés (variante « espace supprimé ») ;
 * (i) un multi-club avec UNE adhésion active n'est jamais orphelin et `/api/me` = active,
 *     qui expose aussi l'échéance `accountDeletionScheduledFor` pour un désactivé stampé.
 */
#[Group('phase1')]
#[Group('integration')]
final class OrphanAccountLifecycleTest extends WebTestCase
{
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testDeactivatingTheLastAccessNotifiesAndStamps(): void
    {
        [$managerToken, , $clubId] = $this->registerManager('ORPA');
        $member = $this->insertMember($clubId, 'member-orpa-' . uniqid() . '@test.fr', 'editor', true, null);
        $membershipId = $this->membershipId($clubId, $member->getId());

        $this->post('/api/memberships/' . $membershipId . '/deactivate', $managerToken);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($member->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotNull($fresh->getOrphanNoticeSentAt(), 'désactivation du dernier accès → stamp posé');
        self::assertNotEmpty($this->emailsTo($member->getEmail()), 'un préavis part à l\'orphelin');
    }

    public function testReactivationCancelsTheNoticeAndTheCronDoesNothing(): void
    {
        [$managerToken, , $clubId] = $this->registerManager('ORPB');
        $member = $this->insertMember($clubId, 'member-orpb-' . uniqid() . '@test.fr', 'editor', false, new DateTimeImmutable);
        $this->stamp($member->getId(), '-31 days');
        $membershipId = $this->membershipId($clubId, $member->getId());

        $this->post('/api/memberships/' . $membershipId . '/reactivate', $managerToken);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($member->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertNull($fresh->getOrphanNoticeSentAt(), 'réactivation → stamp annulé');

        $this->runOrphanCron();
        $this->em()->clear();
        $afterCron = $this->em()->getRepository(User::class)->find($member->getId());
        self::assertInstanceOf(User::class, $afterCron);
        self::assertNull($afterCron->getAnonymizedAt(), 'réactivé → le cron ne supprime rien');
    }

    public function testCronAnonymizesAnOrphanThirtyDaysAfterTheNotice(): void
    {
        [, , $clubId] = $this->registerManager('ORPC');
        $member = $this->insertMember($clubId, 'member-orpc-' . uniqid() . '@test.fr', 'editor', false, new DateTimeImmutable);
        $this->stamp($member->getId(), '-31 days');

        $this->runOrphanCron();

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($member->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotNull($fresh->getAnonymizedAt(), 'stamp ≥ 30 j → compte anonymisé');
        self::assertStringEndsWith('@anonymized.invalid', $fresh->getEmail());
    }

    public function testCronRevalidatesAndSkipsAnAccountThatBecameActiveAgain(): void
    {
        [, , $clubId] = $this->registerManager('ORPD');
        $member = $this->insertMember($clubId, 'member-orpd-' . uniqid() . '@test.fr', 'admin', true, null);
        // Stamp échu posé à tort (course : cancelFor raté), MAIS l'adhésion est active.
        $this->stamp($member->getId(), '-31 days');

        $this->runOrphanCron();

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($member->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertNull($fresh->getAnonymizedAt(), 'redevenu actif → sauté, jamais effacé');
        self::assertNull($fresh->getOrphanNoticeSentAt(), 'stamp résiduel nettoyé à la revalidation');
    }

    public function testAnOrphanIsNeverErasedWhileTheNoticeIsYoungerThanThirtyDays(): void
    {
        [, , $clubId] = $this->registerManager('ORPE');
        $member = $this->insertMember($clubId, 'member-orpe-' . uniqid() . '@test.fr', 'editor', false, new DateTimeImmutable);
        $this->stamp($member->getId(), '-10 days');

        $this->runOrphanCron();

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($member->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertNull($fresh->getAnonymizedAt(), 'préavis de moins de 30 j → jamais effacé');
        self::assertNotNull($fresh->getOrphanNoticeSentAt(), 'stamp conservé');
    }

    public function testPendingAndDemoAccountsAreNeverConcerned(): void
    {
        [, , $clubId] = $this->registerManager('ORPF');
        $pending = $this->insertMember($clubId, 'member-orpf-' . uniqid() . '@test.fr', 'editor', false, null); // pending = deactivatedAt null
        $demo = $this->insertMember($clubId, $this->demoAnimatorEmail(), 'editor', false, new DateTimeImmutable, isDemo: true);

        $notifier = $this->notifier();
        self::assertFalse($notifier->isOrphan($this->reload($pending)), 'une adhésion pending protège de la règle');
        self::assertFalse($notifier->isOrphan($this->reload($demo)), 'un compte démo n\'est jamais concerné');

        $this->runOrphanCron();
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(User::class)->find($pending->getId())?->getOrphanNoticeSentAt());
        self::assertNull($this->em()->getRepository(User::class)->find($demo->getId())?->getOrphanNoticeSentAt());
    }

    public function testRefusingAClubCreationRequestTriggersTheRail(): void
    {
        [$email, $userId] = $this->registerPendingClubRequest('ORPG');

        $request = $this->em()->getRepository(ClubCreationRequest::class)->findOneBy(['userId' => $userId]);
        self::assertInstanceOf(ClubCreationRequest::class, $request);

        $approval = self::getContainer()->get(ClubApprovalService::class);
        \assert($approval instanceof ClubApprovalService);
        $approval->refuse($request);

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotNull($fresh->getOrphanNoticeSentAt(), 'refus d\'une création → préavis + stamp');
        self::assertNotEmpty($this->emailsTo($email), 'un préavis part au demandeur refusé');
    }

    public function testPurgingAClubNotifiesItsNowOrphanedMembers(): void
    {
        [, , $clubId] = $this->registerManager('ORPH');
        $member = $this->insertMember($clubId, 'member-orph-' . uniqid() . '@test.fr', 'editor', false, new DateTimeImmutable);

        // Club orphelin, échéance passée, toutes adhésions inactives (comme après un effacement).
        $em = $this->em();
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        $club->setErasureScheduledAt(new DateTimeImmutable('-1 hour'));
        $this->scopeGucToClub($clubId);
        $em->getConnection()->executeStatement('UPDATE club_user SET is_active = false WHERE club_id = :cid', ['cid' => $clubId]);
        $this->clearGuc();
        $em->flush();
        $em->clear();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:clubs:purge-erased'));
        self::assertSame(0, $tester->execute([]));

        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($member->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotNull($fresh->getOrphanNoticeSentAt(), 'club purgé → membre orphelin stampé');
        $emails = $this->emailsTo($member->getEmail());
        self::assertNotEmpty($emails, 'un préavis « espace supprimé » part au membre');
        self::assertStringContainsString('a été supprimé', (string) end($emails)->getTextBody());
    }

    public function testMultiClubWithOneActiveMembershipIsNeverOrphanAndMeStaysActive(): void
    {
        [$token, $userId, $clubId] = $this->registerManager('ORPI');
        // Une seconde adhésion DÉSACTIVÉE dans un autre club : le compte garde clubId actif.
        [, , $otherClubId] = $this->registerManager('ORPJ');
        $this->insertMembershipFor($otherClubId, $userId, 'editor', false, new DateTimeImmutable);
        // Stamp posé à tort : l'adhésion active doit l'emporter (jamais orphelin).
        $this->stamp($userId, '-31 days');

        $multi = $this->em()->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $multi);
        self::assertFalse($this->notifier()->isOrphan($multi));

        $this->runOrphanCron();
        $this->em()->clear();
        $fresh = $this->em()->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $fresh);
        self::assertNull($fresh->getAnonymizedAt(), 'une adhésion active empêche l\'effacement');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('active', $me['membershipStatus'], 'multi-club avec une adhésion active → active');
        self::assertSame($clubId, $me['club']['id'] ?? null);
    }

    public function testMeExposesTheDeletionDeadlineForADeactivatedStampedAccount(): void
    {
        [$managerToken, , $clubId] = $this->registerManager('ORPK');
        $member = $this->insertMember($clubId, 'member-orpk-' . uniqid() . '@test.fr', 'editor', false, new DateTimeImmutable);
        $this->stamp($member->getId(), '-2 days');
        $memberToken = $this->tokenFor($member->getEmail());

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken]);
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('deactivated', $me['membershipStatus']);
        // stamp = now - 2 j ; échéance = stamp + 30 j = now + 28 j.
        $expected = new DateTimeImmutable('-2 days')->modify('+30 days')->format('Y-m-d');
        self::assertSame($expected, $me['accountDeletionScheduledFor'] ?? null);

        // Non-régression de l'index CI : le manager actif ne porte jamais d'échéance.
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $managerToken]);
        $managerMe = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($managerMe['accountDeletionScheduledFor'] ?? null, 'un actif n\'a pas d\'échéance');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** @return array{0: string, 1: string, 2: string} [token, userId, clubId] */
    private function registerManager(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'O', 'lastName' => 'Rphan', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }

    /** @return array{0: string, 1: string} [email, userId] — registered + verified, request still pending (not approved). */
    private function registerPendingClubRequest(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'P', 'lastName' => 'Ending', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        // Verify WITHOUT the dev auto-approval (so the creation request stays pending).
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User);
        $pending = $em->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        \assert($pending instanceof EmailVerificationToken);
        $raw = $container->get(EmailVerifier::class)->generateToken($user, $pending->getAra(), $pending->getClubName());
        $em->flush();
        $this->client->request('POST', '/api/register/verify', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode(['token' => $raw], \JSON_THROW_ON_ERROR));

        return [$email, $user->getId()];
    }

    private function insertMember(string $clubId, string $email, string $role, bool $active, ?DateTimeImmutable $deactivatedAt, bool $isDemo = false): User
    {
        $em = $this->em();
        $user = new User;
        $user->setEmail(strtolower($email));
        $user->setFirstName('Membre');
        $user->setLastName('Orphelin');
        $user->setPasswordHash('x');
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        // SEC-28 — un compte démo est reconnu par le drapeau is_demo (plus par adresse).
        $user->setIsDemo($isDemo);
        $em->persist($user);
        $em->flush();
        $this->insertMembershipFor($clubId, $user->getId(), $role, $active, $deactivatedAt);

        return $user;
    }

    private function insertMembershipFor(string $clubId, string $userId, string $role, bool $active, ?DateTimeImmutable $deactivatedAt): void
    {
        $em = $this->em();
        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($userId);
        $membership->setRole($role);
        $membership->setIsActive($active);
        $membership->setDeactivatedAt($deactivatedAt);
        $em->persist($membership);
        $em->flush();
        $this->clearGuc();
    }

    private function membershipId(string $clubId, string $userId): string
    {
        $id = $this->em()->getConnection()->fetchOne(
            'SELECT id FROM club_user WHERE club_id = :cid AND user_id = :uid LIMIT 1',
            ['cid' => $clubId, 'uid' => $userId],
        );
        self::assertIsString($id);

        return $id;
    }

    private function stamp(string $userId, string $modifier): void
    {
        $em = $this->em();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        $user->setOrphanNoticeSentAt(new DateTimeImmutable($modifier));
        $em->flush();
        $em->clear();
    }

    private function runOrphanCron(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:users:purge-orphaned'));
        self::assertSame(0, $tester->execute([]));
    }

    private function notifier(): OrphanAccountNotifier
    {
        $notifier = self::getContainer()->get(OrphanAccountNotifier::class);
        \assert($notifier instanceof OrphanAccountNotifier);

        return $notifier;
    }

    private function reload(User $user): User
    {
        $fresh = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }

    private function tokenFor(string $email): string
    {
        $manager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        \assert($user instanceof User);
        \assert(method_exists($manager, 'create'));

        return $manager->create($user);
    }

    private function demoAnimatorEmail(): string
    {
        $value = self::getContainer()->getParameter('app.demo_animator_email');
        \assert(\is_string($value));

        return strtolower($value);
    }

    /** @return list<Email> the emails queued to the given recipient on the in-memory transport */
    private function emailsTo(string $recipient): array
    {
        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        \assert($transport instanceof InMemoryTransport);

        $emails = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof SendEmailMessage) {
                continue;
            }
            $email = $message->getMessage();
            if (!$email instanceof Email) {
                continue;
            }
            foreach ($email->getTo() as $address) {
                if (strtolower($address->getAddress()) === strtolower($recipient)) {
                    $emails[] = $email;
                }
            }
        }

        return $emails;
    }

    private function post(string $uri, string $token): void
    {
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
