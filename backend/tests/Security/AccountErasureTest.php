<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Feedback;
use App\Entity\Season;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * RGPD PR-1 non-regression (axe auth & memberships) : le droit à l'effacement.
 *
 * (a) un compte effacé ne peut plus s'authentifier (login KO, JWT inerte) —
 *     et l'effacement exige une ré-authentification (mot de passe) ;
 * (b) dernier membre actif effacé → purge du club programmée (+30 j) ;
 * (c) un membre actif restant — MÊME non-management (editor) → PAS programmé ;
 * (d) app:clubs:purge-erased vide le workspace du club échu SANS toucher un
 *     autre club (tenant) et ÉPARGNE l'identité publique FFBB (fiche club) ;
 * (e) la commande REVALIDE à l'échéance : un membre actif revenu → annulation ;
 * (f) win-back : ré-inscription sur l'ARA d'un club purgé (sans membre) →
 *     reprise directe du club (owner actif), pas de pending inapprouvable.
 */
#[Group('phase1')]
#[Group('integration')]
final class AccountErasureTest extends WebTestCase
{
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testErasedAccountCannotAuthenticateAnymore(): void
    {
        [$token, , $email] = $this->registerVerified('ERAA');

        // Ré-authentification exigée : un mauvais mot de passe est rejeté —
        // un JWT volé ne suffit pas à détruire le compte.
        $this->request('DELETE', '/api/me', $token, ['password' => 'WrongPassword1!']);
        self::assertResponseStatusCodeSame(400);

        $this->request('DELETE', '/api/me', $token, ['password' => 'Password123!']);
        self::assertResponseIsSuccessful();

        // Login avec les anciens identifiants → 401 (l'email n'existe plus).
        $this->client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => 'Password123!'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);

        // Le JWT encore en circulation est inerte : l'identité (email) ne
        // résout plus aucun compte.
        $this->request('GET', '/api/me', $token);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * RMM-3 (revue de sécurité 2026-08-24) : la visite du module matchs est une
     * donnée personnelle — elle meurt avec le compte MÊME dans un club dont
     * l'adhésion a été désactivée AVANT l'effacement. Le trou falsifié : la
     * boucle d'effacement ne portait que sur les clubs ACTIFS (`is_active`),
     * une visite stampée puis un membre sorti du club laissait la ligne
     * (user_id + horodatages) orpheline pour toujours.
     */
    public function testErasureDeletesVisitRowsInDeactivatedClubsToo(): void
    {
        [$token, $userId] = $this->registerVerified('ERAH');
        $clubId = $this->clubIdOf($userId);
        $seasonId = $this->currentSeasonId($clubId);

        // Une visite stampée dans le club (le GUC est posé par currentSeasonId).
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO match_module_visit (id, version, club_id, season_id, user_id, reference_snapshot, reference_taken_at, last_opened_at)
             VALUES (gen_random_uuid(), 1, :cid, :sid, :uid, :snap, NOW(), NOW())',
            ['cid' => $clubId, 'sid' => $seasonId, 'uid' => $userId, 'snap' => '{"fingerprints":[],"chosenScheduleId":null,"latestCompletedSeasonScheduleId":null}'],
        );

        // Le membre QUITTE le club avant d'effacer son compte : l'adhésion est
        // désactivée — le club sort de findActiveClubIds.
        $this->em()->getConnection()->executeStatement(
            'UPDATE club_user SET is_active = false, deactivated_at = NOW() WHERE user_id = :uid AND club_id = :cid',
            ['uid' => $userId, 'cid' => $clubId],
        );

        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM match_module_visit WHERE user_id = :uid', ['uid' => $userId]);
        self::assertSame(1, (int) $count, 'témoin : la visite existe avant l\'effacement');

        $this->request('DELETE', '/api/me', $token, ['password' => 'Password123!']);
        self::assertResponseIsSuccessful();

