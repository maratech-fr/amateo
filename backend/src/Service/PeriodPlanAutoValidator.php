<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validation automatique des plannings de période d'UN club (décision fondateur 2026-11-03) : un
 * plan de période qu'aucun gestionnaire n'a validé 7 jours après le début de sa période est pointé
 * sur sa version CHARGÉE ({@see SchedulePlanProvisioner::lockSourceScheduleIdOfPlan}), ses sœurs
 * sont gardées, rien d'autre ne bouge (ni matchs, ni doléances, ni e-mail, ni Mercure). Jamais le
 * plan de saison, jamais un plan déjà validé une fois (même rouvert), jamais un plan d'une saison
 * archivée — jugée au JOUR DU CLUB, `$today`, que l'appelant donne ({@see ClubDay::todayFor}).
 *
 * Appelé par la tâche quotidienne `app:period-plans:auto-validate` (GUC posé par la commande) et
 * par `ClubClockController` après la pose de l'horloge d'un club de démo (GUC posé par le listener).
 *
 * Chaque plan se décide dans SA transaction DBAL, sous `lockPlanScope(entryId)` — même verrou que
 * la validation manuelle : relecture des faits sous le verrou, puis UPDATE qui re-vérifie tout
 * (D6). Jamais `EntityManager::wrapInTransaction` : une exception y fermerait l'EntityManager, et
 * l'appelant (cron multi-clubs, requête HTTP) doit rester utilisable après l'échec d'un plan.
 */
final readonly class PeriodPlanAutoValidator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SchedulePlanProvisioner $provisioner,
        private SeasonResolver $seasonResolver,
    ) {}

    public function sweepClub(Club $club, DateTimeImmutable $today, bool $dryRun = false): PeriodPlanSweepReport
    {
        $seasons = $this->seasonResolver->seasonsForClub($club->getId());
        $archived = [];
        foreach ($seasons as $season) {
            if (SeasonResolver::isReadonlyAmong($season, $seasons, $today)) {
                $archived[$season->getId()] = true;
            }
        }

        $validated = [];
        $startedWithoutPlan = [];
        $skippedInFlight = [];
        $connection = $this->entityManager->getConnection();
        foreach ($this->provisioner->autoValidationCandidates($club->getId(), $today) as $candidate) {
            if (isset($archived[$candidate['seasonId']])) {
                continue;
            }

            /** @var array{0: string, 1?: string, 2?: int}|null $outcome */
            $outcome = $connection->transactional(fn (): ?array => $this->decide($candidate['planId'], $candidate['entryId'], $today, $dryRun));
            if (null === $outcome) {
                continue;
            }
            match ($outcome[0]) {
                'validated' => $validated[] = ['planId' => $candidate['planId'], 'scheduleId' => (string) ($outcome[1] ?? ''), 'keptVersions' => (int) ($outcome[2] ?? 0)],
                'started_without_plan' => $startedWithoutPlan[] = $candidate['planId'],
                default => $skippedInFlight[] = $candidate['planId'],
            };
        }

        return new PeriodPlanSweepReport($validated, $startedWithoutPlan, $skippedInFlight);
    }

    /**
     * Sous le verrou de plan-scope de l'entrée : relit les faits, retient la version chargée, pointe.
     *
     * @return array{0: string, 1?: string, 2?: int}|null null = plus rien à faire (validé entre-temps, 0 ligne)
     */
    private function decide(string $planId, string $entryId, DateTimeImmutable $today, bool $dryRun): ?array
    {
        $this->provisioner->lockPlanScope($entryId);

        $facts = $this->provisioner->periodPlanFacts([$planId])[$planId] ?? null;
        if (null === $facts || null !== $facts['chosenScheduleId'] || null !== $facts['firstChosenAt'] || null !== $facts['autoValidatedDate']) {
            return null;
        }
        if ($facts['inFlightCount'] > 0) {
            return ['skipped_in_flight'];
        }

        $scheduleId = $this->provisioner->lockSourceScheduleIdOfPlan($planId);
        if (null === $scheduleId) {
            return ['started_without_plan'];
        }

        $kept = max(0, $facts['completedCount'] - 1);
        if ($dryRun || $this->provisioner->chooseAutomatically($planId, $scheduleId, $today)) {
            return ['validated', $scheduleId, $kept];
        }

        return null;
    }
}
