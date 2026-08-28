<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * RGPD PR-4 non-regression : journal d'audit append-only (accountability).
 *
 * (a) une suppression API d'entité écrit une ligne club-scoped (choke point
 *     processDelete) avec acteur, SANS PII ;
 * (b) append-only tenu PAR LA DB : le rôle runtime ne peut NI modifier NI
 *     supprimer (aucune policy UPDATE/DELETE → 0 ligne affectée) ;
 * (c) isolation tenant : les lignes du club A et les lignes GLOBALES
 *     (club_id NULL — register, login raté) sont invisibles sous le GUC de B,
 *     et les globales invisibles même sans GUC (console admin uniquement) ;
 * (d) la policy INSERT accepte les événements globaux (register s'émet sans
 *     GUC) ;
 * (e) app:audit:purge (connexion admin — la seule porte DELETE) purge > 12 mois ;
 * (f) aucune surface API sur le journal.
 *
 * NB dama : les écritures de la connexion default restent dans la transaction
 * de test (invisibles à la connexion admin) — les assertions lisent donc via
 * la connexion default sous le bon GUC ; seule (e) passe par l'admin, avec ses
 * propres lignes committées puis purgées.
 */
#[Group('phase1')]
#[Group('integration')]
final class AuditTrailTest extends WebTestCase
{
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testApiEntityDeletionIsRecordedWithActorAndWithoutPii(): void
    {
        [$token, $userId, $email] = $this->registerVerified('AUDB');
        $clubId = $this->clubIdOf($userId);

        $this->client->request('POST', '/api/coaches', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], json_encode(['firstName' => 'Audit', 'lastName' => 'Coach'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $coach = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('DELETE', '/api/coaches/' . $coach['id'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 204]);

        $this->scopeGucToClub($clubId);
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT * FROM audit_log WHERE action = \'entity.deleted\' AND club_id = :cid AND entity_id = :eid',
            ['cid' => $clubId, 'eid' => $coach['id']],
        );
        $this->clearGuc();
        self::assertCount(1, $rows, 'suppression API auditée (choke point processDelete)');
        self::assertSame('Coach', $rows[0]['entity_type']);
        self::assertSame($userId, $rows[0]['actor_user_id'], 'acteur tracé');
        self::assertStringNotContainsString(explode('@', $email)[0], json_encode($rows[0], \JSON_THROW_ON_ERROR), 'no-PII');
    }

    public function testAppendOnlyIsEnforcedByTheDatabase(): void
    {
        [, $userId] = $this->registerVerified('AUDC');
        $clubId = $this->clubIdOf($userId);

        $this->scopeGucToClub($clubId);
        $inserted = $this->em()->getConnection()->executeStatement(
            'INSERT INTO audit_log (id, occurred_at, club_id, action, details) VALUES (gen_random_uuid(), NOW(), :cid, \'entity.deleted\', \'{}\')',
            ['cid' => $clubId],
        );
        self::assertSame(1, (int) $inserted);

        // amateo_app n'a AUCUNE policy UPDATE/DELETE : PostgreSQL n'affecte
        // aucune ligne — l'histoire ne se réécrit pas depuis le runtime.
        $updated = $this->em()->getConnection()->executeStatement(
            'UPDATE audit_log SET action = \'auth.register\' WHERE club_id = :cid',
            ['cid' => $clubId],
        );
        self::assertSame(0, (int) $updated, 'append-only : UPDATE sans effet pour le rôle runtime');
        $deleted = $this->em()->getConnection()->executeStatement(
            'DELETE FROM audit_log WHERE club_id = :cid',
            ['cid' => $clubId],
        );
        self::assertSame(0, (int) $deleted, 'append-only : DELETE sans effet pour le rôle runtime');
        $this->clearGuc();
    }

    public function testTenantIsolationAndGlobalRowsInvisibleToRuntime(): void
    {
        [, $userA] = $this->registerVerified('AUDD');
        $clubA = $this->clubIdOf($userA);
        [, $userB] = $this->registerVerified('AUDE');
        $clubB = $this->clubIdOf($userB);

        // Une ligne club A + une ligne GLOBALE (club_id NULL, insérée sans GUC —
        // la policy INSERT doit l'accepter : c'est le chemin du register).
        $this->scopeGucToClub($clubA);
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO audit_log (id, occurred_at, club_id, action, details) VALUES (gen_random_uuid(), NOW(), :cid, \'entity.deleted\', \'{}\')',
            ['cid' => $clubA],
        );
        $this->clearGuc();
        $insertedGlobal = $this->em()->getConnection()->executeStatement(
            'INSERT INTO audit_log (id, occurred_at, action, details) VALUES (gen_random_uuid(), NOW(), \'auth.register\', \'{}\')',
        );
        self::assertSame(1, (int) $insertedGlobal, 'policy INSERT : événement global accepté sans GUC');

        // Sous le GUC de B : ni les lignes de A, ni les globales.
        $this->scopeGucToClub($clubB);
        $visible = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE club_id = :cid OR club_id IS NULL',
            ['cid' => $clubA],
        );
        self::assertSame(0, (int) $visible, 'RLS : audit du club A + événements globaux invisibles sous B');
        $this->clearGuc();

        // Sans GUC du tout : les globales restent invisibles du runtime
        // (lecture réservée à la console admin).
        $visibleGlobal = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE club_id IS NULL',
        );
        self::assertSame(0, (int) $visibleGlobal, 'événements globaux : admin-only en lecture');
    }

    public function testAuditPurgeDeletesOldEntriesViaAdmin(): void
    {
        // Connexion ADMIN (committée, hors dama) : la commande est la seule
        // porte DELETE. On insère une vieille ligne, la purge doit l'éliminer.
        $admin = $this->admin();
        $admin->executeStatement(
            'INSERT INTO audit_log (id, occurred_at, action, details) VALUES (gen_random_uuid(), NOW() - INTERVAL \'13 months\', \'auth.register\', \'{"test":"purge"}\')',
        );
        self::assertGreaterThan(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM audit_log WHERE occurred_at < NOW() - INTERVAL \'12 months\''));

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:audit:purge'));
        self::assertSame(0, $tester->execute([]));

        self::assertSame(0, (int) $admin->fetchOne('SELECT COUNT(*) FROM audit_log WHERE occurred_at < NOW() - INTERVAL \'12 months\''), 'entrées > 12 mois purgées');
    }

    public function testAuditLogHasNoApiSurface(): void
    {
        [$token] = $this->registerVerified('AUDF');
        $this->client->request('GET', '/api/audit_logs', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405], 'aucune surface API sur le journal');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
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
            'firstName' => 'Au', 'lastName' => 'Dit', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $email];
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
}
