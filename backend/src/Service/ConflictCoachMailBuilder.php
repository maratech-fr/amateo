<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CoachGender;
use App\EventListener\EmailSignatureListener;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use DateTimeImmutable;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Les deux e-mails de l'envoi des conflits aux coachs — une liste à sa date, À TITRE INDICATIF,
 * sans décision ni suivi (« il ne pourra pas être surpris ») :
 *
 *  - « conflits » : une tuile par conflit de personne sur un match, triées par date. Le TEXTE
 *    porte la liste complète ; le HTML porte les mêmes tuiles en FRAGMENT (en-tête
 *    {@see EmailTemplateRenderer::HTML_FRAGMENT_HEADER}), enveloppé une fois par la carte de
 *    marque au passage de {@see EmailSignatureListener} ;
 *  - « plus aucun conflit » : texte seul, signé par la chaîne commune (pied anti-usurpation).
 *
 * Aucun statut ni note de traitement n'est lu : le mail ne porte aucune décision. Toute donnée
 * saisie (équipe, adversaire, gymnase, lieu, prénom/nom) est échappée UNE fois, ici. Aucun bouton
 * (rien à faire : un coach n'a pas de compte). Gravité : table PROPRE au mail (couleur + mot).
 */
final class ConflictCoachMailBuilder
{
    /** Gravité → [mot, couleur] — jamais la couleur seule. */
    private const array SEVERITY = [
        3 => ['Important', '#b91c1c'],
        4 => ['À regarder', '#c2410c'],
        5 => ['À surveiller', '#a16207'],
    ];

    private const array FAMILY = [
        'MATCH_MATCH' => 'Personne en double',
        'MATCH_TRAINING' => 'Match × entraînement',
        'MATCH_COACH_UNAVAILABLE' => 'Coach principal indisponible',
    ];

    private const array DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    private const array DAYS_SHORT = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    private const string CLOSING = 'Cette liste vaut à la date ci-dessus ; le planning peut encore bouger. Les matchs sans heure connue ne sont pas vérifiés.';

    public function __construct(
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
        private readonly ProductIdentity $productIdentity = new ProductIdentity,
    ) {}

    /**
     * @param list<array<string, mixed>> $conflicts les conflits de CE coach (déjà choisis par {@see ConflictCoachMailing})
     * @param array<string, Team>        $teams
     * @param array<string, Venue>       $venues
     * @param array<string, Coach>       $coaches
     */
    public function buildConflicts(string $to, Coach $coach, Club $club, Season $season, string $senderFirstName, DateTimeImmutable $clubToday, array $conflicts, array $teams, array $venues, array $coaches): Email
    {
        $label = $club->emailLabel();
        $count = \count($conflicts);
        $subject = 1 === $count
            ? \sprintf('Un conflit vous concerne — %s', $label)
            : \sprintf('%d conflits vous concernent — %s', $count, $label);

        $greeting = \sprintf('Bonjour %s,', $coach->getFirstName());
        $intro = \sprintf(
            '%s (%s) vous informe, à titre indicatif, des conflits de personnes sur un match, datés d\'aujourd\'hui ou à venir, qui vous concernent pour la saison %s, au %s.',
            $senderFirstName,
            $label,
            $season->getName(),
            $clubToday->format('d/m/Y'),
        );

        usort($conflicts, fn (array $a, array $b): int => strcmp($this->moment($a), $this->moment($b)));

        $textTiles = [];
        $htmlTiles = [];
        foreach ($conflicts as $conflict) {
            [$banner, $lines, $severity] = $this->tile($conflict, $teams, $venues, $coaches);
            [$word, $color] = $severity;
            $textTiles[] = implode("\n", [$banner . ' · ' . $word, ...$lines]);
            $htmlTiles[] = $this->htmlTile($banner, $lines, $word, $color);
        }

        $text = implode("\n\n", [$greeting, $intro, ...$textTiles, self::CLOSING]);
        $fragment = $this->paragraph($greeting) . $this->paragraph($intro) . implode('', $htmlTiles) . $this->paragraph(self::CLOSING);

        $email = $this->email($to, $subject, $text, $club, $senderFirstName);
        $email->html($fragment);
        $email->getHeaders()->addTextHeader(EmailTemplateRenderer::HTML_FRAGMENT_HEADER, '1');

        return $email;
    }

    public function buildCleared(string $to, Coach $coach, Club $club, Season $season, string $senderFirstName, DateTimeImmutable $clubToday): Email
    {
        $label = $club->emailLabel();
        $text = implode("\n\n", [
            \sprintf('Bonjour %s,', $coach->getFirstName()),
            \sprintf(
                'Les conflits qui vous avaient été signalés ne sont plus d\'actualité au %s : aucun conflit de personne sur un match, daté d\'aujourd\'hui ou à venir, ne vous concerne pour la saison %s. %s (%s) vous en informe.',
                $clubToday->format('d/m/Y'),
                $season->getName(),
                $senderFirstName,
                $label,
            ),
            'Le planning peut encore bouger. Les matchs sans heure connue ne sont pas vérifiés.',
        ]);

        return $this->email($to, \sprintf('Plus aucun conflit sur un match ne vous concerne — %s', $label), $text, $club, $senderFirstName);
    }

    /** L'unique construction d'e-mail de l'envoi aux coachs (inventaire des CTA : EXEMPT, sans bouton). */
    private function email(string $to, string $subject, string $text, Club $club, string $senderFirstName): Email
    {
        $from = $this->mailFrom->addressAs(\sprintf('%s (%s) via %s', $senderFirstName, $club->emailLabel(), $this->productIdentity->name()));

        // E-mail MÉTIER club (patron CoachWishMailBuilder) + identité du club sur la carte.
        return ClubMailMetadata::mark(ClubBusinessMail::mark((new Email)->from($from)->to(new Address($to))->subject($subject)->text($text)), $club);
    }

    /**
     * @param array<string, mixed> $conflict
     * @param array<string, Team>  $teams
     * @param array<string, Venue> $venues
     * @param array<string, Coach> $coaches
     *
     * @return array{0: string, 1: list<string>, 2: array{0: string, 1: string}}
     */
    private function tile(array $conflict, array $teams, array $venues, array $coaches): array
    {
        $type = \is_string($conflict['type'] ?? null) ? $conflict['type'] : '';
        $personId = \is_string($conflict['coachId'] ?? null) ? $conflict['coachId'] : '';
        $person = $coaches[$personId] ?? null;
        $personName = $person instanceof Coach ? $this->shortName($person) : 'Un coach';
        $gender = $person instanceof Coach ? $person->getGender() : CoachGender::UNSPECIFIED;

        $date = substr($this->moment($conflict), 0, 10);
        $banner = \sprintf('%s — %s · %s', self::FAMILY[$type] ?? $type, $personName, $this->dayDate($date, false));

        $lines = [];
        if ('MATCH_COACH_UNAVAILABLE' === $type) {
            $fixture = $this->side($conflict, 'fixture');
            $line = \sprintf('Indisponible pour le match de %s, %s à %s', $this->teamName($teams, $fixture), $this->dayDate((string) ($fixture['matchDate'] ?? ''), false), $this->kickoffLabel($fixture));
            $opponent = $this->opponent($fixture);
            $lines[] = '' === $opponent ? $line : $line . ' · ' . $opponent;
        } else {
            foreach (['left', 'right', 'fixture'] as $key) {
                if (\is_array($conflict[$key] ?? null)) {
                    $lines[] = $this->matchLine($this->side($conflict, $key), $teams, $gender);
                }
            }
            $training = $conflict['training'] ?? null;
            if (\is_array($training)) {
                $start = \is_string($training['startTime'] ?? null) ? $training['startTime'] : '';
                $duration = \is_int($training['durationMinutes'] ?? null) ? $training['durationMinutes'] : 0;
                $venueId = \is_string($training['venueId'] ?? null) ? $training['venueId'] : '';
                $venue = $venues[$venueId] ?? null;
                $lines[] = implode(' · ', array_filter([
                    'Entraînement ' . $this->teamName($teams, $training),
                    $venue instanceof Venue ? $venue->getName() : '',
                    \sprintf('%s → %s', $start, $this->addMinutes($start, $duration)),
                ], static fn (string $part): bool => '' !== $part));
            }
            $lines[] = $this->overlapLine($conflict);
        }

        $severity = \is_int($conflict['severity'] ?? null) ? $conflict['severity'] : 5;
        $word = self::SEVERITY[max(3, min(5, $severity))];

        return [$banner, $lines, $word];
    }

    /**
     * « U15 (coach) · domicile · vs Les Loups · 15:30 → 17:25 » ; à l'extérieur, le lieu et le
     * trajet quand il est connu (« départ 16:00 · 17:00 · retour 19:30 »), sinon « trajet inconnu ».
     *
     * @param array<string, mixed> $side
     * @param array<string, Team>  $teams
     */
    private function matchLine(array $side, array $teams, CoachGender $gender): string
    {
        $role = $side['role'] ?? null;
        $team = $this->teamName($teams, $side);
        if (\is_string($role)) {
            $team .= \sprintf(' (%s)', 'PLAYER' === $role ? $this->playerWord($gender) : 'coach');
        }

        $parts = [$team];
        $away = 'AWAY' === ($side['homeAway'] ?? null);
        if ($away) {
            $place = $side['opponentPlace'] ?? null;
            $parts[] = \is_string($place) && '' !== trim($place) ? 'extérieur à ' . $place : 'extérieur (lieu inconnu)';
        } else {
            $parts[] = 'domicile';
        }
        $opponent = $this->opponent($side);
        if ('' !== $opponent) {
            $parts[] = $opponent;
        }

        $kickoff = $this->kickoff($side);
        $kickoffLabel = $this->kickoffLabel($side);
        $duration = \is_int($side['matchDurationMinutes'] ?? null) ? $side['matchDurationMinutes'] : null;
        if (!$away) {
            $parts[] = null !== $kickoff && null !== $duration ? \sprintf('%s → %s', $kickoffLabel, $this->addMinutes($kickoff, $duration)) : $kickoffLabel;
        } else {
            $travel = \is_int($side['travelOneWayMinutes'] ?? null) ? $side['travelOneWayMinutes'] : null;
            if (null !== $kickoff && null !== $travel) {
                $parts[] = 'départ ' . $this->addMinutes($kickoff, -$travel);
                $parts[] = $kickoffLabel;
                $parts[] = 'retour ' . $this->addMinutes($kickoff, ($duration ?? 0) + $travel);
            } else {
                $parts[] = $kickoffLabel;
                $parts[] = 'trajet inconnu';
            }
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $conflict */
    private function overlapLine(array $conflict): string
    {
        $start = \is_string($conflict['start'] ?? null) ? $conflict['start'] : '';
        $end = \is_string($conflict['end'] ?? null) ? $conflict['end'] : '';
        $from = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $start);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $end);
        if (false === $from || false === $to) {
            return '';
        }
        $minutes = (int) (($to->getTimestamp() - $from->getTimestamp()) / 60);
        $range = $from->format('Y-m-d') === $to->format('Y-m-d')
            ? \sprintf('%s → %s', $from->format('H:i'), $to->format('H:i'))
            : \sprintf('%s %s → %s %s', $this->dayDate($from->format('Y-m-d'), true), $from->format('H:i'), $this->dayDate($to->format('Y-m-d'), true), $to->format('H:i'));

        return \sprintf('Chevauchement %s (%s)', $range, $this->duration($minutes));
    }

    /** @param list<string> $lines */
    private function htmlTile(string $banner, array $lines, string $word, string $color): string
    {
        $rows = '';
        foreach ($lines as $line) {
            if ('' !== $line) {
                $rows .= '<tr><td colspan="2" style="padding:2px 12px;color:#1a1a1a;">' . $this->h($line) . '</td></tr>';
            }
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;background-color:#ffffff;border:1px solid #e5e5e5;border-left:4px solid ' . $color . ';border-radius:8px;">'
            . '<tr><td style="padding:8px 12px 4px;font-weight:700;color:#1a1a1a;">' . $this->h($banner) . '</td>'
            . '<td align="right" style="padding:8px 12px 4px;font-weight:700;color:' . $color . ';white-space:nowrap;">' . $this->h($word) . '</td></tr>'
            . $rows
            . '<tr><td colspan="2" style="padding:0 0 6px;"></td></tr></table>';
    }

    private function paragraph(string $text): string
    {
        return '<p style="margin:0 0 12px;color:#1a1a1a;">' . $this->h($text) . '</p>';
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $conflict le moment de tri (« Y-m-d H:i ») */
    private function moment(array $conflict): string
    {
        if (\is_string($conflict['start'] ?? null)) {
            return $conflict['start'];
        }
        $fixture = $this->side($conflict, 'fixture');

        return \sprintf('%s %s', (string) ($fixture['matchDate'] ?? ''), $this->kickoff($fixture) ?? '00:00');
    }

    /**
     * @param array<string, mixed> $conflict
     *
     * @return array<string, mixed>
     */
    private function side(array $conflict, string $key): array
    {
        $side = $conflict[$key] ?? null;

        return \is_array($side) ? $side : [];
    }

    /**
     * @param array<string, Team>  $teams
     * @param array<string, mixed> $side
     */
    private function teamName(array $teams, array $side): string
    {
        $teamId = \is_string($side['teamId'] ?? null) ? $side['teamId'] : '';
        $team = $teams[$teamId] ?? null;

        return $team instanceof Team ? $team->getName() : 'Équipe';
    }

    /** @param array<string, mixed> $side */
    private function opponent(array $side): string
    {
        $label = $side['opponentLabel'] ?? null;

        return \is_string($label) && '' !== trim($label) ? 'vs ' . $label : '';
    }

    /** @param array<string, mixed> $side */
    private function kickoff(array $side): ?string
    {
        foreach (['kickoffTime', 'estimatedKickoffTime'] as $key) {
            if (\is_string($side[$key] ?? null) && '' !== $side[$key]) {
                return $side[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $side « 17:00 » ou « 17:00 (heure estimée) » */
    private function kickoffLabel(array $side): string
    {
        $kickoff = $this->kickoff($side) ?? '';
        $estimated = true === ($side['estimatedKickoff'] ?? false) && !\is_string($side['kickoffTime'] ?? null);

        return $estimated ? $kickoff . ' (heure estimée)' : $kickoff;
    }

    private function addMinutes(string $time, int $minutes): string
    {
        if (1 !== preg_match('/^(\d{2}):(\d{2})/', $time, $m)) {
            return $time;
        }
        $total = ((((int) $m[1]) * 60 + (int) $m[2] + $minutes) % 1440 + 1440) % 1440;

        return \sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    private function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $rest = $minutes % 60;

        return 0 === $rest ? intdiv($minutes, 60) . 'h' : \sprintf('%dh%02d', intdiv($minutes, 60), $rest);
    }

    /** « samedi 14/03 » (ou « sam. 14/03 ») depuis une date « Y-m-d ». */
    private function dayDate(string $date, bool $short): string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $day) {
            return $date;
        }
        /** @var 1|2|3|4|5|6|7 $weekday */
        $weekday = (int) $day->format('N');

        return \sprintf('%s %s', ($short ? self::DAYS_SHORT : self::DAYS)[$weekday], $day->format('d/m'));
    }

    /** « Karim B. » : le prénom et l'initiale du nom. */
    private function shortName(Coach $coach): string
    {
        $first = trim($coach->getFirstName());
        $initial = mb_strtoupper(mb_substr(trim($coach->getLastName()), 0, 1));

        return '' === $initial ? $first : \sprintf('%s %s.', $first, $initial);
    }

    private function playerWord(CoachGender $gender): string
    {
        return match ($gender) {
            CoachGender::FEMALE => 'joueuse',
            CoachGender::MALE => 'joueur',
            CoachGender::UNSPECIFIED => 'joueur·euse',
        };
    }
}
