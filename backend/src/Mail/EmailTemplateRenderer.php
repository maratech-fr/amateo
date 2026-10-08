<?php

declare(strict_types=1);

namespace App\Mail;

use App\Service\BrandAssets;
use Symfony\Component\Mime\Email;

/**
 * Le SEUL foyer du gabarit HTML des e-mails sortants (D1) — extrait de
 * {@see App\EventListener\EmailSignatureListener}, qui s'y délègue désormais.
 *
 * Contraintes de rendu e-mail (survivre aux clients les plus rétifs — Outlook, Gmail) :
 *  - carte blanche ~600 px CENTRÉE, mise en page en `<table>` (pas de flex/grid), styles INLINE
 *    (aucun `<style>`/CSS externe) ;
 *  - toute image porte un `alt` ; le logo (produit comme club) voyage en pièce inline `cid:`,
 *    JAMAIS une URL publique (un `cid:` ne fuit aucune donnée et ne fait aucun fetch réseau) ;
 *  - couleurs de texte ET de carte FORCÉES en dur (la carte reste blanche, le texte sombre) pour
 *    survivre au mode sombre des clients, qui ne doit pas inverser un fond pensé clair ;
 *  - toute interpolation est ÉCHAPPÉE ({@see htmlspecialchars}, `ENT_QUOTES`) — nom de club, nom
 *    court et corps métier sont des données non fiables.
 *
 * Le FOND (derrière la carte) est DÉRIVÉ des trois teintes du mark ({@see BrandAssets::MARK_TINTS}),
 * jamais posé tel quel : chaque teinte est mélangée vers le blanc à {@see WHITE_MIX_RATIO}, puis
 * `background-color` = le mélange (moyenne) des trois éclaircies (couleur unie, rendue partout) et
 * `background-image` = un dégradé léger de ces trois éclaircies (rendu par les clients qui le
 * supportent, ignoré ailleurs sans dommage).
 */
