<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PlacementRunEmailBuilder;
use App\Tests\AssertsActionMailCta;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PROMESSE (D1) : les DEUX e-mails de fin de run de placement (abouti et NON abouti) portent un
 * bouton « Ouvrir le calendrier des matchs » vers le lien `/matchs` du corps — le gestionnaire a
 * quitté l'écran, le bouton l'y ramène. ROUGE tant que {@see PlacementRunEmailBuilder::build} /
 * {@see PlacementRunEmailBuilder::buildFailure} ne posent pas l'en-tête CTA.
 */
#[Group('phase1')]
final class PlacementRunEmailBuilderTest extends TestCase
{
    use AssertsActionMailCta;

    public function testSuccessEmailCarriesTheMatchesCta(): void
    {
        $email = new PlacementRunEmailBuilder('https://app.example.test')->build('gest@club.fr', placed: 12, toTreat: 3);

        $this->assertCtaHeadersPointToBodyLink($email, 'https://app.example.test/matchs', 'Ouvrir le calendrier des matchs');
    }

    public function testFailureEmailCarriesTheMatchesCta(): void
    {
        $email = new PlacementRunEmailBuilder('https://app.example.test')->buildFailure('gest@club.fr');

        $this->assertCtaHeadersPointToBodyLink($email, 'https://app.example.test/matchs', 'Ouvrir le calendrier des matchs');
    }

    public function testNoCtaWhenNoFrontendBaseIsConfigured(): void
    {
        $this->assertCarriesNoCtaHeaders(new PlacementRunEmailBuilder('')->build('gest@club.fr', placed: 1, toTreat: 0));
    }
}
