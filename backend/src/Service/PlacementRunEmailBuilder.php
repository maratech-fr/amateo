<?php

declare(strict_types=1);

namespace App\Service;

use App\EventListener\EmailSignatureListener;
use App\Mail\ClubBusinessMail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * L'e-mail « placement automatique terminé », envoyé AU GESTIONNAIRE qui a lancé le run
 * quand celui-ci a duré plus de deux minutes (il a eu le temps de quitter l'écran). Isolé
 * du handler pour un rendu testable à l'unité.
 *
 * Texte FR sobre : combien de matchs placés, combien restent à traiter, et un lien vers le
 * calendrier des matchs. AUCUN identifiant interne (ni id de run, ni jeton) dans le texte
 * (backend.md). Le nom du produit n'apparaît PAS en littéral ici : la signature de marque
 * est posée automatiquement par {@see EmailSignatureListener}.
 */
final class PlacementRunEmailBuilder
{
    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
    ) {}

    public function build(string $to, int $placed, int $toTreat): Email
    {
        $subject = 'Placement automatique terminé';

        $lines = [
            'Le placement automatique de vos matchs est terminé.',
            '',
            \sprintf('%d match%s placé%s.', $placed, $placed > 1 ? 's' : '', $placed > 1 ? 's' : ''),
            \sprintf('%d match%s restant%s à traiter.', $toTreat, $toTreat > 1 ? 's' : '', $toTreat > 1 ? 's' : ''),
        ];
        if ('' !== $this->frontendBaseUrl) {
            $lines[] = '';
            $lines[] = 'Voir le calendrier des matchs :';
            $lines[] = rtrim($this->frontendBaseUrl, '/') . '/matchs';
        }

        // E-mail MÉTIER club : candidat à l'interception « boîte aux lettres » d'un club à
        // horloge simulée (jamais un e-mail de compte, cf. ClubBusinessMail).
        return ClubBusinessMail::mark(
            (new Email)
                ->from($this->mailFrom->address())
                ->to($to)
                ->subject($subject)
                ->text(implode("\n", $lines)),
        );
    }
}
