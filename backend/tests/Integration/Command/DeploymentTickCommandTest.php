<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\DeploymentTickCommand;
use App\Command\HealthAlertCommand;
use App\Service\GitHubDeployClient;
use App\Service\MailFrom;
use App\Service\ProductIdentity;
use App\Tests\Double\GitHubDeployHttpClientStub;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * `app:deployment:tick` — la MACHINE À ÉTATS de la « mise en prod programmée » (PR b), battue
 * toutes les minutes par le cron-runner, horloge figée (MockClock). Les promesses gardées :
 *
 *  - AVANT H : rien ne bouge ; un déploiement ANNULÉ n'est jamais ranimé ;
 *  - À H, si le tag a disparu ou que la CI est redevenue rouge → FAILED + e-mail, SANS dispatch ;
 *  - à H, précondition OK → dispatch du workflow, statut DISPATCHED (dispatched_at + run resolu) ;
 *  - run GitHub `success` → SUCCEEDED + e-mail ; run `failure` → FAILED + e-mail disant la
 *    MAINTENANCE LAISSÉE (décision fondateur : pas de rollback auto v1) ;
 *  - un dispatch qui échoue est RÉESSAYÉ jusqu'à H +10 min, puis FAILED + e-mail.
 *
 * L'e-mail suit le patron {@see HealthAlertCommand} : destinataires = superadmins
 * ACTIFS. Mailer mocké (enregistre to/subject/body). Client GitHub réel sur le double HTTP
 * ({@see GitHubDeployHttpClientStub}) : aucune requête ne part vers api.github.com.
 *
 * Contrat FIXÉ pour NEO : commande `app:deployment:tick`, constructeur
 * `(ManagerRegistry $registry, GitHubDeployClient $client, MailerInterface $mailer,
 * MailFrom $mailFrom, ProductIdentity $productIdentity, ClockInterface $clock)` ; la commande
 * lit/écrit `scheduled_deployment` via la connexion par DÉFAUT (entité Doctrine), les
 * destinataires via la connexion `admin` (patron HealthAlertCommand).
 */
#[Group('phase1')]
#[Group('integration')]
final class DeploymentTickCommandTest extends KernelTestCase
{
    private const string NOW = '2026-10-19T01:00:00+02:00';

    private Connection $db;

    /** @var list<string> */
    private array $sentTo = [];

    /** @var list<string> */
    private array $sentBodies = [];

    private ?string $adminId = null;

    private string $adminEmail;

