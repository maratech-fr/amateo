<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\CoachShortName;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Chantier E — le nom COURT d'un coach à l'export PDF : « prénom + initiale du nom »
 * (« Karim B. »). C'est la règle de présentation que la grille affiche quand le
 * gestionnaire coche « Afficher le nom des coachs » — jamais le nom complet (A4), jamais
 * un identifiant interne. Un nom VIDE rend `null` : la ligne de coach est alors OMISE
 * (promesse fondateur « pas de coach → rien »), et c'est à la source que ce null naît.
 *
 * ⚠ Signature PROPOSÉE (option A, TANK écrit AVANT NEO) : `CoachShortName::from(?prénom,
 * ?nom): ?string`, alignée sur la construction de `coachNames` dans
 * `ScheduleExportDataProvider:87` (`trim(sprintf('%s %s', firstName, lastName))`). NEO peut
 * adapter MÉCANIQUEMENT la signature ; la RÈGLE (prénom + initiale, null si vide) ne bouge pas.
 */
#[Group('phase1')]
final class CoachShortNameTest extends TestCase
{
    public function testFirstNamePlusTheLastNameInitial(): void
    {
        self::assertSame('Karim B.', CoachShortName::from('Karim', 'Benzema'));
    }

    public function testAnEmptyNameYieldsNullSoTheCoachLineIsOmitted(): void
    {
        self::assertNull(CoachShortName::from(null, null), 'coach inconnu → null → ligne omise');
        self::assertNull(CoachShortName::from('', ''), 'prénom et nom vides → null');
        self::assertNull(CoachShortName::from('   ', '   '), 'des espaces seuls ne font pas un nom');
    }

    public function testTheInitialIsMultibyteSafeForAnAccentedSurname(): void
    {
        // Une initiale calculée en octets casserait un nom accentué (« Étienne » → un demi-octet).
        self::assertSame('Léa É.', CoachShortName::from('Léa', 'Étienne'));
    }

    public function testAFirstNameAloneCarriesNoDanglingInitialOrDot(): void
    {
        // Un coach sans nom de famille s'affiche « Karim », jamais « Karim . » : on n'invente pas
        // une initiale vide.
        self::assertSame('Karim', CoachShortName::from('Karim', ''));
        self::assertSame('Karim', CoachShortName::from('Karim', null));
    }
}
