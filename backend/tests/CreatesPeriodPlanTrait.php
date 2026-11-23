<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Un plan de période RÉEL, pour les tests qui ancrent une ligne dessus.
 *
 * Plusieurs tests posaient un `schedulePlanId` inventé (`3333…`). Ça passait
 * tant que la colonne était une `guid` nue : la ligne devenait un orphelin
 * silencieux — exactement la dette que P4-30 solde. Depuis la FK, la même
 * requête est refusée par la base, et le test doit donc faire ce que fait la
 * production : ancrer sur un plan qui existe.
 *
 * Type HOLIDAY et non SEASON : l'index partiel `uniq_schedule_plan_season_base`
 * n'autorise qu'UN plan SEASON par saison, que la saison possède déjà.
 */
trait CreatesPeriodPlanTrait
{
    private function createPeriodPlan(string $clubId, string $seasonId, ?string $calendarEntryId = null): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $plan = new SchedulePlan;
        $plan->setClubId($clubId);
        $plan->setSeasonId($seasonId);
        $plan->setType(SchedulePlanType::HOLIDAY);
        $plan->setName('Plan de test');
        $plan->setStartDate(new DateTimeImmutable('2026-10-19'));
        $plan->setEndDate(new DateTimeImmutable('2026-11-02'));
        $plan->setCalendarEntryId($calendarEntryId);

        $em->persist($plan);
        $em->flush();
        // Lot 4, PR 3a : un plan naît avec une ligne par équipe de sa saison (la porte, comme `provisionPeriodPlan`).
        SeasonPlanOf::id(static::getContainer(), $plan->getSeasonId());
        $em->flush();

        return $plan->getId();
    }
}