    public function testBeforeTheWindowNothingHappens(): void
    {
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T02:00:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('scheduled', $this->statusOf($id), 'avant H, le déploiement attend');
        self::assertSame([], $this->sentTo, 'aucun e-mail avant H');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'aucun dispatch avant H');
    }

    public function testACancelledDeploymentIsNeverRevived(): void
    {
        $id = $this->seedDeployment('cancelled', scheduledAt: '2026-10-19T00:30:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('cancelled', $this->statusOf($id), 'un annulé reste annulé, même après H');
        self::assertSame([], $this->sentTo);
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount);
    }

    public function testAVanishedTagAtTickFailsWithMailAndNoDispatch(): void
    {
        GitHubDeployHttpClientStub::$tags = []; // le tag a disparu entre la programmation et H
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id), 'tag disparu à H → FAILED');
        self::assertContains($this->adminEmail, $this->sentTo, 'un e-mail prévient du refus');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'aucun dispatch quand la précondition a cassé');
    }

    public function testARedCiAtTickFailsWithMailAndNoDispatch(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'failure'; // la CI est redevenue rouge
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id), 'CI rouge à H → FAILED');
        self::assertContains($this->adminEmail, $this->sentTo);
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount);
    }

    public function testPreconditionsMetDispatchesTheWorkflowAndMovesToDispatched(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        GitHubDeployHttpClientStub::$runId = 555000111;
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('dispatched', $this->statusOf($id), 'précondition OK → DISPATCHED');
        self::assertSame(1, GitHubDeployHttpClientStub::$dispatchCount, 'le workflow a été dispatché une fois');
        $row = $this->rowOf($id);
        self::assertNotNull($row['dispatched_at'], 'dispatched_at est horodaté');
        self::assertSame(555000111, (int) $row['github_run_id'], 'le run resolu est mémorisé');
    }

    public function testADispatchedRunThatSucceedsIsMarkedSucceededAndMails(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'success';
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('succeeded', $this->statusOf($id), 'run success → SUCCEEDED');
        self::assertContains($this->adminEmail, $this->sentTo, 'un e-mail de succès part aux superadmins');
    }

    public function testADispatchedRunThatFailsIsMarkedFailedAndMailsThatMaintenanceIsLeftOn(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'failure';
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id), 'run failure → FAILED');
        self::assertContains($this->adminEmail, $this->sentTo);
        $body = strtolower(implode("\n", $this->sentBodies));
        self::assertStringContainsString('maintenance', $body, 'l\'e-mail d\'échec dit que la maintenance reste posée (pas de rollback auto v1)');
    }

    public function testADispatchFailureIsRetriedUntilTenMinutesThenFails(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        GitHubDeployHttpClientStub::$dispatchFails = true;
        $id = $this->seedDeployment('scheduled', scheduledAt: self::NOW);

        // H +5 min : le dispatch échoue mais on reste dans la fenêtre de réessai.
        $this->tickAt('2026-10-19T01:05:00+02:00');
        self::assertSame('scheduled', $this->statusOf($id), 'avant H +10 min, un dispatch en échec est réessayé');
        self::assertSame([], $this->sentTo, 'pas encore d\'e-mail : on réessaie');

        // H +11 min : la fenêtre de réessai est close → FAILED + e-mail.
        $this->tickAt('2026-10-19T01:11:00+02:00');
        self::assertSame('failed', $this->statusOf($id), 'après H +10 min, l\'échec de dispatch devient FAILED');
        self::assertContains($this->adminEmail, $this->sentTo);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        GitHubDeployHttpClientStub::reset();
        $this->sentTo = [];
        $this->sentBodies = [];
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $this->db = $registry->getConnection();
        $this->adminEmail = 'tick-admin-' . bin2hex(random_bytes(3)) . '@example.test';
        $this->seedSuperAdmin($this->adminEmail);
    }

    protected function tearDown(): void
    {
        if (null !== $this->adminId) {
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    private function tickAt(string $now): void
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $mailer = $this->recordingMailer();
        $client = new GitHubDeployClient(new GitHubDeployHttpClientStub, 'github_pat_tick', 'maratech-fr/amateo');

        $command = new DeploymentTickCommand(
            $registry,
            $client,
            $mailer,
            self::getContainer()->get(MailFrom::class),
            self::getContainer()->get(ProductIdentity::class),
            new MockClock(new DateTimeImmutable($now)),
        );

        new CommandTester($command)->execute([]);
    }

    private function seedDeployment(string $status, string $scheduledAt, ?int $githubRunId = null): string
    {
        $id = Uuid::v4()->toRfc4122();
        $scheduled = new DateTimeImmutable($scheduledAt);
        $this->db->executeStatement(
            'INSERT INTO scheduled_deployment (id, version, scheduled_at, expected_return_at, status, created_by, dispatched_at, github_run_id, created_at, updated_at)'
            . ' VALUES (:id, :version, :scheduled, :ret, :status, :by, :dispatched, :run, NOW(), NOW())',
            [
                'id' => $id,
                'version' => 'v1.0.0',
                'scheduled' => $scheduled->format('Y-m-d H:i:sP'),
                'ret' => $scheduled->modify('+60 minutes')->format('Y-m-d H:i:sP'),
                'status' => $status,
                'by' => $this->adminId,
                'dispatched' => 'dispatched' === $status ? $scheduled->modify('+1 minute')->format('Y-m-d H:i:sP') : null,
                'run' => $githubRunId,
            ],
            ['run' => ParameterType::INTEGER],
        );

        return $id;
    }

    private function statusOf(string $id): string
    {
        return (string) $this->db->fetchOne('SELECT status FROM scheduled_deployment WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function rowOf(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM scheduled_deployment WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    private function recordingMailer(): MailerInterface
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                foreach ($message->getTo() as $address) {
                    $this->sentTo[] = $address->getAddress();
                }
                $this->sentBodies[] = (string) $message->getTextBody();
            }
        });

        return $mailer;
    }

    private function seedSuperAdmin(string $email): void
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :hash, :secret, TRUE, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'hash' => 'x', 'secret' => 'x'],
        );
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
