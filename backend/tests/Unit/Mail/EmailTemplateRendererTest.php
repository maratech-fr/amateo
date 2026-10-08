<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\EmailTemplateRenderer;
use App\Service\BrandAssets;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D1 — le gabarit d'e-mail commun : échappement de toute interpolation, logo club en CID (jamais
 * une URL publique), en-tête club optionnel, et fond DÉRIVÉ (calculé, pas recopié) des trois
 * teintes du mark.
 */
#[Group('phase1')]
final class EmailTemplateRendererTest extends TestCase
{
    private const string PRODUCT = 'TestProd';

    private const string TAGLINE = 'ACCROCHE DE TEST';

    private const string SITE = 'https://vitrine.test';

    public function testHtmlInjectionViaClubAndPersonFieldsIsEscaped(): void
    {
        $html = $this->renderer()->render(
            "Bonjour <img src=x onerror=alert(1)>,\nvoici votre lien.",
            self::PRODUCT,
            self::TAGLINE,
            self::SITE,
            '"><script>alert(1)</script>',
            false,
        );

        // Aucune balise active ne survit : le corps ET le libellé club sont échappés.
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x onerror', $html);
        self::assertStringNotContainsString('onerror=alert(1)>', $html);
        // L'échappement a bien transformé les chevrons en entités.
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror', $html);
    }

    public function testClubLogoEmbeddedAsCidNeverPublicUrl(): void
    {
        $html = $this->renderer()->render('Corps.', self::PRODUCT, self::TAGLINE, self::SITE, 'BC Lyon', true);

        // Le logo du club voyage en CID, jamais via une URL.
        self::assertStringContainsString('src="cid:' . EmailTemplateRenderer::CLUB_LOGO_CID . '"', $html);
        // Aucune URL http(s) ne doit servir de source d'IMAGE (le seul lien http est le lien
        // texte de la vitrine en pied — jamais un `<img src="http…`).
        self::assertStringNotContainsString('<img src="http', $html);
        self::assertStringNotContainsString('src="https://', $html);
    }

    public function testFallsBackToStyledLabelWithoutLogo(): void
    {
        $html = $this->renderer()->render('Corps.', self::PRODUCT, self::TAGLINE, self::SITE, 'BC Lyon', false);

        // Sans logo : pas de CID club, mais le libellé apparaît en en-tête.
        self::assertStringNotContainsString('cid:' . EmailTemplateRenderer::CLUB_LOGO_CID, $html);
        self::assertStringContainsString('BC Lyon', $html);
    }

    public function testNoClubHeaderWhenEmailHasNoClub(): void
    {
        $html = $this->renderer()->render('Corps.', self::PRODUCT, self::TAGLINE, self::SITE, null, false);

        // Un e-mail sans club n'a aucun en-tête club (ni logo, ni libellé).
        self::assertStringNotContainsString('cid:' . EmailTemplateRenderer::CLUB_LOGO_CID, $html);
        // Le pied de signature produit reste présent.
        self::assertStringContainsString('cid:' . EmailTemplateRenderer::PRODUCT_LOGO_CID, $html);
        self::assertStringContainsString(self::PRODUCT, $html);
    }

    public function testCardBackgroundIsDerivedFromTheMarkTintsNotRecopied(): void
    {
        $html = $this->renderer()->render('Corps.', self::PRODUCT, self::TAGLINE, self::SITE, null, false);

        // Teintes CALCULÉES (mélange vers le blanc à 85 %) : chaque teinte du mark éclaircie, et le
        // fond uni = leur moyenne. Valeurs dérivées à la main depuis BrandAssets::MARK_TINTS.
        self::assertStringContainsString('#F4DDED', $html); // #B51C8A éclaircie
        self::assertStringContainsString('#F9EBD9', $html); // #D47800 éclaircie
        self::assertStringContainsString('#E3F3F3', $html); // #46AFAC éclaircie
        self::assertStringContainsString('#F0E9E8', $html); // moyenne des trois (fond uni)

        // Jamais la teinte PURE du mark comme fond (le fond est dérivé, pas recopié).
        foreach (BrandAssets::MARK_TINTS as $pureTint) {
            self::assertStringNotContainsString($pureTint, $html, 'une teinte pure du mark ne doit pas servir de fond');
        }
    }

