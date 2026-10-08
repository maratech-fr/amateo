<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\Club;
use App\EventListener\EmailSignatureListener;
use Symfony\Component\Mime\Email;

/**
 * Maison UNIQUE qui pose l'IDENTITÉ du club sur un e-mail sortant (D1 — gabarit commun).
 *
 * Deux en-têtes INTERNES, patron {@see ClubBusinessMail} :
 *  - `X-Amateo-Club-Id`    — l'identifiant du club, lu AU WORKER par {@see EmailSignatureListener}
 *    pour embarquer le logo du club (octets {@see App\Storage\LogoStorage}) en pièce inline (CID) ;
 *  - `X-Amateo-Club-Label` — le libellé à afficher en en-tête de carte : le nom COURT du club
 *    s'il existe, sinon le nom long ({@see Club::emailLabel}).
 *
 * ⚠ Ces en-têtes sont des marqueurs INTERNES, jamais livrés : {@see EmailSignatureListener} les
 * RETIRE tous (préfixe `X-Amateo-`) à la phase worker, juste avant le SMTP. On NE pose ici QUE des
 * en-têtes — aucun contenu métier n'est réécrit (le texte reste celui du builder).
 *
 * À appeler dans le builder/mailer à CLUB CONNU, à la source (jamais sur un e-mail de compte, qui
 * n'a pas de club). Indépendant de {@see ClubBusinessMail} : un e-mail peut porter l'identité du
 * club (carte habillée) sans être « métier club » candidat à l'interception démo (p. ex. l'avis
 * d'effacement institutionnel).
 */
final class ClubMailMetadata
{
    public const string CLUB_ID_HEADER = 'X-Amateo-Club-Id';

    public const string CLUB_LABEL_HEADER = 'X-Amateo-Club-Label';

    /** Pose l'id + le libellé du club sur l'e-mail (à appeler dans le builder, à la source). */
    public static function mark(Email $email, Club $club): Email
    {
        $headers = $email->getHeaders();
        $headers->addTextHeader(self::CLUB_ID_HEADER, $club->getId());
        $headers->addTextHeader(self::CLUB_LABEL_HEADER, $club->emailLabel());

        return $email;
    }
}
