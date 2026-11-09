<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScheduledDeployment;
use App\Enum\DeploymentStatus;
use App\Enum\DeployRunVerdict;
use App\Enum\MatchPlacementRunStatus;
use App\Enum\ScheduleStatus;
use App\Mail\EmailTemplateRenderer;
use App\Repository\ScheduledDeploymentRepository;
use App\Service\DeployRunOutcome;
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
 *  - préconditions OK → snapshot du dernier run connu, dispatch de `deploy.yml`, statut DISPATCHED
 *    (run encore inconnu) ; un dispatch qui échoue est RÉESSAYÉ jusqu'à H +20 min, puis FAILED + e-mail ;
 *  - le run créé par le dispatch est retrouvé aux ticks suivants par sa date de création GitHub
 *    (jamais le run d'avant) ; introuvable après RUN_LOOKUP_MINUTES → FAILED + e-mail ;
 *  - run terminé → verdict lu dans ses jobs et steps ({@see DeployRunOutcome}) : SUCCEEDED (avec
 *    réserve éventuelle dans la note) ou FAILED, l'e-mail disant si la maintenance est restée posée
 *    (décision fondateur : pas de rollback auto en v1) ; GitHub illisible ou run figé au-delà de
 *    l'heure de retour + POLL_GRACE_MINUTES → FAILED sans rien affirmer de la maintenance.
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

    /** Délai, depuis le dispatch, au-delà duquel un run jamais retrouvé chez GitHub est un échec. */
    private const int RUN_LOOKUP_MINUTES = 10;

    /** Délai, après l'heure de retour annoncée, au-delà duquel un run sans verdict lisible est un échec. */
    private const int POLL_GRACE_MINUTES = 30;

    private const string TEXT_SUCCESS = 'La mise en production programmée a réussi : l’application est de nouveau en service.';

    private const string TEXT_MAINTENANCE_LEFT = 'La mise en production programmée a réussi : la nouvelle version est en service sur le serveur, mais la page de maintenance n’a pas pu être retirée. Retirez-la à la main (supprimer le fichier maintenance.on, doc de déploiement, § « Maintenance planifiée »). Ne faites pas de rollback : la version déployée est saine.';

    private const string TEXT_UNVERIFIED = 'La mise en production programmée a réussi d’après GitHub, mais le détail des étapes n’a pas pu être lu : vérifiez dans l’onglet Actions que la branche release a bien été recalée.';

    private const string TEXT_BUILD_ONLY = 'La mise en production programmée a publié les images mais la VM n’a pas été déployée (le déploiement automatique n’est pas armé) : l’application est restée en service sur la version précédente.';

    private const string TEXT_APP_IN_SERVICE = 'La mise en production programmée a échoué avant la bascule : la maintenance n’a pas été posée, l’application est restée en service sur la version précédente.';

    private const string TEXT_UNDER_MAINTENANCE = 'La mise en production programmée a échoué pendant la bascule : la maintenance reste posée, l’application est hors service tant qu’un rollback manuel n’a pas été fait.';

    private const string TEXT_UNDETERMINED = 'La mise en production programmée a échoué. L’état de la maintenance n’a pas pu être déterminé : vérifiez l’onglet Actions de GitHub avant tout geste.';

    private const string TEXT_NOT_FOLLOWED = 'La mise en production programmée n’a pas pu être suivie : GitHub n’a pas répondu dans le délai prévu. L’état de la maintenance est inconnu : vérifiez l’onglet Actions de GitHub avant toute reprogrammation.';

    private const string TEXT_NO_VERDICT = 'Le déploiement n’a pas rendu de verdict dans le délai prévu : son exécution GitHub est toujours en cours ou bloquée. L’état de la maintenance est inconnu : vérifiez l’onglet Actions de GitHub avant toute reprogrammation.';

    private const string TEXT_RUN_NOT_FOUND = 'Le déploiement a été déclenché, mais son exécution GitHub n’a pas été retrouvée après 10 minutes : vérifiez l’onglet Actions de GitHub avant toute reprogrammation. L’état de la maintenance est inconnu.';

    private const string NOTE_RELEASE_NOT_MOVED = 'Étape annexe en échec : la branche release n’a pas été recalée sur la version déployée. Recalez-la à la main (doc de déploiement, § « Branche `release` et correctif rapide (hotfix) »).';

    private const string NOTE_MAINTENANCE_LEFT = 'La page de maintenance est restée affichée après un déploiement réussi : retirez le fichier maintenance.on.';

    private const string NOTE_SEVERAL_RUNS = 'Plusieurs exécutions GitHub ont démarré après le déclenchement : la première a été retenue. Vérifiez l’onglet Actions.';

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

            // Snapshot du dernier run connu AVANT le dispatch : sa date de création (horloge GitHub)
            // borne la recherche du run que le dispatch va créer. Lu ICI, dans le bloc des
            // préconditions : une erreur GitHub sur cette lecture est réessayée comme les autres.
            $previousRun = $this->client->latestDeployRun();

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

        // Le run créé par le dispatch n'est pas encore listé : il est recherché aux ticks suivants,
        // créé STRICTEMENT après cette borne (sans run précédent : une minute avant le dispatch).
        $deployment->setRunLookupAfter($previousRun['createdAt'] ?? $now->modify('-60 seconds'));
        $deployment->markDispatched(null, $now);
        $this->flush();
        $io->writeln('Deployment dispatched; its GitHub run is looked up on the next ticks.');
    }

    /**
     * Toute lecture GitHub de cette phase qui échoue est RÉESSAYÉE en silence au tick suivant
     * (statut inchangé, aucun e-mail), dans deux bornes : la recherche du run vit
     * RUN_LOOKUP_MINUTES après le dispatch, le suivi du run rattaché jusqu'à `expected_return_at` +
     * POLL_GRACE_MINUTES. Passé la borne, l'échec est dit sans rien affirmer de la maintenance.
     */
    private function pollDispatched(ScheduledDeployment $deployment, SymfonyStyle $io): void
    {
        $runId = $deployment->getGithubRunId();
        if (null === $runId) {
            $this->lookForRun($deployment, $io);

            return;
        }

        $pastGrace = $this->clock->now() > $deployment->getExpectedReturnAt()->modify(\sprintf('+%d minutes', self::POLL_GRACE_MINUTES));

        try {
            $conclusion = $this->client->runConclusion($runId);
        } catch (GitHubDeployException) {
            if ($pastGrace) {
                $this->fail($deployment, self::TEXT_NOT_FOLLOWED, $io);

                return;
            }
            $io->writeln('GitHub unreadable while polling the run; will retry on the next tick.');

            return;
        }

        if (null === $conclusion) {
            if ($pastGrace) {
                $this->fail($deployment, self::TEXT_NO_VERDICT, $io);

                return;
            }
            $io->writeln('Deployment run still in progress.');

            return;
        }

        try {
            $verdict = DeployRunOutcome::fromJobs($this->client->runJobs($runId), $conclusion)->verdict;
        } catch (GitHubDeployException) {
            if (!$pastGrace) {
                $io->writeln('Jobs of the run unreadable; will retry on the next tick.');

                return;
            }
            $verdict = 'success' === $conclusion ? DeployRunVerdict::PROD_UPDATED_UNVERIFIED : DeployRunVerdict::UNDETERMINED;
        }

        $this->conclude($deployment, $verdict, $io);
    }

    /**
     * Le run créé par le dispatch = le plus ANCIEN des runs créés strictement après la borne
     * persistée ; plusieurs candidats → le premier, et la note le dit. Introuvable (absent ou
     * GitHub en erreur) RUN_LOOKUP_MINUTES après le dispatch → FAILED.
     */
    private function lookForRun(ScheduledDeployment $deployment, SymfonyStyle $io): void
    {
        $now = $this->clock->now();
        $dispatchedAt = $deployment->getDispatchedAt() ?? $deployment->getScheduledAt();
        $after = $deployment->getRunLookupAfter() ?? $dispatchedAt->modify('-60 seconds');

        try {
            $candidates = $this->client->dispatchRunsAfter($after, null);
        } catch (GitHubDeployException) {
            $candidates = [];
        }

        if ([] === $candidates) {
            if ($now > $dispatchedAt->modify(\sprintf('+%d minutes', self::RUN_LOOKUP_MINUTES))) {
                $this->fail($deployment, self::TEXT_RUN_NOT_FOUND, $io);

                return;
            }
            $io->writeln('Deployment run not listed yet; will look again on the next tick.');

            return;
        }

        $deployment->attachRun($candidates[0]);
        if (\count($candidates) > 1) {
            $deployment->appendNote(self::NOTE_SEVERAL_RUNS);
        }
        $this->flush();
        $io->writeln(\sprintf('Deployment run %d attached.', $candidates[0]));
    }

    /**
     * Statut final et e-mail selon ce que le run a fait à la prod. Pas de rollback automatique
     * (décision fondateur v1) : quand la bascule a échoué sous maintenance, un humain décide.
     */
    private function conclude(ScheduledDeployment $deployment, DeployRunVerdict $verdict, SymfonyStyle $io): void
    {
        $failure = match ($verdict) {
            DeployRunVerdict::BUILD_ONLY => self::TEXT_BUILD_ONLY,
            DeployRunVerdict::FAILED_APP_IN_SERVICE => self::TEXT_APP_IN_SERVICE,
            DeployRunVerdict::FAILED_UNDER_MAINTENANCE => self::TEXT_UNDER_MAINTENANCE,
            DeployRunVerdict::UNDETERMINED => self::TEXT_UNDETERMINED,
            default => null,
        };
        if (null !== $failure) {
            $this->fail($deployment, $failure, $io);

            return;
        }

        [$subject, $body, $reserve] = match ($verdict) {
            DeployRunVerdict::PROD_UPDATED_RELEASE_NOT_MOVED => ['🟢 Mise en production réussie — une étape annexe a échoué', self::TEXT_SUCCESS, self::NOTE_RELEASE_NOT_MOVED],
            DeployRunVerdict::PROD_UPDATED_MAINTENANCE_LEFT => ['🟢 Mise en production réussie — la page de maintenance est restée affichée', self::TEXT_MAINTENANCE_LEFT, self::NOTE_MAINTENANCE_LEFT],
            DeployRunVerdict::PROD_UPDATED_UNVERIFIED => ['🟢 Mise en production réussie — détail non vérifié', self::TEXT_UNVERIFIED, null],
            default => ['🟢 Mise en production réussie', self::TEXT_SUCCESS, null],
        };
        if (null !== $reserve) {
            $deployment->appendNote($reserve);
        }
        $deployment->markSucceeded();
        $this->flush();
        $this->notify($subject, $body, $io, $deployment->getDispatchNote());
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

        // La note du tick (départ retardé/forcé, réserves de fin) est reportée dans l'e-mail.
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
