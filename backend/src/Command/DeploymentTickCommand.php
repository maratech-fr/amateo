<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScheduledDeployment;
use App\Enum\DeploymentStatus;
use App\Repository\ScheduledDeploymentRepository;
use App\Service\GitHubDeployClient;
use App\Service\GitHubDeployException;
use App\Service\MailFrom;
use App\Service\ProductIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * La MACHINE À ÉTATS de la « mise en prod programmée » (console superadmin), battue toutes les
 * minutes par le cron-runner (pas une cadence AdminJob : ce serait 1440 lignes de journal par
 * jour). Elle fait avancer l'UNIQUE déploiement actif :
 *
 *  - AVANT l'heure H : rien ne bouge ;
 *  - à H, préconditions re-vérifiées — tag disparu OU CI redevenue rouge → FAILED + e-mail, SANS
 *    dispatch (programmer n'engageait rien) ;
 *  - préconditions OK → dispatch de `deploy.yml`, statut DISPATCHED (run résolu) ; un dispatch qui
 *    échoue est RÉESSAYÉ jusqu'à H +10 min, puis FAILED + e-mail ;
 *  - run GitHub `success` → SUCCEEDED + e-mail ; `failure` → FAILED + e-mail disant que la
 *    MAINTENANCE RESTE POSÉE (décision fondateur : pas de rollback auto en v1).
 *
 * L'e-mail suit le patron {@see HealthAlertCommand} : destinataires = superadmins ACTIFS, lus par
 * la connexion `admin` (cross-tenant). Le déploiement lui-même est lu/écrit via l'ORM (connexion
 * par défaut). Tout « maintenant » passe par {@see ClockInterface}.
 */
#[AsCommand(
    name: 'app:deployment:tick',
    description: 'Advance the scheduled deployment (dispatch at H, poll the run, email superadmins). Runs every minute (cron-runner).',
)]
final class DeploymentTickCommand extends Command
{
    private const int DISPATCH_RETRY_MINUTES = 10;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly GitHubDeployClient $client,
        private readonly MailerInterface $mailer,
        private readonly MailFrom $mailFrom,
        private readonly ProductIdentity $productIdentity,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deployment = $this->repository()->findActive();
        if (!$deployment instanceof ScheduledDeployment) {
            $io->writeln('No active deployment.');

            return Command::SUCCESS;
        }

        if (DeploymentStatus::SCHEDULED === $deployment->getStatus()) {
            $this->advanceScheduled($deployment, $io);
        } else {
            $this->pollDispatched($deployment, $io);
        }

        return Command::SUCCESS;
    }

    private function advanceScheduled(ScheduledDeployment $deployment, SymfonyStyle $io): void
    {
        $now = $this->clock->now();
        if ($now < $deployment->getScheduledAt()) {
            $io->writeln('Deployment scheduled for later; nothing to do.');

            return;
        }

        $version = $deployment->getVersion();

        // Préconditions RE-vérifiées à H : entre la programmation et maintenant, le tag a pu
        // disparaître ou la CI redevenir rouge. L'une ou l'autre → échec SANS dispatch.
        if (!\in_array($version, $this->client->tags(), true)) {
            $this->fail($deployment, 'La version à déployer n’est plus un tag publié : déploiement abandonné, rien n’a été déclenché.', $io);

            return;
        }
        if (!$this->client->isCiGreen($version)) {
            $this->fail($deployment, 'La CI de la version à déployer n’est plus verte : déploiement abandonné, rien n’a été déclenché.', $io);

            return;
        }

        try {
            // `maintenance_until` = l'heure de retour annoncée : le workflow pose le témoin de
            // maintenance avec cet instant pour que l'app dise « de retour à … ».
            $this->client->dispatchDeploy($version, $deployment->getExpectedReturnAt());
        } catch (GitHubDeployException) {
            $deadline = $deployment->getScheduledAt()->modify(\sprintf('+%d minutes', self::DISPATCH_RETRY_MINUTES));
            if ($now > $deadline) {
                $this->fail($deployment, 'Le déclenchement du déploiement a échoué de façon répétée : abandon. La maintenance n’a pas été posée.', $io);

                return;
            }
            // Dans la fenêtre de réessai : on laisse « scheduled », le prochain tick retentera.
            $io->writeln('Dispatch failed; will retry on the next tick.');

            return;
        }

        $runId = $this->client->latestDeployRunId($version) ?? 0;
        $deployment->markDispatched($runId, $now);
        $this->flush();
        $io->writeln(\sprintf('Deployment dispatched (run %d).', $runId));
    }

    private function pollDispatched(ScheduledDeployment $deployment, SymfonyStyle $io): void
    {
        $runId = $deployment->getGithubRunId();
        if (null === $runId) {
            return;
        }

        $conclusion = $this->client->runConclusion($runId);
        if (null === $conclusion) {
            $io->writeln('Deployment run still in progress.');

            return;
        }

        if ('success' === $conclusion) {
            $deployment->markSucceeded();
            $this->flush();
            $this->notify(
                '🟢 Mise en production réussie',
                'La mise en production programmée a réussi : l’application est de nouveau en service.',
                $io,
            );

            return;
        }

        $deployment->markFailed(\sprintf('Run GitHub conclu : %s.', $conclusion));
        $this->flush();
        // ⚠ Décision fondateur v1 : pas de rollback automatique. Le workflow a posé la maintenance
        // avant de déployer ; sur échec, elle RESTE posée — un humain décide (rollback manuel).
        $this->notify(
            '🔴 Mise en production en échec',
            'La mise en production programmée a échoué. La maintenance reste posée : l’application est hors service tant qu’un rollback manuel n’a pas été fait.',
            $io,
        );
    }

    private function fail(ScheduledDeployment $deployment, string $reason, SymfonyStyle $io): void
    {
        $deployment->markFailed($reason);
        $this->flush();
        $this->notify('🔴 Mise en production en échec', $reason, $io);
    }

    private function notify(string $subject, string $body, SymfonyStyle $io): void
    {
        $recipients = $this->recipients();
        if ([] === $recipients) {
            $io->warning('Deployment transition detected but no enabled superadmin to notify.');

            return;
        }

        $email = (new Email)
            ->from($this->mailFrom->address())
            ->subject(\sprintf('%s — %s', $this->productIdentity->name(), $subject))
            ->text($body . "\n\nConsole : /admin");
        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }
        $this->mailer->send($email);
        $io->writeln(\sprintf('Notified %d superadmin(s).', \count($recipients)));
    }

    /**
     * Les superadmins ACTIFS — mêmes destinataires que les alertes de santé, lus par la connexion
     * `admin` (cross-tenant, aucun club posé).
     *
     * @return list<string>
     */
    private function recipients(): array
    {
        $emails = $this->admin()->fetchFirstColumn('SELECT email FROM super_admin WHERE enabled = TRUE ORDER BY email');

        return array_values(array_filter($emails, static fn (mixed $email): bool => \is_string($email) && '' !== $email));
    }

    private function repository(): ScheduledDeploymentRepository
    {
        return $this->entityManager()->getRepository(ScheduledDeployment::class);
    }

    private function flush(): void
    {
        $this->entityManager()->flush();
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager();
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    private function admin(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
