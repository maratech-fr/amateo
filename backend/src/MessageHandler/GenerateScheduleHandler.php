<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Exception\EngineRequestNotComposable;
use App\Exception\EngineRequestRejectedException;
use App\Message\GenerateScheduleMessage;
use App\Plans\InForce;
use App\Plans\PlanStates;
use App\Plans\SoclePlan;
use App\Service\ClubGenerationLock;
use App\Service\DevScheduleReportWriter;
use App\Service\EngineClient;
use App\Service\EngineRequestComposer;
use App\Service\OutputCreditLedger;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleDiagnosticsRecorder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Service\ScheduleResultImporter;
use App\Service\SolverMetricsMapper;
use App\Service\SolverMetricsRecorder;
use App\Service\TenantConnectionContext;
use App\Structure\FamilyCatalog;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Orchestrates async schedule generation: lock → frozen snapshot → engine solve
 * → import result → diagnostics → Mercure. The mechanics live in dedicated
 * collaborators (BCK-04): EngineClient (HTTP), ScheduleDiagnosticsRecorder
 * (diagnostics), SolverMetricsMapper (metrics), ScheduleProgressPublisher
 * (Mercure). This class is the flow + the terminal-status guarantee (BCK-01).
 */
#[AsMessageHandler]
final class GenerateScheduleHandler
{
    /**
     * Marge du TTL du verrou de génération AU-DESSUS du budget demandé au moteur.
     *
     * ⚠ **L'invariant est : le verrou survit toujours au solve.** S'il expire pendant que le
     * worker attend `EngineClient::solve()`, un second message acquiert le verrou et **deux
     * solves tournent sur le même club** : deux imports, le dernier écrase l'autre. Et le
     * `release()` par token fait correctement son no-op — donc **aucune exception, aucun log,
     * aucun test rouge**. C'est la corruption la plus silencieuse du registre (audit D-05).
     *
     * La marge couvre ce que le budget moteur NE compte PAS : l'aller-retour HTTP, la
     * sérialisation du payload, l'import du résultat. Le budget réel du moteur est
     * `min(tier, solver_timeout_seconds) + CHAINING_PHASE_MAX_SECONDS` — soit 610 s au
     * plafond (700 s par le best-effort : nominal, diagnostic, sonde, best-effort, chaînage), contre 810 s de TTL ici. `GenerationLockOutlivesTheSolveTest` compare les deux
     * en lisant le moteur : baisser cette marge sous le budget fait rougir la CI, au lieu de
     * se découvrir en production sur un planning écrasé.
     */
    private const int LOCK_TTL_MARGIN_SECONDS = 60;

    private EngineRequestComposer $requestComposer;

