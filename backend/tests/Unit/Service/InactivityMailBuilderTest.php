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
        $email = new InactivityMailBuilder('https://app.example.test')->build('x@y.fr', 'Camille');

        $this->assertCtaHeadersPointToBodyLink($email, 'https://app.example.test/login', 'Me connecter');
    }

    public function testNoCtaWhenNoFrontendBaseIsConfigured(): void
    {
        // Sans base front, aucun lien cliquable n'est posé → pas de bouton (patron des crons).
        $email = new InactivityMailBuilder('')->build('x@y.fr', 'Camille');

        $this->assertCarriesNoCtaHeaders($email);
        self::assertFalse($email->getHeaders()->has(EmailTemplateRenderer::CTA_URL_HEADER));
    }
}
