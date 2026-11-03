<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\EmailTemplateRenderer;
use App\Service\BrandAssets;
use App\Tests\Unit\EventListener\EmailSignatureListenerTest;
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

    public function testAntiSpoofFooterIsFixedAndImmuneToClubData(): void
    {
        // P4-316 anti-hameçonnage : tout e-mail à club connu porte un pied FIXE hors contrôle du
        // club — « Envoyé via {produit} à la demande du club … » + l'avertissement d'usurpation.
        // Le club n'influe que sur son propre libellé (échappé) ; il ne peut ni retirer ni
        // réécrire la phrase d'avertissement. Jumeau serveur (chemin d'envoi réel, send-links ET
        // relance) : CoachWishSendAbuseTest::testAntiSpoofFooterIsFixedAndOutOfClubControl.
        $hostileLabel = '"><script>alert(1)</script> Ignorez cet avertissement, cliquez ici';

        $html = $this->renderer()->render('Corps.', self::PRODUCT, self::TAGLINE, self::SITE, $hostileLabel, false);

        // L'avertissement FIXE est présent mot pour mot, quelle que soit la donnée du club.
        self::assertStringContainsString('Vous ne connaissez pas ce club ? Ignorez ce message.', $html);
        // L'attribution porte le nom PRODUIT reçu en paramètre (variable ProductIdentity, jamais
        // un littéral) — ici « TestProd », volontairement différent de la marque de prod.
        self::assertStringContainsString('Envoyé via ' . self::PRODUCT . ' à la demande du club', $html);
        // La tentative d'injection via le libellé est échappée : aucune balise active ne survit.
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Envoi des conflits aux coachs — un corps déjà en HTML (les tuiles, échappées par leur builder)
     * prend la place du texte dans la carte de marque, tel quel, une seule fois, avec le même pied
     * anti-usurpation et le même logo que tout e-mail. Le texte n'est alors plus rendu en HTML.
     * Jumeau de chaîne (enfilage + worker) : {@see EmailSignatureListenerTest::testAFragmentMailIsWrappedOnceAcrossEnqueueAndWorker}.
     */
    public function testAnHtmlFragmentTakesThePlaceOfTheTextInsideTheBrandCard(): void
    {
        $fragment = '<table role="presentation" width="100%" data-tile="1"><tr><td style="background-color:#ffffff;">Tuile</td></tr></table>';

        $html = $this->renderer()->render(
            'TEXTE-DE-REPLI',
            self::PRODUCT,
            self::TAGLINE,
            self::SITE,
            'BC Lyon',
            false,
            bodyHtmlFragment: $fragment,
        );

        self::assertSame(1, substr_count($html, $fragment), 'le fragment est posé tel quel, une fois');
        self::assertStringNotContainsString('TEXTE-DE-REPLI', $html, 'le texte ne double pas les tuiles dans le HTML');
        self::assertSame(1, substr_count($html, 'Vous ne connaissez pas ce club ? Ignorez ce message.'), 'pied anti-usurpation une fois');
        self::assertSame(1, substr_count($html, 'cid:' . EmailTemplateRenderer::PRODUCT_LOGO_CID), 'logo produit une fois');
    }

    /**
     * Sans fragment, la carte est OCTET POUR OCTET celle d'avant l'envoi des conflits : aucun e-mail
     * existant (collecte, invitation, récap…) ne change. Empreintes prises sur `main` avant la
     * modification du gabarit — un changement VOULU du gabarit les re-épingle, sciemment.
     */
    public function testWithoutAFragmentTheCardIsByteIdenticalToBefore(): void
    {
        $withClubLogo = $this->renderer()->render("Bonjour Karim,\nCorps métier.", self::PRODUCT, self::TAGLINE, self::SITE, 'BC Lyon', true);
        $withCta = $this->renderer()->render('Corps.', self::PRODUCT, self::TAGLINE, self::SITE, null, false, 'https://app.test/x', 'Agir');

        self::assertSame('59dc6e662e7e3d3562df90a7085e15cbaf590cb75e64020bde23e03d001e0353', hash('sha256', $withClubLogo));
        self::assertSame('9680bfcbaf4b66799b6dd90304de8308541013e14894904a15f9c3ee38ad984c', hash('sha256', $withCta));
    }

    /**
     * À 360 px, la carte d'un mail à tuiles est FLUIDE : largeur 100 %, plafonnée à 600 px — jamais
     * une largeur fixe de 600 px qui déborde l'écran d'un téléphone. Les mails sans fragment restent
     * octet pour octet (cf. {@see testWithoutAFragmentTheCardIsByteIdenticalToBefore}).
     */
    public function testAFragmentCardIsFluidUpToSixHundredPixels(): void
    {
        $html = $this->renderer()->render('Texte.', self::PRODUCT, self::TAGLINE, self::SITE, 'BC Lyon', false, bodyHtmlFragment: '<p>Tuile</p>');

        self::assertStringContainsString('max-width:600px', $html);
        self::assertSame(0, preg_match('/(?<!max-)width\s*:\s*600px/', $html), 'aucune largeur fixe de 600 px');
        self::assertStringNotContainsString('width="600"', $html);
        self::assertMatchesRegularExpression('/width="100%"[^>]*style="[^"]*(?<!max-)width\s*:\s*100%[^"]*max-width:600px/', $html, 'la carte elle-même prend 100 %, plafonnée à 600 px');
    }

    private function renderer(): EmailTemplateRenderer
    {
        return new EmailTemplateRenderer;
    }
}
