<?php

declare(strict_types=1);

namespace App\Tests;

use App\Plans\PlanComposition;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * Les règles appliquées par un plan de période (lot 4, PR 4) vues d'un décor de test : une ligne `plan_constraint` par
 * (plan de période, contrainte permanente) avec « s'applique », aucune au plan de saison ni pour une contrainte datée,
 * posée par la seule porte {@see PlanComposition::ensureLines}. Un décor qui crée une contrainte à la main rejoue ce
 * que ferait un créateur de l'application : il appelle la porte.
 *
 * Les lectures et les écritures de décor passent par SQL sur les colonnes du contrat (`applies`) : elles ne dépendent
 * pas de l'entité.
 */
trait ComposesPlanConstraintsTrait
{
    /** Pose les lignes qui manquent dans chaque plan de la saison, comme le fait chaque créateur de contrainte. */
    private function layRuleLines(string $seasonId): void
    {
        static::getContainer()->get(PlanComposition::class)->ensureLines($seasonId);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** « S'applique » relu en base pour une règle dans un plan, ou `null` si la ligne manque. */
    private function ruleLineRow(string $planId, string $constraintId): ?bool
    {
        $applies = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT applies FROM plan_constraint WHERE schedule_plan_id = ? AND constraint_id = ?',
            [$planId, $constraintId],
        );

        return false === $applies ? null : (bool) $applies;
    }

    /** Le décor coche ou décoche une règle dans un plan, comme le gestionnaire ; la ligne doit exister. */
    private function setRuleLine(string $planId, string $constraintId, bool $applies): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $updated = $em->getConnection()->executeStatement(
            'UPDATE plan_constraint SET applies = ? WHERE schedule_plan_id = ? AND constraint_id = ?',
            [$applies ? 'true' : 'false', $planId, $constraintId],
        );
        Assert::assertSame(1, $updated, 'la règle a sa ligne dans le plan');
        $em->clear();
    }

    /** Le nombre de lignes d'une règle, tous plans confondus. */
    private function ruleLineCountOf(string $constraintId): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM plan_constraint WHERE constraint_id = ?',
            [$constraintId],
        );
    }
}