    /**
     * `$requestComposer` est injecté par le conteneur ; absent (handler construit à la main par un
     * test), il est composé sur le même constructeur de contraintes et le même provisionneur.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        ScheduleConstraintBuilder $constraintBuilder,
        private ScheduleResultImporter $resultImporter,
        private EngineClient $engineClient,
        private ScheduleProgressPublisher $progressPublisher,
        private ScheduleDiagnosticsRecorder $diagnosticsRecorder,
        private SolverMetricsMapper $metricsMapper,
        private ClubGenerationLock $clubGenerationLock,
        private TenantConnectionContext $tenantConnectionContext,
        private SchedulePlanProvisioner $schedulePlanProvisioner,
        private OutputCreditLedger $creditLedger,
        private PlanStates $planStates,
        private ?LoggerInterface $logger = null,
        private ?DevScheduleReportWriter $devReportWriter = null,
        private ?SolverMetricsRecorder $metricsRecorder = null,
        ?EngineRequestComposer $requestComposer = null,
    ) {
        $this->requestComposer = $requestComposer ?? new EngineRequestComposer($entityManager, $constraintBuilder, $schedulePlanProvisioner, new InForce($entityManager, new SoclePlan($entityManager), new FamilyCatalog($entityManager)));
    }

    public function __invoke(GenerateScheduleMessage $message): void
    {
        // RLS: no HTTP request in the worker → no GUC set by the listener. Scope
        // the connection to the message's club before any query, clear after.
        $this->tenantConnectionContext->setClubId($message->getClubId());

        try {
            $this->handle($message);
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }

    private function handle(GenerateScheduleMessage $message): void
    {
        $schedule = $this->findSchedule($message->getScheduleId());
        if (!$schedule instanceof Schedule) {
            // Under RLS a schedule belonging to another club is invisible here
            // (the old club_mismatch guard below can no longer fire for that
            // case). Deleted or mismatched: never leave the frontend spinning
            // on GENERATING — publish a terminal failure on the message's topic.
            $this->progressPublisher->publishTerminalFailure($message->getClubId(), $message->getScheduleId(), 'schedule_not_found');

            return;
        }

        // Defence in depth — unreachable for cross-club schedules under RLS
        // (handled above), kept for a same-club id mixup or RLS regression.
        if ($schedule->getClubId() !== $message->getClubId()) {
            $this->failSchedule($schedule, 'club_mismatch', 'Message club_id does not match schedule club_id.');
            $this->progressPublisher->publish($schedule, []);
            $this->entityManager->flush();

            return;
        }

        $lockToken = $this->clubGenerationLock->acquire($message->getClubId(), $message->getTimeoutSeconds() + self::LOCK_TTL_MARGIN_SECONDS);
        if (null === $lockToken) {
            $schedule->setStatus(ScheduleStatus::PENDING);
            $this->entityManager->flush();

            throw new RecoverableMessageHandlingException(\sprintf('Schedule generation already running for club %s.', $message->getClubId()));
        }

        try {
            // Garde de redélivrance : SEUL COMPLETED bloque. Une redélivrance Messenger
            // (worker tué après le flush COMPLETED, avant l'ack) re-solverait et écraserait
            // un planning DÉJÀ livré — retouches manuelles comprises. Les trois dispatchers
            // posent PENDING AVANT le dispatch, donc COMPLETED à l'entrée du handler ⟺ travail
            // fini : la garde ne peut pas bloquer une génération légitime.
            //
            // Lecture FRAÎCHE et APRÈS le verrou : lue avant le verrou, la garde verrait
            // GENERATING pendant qu'un autre worker finit, le laisserait flusher COMPLETED puis
            // re-solverait derrière lui. Seule la lecture post-acquisition ferme cette fenêtre.
            //
            // Tout le reste PASSE, chacun pour une raison : GENERATING (un SIGKILL ne fait
            // tourner aucun catch/finally — le planning reste GENERATING à vie, la redélivrance
            // EST sa reprise), PENDING (état de dispatch, ET état reposé par le handler au retry
            // de verrou), FAILED (relancer après échec est un usage normal), DRAFT (jamais
            // dispatché). Aucune seconde garde en amont : dupliquée sur ce chemin d'écriture,
            // elle rendrait le NR intestable (backend.md).
            $this->entityManager->refresh($schedule);
            if (ScheduleStatus::COMPLETED === $schedule->getStatus()) {
                // Fait d'exploitation, pas une anomalie : un log info, aucun publish Mercure
                // (le rail est best-effort, le statut est terminal en base et le front converge
                // au sondage suivant), puis on acquitte le message.
                $this->logger?->info('Génération déjà terminée — redélivrance Messenger ignorée', [
                    'scheduleId' => $schedule->getId(),
                    'clubId' => $message->getClubId(),
                ]);

                return;
            }

            $this->generate($schedule, $message);
        } catch (Throwable $exception) {
            // BCK-01: any uncaught error (snapshot build, result import, a flush)
            // must still leave a terminal status — a schedule frozen forever in
            // GENERATING is the worst failure mode. Mercure publish is best-effort
            // and never reaches here.
            $this->handleUnexpectedFailure($message, $exception);
        } finally {
            $this->clubGenerationLock->release($message->getClubId(), $lockToken);
        }
    }

    /**
     * Record a clean terminal failure for an otherwise-uncaught error.
     *
     * The unit of work may hold half-applied COMPLETED-path mutations (status,
     * season baseline, onboarding flag) when the error fired after the solve —
     * clear() discards them so we commit a FAILED that is *only* FAILED, never a
     * half-success that leaves the season baseline pointing at a failed schedule.
     *
     * Deterministic failures are acked (not rethrown): retrying would re-run the
     * whole ~650 s solve to fail again the same way. If the EntityManager was
     * closed by the original error the row stays GENERATING and the stuck-schedule
     * watchdog (app:schedules:reconcile-stuck) reconciles it — a bounded delay,
     * versus the permanent GENERATING freeze that existed before this net.
     */
    private function handleUnexpectedFailure(GenerateScheduleMessage $message, Throwable $exception): void
    {
        $this->logger?->error('Schedule generation failed unexpectedly', [
            'scheduleId' => $message->getScheduleId(),
            'clubId' => $message->getClubId(),
            'exception' => $exception,
        ]);

        if (!$this->entityManager->isOpen()) {
            return;
        }

        try {
            $this->entityManager->clear();
            $schedule = $this->findSchedule($message->getScheduleId());
            if (!$schedule instanceof Schedule) {
                return;
            }

            $schedule->setStatus(ScheduleStatus::FAILED);
            $this->metricsRecorder?->record($schedule);
            // Generic message: never leak raw exception detail (SQL, DSNs) to the
            // client-facing diagnostic. The full exception is logged above.
            $this->diagnosticsRecorder->recordSingle($schedule, 'internal_error', ScheduleDiagnosticSeverity::ERROR, 'Schedule generation failed unexpectedly. Please regenerate.');
            $this->entityManager->flush();
            $this->progressPublisher->publishSafely($schedule, []);
        } catch (Throwable) {
            // Could not record the failure (e.g. DB connection gone): the
            // watchdog reconciles this schedule on its next pass.
        }
    }

