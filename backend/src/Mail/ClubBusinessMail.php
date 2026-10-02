<?php

declare(strict_types=1);

namespace App\Mail;

use App\EventListener\ClockedClubMailInterceptor;
use Symfony\Component\Mime\Email;

/**
 * Maison UNIQUE qui marque un e-mail comme « métier club » (P4-16, durcissement revue sécu).
 *
 * L'interception des e-mails d'un club à horloge simulée ({@see ClockedClubMailInterceptor})
 * fonctionne en LISTE BLANCHE, pas en liste noire : elle ne capte QUE les e-mails explicitement
 * marqués ici. Pourquoi ce sens et pas « marquer les e-mails de COMPTE » :
 *
 *  - un e-mail de COMPTE (reset de mot de passe, vérification/changement d'adresse, inscription,
 *    feedback, alerte santé) porte un SECRET (jeton de reset, lien de confirmation). Le router
 *    `/api/*` pose le GUC tenant du DEMANDEUR sur TOUTE requête d'un membre connecté, y compris
 *    les routes PUBLIC_ACCESS : un membre d'un club à horloge pouvait donc déclencher un reset
 *    pour une victime, capter le mail dans la boîte (lisible par tout membre) et lire le jeton →
 *    prise de compte. Une liste NOIRE échouerait OUVERT : un nouvel e-mail de compte oublié
 *    serait capté. Une liste BLANCHE échoue SÛR : un e-mail non marqué part toujours RÉELLEMENT.
 *  - seuls les 3 e-mails VRAIMENT métier (rappels de période, rappels de transition de saison,
 *    campagnes/digests de vœux de coachs) ont un sens dans la boîte d'une démo — ce sont EUX
 *    qu'on marque, à la source (leurs builders), jamais un e-mail de compte.
 *
 * Le marqueur est un en-tête interne `X-Amateo-Scope: club-business`. L'interception vérifie EN
 * PLUS que tous les destinataires appartiennent au club (membres/coachs) — défense en profondeur,
 * un builder métier buggé qui adresserait hors club ne doit jamais capter.
 */
final class ClubBusinessMail
{
    public const string SCOPE_HEADER = 'X-Amateo-Scope';

    public const string CLUB_BUSINESS = 'club-business';

    /** Marque l'e-mail comme métier club (à appeler dans le builder, à la source). */
    public static function mark(Email $email): Email
    {
        $email->getHeaders()->addTextHeader(self::SCOPE_HEADER, self::CLUB_BUSINESS);

        return $email;
    }

    /** L'e-mail est-il un e-mail métier club (seul candidat à l'interception) ? */
    public static function isClubBusiness(Email $email): bool
    {
        return self::CLUB_BUSINESS === $email->getHeaders()->getHeaderBody(self::SCOPE_HEADER);
    }
}
