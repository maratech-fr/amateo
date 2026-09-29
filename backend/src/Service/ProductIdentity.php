<?php

declare(strict_types=1);

namespace App\Service;

/**
 * L'IDENTITÉ du produit — maison unique du nom commercial (« Amateo »), de
 * l'éditeur (« Maratech »), de l'accroche et de l'URL de la vitrine, lus par un
 * humain (P5-15, P5-24). Les valeurs viennent de l'environnement ou d'un bind
 * (`APP_PRODUCT_NAME`, `APP_PUBLISHER_NAME`, `PRODUCT_SITE_URL`, l'accroche par
 * bind littéral, tous dans `config/services.yaml`) : un futur changement se fait
 * EN UN POINT, il ne rouvre pas la chasse aux littéraux dans les sujets d'e-mail,
 * les signatures, les libellés d'écran ou l'URI TOTP.
 *
 * Même patron que `MailFrom` : les défauts ci-dessous ne sont PAS une seconde
 * configuration à maintenir — ils servent aux tests unitaires qui construisent
 * le service à la main. En application, le bind gagne toujours.
 *
 * Deux axes, une seule maison : le nom produit et le nom éditeur sont distincts
 * (le second est le responsable de traitement RGPD, cf. page Confidentialité)
 * mais voyagent ensemble — un service unique évite de threader ces scalaires
 * dans les nombreux points d'appel.
 *
 * `tagline` vs `siteUrl` — deux natures :
 *  - L'accroche est un texte produit FIXE, identique dans tous les environnements
 *    (un rebranding l'édite ici et dans le bind `services.yaml`, jamais ailleurs) ;
 *    elle n'a donc PAS de variable d'environnement, juste un bind littéral.
 *  - L'URL de la vitrine VARIE par environnement (dev/staging/prod pointent des
 *    hôtes différents) : elle prend la variable d'env `PRODUCT_SITE_URL`. C'est le
 *    domaine NU de la vitrine (`amateo.app`), à ne pas confondre avec
 *    `FRONTEND_BASE_URL` qui est l'application (`app.amateo.app`).
 */
final readonly class ProductIdentity
{
    public function __construct(
        private string $productName = 'Amateo',
        private string $publisherName = 'Maratech',
        private string $productTagline = 'Le planning de votre club, sans le casse-tête.',
        private string $productSiteUrl = 'https://amateo.app',
    ) {}

    /** Le nom commercial du produit — « Amateo ». */
    public function name(): string
    {
        return $this->productName;
    }

    /** L'éditeur (responsable de traitement RGPD) — « Maratech ». */
    public function publisher(): string
    {
        return $this->publisherName;
    }

    /** L'accroche produit — « Le planning de votre club, sans le casse-tête. ». */
    public function tagline(): string
    {
        return $this->productTagline;
    }

    /** L'URL de la vitrine (domaine nu), p. ex. « https://amateo.app ». */
    public function siteUrl(): string
    {
        return $this->productSiteUrl;
    }
}