    public function testCtaButtonEscapesLabelAndUrl(): void
    {
        $html = $this->renderer()->render(
            'Corps.',
            self::PRODUCT,
            self::TAGLINE,
            self::SITE,
            null,
            false,
            'https://app.amateo.test/doleances/abc?x="><script>',
            'Donner "mes" <dispos>',
        );

        // Le bouton est rendu, avec un href http(s).
        self::assertStringContainsString('<a href="https://app.amateo.test/doleances/abc', $html);
        // Label ET URL échappés : aucune balise/guillemet actif ne survit.
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;dispos&gt;', $html);
        self::assertStringContainsString('&quot;mes&quot;', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testCtaButtonRefusesNonHttpUrl(): void
    {
        $html = $this->renderer()->render(
            'Corps.',
            self::PRODUCT,
            self::TAGLINE,
            self::SITE,
            null,
            false,
            'javascript:alert(1)',
            'Cliquez',
        );

        // Une URL non http(s) ne produit AUCUN lien cliquable.
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<a href="javascript', $html);
        // Pas de bouton du tout : le libellé n'est pas rendu.
        self::assertStringNotContainsString('Cliquez', $html);
    }

    public function testCtaUrlRawLineRemovedFromBodyWhenButtonRendered(): void
    {
        $url = 'https://app.amateo.test/doleances/tok123';
        $body = "Bonjour,\n\nPrépare le planning.\n\n" . $url . "\n\nC'est un souhait.";

        $html = $this->renderer()->render($body, self::PRODUCT, self::TAGLINE, self::SITE, null, false, $url, 'Donner mes disponibilités');

        // L'URL n'apparaît qu'UNE fois — dans le href du bouton, jamais en doublon dans le corps.
        self::assertSame(1, substr_count($html, $url), 'l\'URL ne doit plus être dupliquée au-dessus du bouton');
        self::assertStringContainsString('href="' . $url . '"', $html);
        // Le reste du corps est conservé.
        self::assertStringContainsString('Prépare le planning.', $html);
        self::assertStringContainsString('est un souhait.', $html); // la dernière ligne survit (apostrophe échappée)
    }

    public function testCtaUrlStaysInBodyWhenNoButton(): void
    {
        $url = 'https://app.amateo.test/doleances/tok123';
        $body = "Bonjour,\n\n" . $url . "\n\nFin.";

        // Sans CTA, le lien nu reste dans le corps (aucun bouton ne le porte).
        $html = $this->renderer()->render($body, self::PRODUCT, self::TAGLINE, self::SITE, null, false);

        self::assertStringContainsString($url, $html);
        self::assertStringNotContainsString('href="' . $url . '"', $html, 'pas de bouton = pas de href CTA');
    }

    public function testNoCtaButtonWhenAbsent(): void
    {
        $html = $this->renderer()->render('Corps.', self::PRODUCT, self::TAGLINE, self::SITE, null, false);

        // Sans en-tête CTA, aucun bouton (le lien nu reste dans la partie TEXTE, hors renderer).
        self::assertStringNotContainsString('Donner mes disponibilités', $html);
    }

    public function testImageSourcesCanBeDataUrisForPreview(): void
    {
        // L'aperçu rend les logos en data: URI (les cid: ne résolvent pas en iframe).
        $html = $this->renderer()->render(
            'Corps.',
            self::PRODUCT,
            self::TAGLINE,
            self::SITE,
            'BC Lyon',
            true,
            null,
            null,
            'data:image/png;base64,UFJPRA==',
            'data:image/png;base64,Q0xVQg==',
        );

        self::assertStringContainsString('src="data:image/png;base64,UFJPRA=="', $html);
        self::assertStringContainsString('src="data:image/png;base64,Q0xVQg=="', $html);
        // Aucun cid: résiduel quand les sources sont fournies.
        self::assertStringNotContainsString('cid:', $html);
    }

    private function renderer(): EmailTemplateRenderer
    {
        return new EmailTemplateRenderer;
    }
}
