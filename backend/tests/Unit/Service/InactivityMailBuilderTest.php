<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Mail\EmailTemplateRenderer;
use App\Service\InactivityMailBuilder;
use App\Tests\AssertsActionMailCta;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PROMESSE (D1) : le préavis d'inactivité RGPD porte un bouton « Me connecter » vers le lien
 * `/login` du corps — un simple login conserve le compte, le bouton y mène. ROUGE tant que le
 * builder ne pose pas l'en-tête CTA ({@see InactivityMailBuilder::build}).
 *
 * Le rendu du bouton HTML / le strip au worker sont prouvés au plus bas par les jumeaux
 * {@see App\Tests\Unit\Mail\EmailTemplateRendererTest} / {@see App\Tests\Unit\EventListener\EmailSignatureListenerTest}.
 */
#[Group('phase1')]
final class InactivityMailBuilderTest extends TestCase
{
    use AssertsActionMailCta;

    public function testInactivityNoticeCarriesALoginCta(): void
    {
        $email = new InactivityMailBuilder('https://app.example.test')->build('x@y.fr', 'Camille', []);

        $this->assertCtaHeadersPointToBodyLink($email, 'https://app.example.test/login', 'Me connecter');
    }

    /**
     * PROMESSE (chantier Membre, PR 1) : le dernier gestionnaire d'un club, prévenu de son
     * inactivité, apprend que l'espace du club partira avec son compte — une phrase PAR club.
     * Un compte qui n'est le dernier gestionnaire de rien ne lit aucune annonce de suppression.
     */
    public function testLastManagerWarningNamesEachClubThatWouldClose(): void
    {
        $email = new InactivityMailBuilder('https://app.example.test')->build('x@y.fr', 'Camille', ['BC Testville', 'AS Autreville']);
        $body = (string) $email->getTextBody();

        self::assertStringContainsString('Vous êtes le dernier gestionnaire de BC Testville : sans reconnexion, cet espace sera ensuite supprimé.', $body);
        self::assertStringContainsString('Vous êtes le dernier gestionnaire de AS Autreville : sans reconnexion, cet espace sera ensuite supprimé.', $body);
        $this->assertCtaHeadersPointToBodyLink($email, 'https://app.example.test/login', 'Me connecter');

        $plain = new InactivityMailBuilder('https://app.example.test')->build('x@y.fr', 'Camille', []);
        self::assertStringNotContainsString('dernier gestionnaire', (string) $plain->getTextBody());
    }

    /**
     * PROMESSE (chantier Membre PR 3, prévention D3) : le dernier gestionnaire prévenu de son
     * inactivité se voit proposer d'éviter la fermeture en nommant un autre gestionnaire — UNE
     * fois, après la phrase du club ; un compte qui n'est le dernier gestionnaire de rien ne la lit pas.
     */
    public function testInactivityWarningProposesToNameASuccessor(): void
    {
        $sentence = 'Pour éviter cette fermeture, connectez-vous et nommez un autre gestionnaire dans l\'écran Club, section « Membres ».';
        $body = (string) new InactivityMailBuilder('https://app.example.test')->build('x@y.fr', 'Camille', ['BC Testville'])->getTextBody();

        self::assertSame(1, substr_count($body, $sentence));
        self::assertGreaterThan(strpos($body, 'Vous êtes le dernier gestionnaire de BC Testville'), strpos($body, $sentence), 'après la phrase qui nomme le club');

        $plain = (string) new InactivityMailBuilder('https://app.example.test')->build('x@y.fr', 'Camille', [])->getTextBody();
        self::assertStringNotContainsString('nommez un autre gestionnaire', $plain);
    }

    public function testNoCtaWhenNoFrontendBaseIsConfigured(): void
    {
        // Sans base front, aucun lien cliquable n'est posé → pas de bouton (patron des crons).
        $email = new InactivityMailBuilder('')->build('x@y.fr', 'Camille', []);

        $this->assertCarriesNoCtaHeaders($email);
        self::assertFalse($email->getHeaders()->has(EmailTemplateRenderer::CTA_URL_HEADER));
    }
}