    private function generate(Schedule $schedule, GenerateScheduleMessage $message): void
    {
        // La demande au moteur (entrée figée + greffes de convergence) : `EngineRequestComposer`.
        // Ses deux refus nommés deviennent ici des échecs terminaux.
        try {
            $composed = $this->requestComposer->compose($schedule, $message->getSourceScheduleId(), $message->getFillSourceScheduleId());
        } catch (EngineRequestNotComposable $notComposable) {
            if (EngineRequestNotComposable::OVERLAY_ENTRY_MISSING === $notComposable->reason) {
                // The period was deleted between queueing and running — fail terminally.
                $this->failSchedule($schedule, 'overlay_entry_missing', 'The overlay period no longer exists.');
                $this->metricsRecorder?->record($schedule);
                $this->entityManager->flush();
                $this->progressPublisher->publishSafely($schedule, ['warnings' => ['The overlay period no longer exists.']]);

                return;
            }

            $failure = 'La version de départ de cette génération n\'existe plus. Relancez la régénération.';
            $this->failSchedule($schedule, 'source_version_missing', $failure);
            $this->metricsRecorder?->record($schedule);
            $this->entityManager->flush();
            $this->progressPublisher->publishSafely($schedule, ['warnings' => [$failure]]);

            return;
        }

        $snapshot = $composed->snapshot;
        $scheduleInput = $composed->input;

        // Lot 5 — la copie du froid du plan, lue MAINTENANT avec l'entrée figée (le plan peut changer pendant le
        // solve), socle comme période. L'empreinte prend la règle de trajet qu'elle porte. Son écriture, plus bas,
        // est BLOQUANTE.
        $planState = $this->planStates->capture($schedule->getSchedulePlanId());

        // P5-10 — la taille du payload (métrique de capacité). Le hash, lui, passe par la maison unique
        // de l'empreinte, qui ignore les séances NONE.
        $encodedSnapshot = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $payloadBytes = \strlen($encodedSnapshot);
        $schedule
            ->setStatus(ScheduleStatus::GENERATING)
            // Dernier passage gagne (retries de verrou) : sémantique voulue.
            ->setSolveStartedAt(new DateTimeImmutable)
            ->setSolverTimeoutSeconds($message->getTimeoutSeconds())
            ->setSnapshotData($snapshot)
            ->setSnapshotHash(ScheduleConstraintBuilder::fingerprint($snapshot, $this->planStates->travelRuleIn($planState)));

        // Geste 2 — figer la GREFFE de convergence pour que `Schedule::engineInput()` reste
        // fidèle à ce qui PART au moteur, sans jamais toucher au hash. Extraction par DIFFÉRENCE
        // de clés entre le payload post-greffe et le snapshot pré-greffe : les deux greffes
        // (`withPreviousAssignments` / `withSocleReferenceAssignments`) AJOUTENT une clé, n'en
        // modifient jamais une existante — le diff isole donc exactement la greffe. Les épingles
        // HARD du comblement (`withPinnedAssignments`) sont injectées AVANT le hash : elles vivent
        // déjà dans le snapshot et sortent naturellement de ce diff. Flush au flush existant
        // ci-dessous (avant le solve) : un signalement émis pendant le solve reste cohérent.
        $graft = array_diff_key($scheduleInput, $schedule->getSnapshotData());
        $schedule->setPayloadGraft([] === $graft ? null : $graft);

        $this->diagnosticsRecorder->purgePrevious($schedule);
        $this->entityManager->flush();

        $lotDir = null;
        if ($this->devReportWriter instanceof DevScheduleReportWriter) {
            try {
                $lotDir = $this->devReportWriter->writePayloadFiles($schedule, $scheduleInput);
            } catch (Throwable) {
                // never crash the generation over a report write failure
            }
        }

        try {
            $result = $this->engineClient->solve($scheduleInput, $message->getTimeoutSeconds());
        } catch (TransportExceptionInterface) {
            $schedule->setStatus(ScheduleStatus::FAILED);
            $this->diagnosticsRecorder->recordSingle($schedule, 'engine_timeout', ScheduleDiagnosticSeverity::ERROR, 'Schedule generation timed out.');
            $this->metricsRecorder?->record($schedule, null, $payloadBytes);
            $this->entityManager->flush();
            $this->progressPublisher->publishSafely($schedule, ['warnings' => ['Schedule generation timed out.']]);
            $this->writeResultFilesIfEnabled($schedule, $lotDir);

            return;
        } catch (EngineRequestRejectedException $rejected) {
            // P4-340 — refus de CONTRAT (422 Pydantic) : échec terminal LISIBLE, jamais l'opaque
            // « Schedule generation failed. » ni l'exception brute. Le message NOMME le(s) champ(s)
            // en cause (le `loc` sans « body ») et ne fait fuir NI la valeur d'input refusée, NI le
            // message anglais de Pydantic, NI l'URL interne du moteur. Patron de l'engine_timeout.
            // Pas de « relancez » : un refus de contrat est déterministe (c'est aussi pourquoi
            // `engine_rejected` est exclu de SERVICE_FAILURE_TYPES côté front).
            $this->failSchedule($schedule, 'engine_rejected', \sprintf(
                'Le moteur de calcul a refusé la demande de génération (contenu invalide). Champ(s) en cause : %s. Ce refus ne vient pas de votre saisie : signalez le problème.',
                implode(', ', $rejected->getFields()),
            ));
            $this->metricsRecorder?->record($schedule, null, $payloadBytes);
            $this->entityManager->flush();
            $this->progressPublisher->publishSafely($schedule, ['warnings' => ['Le moteur de calcul a refusé la demande de génération.']]);
            $this->writeResultFilesIfEnabled($schedule, $lotDir);

            return;
        } catch (Throwable $exception) {
            // P4-358 — exception moteur NON traduite (ni timeout de transport, ni refus de contrat
            // 422) : échec terminal LISIBLE avec un libellé STABLE, jamais le message brut de
            // l'exception. Symfony APPOSE l'URL appelée sur une JsonException/HttpException
            // (« … for "http://engine:8000/generate". ») : recopié tel quel, l'hôte/port interne du
            // solveur fuirait à l'écran ET dans `auto.generationFailure.firstError` d'un signalement
            // (P4-355), lisible par le support. La substance technique reste dans le log serveur.
            // Patron de l'engine_timeout (:361) / engine_rejected (:375).
            $this->logger?->error('Engine call failed with an untranslated exception', [
                'scheduleId' => $schedule->getId(),
                'clubId' => $schedule->getClubId(),
                'exception' => $exception,
            ]);
            $this->failSchedule($schedule, 'engine_error', 'Le moteur de calcul n\'a pas pu traiter la demande de génération. Réessayez ; si le problème persiste, signalez-le.');
            $this->metricsRecorder?->record($schedule, null, $payloadBytes);
            $this->entityManager->flush();
            $this->progressPublisher->publishSafely($schedule, ['warnings' => ['Le moteur de calcul n\'a pas pu traiter la demande de génération.']]);
            $this->writeResultFilesIfEnabled($schedule, $lotDir);

            return;
        }

        // Persist the result BEFORE notifying: a COMPLETED solve must be durable
        // even if Mercure is momentarily down (publish is best-effort below).
        // Le crédit fait partie du résultat (décision fondateur Q16 a) : import des créneaux (son
        // flush devient une transaction imbriquée), statut et crédit s'écrivent dans UNE transaction
        // tenue sur la connexion (pas `wrapInTransaction`, qui fermerait l'EM sur une erreur du
        // registre : le FAILED du filet doit encore pouvoir s'écrire). Une erreur du registre annule
        // tout — la version finit « échouée », sans crédit. Un flush ORM en échec ferme l'EM : la
        // version reste « en cours » jusqu'au rattrapage, comme avant. Mercure
        // reste APRÈS le commit, hors transaction. Lot 5 : la copie du froid s'écrit DANS la
        // transaction — sans elle, une version n'est jamais « terminée » : l'échec annule tout, sans
        // crédit, et le filet pose « échouée » au message générique.
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->applyEngineResult($schedule, $result);
            $this->metricsRecorder?->record($schedule, $result, $payloadBytes);
            $this->entityManager->flush();
            if (ScheduleStatus::COMPLETED === $schedule->getStatus()) {
                $this->planStates->store($schedule, $planState);
                $this->creditLedger->settle($message->getClubId(), $schedule->getSeasonId());
            }
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        $this->progressPublisher->publishSafely($schedule, $result);
        $this->writeResultFilesIfEnabled($schedule, $lotDir);
    }

