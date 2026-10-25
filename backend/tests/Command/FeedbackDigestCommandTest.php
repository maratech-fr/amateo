<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\FeedbackDigestCommand;
use App\Mail\EmailTemplateRenderer;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * P5-6 PR 2, réécrit Chantier F PR4 — le digest quotidien des signalements au support.
 *
 * Promesse (décision fondateur PR4) : UN SEUL mail le matin annonçant les NOUVEAUX
 * signalements NON TRAITÉS = déposés la VEILLE [J-1, J) ET encore non traités. Zéro
 * nouveau non-traité → AUCUN mail. Corps bref (~3 lignes), SANS extrait des messages,
 * avec UN lien unique vers la console filtrée sur les non-traités (jamais un lien par
 * item). SUPPORT_EMAIL vide → no-op ; l'exit non-zéro ne signale que l'échec de DISPATCH.
 *
 * `feedback` est FORCE RLS : on sème et on lit par la connexion `admin` (hors DAMA).
 * On ouvre une transaction dessus, on vide la table pour un décor déterministe, et
 * on `rollBack` au teardown — rien de committé ne fuit (patron AdminCapacityServiceTest).
 */
#[Group('integration')]
final class FeedbackDigestCommandTest extends KernelTestCase
{
    private const string FRONTEND_BASE_URL = 'https://app.example.test';

    /** Le lien UNIQUE du digest : la console, onglet incidents, sous-onglet signalements, filtré non-traités. */
    private const string EXPECTED_LINK = self::FRONTEND_BASE_URL . '/admin?tab=incidents&sub=signalements&fstatus=untreated';

    private Connection $admin;

    private bool $inTransaction = false;

