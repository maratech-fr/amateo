<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\AdminJob\AdminJobCatalog;
use App\Command\HealthAlertCommand;
use App\Mail\EmailTemplateRenderer;
use App\Service\AdminAlertStateStore;
use App\Service\AdminDataFreshnessService;
use App\Service\AdminHealthService;
use App\Service\HealthAlertEvaluator;
use App\Service\MailFrom;
use App\Service\ProductIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * PROMESSE (D1) : l'e-mail d'alerte santé superadmin porte un bouton « Ouvrir la console » vers
 * une URL ABSOLUE de la console (plus « Console : /admin » relatif). On force une transition ROUGE
 * (services santé mockés + state store mocké) et on capte l'e-mail combiné. ROUGE tant que le
 * producteur ne pose pas l'en-tête CTA ni l'URL absolue ({@see HealthAlertCommand}).
 *
 * `super_admin` est lu/écrit par la connexion `admin` : on sème un destinataire sous transaction
 * et on `rollBack` au teardown (patron FeedbackDigestCommandTest, rien de committé ne fuit).
 */
#[Group('integration')]
final class HealthAlertCommandTest extends KernelTestCase
{
    private Connection $admin;

    private bool $inTransaction = false;

    public function testTheAlertEmailCarriesAConsoleCtaWithAnAbsoluteUrl(): void
    {
        $this->seedSuperAdmin('health-admin-' . bin2hex(random_bytes(3)) . '@example.test');
        // Une transition ROUGE garantie : le job surveillé `db-restore-check` a ÉCHOUÉ à son dernier
        // run (l'évaluateur fire alors `job:db-restore-check`). On vide l'état d'alerte pour que ce
        // soit bien une TRANSITION (fired), pas un état déjà connu — le tout dans la transaction.
        $this->admin->executeStatement('DELETE FROM admin_alert_state');
        $this->admin->executeStatement(
            'INSERT INTO admin_job_run (id, job_key, command_name, source, status, started_at) VALUES (:id, :key, :cmd, :src, :status, NOW())',
            ['id' => Uuid::v4()->toRfc4122(), 'key' => 'db-restore-check', 'cmd' => 'app:db:restore-check', 'src' => 'scheduled', 'status' => 'failed'],
        );

        $mailer = $this->recordingMailer();
        $tester = new CommandTester($this->command($mailer));
        $tester->execute([]);

        self::assertInstanceOf(Email::class, $mailer->last, 'une transition rouge doit émettre un e-mail d\'alerte');
        $headers = $mailer->last->getHeaders();
        self::assertTrue($headers->has(EmailTemplateRenderer::CTA_URL_HEADER), 'ROUGE tant que l\'alerte ne pose pas le bouton console');
        self::assertSame('Ouvrir la console', $headers->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));
        self::assertMatchesRegularExpression('~^https?://\S+/admin$~', (string) $headers->getHeaderBody(EmailTemplateRenderer::CTA_URL_HEADER), 'la console en URL ABSOLUE');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $connection = $registry->getConnection('admin');
        \assert($connection instanceof Connection);
        $this->admin = $connection;
        $this->admin->beginTransaction();
        $this->inTransaction = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            $this->admin->rollBack();
            $this->inTransaction = false;
        }
        parent::tearDown();
    }

    /** @param MailerInterface&object{last: ?Email} $mailer */
    private function command(MailerInterface $mailer): HealthAlertCommand
    {
        $container = self::getContainer();
        $registry = $container->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);

        // Services RÉELS (classes `final`, non-doublables) : le décor (job échoué + état vidé) suffit
        // à produire une transition rouge ; seul le mailer est un double d'enregistrement.
        return new HealthAlertCommand(
            $container->get(AdminHealthService::class),
            $container->get(AdminDataFreshnessService::class),
            $container->get(HealthAlertEvaluator::class),
            $container->get(AdminJobCatalog::class),
            $container->get(AdminAlertStateStore::class),
            $mailer,
            $registry,
            $container->get(MailFrom::class),
            $container->get(ProductIdentity::class),
            'https://app.example.test',
        );
    }

    private function seedSuperAdmin(string $email): void
    {
        $this->admin->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :hash, :secret, TRUE, NOW())',
            ['id' => Uuid::v4()->toRfc4122(), 'email' => $email, 'hash' => 'x', 'secret' => 'x'],
        );
    }

    /** @return MailerInterface&object{last: ?Email} */
    private function recordingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            public ?Email $last = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($message instanceof Email) {
                    $this->last = $message;
                }
            }
        };
    }
}
