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
 * Chantier F PR4 (réécrit depuis P5-6 PR 2) — digest quotidien des signalements au support.
 *
 * Promesse (décision fondateur PR4) : UN SEUL mail le matin annonçant les NOUVEAUX
 * signalements NON TRAITÉS = déposés la VEILLE [J-1, J) ET encore `untreated`. Zéro nouveau
 * non-traité → AUCUN mail. Corps BREF (~3 lignes), SANS extrait des messages (plus de recopie
 * du texte utilisateur), avec UN lien unique vers la console filtrée sur les non-traités
 * (jamais un lien par item). SUPPORT_EMAIL vide → no-op annoncé ; l'exit non-zéro ne signale
 * que l'échec de DISPATCH (`mailer->send` n'enfile qu'un message sur le bus — post-async).
 *
 * Lecture CROSS-TENANT : `feedback` est FORCE RLS, l'amateo_app sans GUC ne verrait rien —
 * on lit par la connexion `admin` (porte admin_all), en SQL paramétré.
 */
#[AsCommand(
    name: 'app:feedback:digest',
    description: 'Daily support digest of yesterday\'s new untreated feedback reports (single link, no excerpt). Runs daily (cron-runner).',
)]
final class FeedbackDigestCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly MailerInterface $mailer,
        // Destinataire du digest — bind global `$supportEmail` (%env(SUPPORT_EMAIL)%),
        // vide par défaut → no-op annoncé.
        private readonly string $supportEmail = '',
        // Origine navigateur pour le lien de la console — bind global `$frontendBaseUrl`
        // (%env(FRONTEND_BASE_URL)%).
        private readonly string $frontendBaseUrl = '',
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

        // Les NOUVEAUX non-traités de la veille : déposés dans [J-1, J) ET encore `untreated`.
        $count = (int) $this->connection()->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM feedback
                WHERE created_at >= :start AND created_at < :end AND status = 'untreated'
                SQL,
            ['start' => $start->format('Y-m-d'), 'end' => $today->format('Y-m-d')],
        );

        if (0 === $count) {
            $io->success(\sprintf('Aucun nouveau signalement non traité le %s — pas de digest.', $start->format('Y-m-d')));

            return Command::SUCCESS;
        }

        if ('' === $this->supportEmail) {
            $io->warning(\sprintf('%d nouveau(x) non-traité(s) le %s, mais aucune adresse support configurée — rien envoyé.', $count, $start->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $email = $this->buildDigest($start, $count);

        if ($dryRun) {
            $io->success(\sprintf('[dry-run] %d nouveau(x) non-traité(s) le %s → digest vers %s (non envoyé).', $count, $start->format('Y-m-d'), $this->supportEmail));

            return Command::SUCCESS;
        }

        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            $io->error(\sprintf('Échec de dispatch du digest : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d nouveau(x) non-traité(s) le %s → digest envoyé à %s.', $count, $start->format('Y-m-d'), $this->supportEmail));

        return Command::SUCCESS;
    }

    private function buildDigest(DateTimeImmutable $day, int $count): Email
    {
        // Corps BREF : le nombre + UN lien vers la console filtrée sur les non-traités. Aucun
        // extrait du message utilisateur (plus de recopie du texte) — le support ouvre la console.
        $body = implode("\n", [
            \sprintf('Nouveaux signalements non traités déposés le %s : %d', $day->format('d/m/Y'), $count),
            'Les consulter et les traiter dans la console :',
            $this->consoleLink(),
        ]);

        return (new Email)
            ->from($this->mailFrom->address())
            ->to($this->supportEmail)
            ->subject(\sprintf('Signalements non traités du %s (%d)', $day->format('d/m/Y'), $count))
            ->text($body);
    }

    /** Lien UNIQUE : console → onglet incidents → sous-onglet signalements → filtre non-traités. */
    private function consoleLink(): string
    {
        return rtrim($this->frontendBaseUrl, '/') . '/admin?tab=incidents&sub=signalements&fstatus=untreated';
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
