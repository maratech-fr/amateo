<?php

declare(strict_types=1);

namespace App\Command;

use App\AdminJob\AdminJobCatalog;
use App\AdminJob\AdminJobDefinition;
use App\Service\AdminAlertStateStore;
use App\Service\AdminDataFreshnessService;
use App\Service\AdminHealthService;
use App\Service\HealthAlertEvaluator;
use App\Service\MailFrom;
use App\Service\ProductIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Alerting minimal (console superadmin) : vérifie les MÊMES sondes que le dashboard
 * (AdminHealthService) + la fraîcheur des référentiels, et EMAILE les superadmins
 * actifs quand un check passe au rouge — « tu apprends la panne avant tes
 * utilisateurs ». Anti-spam par état (AdminAlertStateStore) : une alerte à l'entrée
 * d'incident, un email de rétablissement au retour, silence entre les deux.
 * Cadence : toutes les 10 minutes (catalogue SA3, cron-runner).
 */
#[AsCommand(
    name: 'app:health:alert',
    description: 'Check health probes + data freshness and email superadmins on red transitions. Runs every 10 minutes (cron-runner).',
)]
final class HealthAlertCommand extends Command
{
    public function __construct(
        private readonly AdminHealthService $healthService,
        private readonly AdminDataFreshnessService $freshnessService,
        private readonly HealthAlertEvaluator $evaluator,
        // Catalogue fermé : label/command du job surveillé, jamais une chaîne libre.
        private readonly AdminJobCatalog $catalog,
        private readonly AdminAlertStateStore $stateStore,
        private readonly MailerInterface $mailer,
        private readonly ManagerRegistry $registry,
        // Même expéditeur que tous les mails de l'app (domaine vérifié, SPF/DKIM alignés).
        private readonly MailFrom $mailFrom,
        // Nom produit unique (P5-15) — préfixe des sujets d'alerte superadmin.
        private readonly ProductIdentity $productIdentity,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $health = $this->healthService->health();
        $freshness = $this->freshnessService->referentials();
        $solver = $this->solverLast24h();

        $alerts = $this->evaluator->evaluate($health, $freshness, $solver, $this->jobSignals());
        // DEUX temps (revue #257) : preview (aucune écriture) → ENVOI → commit. Si le
        // mailer lève, rien n'est commité : le job est marqué failed (visible au panneau)
        // et le prochain tick RE-TENTE l'envoi — une panne SMTP transitoire ne peut pas
        // avaler définitivement l'alerte d'un incident.
        $diff = $this->stateStore->preview($alerts);

        if ([] === $diff['fired'] && [] === $diff['recovered']) {
            $io->writeln(\sprintf('No transition (%d check(s) currently firing).', \count($alerts)));

            return Command::SUCCESS;
        }

        $recipients = $this->recipients();
        if ([] !== $recipients) {
            // UN SEUL email combiné (🔴 nouvelles + 🟢 rétablies) : un envoi, une
            // atomicité naturelle — pas de fenêtre « fired parti, recovered perdu ».
            $sections = [];
            if ([] !== $diff['fired']) {
                $sections[] = "Nouvelles alertes :\n" . implode("\n", array_map(static fn (array $alert): string => '• ' . $alert['message'], $diff['fired']));
            }
            if ([] !== $diff['recovered']) {
                $sections[] = "De nouveau au vert :\n" . implode("\n", array_map(static fn (string $key): string => '• ' . $key, $diff['recovered']));
            }
            $product = $this->productIdentity->name();
            $subject = [] !== $diff['fired']
                ? \sprintf('🔴 %s — %d alerte(s)', $product, \count($diff['fired']))
                : \sprintf('🟢 %s — %d check(s) rétabli(s)', $product, \count($diff['recovered']));
            $this->send($recipients, $subject, implode("\n\n", $sections));
            $io->writeln(\sprintf('Transition email sent (%d fired, %d recovered).', \count($diff['fired']), \count($diff['recovered'])));
        } else {
            // Pas de destinataire : rien à envoyer — on committe quand même (l'état doit
            // refléter la réalité, sinon la transition serait re-signalée à chaque tick).
            $io->warning('Alert transitions detected but no enabled superadmin to notify.');
        }

        $this->stateStore->commit($alerts);

        return Command::SUCCESS;
    }

    /**
     * Dernier statut connu des jobs dont un ÉCHEC doit alerter immédiatement (Q2) :
     * aujourd'hui le contrôle de restauration hebdomadaire. Statut = dernier run
     * (admin_job_run) ; label/command viennent du catalogue fermé, jamais d'une chaîne
     * libre. Liste vide si le job n'est pas au catalogue — jamais un `$jobs` vide passé
     * par oubli de câblage.
     *
     * @return list<array{key: string, label: string, command: string, lastStatus: ?string}>
     */
    private function jobSignals(): array
    {
        $signals = [];
        foreach (['db-restore-check'] as $jobKey) {
            $definition = $this->catalog->find($jobKey);
            if (!$definition instanceof AdminJobDefinition) {
                continue;
            }
            $lastStatus = $this->admin()->fetchOne(
                'SELECT status FROM admin_job_run WHERE job_key = :job_key ORDER BY started_at DESC, id DESC LIMIT 1',
                ['job_key' => $definition->key],
            );
            $signals[] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'command' => $definition->command,
                'lastStatus' => \is_string($lastStatus) ? $lastStatus : null,
            ];
        }

        return $signals;
    }

    /** @return array{generations24h: int, infeasible24h: int} */
    private function solverLast24h(): array
    {
        $row = $this->admin()->fetchAssociative(
            'SELECT COUNT(*) AS generations, COUNT(*) FILTER (WHERE status = \'INFEASIBLE\') AS infeasible FROM solver_metrics WHERE created_at >= NOW() - INTERVAL \'24 hours\'',
        );

        return [
            'generations24h' => false === $row ? 0 : (int) ($row['generations'] ?? 0),
            'infeasible24h' => false === $row ? 0 : (int) ($row['infeasible'] ?? 0),
        ];
    }

    /**
     * Les superadmins ACTIFS sont les destinataires — zéro configuration nouvelle :
     * qui peut ouvrir la console reçoit ses alertes.
     *
     * @return list<string>
     */
    private function recipients(): array
    {
        $emails = $this->admin()->fetchFirstColumn('SELECT email FROM super_admin WHERE enabled = TRUE ORDER BY email');

        return array_values(array_filter($emails, static fn (mixed $email): bool => \is_string($email) && '' !== $email));
    }

    /** @param list<string> $recipients */
    private function send(array $recipients, string $subject, string $body): void
    {
        $email = (new Email)
            ->from($this->mailFrom->address())
            ->subject($subject)
            ->text($body . "\n\nConsole : /admin");
        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }
        $this->mailer->send($email);
    }

    private function admin(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