    private function writeResultFilesIfEnabled(Schedule $schedule, ?string $lotDir): void
    {
        if (!$this->devReportWriter instanceof DevScheduleReportWriter || null === $lotDir) {
            return;
        }

        try {
            $this->devReportWriter->writeResultFiles($schedule, $lotDir);
        } catch (Throwable) {
            // never crash the generation over a report write failure
        }
    }

    private function findSchedule(string $scheduleId): ?Schedule
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);

        return $schedule instanceof Schedule ? $schedule : null;
    }

    /** @param array<string, mixed> $result */
    private function applyEngineResult(Schedule $schedule, array $result): void
    {
        $engineStatus = strtolower((string) ($result['status'] ?? 'failed'));
        $this->metricsMapper->apply($schedule, $result);

        if (\in_array($engineStatus, ['failed', 'infeasible'], true)) {
            $schedule->setStatus(ScheduleStatus::FAILED);
            $this->diagnosticsRecorder->record($schedule, $result);

            return;
        }

        if ('completed' !== $engineStatus) {
            $schedule->setStatus(ScheduleStatus::FAILED);
            $this->diagnosticsRecorder->recordSingle($schedule, 'engine_status', ScheduleDiagnosticSeverity::ERROR, \sprintf('Unsupported engine status "%s".', $engineStatus));

            return;
        }

        $this->resultImporter->import($schedule, $result);
        $this->diagnosticsRecorder->record($schedule, $result);
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        // V1bis (planning lifecycle) — la version qui vient de se terminer DEVIENT la version chargée
        // (l'étoile) de son plan, socle comme période : lot 4, un seul pointeur, sur le plan.
        // « Régénérer » repartira d'elle, « Charger » une version plus ancienne la remplacera. Pose
        // immédiate (SQL brut hors UnitOfWork, dans la transaction de l'appelant) : le plan n'est pas
        // chargé ici et la ClubGenerationLock sérialise les écritures de génération du club.
        // ADR-0002 inv. 2 : générer ne VALIDE pas (aucun pointage de `chosenScheduleId`).
        $this->schedulePlanProvisioner->pointLockSource($schedule->getSchedulePlanId(), $schedule->getId());
        $this->completeOnboarding($schedule);
    }

    /** The first successful generation marks the club as onboarded (wizard done). */
    private function completeOnboarding(Schedule $schedule): void
    {
        $club = $this->entityManager->getRepository(Club::class)->find($schedule->getClubId());
        if ($club instanceof Club && !$club->getOnboardingCompleted()) {
            $club->setOnboardingCompleted(true);
        }
    }

    private function failSchedule(Schedule $schedule, string $type, string $message): void
    {
        $schedule->setStatus(ScheduleStatus::FAILED);
        $this->diagnosticsRecorder->recordSingle($schedule, $type, ScheduleDiagnosticSeverity::ERROR, $message);
    }
}
