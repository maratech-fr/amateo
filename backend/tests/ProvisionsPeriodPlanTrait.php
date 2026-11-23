<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\CalendarEntry;
use App\Entity\Season;
use App\Plans\PlanComposition;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * ADR-0002 lot C — l'ancre des réglages de période est son PLAN (inv. 5).
 *
 * En production, le plan naît DU GESTE : créer une période via `POST /api/calendar_entries`
 * crée son plan dans la même transaction (lot C1, `CalendarEntryStateProcessor`). Un test
 * qui fabrique sa `CalendarEntry` à la main court-circuite ce chemin : il doit rejouer le
 * geste, sinon la période n'a pas de plan et rien ne peut s'y accrocher.
 *
 * À n'utiliser QUE pour une entrée construite à la main. Un test qui passe par l'API a déjà
 * son plan — il le résout avec `SchedulePlanProvisioner::periodPlanId()`.
 */
trait ProvisionsPeriodPlanTrait
{
    /**
     * Rejoue le geste sur une entrée fabriquée à la main, et rend l'ancre de ses réglages.
     * Flush d'abord : le provisioner relit la ligne en SQL brut (il contourne le
     * season_filter, cf. son docblock), donc elle doit être en base.
     */
    private function planIdOf(CalendarEntry $entry): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        // Une saison naît avec son plan de saison : le plan de période y lit les équipes du socle (lot 4, PR 3a).
        SeasonPlanOf::id(static::getContainer(), $entry->getSeasonId());

        $planId = static::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());
        Assert::assertIsString($planId, 'la période doit porter un plan (type closure/holiday attendu — inv. 9)');

        return $planId;
    }

    /**
     * C4 : l'ancre d'une version de BASE (le socle) est le plan SEASON de sa saison. Un test
     * qui fabrique une version de base à la main doit la lier à ce plan (la prod le fait au POST).
     */
    private function seasonPlanIdOf(Season $season): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $planId = static::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($season->getId());
        $em->flush();
        Assert::assertIsString($planId, 'la saison doit porter un plan SEASON');
        // Lot 4, PR 3a : les équipes écrites avant le plan de saison y reçoivent leur ligne (la porte ne touche aucune
        // ligne existante).
        static::getContainer()->get(PlanComposition::class)->ensureLines($season->getId());

        return $planId;
    }
}
