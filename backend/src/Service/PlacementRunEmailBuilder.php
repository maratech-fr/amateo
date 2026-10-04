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
        $lines = [
            'Le placement automatique de vos matchs est terminé.',
            '',
            \sprintf('%d match%s placé%s.', $placed, $placed > 1 ? 's' : '', $placed > 1 ? 's' : ''),
            \sprintf('%d match%s restant%s à traiter.', $toTreat, $toTreat > 1 ? 's' : '', $toTreat > 1 ? 's' : ''),
        ];

        return $this->compose('Placement automatique terminé', $lines, $to);
    }

    /**
     * L'e-mail « placement automatique NON ABOUTI », envoyé AU GESTIONNAIRE qui a lancé un run qui
     * a ÉCHOUÉ après plus de deux minutes. Décision fondateur (2026-10-03) : « on envoie que ce
     * soit un échec ou un succès, sinon il ne reviendra jamais car il pensera que le moteur
     * travaille toute la nuit. » Aucun détail technique, aucun identifiant interne : juste le fait
     * et comment relancer.
     */
    public function buildFailure(string $to): Email
    {
        $lines = [
            'Le placement automatique n\'a pas abouti.',
            'Vous pouvez le relancer depuis le calendrier des matchs.',
        ];

        return $this->compose('Le placement automatique n\'a pas abouti', $lines, $to);
    }

    /**
     * Compose l'e-mail métier : ajoute le lien vers le calendrier des matchs (quand l'URL est
     * configurée) et pose la marque « boîte aux lettres » commune aux deux variantes.
     *
     * @param list<string> $lines
     */
    private function compose(string $subject, array $lines, string $to): Email
    {
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
