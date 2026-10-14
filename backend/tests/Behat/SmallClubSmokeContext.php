<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Smoke de la MAISON DU DÉCOR ({@see SmallClubFixtureTrait}) : prouve qu'un petit club
 * réaliste bâti PAR L'API (11 équipes / 3 gymnases, palier 60 s) génère et valide son
 * planning de saison. C'est la fondation réutilisée par les enchaînements P3/P1/P7 ; ce
 * smoke garantit qu'elle tient debout seule, et mesure la durée du solve.
 */
final class SmallClubSmokeContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private string $scheduleId = '';

    #[Given('un petit club réaliste bâti par l\'API : 11 équipes, 3 gymnases, un coach sur deux équipes')]
    public function unPetitClubRealiste(): void
    {
        $this->buildSmallClub();
    }

    #[When('je génère et valide le planning de saison du petit club')]
    public function jeGenereEtValide(): void
    {
        $this->scheduleId = $this->generateAndValidateSmallClubSocle();
    }

    #[Then('la génération aboutit et le socle du petit club est en vigueur')]
    public function leSocleEstEnVigueur(): void
    {
        if (!$this->smallClubSocleIsChosen($this->scheduleId)) {
            throw new RuntimeException('le socle de saison généré n\'est pas devenu la version en vigueur');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }
}
