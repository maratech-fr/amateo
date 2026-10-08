<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Builds the "period without an overlay plan" reminder email (cockpit palier C).
 * Isolated from the cron walker so the rendering is unit-testable on its own.
 */
final class PeriodReminderMailBuilder
{
    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
    ) {}

    public function build(string $to, string $clubName, CalendarEntry $entry, int $days, ?Club $club = null): Email
    {
        $red = $days <= 3; // J-3 = the "red" alert (v3 §8.2).
        $subject = \sprintf('%s %s dans %d j — pas de plan de période', $red ? '🔴' : '⏳', $entry->getTitle(), $days);

        $lines = [
            \sprintf('Club : %s', $clubName),
            \sprintf('Période : %s', $entry->getTitle()),
            \sprintf('Du %s au %s', $entry->getStartDate()->format('d/m/Y'), $entry->getEndDate()->format('d/m/Y')),
            '',
            \sprintf('Elle commence dans %d jour%s et n\'a pas encore de plan de période (calendrier secondaire).', $days, $days > 1 ? 's' : ''),
            'Rien ne se fait tout seul — ouvre le cockpit pour l\'adapter.',
        ];
        if ('' !== $this->frontendBaseUrl) {
            $lines[] = '';
            $lines[] = rtrim($this->frontendBaseUrl, '/') . '/';
        }

        // E-mail MÉTIER club : candidat à l'interception « boîte aux lettres » d'un club à
        // horloge simulée (jamais un e-mail de compte, cf. ClubBusinessMail).
        $email = ClubBusinessMail::mark(
            (new Email)
                ->from($this->mailFrom->address())
                ->to($to)
                ->subject($subject)
                ->text(implode("\n", $lines)),
        );
        // Identité du club (logo + nom court) sur la carte d'e-mail (D1), quand elle est connue.
        if ($club instanceof Club) {
            ClubMailMetadata::mark($email, $club);
        }

        return $email;
    }
}
