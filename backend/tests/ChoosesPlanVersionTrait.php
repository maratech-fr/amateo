<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Plans\PlanStates;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * ADR-0002 : « le planning de la saison est arrêté » se dit désormais d'UNE façon —
 * le plan SEASON POINTE une version (`chosen_schedule_id`). Il n'y a plus de statut
 * `VALIDATED`, plus de `baselineScheduleId`, plus de `socleValidatedAt`.
 *
 * Les seeds qui posaient l'un de ces trois passent par ici. Attention : un seed
 * brut à l'EntityManager court-circuite le provisioning (qui, en vrai, a lieu à la
 * création de la saison puis à celle de la version) — d'où le ensureSeasonPlan +
 * linkSchedule avant le choose.
 */
trait ChoosesPlanVersionTrait
{
    /**
     * Le plan de la version pointe cette version = « validée ».
     *
     * ⚠️ lot D : `schedule_plan_id`/`version_number` sont NOT NULL et non-nullables en PHP.
     * La version NE DOIT PAS avoir été persistée/flushée avant l'appel (sinon l'INSERT part
     * sans plan). Le seed la crée en mémoire ; ici on la lie (plan AVANT persist) puis on pointe.
     */
    private function choosePlanVersion(Schedule $schedule): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $provisioner = $container->get(SchedulePlanProvisioner::class);

        // versionNumber 0 = pas encore liée (une version liée est ≥ 1) — on ne peut pas lire
        // getSchedulePlanId() sur une version fraîche (non-nullable non initialisé).
        if (0 === $schedule->getVersionNumber()) {
            $this->linkSeededSchedule($schedule);
        }

        Assert::assertTrue(
            $provisioner->choose($schedule),
            'seed: la version n\'a pu être pointée — son plan existe-t-il ?',
        );
        $em->flush();
        // Lot 5 : une version en vigueur porte la copie de son état (la porte lève sinon) — celle du décor à cet instant.
        $states = $container->get(PlanStates::class);
        $states->store($schedule, $states->capture($schedule->getSchedulePlanId()));
    }

    /**
     * « Le planning de la saison est arrêté » sans se soucier de QUELLE version —
     * ce que demandent les tests qui veulent seulement franchir le SocleGuard
     * (module matchs, état 3 du cockpit) et testent autre chose.
     */
    private function settleSeasonPlan(Season $season): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('Planning')
            ->setStatus(ScheduleStatus::COMPLETED);
        // Pas de persist/flush ici : choosePlanVersion lie (plan AVANT persist) puis pointe.
        $this->choosePlanVersion($schedule);

        return $schedule;
    }

    /**
     * Lie une version seedée à son plan comme la PRODUCTION au POST : résout le plan (SEASON
     * si `$calendarEntryId` null, sinon celui de la période), le POSE, persiste, puis
     * `linkSchedule` NUMÉROTE. lot D : la version ne doit PAS avoir été persistée/flushée avant
     * (schedule_plan_id NOT NULL) — on résout le plan AVANT de la persister car ensureSeasonPlanId
     * a sa propre transaction (elle flush le pending). L'entrée/la saison doivent être en base.
     */
    private function linkSeededSchedule(Schedule $schedule, ?string $calendarEntryId = null): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provisioner = static::getContainer()->get(SchedulePlanProvisioner::class);

        $planId = null === $calendarEntryId
            ? $provisioner->ensureSeasonPlanId($schedule->getSeasonId())
            : $provisioner->provisionPeriodPlan($calendarEntryId);
        Assert::assertIsString($planId, 'seed: la saison/période doit porter un plan');

        $schedule->setSchedulePlanId($planId);
        $em->persist($schedule);
        $provisioner->linkSchedule($schedule);
    }

    /**
     * Le plan SEASON vide qu'une vraie saison possède dès sa naissance — les trois
     * chemins qui le posent (register, transition N+1, reset). Un test qui persiste une Season à la main court-circuite ce geste
     * et obtiendrait une saison SANS plan, état qui n'existe pas en production.
     */
    private function provisionSeasonPlan(Season $season): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $em->flush();
    }

    /** La version pointée par le plan SEASON, ou null si le plan est encore un espace de travail. */
    private function chosenPlanVersion(Season $season): ?string
    {
        return static::getContainer()->get(SchedulePlanProvisioner::class)->chosenOfSeasonPlan($season->getId());
    }
}
