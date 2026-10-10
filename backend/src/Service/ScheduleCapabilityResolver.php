<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ScheduleCapabilities;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-8 (PR A) — SOURCE UNIQUE des prédicats de permission d'une version, PARTAGÉE entre
 * les gardes d'écriture (qui REFUSENT) et le bloc `capabilities` sérialisé (qui AFFICHE).
 * « Capacité affichée == verdict du refus » : les deux passent par le même code, donc pas
 * de dérive serveur↔serveur (le motif des 40 défauts d'ADR-0002, où le front re-dérivait
 * des règles que le serveur possédait déjà).
 *
 * Les prédicats unitaires ci-dessous sont ceux que déléguaient auparavant, chacun de son
 * côté, ScheduleStateProcessor::processDelete, ValidateScheduleController et
 * RegenerateFromVersionController — extraits ICI sans changer leur logique. Le batch
 * {@see forSchedules} les reproduit en AGRÉGATS (un GROUP BY par plan, un appel par saison)
 * pour ne jamais N+1 la collection ; le test de parité épingle l'équivalence.
 */
final class ScheduleCapabilityResolver
{
    /** @var list<ScheduleStatus> */
    private const IN_FLIGHT = [ScheduleStatus::PENDING, ScheduleStatus::GENERATING];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly OverlayManager $overlayManager,
    ) {}

    /** Une version en cours de solve (PENDING/GENERATING) — ni supprimable, ni « sœur » validable. */
    public function isInFlight(Schedule $schedule): bool
    {
        return \in_array($schedule->getStatus(), self::IN_FLIGHT, true);
    }

    /** Le plan de cette version la POINTE (« validée », ADR-0002 inv. 1). */
    public function isChosen(Schedule $schedule): bool
    {
        return $this->schedulePlanProvisioner->isChosen($schedule->getId());
    }

    /**
     * Cette version est-elle la seule version TERMINÉE du plan de la saison ? Les overlays de
     * période ne comptent pas (leur plan n'est pas SEASON). Déplacé depuis
     * ScheduleStateProcessor sans changer sa logique : variante NON levante (planIsSeason)
     * — un schedule sans plan reste SUPPRIMABLE (ruling 2026-07-17), jamais un 500.
     */
    public function isLastFinishedSeasonVersion(Schedule $schedule): bool
    {
        $planId = $schedule->getSchedulePlanId();
        if (ScheduleStatus::COMPLETED !== $schedule->getStatus() || !$this->schedulePlanProvisioner->planIsSeason($planId)) {
            return false;
        }

        $others = $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
            'schedulePlanId' => $planId,
            'status' => ScheduleStatus::COMPLETED,
        ]);

        return $others <= 1;
    }

    /** Une génération est-elle en cours quelque part dans la saison ? (garde du restore destructif). */
    public function inFlightInSeason(string $clubId, string $seasonId): bool
    {
        return $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'status' => self::IN_FLIGHT,
        ]) > 0;
    }

    /**
     * Le bloc `capabilities` de chaque version, en BATCH — ZÉRO N+1. Reçoit du provider les
     * ensembles qu'il calcule déjà pour les autres champs du DTO (choisi / type de plan /
     * photo), puis n'ajoute qu'UN GROUP BY (schedule_plan_id, status) sur les plans présents
     * et UN appel par saison (générations en cours + périodes invalidées). Les verdicts
     * reproduisent EXACTEMENT les prédicats unitaires ci-dessus.
     *
     * @param list<Schedule>        $schedules
     * @param array<string, true>   $chosenIds     ids des versions que leur plan pointe
     * @param array<string, string> $planTypeById  scheduleId => type de plan (SEASON|CLOSURE|HOLIDAY)
     * @param array<string, true>   $withPhoto     ids des versions portant une photo de structure
     * @param array<string, true>   $lockSourceIds ids des versions qui SONT la source des verrous de
     *                                             leur plan (maison {@see SchedulePlanProvisioner::lockSourceScheduleIdOfPlan})
     *
     * @return array<string, ScheduleCapabilities> scheduleId => capacités
     */
    public function forSchedules(array $schedules, array $chosenIds, array $planTypeById, array $withPhoto, array $lockSourceIds = []): array
    {
        if ([] === $schedules) {
            return [];
        }

        $planIds = [];
        foreach ($schedules as $schedule) {
            $planIds[$schedule->getSchedulePlanId()] = true;
        }
        $planStats = $this->planStats(array_keys($planIds));
        // V1bis — les plans VALIDÉS (chosen_schedule_id non-null) du lot : « Charger » (load-locks)
        // est refusé sur un plan validé (rouvrir d'abord), donc canLoadLocks le lit par plan.
        $validatedPlanIds = $this->validatedPlanIds(array_keys($planIds));

        $seasonInFlight = []; // seasonId => bool, résolu une fois par saison
        $seasonOverlays = []; // seasonId => int, résolu une fois par saison
        $capabilities = [];
        foreach ($schedules as $schedule) {
            $id = $schedule->getId();
            $seasonId = $schedule->getSeasonId();
            $stats = $planStats[$schedule->getSchedulePlanId()] ?? ['total' => 0, 'completed' => 0, 'inFlight' => 0];

            $isSeason = SchedulePlanType::SEASON->value === ($planTypeById[$id] ?? null);
            $isChosen = isset($chosenIds[$id]);
            $isCompleted = ScheduleStatus::COMPLETED === $schedule->getStatus();
            $isInFlight = $this->isInFlight($schedule);

            // hasInFlightSibling : une AUTRE version du plan est en vol (soi-même exclu).
            $hasInFlightSibling = ($stats['inFlight'] - ($isInFlight ? 1 : 0)) > 0;
            // isLastFinishedSeasonVersion : socle terminé, seul terminé de son plan.
            $isLastFinishedSeasonVersion = $isSeason && $isCompleted && $stats['completed'] <= 1;

            if (!isset($seasonInFlight[$seasonId])) {
                $seasonInFlight[$seasonId] = $this->inFlightInSeason($schedule->getClubId(), $seasonId);
            }

            // overlaysDroppedOnValidate : seul un socle NON-choisi déplace le calendrier de base
            // et invalide les plannings de période à venir (miroir de ValidateScheduleController).
            $overlaysDropped = 0;
            if ($isSeason && !$isChosen) {
                if (!isset($seasonOverlays[$seasonId])) {
                    $seasonOverlays[$seasonId] = \count(
                        $this->overlayManager->periodPlansInvalidatedBySeasonChange($schedule->getClubId(), $seasonId),
                    );
                }
                $overlaysDropped = $seasonOverlays[$seasonId];
            }

            // V1bis — « Charger » pour une PÉRIODE (load-locks) : version de période (plan non-SEASON,
            // jamais une version anomale sans plan) TERMINÉE, plan NON validé, aucune génération en
            // cours dans la saison, ET pas DÉJÀ la source (sinon le geste ne changerait rien — on ne
            // l'offre pas). Miroir des gardes de LoadLocksController (la génération en cours y est lue
            // sur la ClubGenerationLock Redis ; ici son miroir base PENDING/GENERATING de la saison).
            $isPeriod = !$isSeason && null !== ($planTypeById[$id] ?? null);
            $planValidated = isset($validatedPlanIds[$schedule->getSchedulePlanId()]);

            $capabilities[$id] = new ScheduleCapabilities(
                canDelete: !$isChosen && !$isInFlight && !$isLastFinishedSeasonVersion,
                canValidate: $isCompleted && !$hasInFlightSibling,
                canRegenerateFrom: $isSeason && $isCompleted && !$isChosen
                    && !$seasonInFlight[$seasonId] && isset($withPhoto[$id]),
                canLoadLocks: $isPeriod && $isCompleted && !$planValidated
                    && !$seasonInFlight[$seasonId] && !isset($lockSourceIds[$id]),
                versionsDeletedOnValidate: max(0, $stats['total'] - 1),
                overlaysDroppedOnValidate: $overlaysDropped,
            );
        }

        return $capabilities;
    }

    /**
     * Agrégats par plan pour la collection : un seul GROUP BY (schedule_plan_id, status).
     * Tenant+season filtrés comme le reste des lectures ORM du provider (un plan appartient à
     * UNE saison, ses versions la partagent — le season_filter ne sous-compte donc jamais).
     *
     * @param list<string> $planIds
     *
     * @return array<string, array{total: int, completed: int, inFlight: int}>
     */
    private function planStats(array $planIds): array
    {
        if ([] === $planIds) {
            return [];
        }

        /** @var list<array{planId: string, status: string|ScheduleStatus, c: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.schedulePlanId AS planId', 's.status AS status', 'COUNT(s.id) AS c')
            ->from(Schedule::class, 's')
            ->where('s.schedulePlanId IN (:planIds)')
            ->groupBy('s.schedulePlanId')
            ->addGroupBy('s.status')
            ->setParameter('planIds', $planIds, ArrayParameterType::STRING)
            ->getQuery()
            ->getScalarResult();

        $stats = [];
        foreach ($rows as $row) {
            $planId = $row['planId'];
            $status = $row['status'] instanceof ScheduleStatus ? $row['status'] : ScheduleStatus::from((string) $row['status']);
            $count = (int) $row['c'];
            $stats[$planId] ??= ['total' => 0, 'completed' => 0, 'inFlight' => 0];
            $stats[$planId]['total'] += $count;
            if (ScheduleStatus::COMPLETED === $status) {
                $stats[$planId]['completed'] += $count;
            }
            if (\in_array($status, self::IN_FLIGHT, true)) {
                $stats[$planId]['inFlight'] += $count;
            }
        }

        return $stats;
    }

    /**
     * V1bis — les plans VALIDÉS (chosen_schedule_id non-null) parmi `$planIds`, en UN batch.
     * Tenant+season filtrés comme le reste des lectures ORM du provider (un plan appartient à UNE
     * saison). Un plan absent de la carte est NON validé (espace de travail).
     *
     * @param list<string> $planIds
     *
     * @return array<string, true>
     */
    private function validatedPlanIds(array $planIds): array
    {
        if ([] === $planIds) {
            return [];
        }

        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.id AS id')
            ->from(SchedulePlan::class, 'p')
            ->where('p.id IN (:planIds)')
            ->andWhere('p.chosenScheduleId IS NOT NULL')
            ->setParameter('planIds', $planIds, ArrayParameterType::STRING)
            ->getQuery()
            ->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['id']] = true;
        }

        return $set;
    }
}