        $this->scopeGucToClub($clubId);
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM match_module_visit WHERE user_id = :uid', ['uid' => $userId]);
        self::assertSame(0, (int) $count, 'la visite du club quitté est détruite avec le compte');
        $this->clearGuc();
    }

    public function testLastManagerErasureSchedulesTheClubPurge(): void
    {
        [$token, $userId, $email] = $this->registerVerified('ERAB');
        $em = $this->em();
        $clubId = $this->clubIdOf($userId);

        $this->request('DELETE', '/api/me', $token, ['password' => 'Password123!']);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['clubPurgeScheduled']);

        $em->clear();
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNotNull($club->getErasureScheduledAt(), 'dernier admin effacé → purge programmée');
        self::assertGreaterThan(new DateTimeImmutable('+29 days'), $club->getErasureScheduledAt(), 'délai de grâce ~30 j');

        $user = $em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getAnonymizedAt());
        self::assertStringEndsWith('@anonymized.invalid', $user->getEmail());
        self::assertSame('Compte', $user->getFirstName());
    }

    public function testRemainingActiveMemberEvenNonManagementPreventsTheClubPurge(): void
    {
        [$token, $userId, $email] = $this->registerVerified('ERAC');
        $em = $this->em();
        $clubId = $this->clubIdOf($userId);

        // Un membre actif NON-management (editor) : il utilise le workspace —
        // sa présence doit suffire à bloquer la programmation de la purge.
        $this->insertActiveMember($clubId, 'other-' . strtolower($email), 'editor');

        $this->request('DELETE', '/api/me', $token, ['password' => 'Password123!']);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['clubPurgeScheduled']);

        $em->clear();
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNull($club->getErasureScheduledAt(), 'un membre actif reste (editor) → pas de purge programmée');
    }

    public function testDuePurgeIsCancelledWhenAnActiveMemberReturned(): void
    {
        [, $userId, $email] = $this->registerVerified('ERAF');
        $em = $this->em();
        $clubId = $this->clubIdOf($userId);
        $seasonId = $this->currentSeasonId($clubId);

        // Club programmé, échéance passée… mais un membre actif est revenu
        // (réactivation support pendant la grâce).
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        $club->setErasureScheduledAt(new DateTimeImmutable('-1 hour'));
        $em->flush();
        $this->insertActiveMember($clubId, 'back-' . strtolower($email), 'admin');
        $em->clear();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:clubs:purge-erased'));
        self::assertSame(0, $tester->execute([]));

        $em->clear();
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertNull($club->getErasureScheduledAt(), 'membre revenu → programmation auto-annulée');
        self::assertNull($club->getUnsubscribedAt(), 'pas de purge exécutée');
        $this->scopeGucToClub($clubId);
        self::assertNotNull(
            $this->em()->getConnection()->fetchOne('SELECT id FROM season WHERE club_id = :cid AND id = :sid', ['cid' => $clubId, 'sid' => $seasonId]),
            'workspace intact',
        );
    }

    public function testMemberlessPurgedClubIsResurrectedOnReRegister(): void
    {
        [, $userA, $emailA] = $this->registerVerified('ERAG');
        $clubId = $this->clubIdOf($userA);
        $ara = strtoupper(explode('@', $emailA)[0]);
        $em = $this->em();

        // Purge complète du club (comme en (d)).
        $club = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        $club->setErasureScheduledAt(new DateTimeImmutable('-1 hour'));
        // Désactive le membre (l'effacement l'aurait fait) pour rendre le club orphelin.
        $this->scopeGucToClub($clubId);
        $em->getConnection()->executeStatement('UPDATE club_user SET is_active = false WHERE club_id = :cid', ['cid' => $clubId]);
        $this->clearGuc();
        $em->flush();
        $em->clear();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:clubs:purge-erased'));
        self::assertSame(0, $tester->execute([]));

        // Win-back : un nouveau compte s'inscrit avec le MÊME code FFBB → il
        // reprend le club directement (owner actif), pas de pending sans issue.
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $newEmail = 'winback-' . strtolower($ara) . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $newEmail, 'password' => 'Password123!',
            'firstName' => 'Win', 'lastName' => 'Back', 'ara' => $ara, 'club_name' => 'Reprise', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $newEmail);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('active', $me['membershipStatus'], 'reprise directe, pas de pending inapprouvable');
        self::assertSame($clubId, $me['club']['id'] ?? null, 'même fiche club (identité FFBB conservée)');

        $em->clear();
        $resurrected = $em->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $resurrected);
        self::assertNull($resurrected->getUnsubscribedAt(), 'club réactivé');
        $this->scopeGucToClub($clubId);
        self::assertNotFalse(
            $this->em()->getConnection()->fetchOne('SELECT id FROM season WHERE club_id = :cid LIMIT 1', ['cid' => $clubId]),
            'workspace re-seedé (saison)',
        );
    }

    public function testPurgeErasedWipesTheWorkspaceSparesFfbbIdentityAndOtherClubs(): void
    {
        // Club A : programmé (échéance passée) + données workspace.
        [, $userA] = $this->registerVerified('ERAD');
        $clubA = $this->clubIdOf($userA);
        // Club B : témoin tenant, jamais programmé.
        [, $userB] = $this->registerVerified('ERAE');
        $clubB = $this->clubIdOf($userB);

        $em = $this->em();
        $seasonA = $this->currentSeasonId($clubA);
        $seasonB = $this->currentSeasonId($clubB);
        $this->insertCoach($clubA, $seasonA, 'CoachA');
        $this->insertCoach($clubB, $seasonB, 'CoachB');
        // RGPD (P5-6) : un signalement du club A doit être emporté par la purge.
        $this->insertFeedback($clubA, $userA);

        $club = $em->getRepository(Club::class)->find($clubA);
        self::assertInstanceOf(Club::class, $club);
        $club->setName('B CHARPENNES TEST');
        $club->setErasureScheduledAt(new DateTimeImmutable('-1 hour'));
        // Le vrai flux (erase) désactive les memberships — sans quoi la
        // revalidation de la commande auto-annulerait (un membre actif reste).
        $this->scopeGucToClub($clubA);
        $em->getConnection()->executeStatement('UPDATE club_user SET is_active = false WHERE club_id = :cid', ['cid' => $clubA]);
        $this->clearGuc();
        $em->flush();
        $em->clear();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:clubs:purge-erased'));
        self::assertSame(0, $tester->execute([]));

        $em->clear();
        // Workspace A vidé (coach + saison), identité FFBB épargnée.
        self::assertSame(0, $this->countRows(Coach::class, $clubA), 'coachs du club A purgés');
        self::assertSame(0, $this->countRows(Feedback::class, $clubA), 'signalements du club A purgés (RGPD)');
        self::assertSame([], $em->getRepository(Season::class)->findBy(['clubId' => $clubA]), 'saisons du club A purgées');
        $survivor = $em->getRepository(Club::class)->find($clubA);
        self::assertInstanceOf(Club::class, $survivor, 'la fiche club survit');
        self::assertSame('B CHARPENNES TEST', $survivor->getName(), 'identité (nom) épargnée');
        self::assertNotNull($survivor->getUnsubscribedAt());
        self::assertNull($survivor->getErasureScheduledAt(), 'programmation consommée');
        self::assertFalse($survivor->isOnboardingCompleted());

        // ADR-0002: the SchedulePlan of club A (seeded at onboarding) is purged
        // too — a club_id+season_id table must not survive erasure (RGPD).
        $this->scopeGucToClub($clubA);
        self::assertSame(
            0,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_plan WHERE club_id = :cid', ['cid' => $clubA]),
            'plans (schedule_plan) du club A purgés',
        );
        $this->clearGuc();

        // Club B intact (frontière tenant).
        self::assertSame(1, $this->countRows(Coach::class, $clubB), 'club B intact');
        self::assertNotSame([], $em->getRepository(Season::class)->findBy(['clubId' => $clubB]));
        $this->scopeGucToClub($clubB);
        self::assertGreaterThan(
            0,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule_plan WHERE club_id = :cid', ['cid' => $clubB]),
            'plan (schedule_plan) du club B intact',
        );
        $this->clearGuc();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
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
            'firstName' => 'E', 'lastName' => 'Rasure', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $email];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $uri, string $token, array $body = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], [] === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
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

    private function currentSeasonId(string $clubId): string
    {
        $this->scopeGucToClub($clubId);
        $seasonId = $this->em()->getConnection()->fetchOne(
            'SELECT id FROM season WHERE club_id = :cid LIMIT 1',
            ['cid' => $clubId],
        );
        self::assertIsString($seasonId, 'le register/verify matérialise une saison');

        return $seasonId;
    }

    /** Membre actif inséré directement (le flux d'approbation est couvert par MembershipTest). */
    private function insertActiveMember(string $clubId, string $email, string $role): void
    {
        $em = $this->em();
        $other = new User;
        $other->setEmail($email);
        $other->setFirstName('Autre');
        $other->setLastName('Membre');
        $other->setPasswordHash('x');
        $other->setEmailVerifiedAt(new DateTimeImmutable);
        $em->persist($other);
        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($other->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();
        $this->clearGuc();
    }

    private function insertFeedback(string $clubId, string $userId): void
    {
        // RLS : l'INSERT direct exige le GUC du club (WITH CHECK policy).
        $this->scopeGucToClub($clubId);
        $em = $this->em();
        $em->persist(new Feedback($clubId, $userId, 'bug', 'un souci quelconque'));
        $em->flush();
        $em->clear();
    }

    private function insertCoach(string $clubId, string $seasonId, string $name): void
    {
        // RLS : l'INSERT direct exige le GUC du club (WITH CHECK policy).
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
    }

    /** @param class-string $entityClass */
    private function countRows(string $entityClass, string $clubId): int
    {
        // RLS : lire les lignes d'un club exige son GUC — sans lui le résultat
        // serait 0 pour tout le monde (faux vert).
        $this->scopeGucToClub($clubId);

        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entityClass, 'e')
            ->where('e.clubId = :clubId')
            ->setParameter('clubId', $clubId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
