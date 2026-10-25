<?php

declare(strict_types=1);

namespace App\Tests;

use App\Mail\EmailTemplateRenderer;
use Symfony\Component\Mime\Email;

/**
 * Les vérités du bouton d'action (D1) partagées par les tests « tout mail à lien d'action porte
 * son bouton ». Deux niveaux de capture, deux assertions :
 *
 *  - {@see assertCtaHeadersPointToBodyLink} — au niveau BUILDER (l'e-mail sort du producteur, le
 *    listener n'est PAS encore passé : ni HTML, ni signature). L'en-tête CTA porte EXACTEMENT
 *    l'unique URL http(s) du corps TEXTE + le libellé attendu, et le corps ne porte ce lien
 *    qu'UNE fois (A2, anti-duplication). Le rendu du bouton HTML et le strip des en-têtes au
 *    worker sont prouvés ailleurs, au plus bas, par les jumeaux unitaires
 *    {@see App\Tests\Unit\Mail\EmailTemplateRendererTest} /
 *    {@see App\Tests\Unit\EventListener\EmailSignatureListenerTest}.
 *  - {@see assertCtaButtonRendered} — sur le clone SIGNÉ capté à l'enfilage d'un WebTestCase (le
 *    HTML est rendu, les en-têtes internes pas encore retirés) : l'en-tête CTA porte le lien + le
 *    libellé, et la partie HTML montre ce lien UNE seule fois, dans un `<a href>` (A1/A2). Jumeau
 *    de {@see App\Tests\Integration\Api\IdentityEmailCtaButtonTest::assertCtaButton}.
 */
trait AssertsActionMailCta
{
    /**
     * La liste ORDONNÉE des URLs http(s) du texte (jamais dédupliquée : un lien présent deux fois
     * donne deux entrées, ce qui fait rougir le `assertSame` d'exactement-une-fois).
     *
     * @return list<string>
     */
    private static function httpLinksIn(string $text): array
    {
        preg_match_all('~https?://\S+~', $text, $matches);

        return $matches[0];
    }

    /**
     * Niveau builder : l'en-tête CTA == l'unique lien http(s) du corps TEXTE, + le libellé ; le
     * lien n'apparaît qu'une fois dans le corps (A2). ROUGE tant que le producteur ne pose pas
     * l'en-tête (ou, pour les mails à texte modifié, tant que le corps ne porte pas le lien).
     */
    private function assertCtaHeadersPointToBodyLink(Email $email, string $expectedUrl, string $expectedLabel): void
    {
        self::assertSame(
            [$expectedUrl],
            self::httpLinksIn((string) $email->getTextBody()),
            'le corps TEXTE doit porter EXACTEMENT ce lien http(s), une seule fois (A2) — ROUGE si le lien manque encore',
        );

        $headers = $email->getHeaders();
        self::assertTrue(
            $headers->has(EmailTemplateRenderer::CTA_URL_HEADER),
            'ROUGE tant que le producteur ne pose pas l\'en-tête CTA — l\'e-mail n\'a pas de bouton d\'action',
        );
        self::assertSame($expectedUrl, $headers->getHeaderBody(EmailTemplateRenderer::CTA_URL_HEADER), 'l\'URL du CTA == le lien du corps');
        self::assertSame($expectedLabel, $headers->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));
    }

    /** Un e-mail SANS lien d'action (accusé, refus, avis) ne porte AUCUN en-tête CTA. */
    private function assertCarriesNoCtaHeaders(Email $email): void
    {
        $headers = $email->getHeaders();
        self::assertFalse($headers->has(EmailTemplateRenderer::CTA_URL_HEADER), 'aucun bouton d\'action attendu : pas d\'en-tête CTA');
        self::assertFalse($headers->has(EmailTemplateRenderer::CTA_LABEL_HEADER));
    }

    /**
     * Niveau pipeline (clone signé à l'enfilage) : l'en-tête CTA porte le lien + le libellé, et la
     * partie HTML rend un bouton `<a href>` vers ce lien, qui n'y figure qu'UNE fois (A1/A2).
     */
    private function assertCtaButtonRendered(Email $email, string $link, string $expectedLabel): void
    {
        $headers = $email->getHeaders();
        self::assertTrue(
            $headers->has(EmailTemplateRenderer::CTA_URL_HEADER),
            'ROUGE tant que le producteur ne pose pas l\'en-tête CTA — pas de bouton d\'action',
        );
        self::assertSame($link, $headers->getHeaderBody(EmailTemplateRenderer::CTA_URL_HEADER), 'l\'URL du CTA == le lien du corps');
        self::assertSame($expectedLabel, $headers->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('href="' . $link . '"', $html, 'la partie HTML doit rendre un bouton <a href> vers le lien');
        self::assertSame(1, substr_count($html, $link), 'le lien n\'apparaît qu\'une fois dans le HTML (jamais auto-lié/dupliqué, A1/A2)');
    }
}
