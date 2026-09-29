<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Les ACTIFS de marque servis côté backend — frère de `ProductIdentity` (P5-24).
 *
 * `ProductIdentity` porte le NOM du produit lu par un humain ; `BrandAssets` porte
 * son MARK graphique inliné dans un document que le backend génère : le pied de page
 * « Généré avec … » de l'export PDF (SVG), et la signature des e-mails (PNG en CID).
 *
 * Le fichier source vit dans `backend/assets/brand/` (README de provenance à côté) ;
 * le chemin ARRIVE par injection (`%kernel.project_dir%/assets/brand`), jamais codé en
 * dur chez l'appelant — un `PdfGenerator` ne doit pas connaître la disposition du dépôt.
 *
 * Même patron que `ProductIdentity` : le défaut ci-dessous n'est PAS une seconde
 * configuration à maintenir — il résout l'asset réel depuis l'emplacement de cette
 * classe pour les tests unitaires qui construisent le service à la main. En application,
 * le bind gagne toujours.
 */
final readonly class BrandAssets
{
    public function __construct(
        private string $brandAssetsDir = __DIR__ . '/../../assets/brand',
    ) {}

    /**
     * L'icône produit inlinée en data URI, prête à être posée dans un `src=""`.
     *
     * SVG data URI : le mark reste vectoriel (net à toute échelle) et le document
     * n'a AUCUN fetch réseau à faire — indispensable dans le contexte du worker
     * Puppeteer, qui ne joint aucune URL applicative.
     */
    public function pdfLogoDataUri(): string
    {
        $path = $this->brandAssetsDir . '/icon.svg';
        $svg = @file_get_contents($path);
        if (false === $svg || '' === $svg) {
            throw new RuntimeException(\sprintf('Brand icon asset unreadable at %s', $path));
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Les octets bruts du mark produit en PNG, prêts à être embarqués en pièce
     * inline (Content-ID) dans la signature d'un e-mail.
     *
     * PNG et non SVG : les clients de messagerie ne rendent pas fiablement un SVG
     * (Outlook, Gmail le rejettent). L'icône est une pastille couleur sur disque
     * blanc (fond transparent hors du disque) dimensionnée pour tenir sous 20 Ko :
     * elle voyage inline dans le corps, sans fetch réseau côté destinataire.
     */
    public function emailLogoPngBytes(): string
    {
        $path = $this->brandAssetsDir . '/email-icon.png';
        $bytes = @file_get_contents($path);
        if (false === $bytes || '' === $bytes) {
            throw new RuntimeException(\sprintf('Brand email icon asset unreadable at %s', $path));
        }

        return $bytes;
    }
}
