<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\ClubGenerationLock;
use App\Service\DevScheduleReportWriter;
use App\Service\EngineClient;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleDiagnosticsRecorder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Service\ScheduleResultImporter;
use App\Service\SolverMetricsMapper;
use App\Service\SolverMetricsRecorder;
use App\Service\StructureSnapshotter;
use App\Service\TenantConnectionContext;
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
     * plafond, contre 710 s de TTL ici. `GenerationLockOutlivesTheSolveTest` compare les deux
     * en lisant le moteur : baisser cette marge sous le budget fait rougir la CI, au lieu de
     * se découvrir en production sur un planning écrasé.
     */
    private const int LOCK_TTL_MARGIN_SECONDS = 60;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScheduleConstraintBuilder $constraintBuilder,
        private ScheduleResultImporter $resultImporter,
        private EngineClient $engineClient,
        private ScheduleProgressPublisher $progressPublisher,
        private ScheduleDiagnosticsRecorder $diagnosticsRecorder,
        private SolverMetricsMapper $metricsMapper,
        private ClubGenerationLock $clubGenerationLock,
        private TenantConnectionContext $tenantConnectionContext,
        private StructureSnapshotter $structureSnapshotter,
        private SchedulePlanProvisioner $schedulePlanProvisioner,
        private ?LoggerInterface $logger = null,
        private ?DevScheduleReportWriter $devReportWriter = null,
        private ?SolverMetricsRecorder $metricsRecorder = null,
    ) {}

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
        // Overlay (palier B): build from the period entry instead of the base plan.
        // ADR-0002 C4 : le type de build se dérive du PLAN (plan.type), plus de
        // Schedule.calendarEntryId. Un schedule sans plan LÈVE (periodEntryIdOf) —
        // une version sans plan ne doit pas exister (ruling 2026-07-17) ; l'exception
        // remonte à handleUnexpectedFailure → échec terminal, jamais un build socle muet.
        $overlayEntry = null;
        $overlayEntryId = $this->schedulePlanProvisioner->periodEntryIdOf($schedule);
        if (null !== $overlayEntryId) {
            $overlayEntry = $this->entityManager->getRepository(CalendarEntry::class)->find($overlayEntryId);
            if (!$overlayEntry instanceof CalendarEntry) {
                // The period was deleted between queueing and running — fail terminally.
                $this->failSchedule($schedule, 'overlay_entry_missing', 'The overlay period no longer exists.');
                $this->metricsRecorder?->record($schedule);
                $this->entityManager->flush();
                $this->progressPublisher->publishSafely($schedule, ['warnings' => ['The overlay period no longer exists.']]);

                return;
            }
        }

        $scheduleInput = $overlayEntry instanceof CalendarEntry
            ? $this->constraintBuilder->buildForOverlay($schedule, $overlayEntry)
            : $this->buildFrozenSnapshot($schedule);

        // P2-44 PR-3 (comblement) — solve PARTIEL : les placements de la version SOURCE de la
        // période sont épinglés HARD dans le payload (jamais persistés en base), seuls les trous
        // (équipes sous leur volume de séances) restent à placer. Injecté AVANT le hash de
        // snapshot : contrairement à `previousAssignments` (préférence de convergence), ces
        // épingles SONT l'entrée figée du solve — le snapshot doit les porter, comme il porte les
        // verrous d'un overlay ordinaire. N'a lieu que sur un plan de période (overlayEntry).
        $fillSourceId = $message->getFillSourceScheduleId();
        $isFill = null !== $fillSourceId && $overlayEntry instanceof CalendarEntry;
        if ($isFill) {
            $scheduleInput = $this->constraintBuilder->withPinnedAssignments(
                $scheduleInput,
                $this->pinnedSourceSlots((string) $fillSourceId, $schedule),
            );
        }

        // P5-10 — sérialiser UNE fois : le hash de snapshot ET la taille du payload
        // (métrique de capacité) sortent du même JSON — coût nul vs le hash existant.
        $encodedSnapshot = json_encode($scheduleInput, \JSON_THROW_ON_ERROR);
        $payloadBytes = \strlen($encodedSnapshot);
        $schedule
            ->setStatus(ScheduleStatus::GENERATING)
            // Dernier passage gagne (retries de verrou) : sémantique voulue.
            ->setSolveStartedAt(new DateTimeImmutable)
            ->setSolverTimeoutSeconds($message->getTimeoutSeconds())
            ->setSnapshotData($scheduleInput)
            ->setSnapshotHash(hash('sha256', $encodedSnapshot));

        // P3-21 — le placement PRÉCÉDENT (terme de stabilité moteur) est greffé sur l'entrée
        // du solveur APRÈS le snapshot (data + hash), jamais avant : le précédent est une
        // préférence de CONVERGENCE, pas une donnée de STRUCTURE. L'inclure dans
        // `snapshotHash` (ou dans `snapshotData`, gardée alignée sur le hash) le ferait
        // diverger de `currentStructureHash` — recalculé SANS lui par SchedulePlanProvisioner
        // — à CHAQUE régénération, cassant en silence le garde « structure inchangée »
        // (bouton Régénérer grisé, signal « structure modifiée » du cockpit). Seul
        // `$scheduleInput`, l'entrée réelle envoyée au moteur, le porte.
        //
        // P2-44 PR-3 — sauté en mode comblement : le fill épingle DÉJÀ tout le placé en HARD
        // (ci-dessus), et un HARD n'a pas de variable côté moteur — le terme de stabilité serait
        // un no-op sur les épingles et n'a aucun placement « précédent » à faire converger pour
        // les trous (jamais placés). L'ajouter ne ferait que doubler les mêmes placements.
        if (!$isFill) {
            $scheduleInput = $this->constraintBuilder->withPreviousAssignments(
                $scheduleInput,
                $this->resolvePreviousAssignmentSlots($schedule, $message),
            );
        } else {
            // PR-3 (comblement) — RÉFÉRENCE de comblement : les placements de la version POINTÉE du
            // socle (plan SEASON) sont émis pour que le solveur GARDE le jour+heure de référence des
            // séances comblées (gymnase libre). Comme `previousAssignments` : APRÈS le hash (une
            // préférence de convergence, pas une donnée de structure — sinon `snapshotHash`
            // divergerait de `currentStructureHash`). Distinct des épingles HARD (`withPinnedAssignments`,
            // AVANT le hash) : celles-ci FIGENT le déjà-placé, la référence ORIENTE les trous.
            $scheduleInput = $this->constraintBuilder->withSocleReferenceAssignments(
                $scheduleInput,
                $this->socleReferenceSlots($schedule),
            );
        }

        $this->diagnosticsRecorder->purgePrevious($schedule);
        $this->entityManager->flush();

        // planning-versions D2 (phase 1/2): serialize the structure NOW — pure
        // read, consistent with the payload just frozen (the wizard could be
        // edited during the ~650 s solve). Season plans only (the D3 restore is
        // season-scoped; overlays get their own tier later). Non-fatal: a read
        // failure cannot poison the EntityManager, nothing is written yet.
        $structureData = null;
        if (!$overlayEntry instanceof CalendarEntry) {
            // null === overlayEntry ⟺ plan SEASON (le socle) : la photo D2 est saison-scopée.
            try {
                $structureData = $this->structureSnapshotter->serialize($schedule->getClubId(), $schedule->getSeasonId());
            } catch (Throwable $e) {
                $this->logger?->warning('Structure snapshot serialization failed (generation continues)', [
                    'scheduleId' => $schedule->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
        } catch (Throwable $exception) {
            $this->failSchedule($schedule, 'engine_error', $exception->getMessage());
            $this->metricsRecorder?->record($schedule, null, $payloadBytes);
            $this->entityManager->flush();
            $this->progressPublisher->publishSafely($schedule, ['warnings' => [$exception->getMessage()]]);
            $this->writeResultFilesIfEnabled($schedule, $lotDir);

            return;
        }

        // Persist the result BEFORE notifying: a COMPLETED solve must be durable
        // even if Mercure is momentarily down (publish is best-effort below).
        // « socle ? » est déjà connu ici (null === overlayEntry) — on le passe pour
        // éviter de re-lire le plan à la complétion (C4 / code-review).
        $this->applyEngineResult($schedule, $result, !$overlayEntry instanceof CalendarEntry);
        $this->metricsRecorder?->record($schedule, $result, $payloadBytes);
        $this->entityManager->flush();

        // planning-versions D2 (phase 2/2): store the photo ONLY on a COMPLETED
        // plan — a FAILED regeneration must never overwrite the photo of the
        // still-visible previous plan. Direct DBAL upsert (no UnitOfWork): a
        // failure here cannot close the EM, and a concurrent duplicate resolves
        // via ON CONFLICT. Non-fatal by construction.
        if (null !== $structureData && ScheduleStatus::COMPLETED === $schedule->getStatus()) {
            try {
                $this->structureSnapshotter->store($schedule, $structureData);
            } catch (Throwable $e) {
                $this->logger?->warning('Structure snapshot store failed (plan already persisted)', [
                    'scheduleId' => $schedule->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
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

    /** @return array<string, mixed> */
    private function buildFrozenSnapshot(Schedule $schedule): array
    {
        return $this->constraintBuilder->buildForClubSeason(
            $schedule->getClubId(),
            $schedule->getSeasonId(),
        );
    }

    /**
     * P3-21 — les placements de la version « précédente » à faire converger (terme de
     * stabilité). Source EXPLICITE d'abord (`sourceScheduleId` = la version que le
     * gestionnaire REGARDE en régénérant) ; à défaut, repli sur la dernière version
     * COMPLETED du MÊME plan. Aucune ⇒ première génération : bloc vide.
     *
     * La source est TOUJOURS de la MÊME lignée (le même `schedulePlanId`) : ADR-0002 —
     * jamais le socle pour un overlay, ni l'inverse. Pour une source explicite, on le VÉRIFIE
     * (défense en profondeur contre un id incohérent) ; pour le repli, la requête est déjà
     * bornée au plan.
     *
     * @return array<ScheduleSlotTemplate>
     */
    private function resolvePreviousAssignmentSlots(Schedule $schedule, GenerateScheduleMessage $message): array
    {
        $sourceId = $message->getSourceScheduleId();
        if (null === $sourceId) {
            $source = $this->latestCompletedOfPlan($schedule);
        } else {
            $source = $this->findSchedule($sourceId);
            // Même lignée obligatoire : une source d'un autre plan (le socle sous un overlay,
            // p.ex.) donnerait un précédent hors sujet. Incohérence ⇒ pas de précédent.
            if (!$source instanceof Schedule || $source->getSchedulePlanId() !== $schedule->getSchedulePlanId()) {
                $source = null;
            }
        }

        if (!$source instanceof Schedule) {
            return [];
        }

        return $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(
            ['scheduleId' => $source->getId()],
            ['id' => 'ASC'],
        );
    }

    /**
     * P2-44 PR-3 (comblement) — les placements de la version SOURCE à épingler HARD dans le
     * payload du solve partiel. Même lignée obligatoire (même `schedulePlanId`) : le contrôleur
     * l'a déjà garanti, on le RE-vérifie en défense — une source d'un autre plan (le socle sous
     * un overlay) épinglerait le mauvais planning ; incohérence ⇒ aucune épingle (le solve
     * repartirait de zéro plutôt que de figer des placements étrangers).
     *
     * @return array<ScheduleSlotTemplate>
     */
    private function pinnedSourceSlots(string $sourceScheduleId, Schedule $target): array
    {
        $source = $this->findSchedule($sourceScheduleId);
        if (!$source instanceof Schedule || $source->getSchedulePlanId() !== $target->getSchedulePlanId()) {
            return [];
        }

        return $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(
            ['scheduleId' => $source->getId()],
            ['id' => 'ASC'],
        );
    }

    /**
     * PR-3 (comblement) — les placements de la version POINTÉE du socle (plan SEASON de la saison),
     * RÉFÉRENCE du comblement émise au moteur en `socleReferenceAssignments`. En comblement le socle
     * est GARANTI en vigueur (SocleGuard, `FillPeriodPlanController`) : le pointeur est non-null.
     * Le `null ===` ci-dessous n'est donc PAS un repli métier (« et si pas de version » est un cas
     * IMPOSSIBLE en fill) — c'est un simple rétrécissement de type ; sans version pointée il n'y a
     * rien à référencer, on rend `[]` (chemin byte-identique, aucune greffe).
     *
     * @return array<ScheduleSlotTemplate>
     */
    private function socleReferenceSlots(Schedule $schedule): array
    {
        $socleScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($schedule->getSeasonId());
        if (null === $socleScheduleId) {
            return [];
        }

        return $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(
            ['scheduleId' => $socleScheduleId],
            ['id' => 'ASC'],
        );
    }

    /**
     * La dernière version COMPLETED du plan de `$schedule`, hors la version en cours de
     * génération (numérotation linéaire `versionNumber` — la V la plus haute finie).
     */
    private function latestCompletedOfPlan(Schedule $schedule): ?Schedule
    {
        $candidates = $this->entityManager->getRepository(Schedule::class)->findBy(
            ['schedulePlanId' => $schedule->getSchedulePlanId(), 'status' => ScheduleStatus::COMPLETED],
            ['versionNumber' => 'DESC'],
        );

        foreach ($candidates as $candidate) {
            if ($candidate->getId() !== $schedule->getId()) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $result */
    private function applyEngineResult(Schedule $schedule, array $result, bool $isSeasonPlan): void
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
        // An overlay (period plan) must NEVER become the season baseline nor the
        // season's loaded context (both are season-plan concepts). ADR-0002 C4 :
        // « socle ? » = plan.type === SEASON, déjà résolu au début de generate()
        // (null === overlayEntry) et passé ici — zéro lecture de plan à la complétion.
        if ($isSeasonPlan) {
            $this->anchorSeasonToCompletedPlan($schedule);
        }
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

    /**
     * The first successful **season plan** of a season becomes its baseline (the
     * "main" plan) automatically. Later generations do not steal it (re-designation
     * is an explicit user action via POST /api/schedules/{id}/validate).
     *
     * UX-02: a period **overlay** (plan CLOSURE/HOLIDAY) must NEVER become the
     * baseline — the baseline is the full season plan, an overlay is a bounded
     * exception plan. The SOLE caller only enters here for a season plan (the
     * `isSeasonPlan` bool resolved once at generate() start, no extra plan SELECT).
     */
    private function anchorSeasonToCompletedPlan(Schedule $schedule): void
    {
        $season = $this->entityManager->getRepository(Season::class)->find($schedule->getSeasonId());
        if (!$season instanceof Season) {
            return;
        }
        // ADR-0002 inv. 2 : AUCUN pointage automatique. Générer ne choisit pas —
        // seul le gestionnaire choisit, en validant. (L'ancienne auto-baseline au
        // 1er COMPLETED désignait un « calendrier de base » que personne n'avait
        // choisi.) L'auto-★ RESTE (inv. 17) : chaque plan de saison COMPLETED EST
        // le contexte fraîchement chargé — sa structure a nourri ce solve.
        // « Charger cette version » la repointe ensuite.
        $season->setLiveContextScheduleId($schedule->getId());
    }

    private function failSchedule(Schedule $schedule, string $type, string $message): void
    {
        $schedule->setStatus(ScheduleStatus::FAILED);
        $this->diagnosticsRecorder->recordSingle($schedule, $type, ScheduleDiagnosticSeverity::ERROR, $message);
    }
}
