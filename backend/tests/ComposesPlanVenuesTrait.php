<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\PlanVenue;
use App\Plans\PlanComposition;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * Les gymnases d'un plan de période (lot 4, PR 3b) vus d'un décor de test : une ligne `plan_venue` par (plan de
 * période, gymnase), aucune pour le plan de saison, posée par la seule porte {@see PlanComposition::ensureLines}. Un
 * décor qui crée un gymnase à la main rejoue ce que ferait un créateur de l'application : il appelle la porte.
 *
 * Les lectures de vérification passent par SQL sur les colonnes du contrat (`participates`, `closure_lifted`,
 * `day_overrides`) : elles ne dépendent pas de l'entité.
 */
trait ComposesPlanVenuesTrait
{
    /** Pose les lignes qui manquent dans chaque plan de la saison, comme le fait chaque créateur de gymnase. */
    private function layVenueLines(string $seasonId): void
    {
        static::getContainer()->get(PlanComposition::class)->ensureLines($seasonId);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** La ligne d'un gymnase dans un plan de période ; elle doit exister. */
    private function venueLine(string $planId, string $venueId): PlanVenue
    {
        $lines = static::getContainer()->get(PlanComposition::class)->venuesOf($planId);
        Assert::assertArrayHasKey($venueId, $lines, 'le gymnase a sa ligne dans le plan');

        return $lines[$venueId];
    }

    /**
     * La ligne relue en base, ou `null` si elle manque. Le masque est rendu trié par jour.
     *
     * @return array{participates: bool, closureLifted: bool, dayOverrides: array<int, string>|null}|null
     */
    private function venueLineRow(string $planId, string $venueId): ?array
    {
        $row = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT participates, closure_lifted, day_overrides FROM plan_venue WHERE schedule_plan_id = ? AND venue_id = ?',
            [$planId, $venueId],
        );
        if (false === $row) {
            return null;
        }
        $mask = null === $row['day_overrides'] ? null : json_decode((string) $row['day_overrides'], true, 512, \JSON_THROW_ON_ERROR);
        if (\is_array($mask)) {
            ksort($mask);
            $mask = array_map(strval(...), $mask);
        }

        return [
            'participates' => (bool) $row['participates'],
            'closureLifted' => (bool) $row['closure_lifted'],
            'dayOverrides' => \is_array($mask) && [] !== $mask ? $mask : null,
        ];
    }

    /** Le nombre de lignes d'un gymnase, tous plans confondus. */
    private function venueLineCountOf(string $venueId): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM plan_venue WHERE venue_id = ?',
            [$venueId],
        );
    }
}
