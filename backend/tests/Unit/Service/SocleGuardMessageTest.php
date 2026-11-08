<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SocleGuard;
use PHPUnit\Framework\TestCase;

/**
 * Nom unique (D1/D7, PR 2 « Moyennes ») : le refus 409 « socle non validé », lu tel quel dans un toast
 * (`ConflictsPage`, création de match, Adapter), nomme les deux plannings par leur seul nom —
 * « planning de saison » et « planning de période » — jamais « principal » ni « secondaire ».
 *
 * La phrase vit dans UNE constante publique : `SocleGuard` et `SchedulePlanProvisioner` sont `final`
 * (pas de doublure), et les lecteurs du message (`SoclePlansContext` côté Behat,
 * `ConflictCoachMailApiTest`) la comparent à cette constante au lieu d'en recopier un fragment. Que
 * la garde lève bien CETTE constante est prouvé par ces deux lecteurs, sur la vraie pile.
 */
final class SocleGuardMessageTest extends TestCase
{
    public function testTheRefusalNamesSeasonAndPeriodPlanningsByTheirOnlyName(): void
    {
        $message = SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE;

        self::assertStringContainsString('planning de saison', $message);
        self::assertStringContainsString('planning de période', $message);
        self::assertDoesNotMatchRegularExpression('/principal|secondaire/iu', $message);
    }
}
