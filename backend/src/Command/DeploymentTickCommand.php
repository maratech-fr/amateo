<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScheduledDeployment;
use App\Enum\DeploymentStatus;
use App\Enum\MatchPlacementRunStatus;
use App\Enum\ScheduleStatus;
use App\Mail\EmailTemplateRenderer;
use App\Repository\ScheduledDeploymentRepository;
use App\Service\GitHubDeployClient;
use App\Service\GitHubDeployException;
use App\Service\MailFrom;
use App\Service\ProductIdentity;
use DateTimeZone;
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
    /** Plafond d'attente d'une activité en cours (génération/placement) avant de forcer le départ. */
    private const int WAIT_CAP_MINUTES = 10;

    private const int DISPATCH_RETRY_MINUTES = 10;

    /** Fuseau d'affichage de l'heure de plafond dans la note de retard (audience superadmin FR). */
    private const string DISPLAY_TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly GitHubDeployClient $client,
        private readonly MailerInterface $mailer,
        private readonly MailFrom $mailFrom,
        private readonly ProductIdentity $productIdentity,
        private readonly ClockInterface $clock,
        // Origine navigateur pour l'URL ABSOLUE de la console — bind global `$frontendBaseUrl`
        // (%env(FRONTEND_BASE_URL)%) ; un chemin relatif « /admin » serait inutilisable en client mail.
        private readonly string $frontendBaseUrl = '',
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

        // P4-348 — à H, une GÉNÉRATION (schedule GENERATING) ou un PLACEMENT (match_placement_run
        // RUNNING) en cours, PEU IMPORTE le club (lu cross-tenant par la connexion admin, EN LECTURE
        // SEULE), retarde le départ : un déploiement pendant un solve corromprait le run. Plafonné à
        // H + WAIT_CAP — au-delà, on déploie malgré l'activité (le train ne peut attendre sans fin).
        $cap = $deployment->getScheduledAt()->modify(\sprintf('+%d minutes', self::WAIT_CAP_MINUTES));
        if ($this->activeSolveCount() > 0) {
            if ($now <= $cap) {
                $capLabel = $cap->setTimezone(new DateTimeZone(self::DISPLAY_TIMEZONE))->format('H:i');
                $deployment->setDispatchNote(\sprintf(
                    'Départ retardé : une génération ou un placement est en cours — nouvel essai au prochain passage, au plus tard à %s.',
                    $capLabel,
                ));
                $this->flush();
                $io->writeln('Active solve in progress; departure delayed.');

                return;
            }
            // Plafond atteint : on force le départ, en le DISANT (note de plafond, reportée en e-mail).
            $deployment->setDispatchNote('Plafond d’attente atteint : déploiement déclenché malgré une activité encore en cours.');
        }

        $version = $deployment->getVersion();

        try {
            // Préconditions RE-vérifiées à H : entre la programmation et maintenant, le tag a pu
            // disparaître ou la CI redevenir rouge → abandon SEC (tags VIDE-mais-réussi). Une lecture
            // qui ÉCHOUE (4xx/transport) lève GitHubDeployException « injoignable » → RÉESSAYABLE
            // (catch), jamais confondue avec un tag disparu.
            if (!\in_array($version, $this->client->tags(), true)) {
                $this->fail($deployment, 'La version à déployer n’est plus un tag publié : déploiement abandonné, rien n’a été déclenché.', $io);

                return;
            }
            if (!$this->client->isCiGreen($version)) {
                $this->fail($deployment, 'La CI de la version à déployer n’est plus verte : déploiement abandonné, rien n’a été déclenché.', $io);

                return;
            }

            // Re-datage de l'heure de retour sur le dispatch RÉEL (now + fenêtre d'origine) ; c'est
            // cette valeur qui part en `maintenance_until` (le workflow pose le témoin « de retour à … »).
            $deployment->redateExpectedReturn($now);
            $this->client->dispatchDeploy($version, $deployment->getExpectedReturnAt());
        } catch (GitHubDeployException) {
            // GitHub injoignable à H (tags/CI en 4xx ou transport) OU dispatch refusé : RÉESSAYÉ
            // jusqu'à scheduledAt + WAIT_CAP + DISPATCH_RETRY, puis FAILED « injoignable », zéro dispatch.
            $deadline = $deployment->getScheduledAt()->modify(\sprintf('+%d minutes', self::WAIT_CAP_MINUTES + self::DISPATCH_RETRY_MINUTES));
            if ($now > $deadline) {
                $this->fail($deployment, 'Le déclenchement du déploiement a échoué : GitHub est resté injoignable. La maintenance n’a pas été posée.', $io);

                return;
            }
            // Dans la fenêtre de réessai : on laisse « scheduled », le prochain tick retentera.
            $io->writeln('GitHub unreachable; will retry on the next tick.');

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
                $deployment->getDispatchNote(),
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
            $deployment->getDispatchNote(),
        );
    }

    /**
     * Génération (schedule GENERATING) ou placement (match_placement_run RUNNING) en cours, TOUS
     * clubs confondus — lu cross-tenant par la connexion `admin`, EN LECTURE SEULE (le tick ne mute
     * jamais une donnée tenant). PENDING n'est PAS compté (TOCTOU accepté : l'enfilage n'est pas
     * encore un run moteur).
     */
    private function activeSolveCount(): int
    {
        $generating = (int) $this->admin()->fetchOne(
            'SELECT COUNT(*) FROM schedule WHERE status = :status',
            ['status' => ScheduleStatus::GENERATING->value],
        );
        $running = (int) $this->admin()->fetchOne(
            'SELECT COUNT(*) FROM match_placement_run WHERE status = :status',
            ['status' => MatchPlacementRunStatus::RUNNING->value],
        );

        return $generating + $running;
    }

    private function fail(ScheduledDeployment $deployment, string $reason, SymfonyStyle $io): void
    {
        $deployment->markFailed($reason);
        $this->flush();
        $this->notify('🔴 Mise en production en échec', $reason, $io, $deployment->getDispatchNote());
    }

    private function notify(string $subject, string $body, SymfonyStyle $io, ?string $note = null): void
    {
        $recipients = $this->recipients();
        if ([] === $recipients) {
            $io->warning('Deployment transition detected but no enabled superadmin to notify.');

            return;
        }

        // La note de DÉPART (retard/plafond) est reportée dans l'e-mail (succès comme échec).
        $text = $body;
        if (null !== $note && '' !== $note) {
            $text .= "\n\n" . $note;
        }
        // Console en URL ABSOLUE (jamais « /admin » relatif, inutilisable en client mail), sur sa
        // propre ligne pour être rendue en bouton par le gabarit HTML (patron FeedbackDigestCommand).
        $consoleLink = rtrim($this->frontendBaseUrl, '/') . '/admin';
        $email = (new Email)
            ->from($this->mailFrom->address())
            ->subject(\sprintf('%s — %s', $this->productIdentity->name(), $subject))
            ->text($text . "\n\nConsole :\n" . $consoleLink);
        $headers = $email->getHeaders();
        $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $consoleLink);
        $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Ouvrir la console');
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
