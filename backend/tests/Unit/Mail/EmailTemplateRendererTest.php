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

        // Teintes CALCULÉES (mélange vers le blanc à 94 %) : chaque teinte du mark éclaircie, et le
        // fond uni = leur moyenne. Valeurs dérivées à la main depuis BrandAssets::MARK_TINTS.
        self::assertStringContainsString('#FBF1F8', $html); // #B51C8A éclaircie
        self::assertStringContainsString('#FCF7F0', $html); // #D47800 éclaircie
        self::assertStringContainsString('#F4FAFA', $html); // #46AFAC éclaircie
        self::assertStringContainsString('#F9F6F6', $html); // moyenne des trois (fond uni)

        // Jamais la teinte PURE du mark comme fond (le fond est dérivé, pas recopié).
        foreach (BrandAssets::MARK_TINTS as $pureTint) {
            self::assertStringNotContainsString($pureTint, $html, 'une teinte pure du mark ne doit pas servir de fond');
        }
    }

    private function renderer(): EmailTemplateRenderer
    {
        return new EmailTemplateRenderer;
    }
}