final readonly class EmailTemplateRenderer
{
    /**
     * Noms techniques des pièces inline, référencés par `cid:` dans le HTML. Opaques (jamais lus
     * par un humain) : {@see Email::prepareParts()} les remplace par le
     * Content-ID généré au rendu. Les octets sont embarqués par {@see EmailSignatureListener}.
     */
    public const string PRODUCT_LOGO_CID = 'product-email-logo';

    public const string CLUB_LOGO_CID = 'club-email-logo';

    /**
     * En-têtes INTERNES portant le bouton d'action du HTML (D1), posés à la source par le
     * builder (patron {@see App\Mail\ClubMailMetadata}) et lus au worker par
     * {@see App\EventListener\EmailSignatureListener}, qui les retire avant le SMTP (préfixe
     * `X-Amateo-`). L'URL est validée `https?://` et échappée AU RENDU ({@see ctaButton}).
     */
    public const string CTA_URL_HEADER = 'X-Amateo-Cta-Url';

    public const string CTA_LABEL_HEADER = 'X-Amateo-Cta-Label';

    /**
     * Part de BLANC dans le mélange qui éclaircit chaque teinte du mark (0 = teinte pure, 1 =
     * blanc). 0.85 garde un fond pâle, lisible sous du texte sombre, qui laisse deviner la couleur
     * de marque. Le ratio est ici, nommé : le changer re-dérive le fond.
     */
    public const float WHITE_MIX_RATIO = 0.85;

    /**
     * `$ctaUrl`/`$ctaLabel` : bouton d'action optionnel (lien coach D1). `$productLogoSrc`/
     * `$clubLogoSrc` : la SOURCE des images — `cid:` pour le mail réel (défaut), un `data:` URI
     * pour un aperçu rendu en iframe (les `cid:` n'y résolvent pas). Le MÊME renderer sert les
     * deux, seule la source des images change.
     */
    public function render(
        string $bodyText,
        string $productName,
        string $productTagline,
        string $productSiteUrl,
        ?string $clubLabel,
        bool $hasClubLogo,
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
        ?string $productLogoSrc = null,
        ?string $clubLogoSrc = null,
    ): string {
        $flags = \ENT_QUOTES | \ENT_SUBSTITUTE;

        $productLogoSrc ??= 'cid:' . self::PRODUCT_LOGO_CID;
        $clubLogoSrc ??= 'cid:' . self::CLUB_LOGO_CID;

        $bodyHtml = nl2br(htmlspecialchars($bodyText, $flags, 'UTF-8'));
        $nameHtml = htmlspecialchars($productName, $flags, 'UTF-8');
        $taglineHtml = htmlspecialchars($productTagline, $flags, 'UTF-8');
        $hrefHtml = htmlspecialchars($productSiteUrl, $flags, 'UTF-8');
        $productLogoSrcHtml = htmlspecialchars($productLogoSrc, $flags, 'UTF-8');
        // Libellé du lien = domaine nu de la vitrine (cf. maquette), pas l'URL entière.
        $label = parse_url($productSiteUrl, \PHP_URL_HOST) ?: $productSiteUrl;
        $labelHtml = htmlspecialchars($label, $flags, 'UTF-8');

        [$pageBackground, $pageGradient] = $this->derivedBackground();
        $clubHeaderHtml = $this->clubHeader($clubLabel, $hasClubLogo, $clubLogoSrc, $flags);
        $ctaHtml = $this->ctaButton($ctaUrl, $ctaLabel, $flags);

        return <<<HTML
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{$pageBackground};background-image:{$pageGradient};margin:0;padding:24px 16px;">
            <tr><td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:12px;border:1px solid #e5e5e5;">
            <tr><td style="padding:28px 32px;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1a1a1a;">
            {$clubHeaderHtml}
            <div style="color:#1a1a1a;">{$bodyHtml}</div>
            {$ctaHtml}
            <hr style="border:none;border-top:1px solid #e5e5e5;margin:24px 0 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="vertical-align:middle;padding-right:12px;"><img src="{$productLogoSrcHtml}" width="40" height="40" alt="{$nameHtml}" style="display:block;width:40px;height:40px;border-radius:50%;"></td>
            <td style="vertical-align:middle;">
            <div style="font-weight:700;color:#1a1a1a;">{$nameHtml}</div>
            <div style="color:#6b7280;">{$taglineHtml}</div>
            <div><a href="{$hrefHtml}" style="color:#2563eb;text-decoration:underline;">{$labelHtml}</a></div>
            </td>
            </tr></table>
            </td></tr>
            </table>
            </td></tr>
            </table>
            HTML;
    }

    /**
     * L'en-tête de carte du club : logo (CID) + nom, ou nom seul en texte stylé, ou rien. Un
     * e-mail SANS club (libellé null) n'a pas d'en-tête club du tout.
     */
    private function clubHeader(?string $clubLabel, bool $hasClubLogo, string $clubLogoSrc, int $flags): string
    {
        if (null === $clubLabel) {
            return '';
        }

        $labelHtml = htmlspecialchars($clubLabel, $flags, 'UTF-8');
        $logoSrcHtml = htmlspecialchars($clubLogoSrc, $flags, 'UTF-8');

        $logoCell = $hasClubLogo
            ? <<<HTML
                <td style="vertical-align:middle;padding-right:12px;"><img src="{$logoSrcHtml}" width="48" height="48" alt="{$labelHtml}" style="display:block;width:48px;height:48px;border-radius:8px;object-fit:contain;"></td>
                HTML
            : '';

        return <<<HTML
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;"><tr>
            {$logoCell}
            <td style="vertical-align:middle;"><div style="font-weight:700;font-size:18px;color:#1a1a1a;">{$labelHtml}</div></td>
            </tr></table>
            HTML;
    }

    /**
     * Le bouton d'action (table + styles inline, survit à Outlook/Gmail), ou '' si pas de CTA.
     *
     * Durcissement : l'`href` n'est rendu QUE s'il est `http(s)` — un `javascript:`/`data:`/`vbscript:`
     * n'ouvre jamais de lien cliquable. Label ET URL sont échappés ({@see htmlspecialchars},
     * `ENT_QUOTES`) : ce sont des données non fiables (jeton, libellé posés à la source).
     */
    private function ctaButton(?string $ctaUrl, ?string $ctaLabel, int $flags): string
    {
        if (null === $ctaUrl || '' === $ctaUrl || null === $ctaLabel || '' === $ctaLabel) {
            return '';
        }
        if (1 !== preg_match('~^https?://~i', $ctaUrl)) {
            return '';
        }

        $hrefHtml = htmlspecialchars($ctaUrl, $flags, 'UTF-8');
        $labelHtml = htmlspecialchars($ctaLabel, $flags, 'UTF-8');

        return <<<HTML
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 4px;"><tr>
            <td style="border-radius:8px;background-color:#1a1a1a;">
            <a href="{$hrefHtml}" style="display:inline-block;padding:12px 24px;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">{$labelHtml}</a>
            </td>
            </tr></table>
            HTML;
    }

    /**
     * Le fond dérivé : [couleur unie, dégradé] — tous deux CALCULÉS depuis {@see
     * BrandAssets::MARK_TINTS} (jamais recopiés). La couleur unie est la moyenne des trois teintes
     * éclaircies ; le dégradé enchaîne ces trois éclaircies.
     *
     * @return array{0: string, 1: string}
     */
    private function derivedBackground(): array
    {
        $lightened = array_map(
            fn (string $hex): string => $this->mixTowardWhite($hex, self::WHITE_MIX_RATIO),
            BrandAssets::MARK_TINTS,
        );

        $solid = $this->averageHex($lightened);
        $gradient = \sprintf(
            'linear-gradient(135deg, %s 0%%, %s 50%%, %s 100%%)',
            $lightened[0],
            $lightened[1],
            $lightened[2],
        );

        return [$solid, $gradient];
    }

    /** Mélange une teinte `#RRGGBB` vers le blanc : `ratio` = part de blanc (0 → teinte, 1 → blanc). */
    private function mixTowardWhite(string $hex, float $ratio): string
    {
        [$r, $g, $b] = $this->toRgb($hex);

        return $this->toHex(
            (int) round($r * (1 - $ratio) + 255 * $ratio),
            (int) round($g * (1 - $ratio) + 255 * $ratio),
            (int) round($b * (1 - $ratio) + 255 * $ratio),
        );
    }

    /**
     * @param list<string> $hexes
     */
    private function averageHex(array $hexes): string
    {
        $sumR = $sumG = $sumB = 0;
        foreach ($hexes as $hex) {
            [$r, $g, $b] = $this->toRgb($hex);
            $sumR += $r;
            $sumG += $g;
            $sumB += $b;
        }
        $count = \count($hexes);

        return $this->toHex(
            (int) round($sumR / $count),
            (int) round($sumG / $count),
            (int) round($sumB / $count),
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function toRgb(string $hex): array
    {
        $clean = ltrim($hex, '#');

        return [
            (int) hexdec(substr($clean, 0, 2)),
            (int) hexdec(substr($clean, 2, 2)),
            (int) hexdec(substr($clean, 4, 2)),
        ];
    }

    private function toHex(int $r, int $g, int $b): string
    {
        return \sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}
