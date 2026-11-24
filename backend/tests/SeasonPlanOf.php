<?php

declare(strict_types=1);

namespace App\Tests;

use App\Plans\PlanComposition;
use App\Service\SchedulePlanProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;

/**
 * Le plan de saison d'une saison de DÉCOR de test, créé s'il manque. Un décor écrit une saison à la main, sans
 * passer par la naissance d'un club ni par « Préparer la saison suivante » : c'est le test qui lui donne son
 * plan, comme ces chemins le feraient. Le code de l'application, lui, lit par `SoclePlan::idOf`, qui lève sur une
 * saison sans plan (cas impossible, `Integration/Plans/SoclePlanTest`).
 *
 * Même service que {@see ProvisionsPeriodPlanTrait::seasonPlanIdOf()}, pour une classe qui n'a que l'identifiant.
 */
final class SeasonPlanOf
{
    public static function id(ContainerInterface $container, string $seasonId): string
    {
        $provisioner = $container->get(SchedulePlanProvisioner::class);
        Assert::assertInstanceOf(SchedulePlanProvisioner::class, $provisioner);
        $planId = $provisioner->ensureSeasonPlanId($seasonId);
        Assert::assertIsString($planId, 'la saison du décor doit exister pour recevoir son plan de saison');
        $em = $container->get(EntityManagerInterface::class);
        Assert::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();
        // Lot 4, PR 3a : les équipes que le décor a écrites avant le plan de saison y reçoivent leur ligne, comme si
        // la saison était née avec son plan (la porte ne touche aucune ligne existante).
        $composition = $container->get(PlanComposition::class);
        Assert::assertInstanceOf(PlanComposition::class, $composition);
        $composition->ensureLines($seasonId);

        return $planId;
    }
}
