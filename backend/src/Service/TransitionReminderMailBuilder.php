<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\ClubBusinessMail;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Builds the "prepare next season" reminder email (transition P2-PR2).
 * Isolated from the cron walker so the rendering is unit-testable on its own.
 */
final class TransitionReminderMailBuilder
{
    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
    ) {}

    public function build(string $to, string $clubName, string $currentSeasonName, DateTimeImmutable $pivot, int $days): Email
    {
        $red = $days <= 14; // last milestone before the pivot = the "red" alert.
        $subject = \sprintf('%s Préparez la saison suivante — bascule dans %d j', $red ? '🔴' : '⏳', $days);

        $lines = [
            \sprintf('Club : %s', $clubName),
            \sprintf('Saison en cours : %s', $currentSeasonName),
            \sprintf('Bascule de saison : %s', $pivot->format('d/m/Y')),
            '',
            'La saison suivante n\'est pas encore préparée.',
            'Ouvre l\'app et lance « Préparer la saison suivante » pour copier la structure (gymnases, équipes, coachs, contraintes) dans un brouillon librement modifiable.',
        ];
        if ('' !== $this->frontendBaseUrl) {
            $lines[] = '';
            $lines[] = rtrim($this->frontendBaseUrl, '/') . '/';
        }

        // E-mail MÉTIER club : candidat à l'interception « boîte aux lettres » (cf. ClubBusinessMail).
        return ClubBusinessMail::mark(
            (new Email)
                ->from($this->mailFrom->address())
                ->to($to)
                ->subject($subject)
                ->text(implode("\n", $lines)),
        );
    }
}
