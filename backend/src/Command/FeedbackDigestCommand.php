<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MailFrom;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * P5-6 PR 2 — digest quotidien des signalements au support (rail superadmin),
 * 7h30 Europe/Paris via l'AdminJobCatalog. Patron ClubApprovalDigestCommand :
 * `--dry-run` + `--date` pour répéter sans écrire ni envoyer.
 *
 * Fenêtre = les signalements CRÉÉS la veille [J-1 00:00, J 00:00), plus le compteur
 * global des non-traités. Digest VIDE (aucun signalement dans la fenêtre) → aucun
 * email. Destinataire = SUPPORT_EMAIL (vide → no-op annoncé).
 *
 * Lecture CROSS-TENANT : `feedback` est FORCE RLS, l'amateo_app sans GUC ne verrait
 * rien — on lit par la connexion `admin` (porte admin_all), en SQL paramétré.
 *
 * ⚠ Le corps de l'email — INTERNE au fondateur — porte un EXTRAIT du message
 * saisi par l'utilisateur (borné à 200 caractères) : c'est un digest de support,
 * pas un texte public. L'exit non-zéro ne signale QUE l'échec de DISPATCH (le
 * `mailer->send` n'enfile qu'un message sur le bus — sémantique post-async).
 */
#[AsCommand(
    name: 'app:feedback:digest',
    description: 'Daily support digest of the feedback reports created yesterday (+ untreated backlog count). Runs daily (cron-runner).',
)]
final class FeedbackDigestCommand extends Command
{
    private const int MESSAGE_EXCERPT_MAX = 200;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly MailerInterface $mailer,
        // Destinataire du digest — bind global `$supportEmail` (%env(SUPPORT_EMAIL)%),
        // vide par défaut → no-op annoncé.
        private readonly string $supportEmail = '',
        private readonly MailFrom $mailFrom = new MailFrom,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Build the digest without sending any email.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        $today = \is_string($dateOption) && '' !== $dateOption
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $dateOption) ?: null
            : new DateTimeImmutable('today');
        if (!$today instanceof DateTimeImmutable) {
            $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

            return Command::FAILURE;
        }
        $start = $today->modify('-1 day');

        $connection = $this->connection();
        $rows = $connection->fetchAllAssociative(<<<'SQL'
            SELECT f.topic, f.message, f.created_at, c.name AS club_name
            FROM feedback f
            LEFT JOIN club c ON c.id = f.club_id
            WHERE f.created_at >= :start AND f.created_at < :end
            ORDER BY f.topic, f.created_at
            SQL, ['start' => $start->format('Y-m-d'), 'end' => $today->format('Y-m-d')]);

        if ([] === $rows) {
            $io->success(\sprintf('Aucun signalement le %s — pas de digest.', $start->format('Y-m-d')));

            return Command::SUCCESS;
        }

        if ('' === $this->supportEmail) {
            $io->warning(\sprintf('%d signalement(s) le %s, mais aucune adresse support configurée — rien envoyé.', \count($rows), $start->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $untreated = (int) $connection->fetchOne('SELECT COUNT(*) FROM feedback WHERE status = \'untreated\'');
        $email = $this->buildDigest($start, $rows, $untreated);

        if ($dryRun) {
            $io->success(\sprintf('[dry-run] %d signalement(s) le %s → digest vers %s (non envoyé).', \count($rows), $start->format('Y-m-d'), $this->supportEmail));

            return Command::SUCCESS;
        }

        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            $io->error(\sprintf('Échec de dispatch du digest : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d signalement(s) le %s → digest envoyé à %s.', \count($rows), $start->format('Y-m-d'), $this->supportEmail));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function buildDigest(DateTimeImmutable $day, array $rows, int $untreated): Email
    {
        $lines = [
            \sprintf('Signalements du %s : %d', $day->format('d/m/Y'), \count($rows)),
            \sprintf('En attente de traitement (total) : %d', $untreated),
            '',
        ];

        $currentTopic = null;
        foreach ($rows as $row) {
            $topic = (string) $row['topic'];
            if ($topic !== $currentTopic) {
                $currentTopic = $topic;
                $lines[] = '';
                $lines[] = \sprintf('— %s —', $topic);
            }
            // Le nom de club est du contenu géré par le club : même aplatissement
            // que le message (revue sécurité — cosmétique, le corps est MIME-encodé).
            $club = null === $row['club_name'] ? 'club inconnu' : $this->excerpt((string) $row['club_name']);
            $lines[] = \sprintf('  [%s] %s', $club, $this->excerpt((string) $row['message']));
        }

        return (new Email)
            ->from($this->mailFrom->address())
            ->to($this->supportEmail)
            ->subject(\sprintf('Signalements du %s (%d)', $day->format('d/m/Y'), \count($rows)))
            ->text(implode("\n", $lines));
    }

    /** Extrait borné du message utilisateur — digest INTERNE, pas un texte public. */
    private function excerpt(string $message): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if (mb_strlen($flat) <= self::MESSAGE_EXCERPT_MAX) {
            return $flat;
        }

        return mb_substr($flat, 0, self::MESSAGE_EXCERPT_MAX) . '…';
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
