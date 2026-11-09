<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\SchoolHolidayPeriod;
use App\Enum\SeasonPlanState;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Builds the "period without an overlay plan" reminder email (cockpit palier C) and its
 * school-holiday sibling (a holiday of the club's zone not yet adapted).
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
        // J-3 = the "red" alert (v3 §8.2).
        return $this->email($to, $clubName, $entry->getTitle(), $entry->getStartDate(), $entry->getEndDate(), $days, $days <= 3, 'pas de planning de période', [
            $this->startsIn($days) . ' et n\'a pas encore de planning de période.',
            'Rien ne se fait tout seul — ouvre le cockpit pour l\'adapter.',
        ], $club);
    }

    /**
     * Rappel d'une vacance scolaire sans planning de période. Rouge à son dernier palier (J-7) ;
     * le texte suit l'état du planning de saison qui la contient : validé → adapter la vacance ;
     * sinon → générer puis valider (ou seulement valider) le planning de saison d'abord.
     */
    public function buildForHoliday(string $to, string $clubName, SchoolHolidayPeriod $holiday, int $days, SeasonPlanState $seasonPlan, ?Club $club = null): Email
    {
        if (SeasonPlanState::CHOSEN === $seasonPlan) {
            $suffix = 'pas de planning de période';
            $body = [
                $this->startsIn($days) . ' et n\'a pas encore de planning de période.',
                'Rien ne se fait tout seul — ouvre le cockpit pour l\'adapter.',
            ];
        } else {
            $suffix = 'planning de saison à valider';
            $body = [
                \sprintf(
                    '%s. Pour préparer son planning de période, %s d\'abord ton planning de saison (page Planning).',
                    $this->startsIn($days),
                    SeasonPlanState::FINISHED_NOT_CHOSEN === $seasonPlan ? 'valide' : 'génère puis valide',
                ),
                'Rien ne se fait tout seul — ouvre le cockpit pour t\'y mettre.',
            ];
        }

        return $this->email($to, $clubName, $holiday->getLabel(), $holiday->getStartDate(), $holiday->getEndDate(), $days, $days <= 7, $suffix, $body, $club);
    }

    /** « Elle commence aujourd'hui » au jour J, sinon « Elle commence dans N jour(s) ». */
    private function startsIn(int $days): string
    {
        return 0 === $days
            ? 'Elle commence aujourd\'hui'
            : \sprintf('Elle commence dans %d jour%s', $days, $days > 1 ? 's' : '');
    }

    /**
     * Le foyer unique des rappels (fermetures/périodes et vacances scolaires).
     *
     * @param list<string> $body
     */
    private function email(string $to, string $clubName, string $title, DateTimeImmutable $start, DateTimeImmutable $end, int $days, bool $red, string $suffix, array $body, ?Club $club): Email
    {
        $subject = 0 === $days
            ? \sprintf('🔴 %s aujourd\'hui — %s', $title, $suffix)
            : \sprintf('%s %s dans %d j — %s', $red ? '🔴' : '⏳', $title, $days, $suffix);

        $lines = [
            \sprintf('Club : %s', $clubName),
            \sprintf('Période : %s', $title),
            \sprintf('Du %s au %s', $start->format('d/m/Y'), $end->format('d/m/Y')),
            '',
            ...$body,
        ];
        $link = '' !== $this->frontendBaseUrl ? rtrim($this->frontendBaseUrl, '/') . '/' : null;
        if (null !== $link) {
            $lines[] = '';
            $lines[] = $link;
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
        // Bouton CTA du HTML (D1) — posé seulement quand le lien est cliquable (base front configurée).
        if (null !== $link) {
            $headers = $email->getHeaders();
            $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $link);
            $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, 'Ouvrir le cockpit');
        }
        // Identité du club (logo + nom court) sur la carte d'e-mail (D1), quand elle est connue.
        if ($club instanceof Club) {
            ClubMailMetadata::mark($email, $club);
        }

        return $email;
    }
}
