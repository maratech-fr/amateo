<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mime\Address;

/**
 * L'expéditeur de TOUS les e-mails sortants — maison unique de l'adresse et du nom
 * affiché (P5-15). Les deux valeurs viennent de l'environnement (`MAIL_FROM_ADDRESS`,
 * `MAIL_FROM_NAME`, bindées dans `config/services.yaml`) : le domaine d'envoi doit
 * suivre le domaine VÉRIFIÉ chez le fournisseur transactionnel (SPF/DKIM/DMARC), et
 * il change sans qu'on retouche les treize points d'envoi.
 *
 * Les valeurs par défaut ci-dessous ne sont PAS une configuration de secours à
 * maintenir en double : elles servent aux tests unitaires qui construisent un
 * builder à la main. En application, le bind gagne toujours.
 */
final readonly class MailFrom
{
    public function __construct(
        private string $mailFromAddress = 'no-reply@amateo.app',
        private string $mailFromName = 'Amateo',
    ) {}

    /**
     * L'expéditeur prêt à poser sur un `Email` : « Amateo <no-reply@amateo.app> ».
     * Un nom vide retombe sur l'adresse nue (ce que faisaient les douze envois
     * historiques), jamais sur un nom inventé.
     */
    public function address(): Address
    {
        return new Address($this->mailFromAddress, $this->mailFromName);
    }

    /**
     * La MÊME adresse d'envoi, mais avec un nom affiché FOURNI — p. ex. « Prénom (BC Lyon)
     * via Amateo » sur le lien coach (D1). L'adresse ne change jamais (elle doit suivre le
     * domaine vérifié SPF/DKIM/DMARC) ; seul le nom varie, personnalisé à la source.
     *
     * Maison unique de cette variante : {@see Address} strippe les CR/LF du nom à la
     * construction et encode le reste en RFC 2047 au rendu — aucune injection d'en-tête
     * possible depuis un prénom ou un nom court non fiables.
     */
    public function addressAs(string $displayName): Address
    {
        return new Address($this->mailFromAddress, $displayName);
    }
}