    public function testSendsDigestOfYesterdaysNewUntreatedWithSingleLinkAndNoExcerpt(): void
    {
        $club = $this->seedClub('Golf');
        $secret = 'ne jamais recopier ce texte de message dans le mail';
        // DEUX nouveaux non-traités de la veille — J = 2026-08-13, veille = 2026-08-12.
        $this->seedFeedback($club, 'bug', $secret, 'untreated', '2026-08-12T09:00:00+00:00');
        $this->seedFeedback($club, 'idea', 'une autre doléance', 'untreated', '2026-08-12T15:00:00+00:00');
        // Hors périmètre : déposé la veille mais DÉJÀ TRAITÉ → n'est pas un « nouveau non-traité ».
        $this->seedFeedback($club, 'bug', 'déjà traité hier', 'treated', '2026-08-12T10:00:00+00:00');
        // Hors fenêtre : le jour même, et l'avant-veille.
        $this->seedFeedback($club, 'bug', 'aujourd\'hui', 'untreated', '2026-08-13T09:00:00+00:00');
        $this->seedFeedback($club, 'bug', 'avant-veille', 'untreated', '2026-08-10T09:00:00+00:00');

        $mailer = $this->recordingMailer();
        $tester = new CommandTester($this->command($mailer, 'support@example.test'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-08-13']));

        self::assertCount(1, $mailer->sent, 'UN seul mail le matin, pas un par signalement');
        $email = $mailer->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('support@example.test', $email->getTo()[0]->getAddress());
        $body = $email->getTextBody() ?? '';
        // UN lien unique vers la console filtrée sur les non-traités — jamais un lien par item.
        self::assertSame(1, substr_count($body, self::EXPECTED_LINK), 'un lien unique vers les non-traités, pas un par item');
        // AUCUN extrait du message utilisateur dans le corps (plus de recopie du texte).
        self::assertStringNotContainsString($secret, $body, 'le digest ne recopie jamais le texte des messages');
        // Corps bref (~3 lignes) : jamais une ligne par signalement.
        self::assertLessThanOrEqual(6, substr_count(trim($body), "\n") + 1, 'un corps bref (~3 lignes), pas une ligne par item');
    }

    public function testDigestCarriesTheConsoleCtaToTheSingleLink(): void
    {
        // PROMESSE (D1) : le digest porte un bouton « Traiter les signalements » vers le lien
        // UNIQUE de la console (le même que le corps). ROUGE tant que le producteur ne pose pas
        // l'en-tête CTA.
        $club = $this->seedClub('Kilo');
        $this->seedFeedback($club, 'bug', 'à traiter', 'untreated', '2026-08-12T09:00:00+00:00');

        $mailer = $this->recordingMailer();
        $tester = new CommandTester($this->command($mailer, 'support@example.test'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-08-13']));

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertInstanceOf(Email::class, $email);
        $headers = $email->getHeaders();
        self::assertTrue($headers->has(EmailTemplateRenderer::CTA_URL_HEADER), 'ROUGE tant que le digest ne pose pas le bouton console');
        self::assertSame(self::EXPECTED_LINK, $headers->getHeaderBody(EmailTemplateRenderer::CTA_URL_HEADER), 'le CTA pointe le lien UNIQUE de la console');
        self::assertSame('Traiter les signalements', $headers->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));
    }

    public function testNoNewUntreatedYesterdaySendsNoEmail(): void
    {
        $club = $this->seedClub('Hotel');
        // Un non-traité, mais du JOUR MÊME (hors fenêtre de la veille).
        $this->seedFeedback($club, 'bug', 'aujourd\'hui', 'untreated', '2026-08-13T09:00:00+00:00');
        // Un signalement de la veille, mais DÉJÀ TRAITÉ → ne compte pas comme nouveau non-traité.
        $this->seedFeedback($club, 'bug', 'veille traité', 'treated', '2026-08-12T09:00:00+00:00');

        $mailer = $this->recordingMailer();
        $tester = new CommandTester($this->command($mailer, 'support@example.test'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-08-13']));

        self::assertSame([], $mailer->sent, 'aucun nouveau non-traité de la veille → aucun mail');
    }

    public function testEmptySupportEmailIsANoOp(): void
    {
        $club = $this->seedClub('India');
        $this->seedFeedback($club, 'bug', 'à envoyer', 'untreated', '2026-08-12T09:00:00+00:00');

        $mailer = $this->recordingMailer();
        $tester = new CommandTester($this->command($mailer, ''));
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-08-13']));

        self::assertSame([], $mailer->sent, 'sans adresse support : no-op, aucun envoi');
        self::assertStringContainsString('aucune adresse support', $tester->getDisplay());
    }

    public function testDispatchFailureIsANonZeroExit(): void
    {
        $club = $this->seedClub('Juliett');
        $this->seedFeedback($club, 'bug', 'à envoyer', 'untreated', '2026-08-12T09:00:00+00:00');

        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new RuntimeException('bus down');
            }
        };
        $tester = new CommandTester($this->command($mailer, 'support@example.test'));

        self::assertSame(Command::FAILURE, $tester->execute(['--date' => '2026-08-13']));
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
        $this->admin->executeStatement('DELETE FROM feedback');
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            $this->admin->rollBack();
            $this->inTransaction = false;
        }
        parent::tearDown();
    }

    private function command(MailerInterface $mailer, string $supportEmail): FeedbackDigestCommand
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);

        return new FeedbackDigestCommand($registry, $mailer, supportEmail: $supportEmail, frontendBaseUrl: self::FRONTEND_BASE_URL);
    }

    /** @return MailerInterface&object{sent: list<RawMessage>} */
    private function recordingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };
    }

    private function seedClub(string $name): string
    {
        $clubId = Uuid::v4()->toRfc4122();
        $suffix = strtolower(substr(md5($clubId), 0, 8));
        $this->admin->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed) VALUES (:id, 1, NOW(), NOW(), :name, :slug, 0, :tz, :locale, FALSE)',
            ['id' => $clubId, 'name' => $name, 'slug' => 'dig-' . $suffix, 'tz' => 'Europe/Paris', 'locale' => 'fr'],
        );

        return $clubId;
    }

    private function seedFeedback(string $clubId, string $topic, string $message, string $status, string $createdAt): void
    {
        $this->admin->executeStatement(
            'INSERT INTO feedback (id, version, club_id, user_id, topic, message, context, status, treated_at, created_at) VALUES (:id, 1, :club, :user, :topic, :message, NULL, :status, NULL, :created)',
            [
                'id' => Uuid::v4()->toRfc4122(),
                'club' => $clubId,
                'user' => Uuid::v4()->toRfc4122(),
                'topic' => $topic,
                'message' => $message,
                'status' => $status,
                'created' => $createdAt,
            ],
        );
    }
}
