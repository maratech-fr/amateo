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
use App\Service\Conflicts\ConflictMoments;
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
    /** Gravité → [mot, couleur du filet et du contour, encre du mot et du pied, fond de la pastille] — jamais la couleur seule. */
    private const array SEVERITY = [
        3 => ['Important', '#b91c1c', '#991b1b', '#fef2f2'],
        4 => ['À regarder', '#c2410c', '#9a3412', '#fff7ed'],
        5 => ['À surveiller', '#a16207', '#854d0e', '#fefce8'],
    ];

    /**
     * Pile COURTE redéclarée sur chaque cellule de tuile : Outlook de bureau n'hérite pas la police
     * dans les tables imbriquées. Courte pour tenir 50 tuiles sous la coupe de Gmail (100 Ko).
     */
    private const string FONT = 'font-family:\'Segoe UI\',Arial,sans-serif;';

    private const array FAMILY = [
        'MATCH_MATCH' => 'Personne en double',
        'MATCH_TRAINING' => 'Match × entraînement',
        'MATCH_COACH_UNAVAILABLE' => 'Coach principal indisponible',
    ];

    /** Marque d'une tuile depuis l'envoi précédent — un mot, en fin de ligne-titre et en pastille. */
    private const array MARK = ['new' => 'Nouveau', 'changed' => 'Modifié'];

    private const array DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    private const array DAYS_SHORT = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    private const string CLOSING = 'Cette liste vaut à la date ci-dessus ; le planning peut encore bouger. Les matchs sans heure connue ne sont pas vérifiés.';

    public function __construct(
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
        private readonly ProductIdentity $productIdentity = new ProductIdentity,
    ) {}

    /**
     * `$marks` marque une tuile « Nouveau » ou « Modifié » depuis l'envoi précédent ; `$previousSentAt`
     * (heure murale du club) est cet envoi — null quand le coach n'a aucun envoi détaillé connu :
     * alors ni marque ni phrase (on ne prétend pas savoir ce qu'il a reçu).
     *
     * @param list<array<string, mixed>>     $conflicts les conflits de CE coach (déjà choisis par {@see ConflictCoachMailing})
     * @param array<string, Team>            $teams
     * @param array<string, Venue>           $venues
     * @param array<string, Coach>           $coaches
     * @param array<string, 'new'|'changed'> $marks     empreinte → marque
     */
    public function buildConflicts(string $to, Coach $coach, Club $club, Season $season, string $senderFirstName, DateTimeImmutable $clubToday, array $conflicts, array $teams, array $venues, array $coaches, array $marks = [], ?DateTimeImmutable $previousSentAt = null): Email
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
        $marked = 0;
        foreach ($conflicts as $conflict) {
            $fingerprint = \is_string($conflict['fingerprint'] ?? null) ? $conflict['fingerprint'] : '';
            $mark = $previousSentAt instanceof DateTimeImmutable ? (self::MARK[$marks[$fingerprint] ?? ''] ?? '') : '';
            if ('' !== $mark) {
                ++$marked;
            }
            $tile = $this->tile($conflict, $teams, $venues, $coaches) + ['mark' => $mark];
            $textTiles[] = $this->textTile($tile);
            $htmlTiles[] = $this->htmlTile($tile);
        }

        $since = [];
        if ($previousSentAt instanceof DateTimeImmutable) {
            $date = $previousSentAt->format('d/m/Y');
            $since[] = match (true) {
                0 === $marked => \sprintf('Cette liste vous est renvoyée : aucun conflit de cette liste n\'est nouveau ou modifié depuis l\'envoi du %s.', $date),
                1 === $marked => \sprintf('Depuis le dernier envoi du %s, 1 conflit est nouveau ou modifié : il est marqué.', $date),
                default => \sprintf('Depuis le dernier envoi du %s, %d conflits sont nouveaux ou modifiés : ils sont marqués.', $date, $marked),
            };
        }

        $text = implode("\n\n", [$greeting, $intro, ...$since, ...$textTiles, self::CLOSING]);
        $fragment = $this->paragraph($greeting) . $this->paragraph($intro) . implode('', array_map($this->paragraph(...), $since))
            . '<div style="margin-top:16px;">' . implode('', $htmlTiles) . '</div>'
            . '<p style="margin:12px 0 0 0;color:#4b5563;font-size:13px;">' . $this->h(self::CLOSING) . '</p>';

        return $this->withFragment($this->email($to, $subject, $text, $club, $senderFirstName), $fragment);
    }

    public function buildCleared(string $to, Coach $coach, Club $club, Season $season, string $senderFirstName, DateTimeImmutable $clubToday): Email
    {
        $label = $club->emailLabel();
        $paragraphs = [
            \sprintf('Bonjour %s,', $coach->getFirstName()),
            \sprintf(
                'Les conflits qui vous avaient été signalés ne sont plus d\'actualité au %s : aucun conflit de personne sur un match, daté d\'aujourd\'hui ou à venir, ne vous concerne pour la saison %s. %s (%s) vous en informe.',
                $clubToday->format('d/m/Y'),
                $season->getName(),
                $senderFirstName,
                $label,
            ),
            'Le planning peut encore bouger. Les matchs sans heure connue ne sont pas vérifiés.',
        ];

        // Mêmes paragraphes que la liste (espacement identique), en fragment enveloppé par la carte.
        return $this->withFragment(
            $this->email($to, \sprintf('Plus aucun conflit sur un match ne vous concerne — %s', $label), implode("\n\n", $paragraphs), $club, $senderFirstName),
            implode('', array_map($this->paragraph(...), $paragraphs)),
        );
    }

    /** L'unique construction d'e-mail de l'envoi aux coachs (inventaire des CTA : EXEMPT, sans bouton). */
    private function email(string $to, string $subject, string $text, Club $club, string $senderFirstName): Email
    {
        $from = $this->mailFrom->addressAs(\sprintf('%s (%s) via %s', $senderFirstName, $club->emailLabel(), $this->productIdentity->name()));

        // E-mail MÉTIER club (patron CoachWishMailBuilder) + identité du club sur la carte.
        return ClubMailMetadata::mark(ClubBusinessMail::mark((new Email)->from($from)->to(new Address($to))->subject($subject)->text($text)), $club);
    }

    /** Pose le corps HTML en FRAGMENT : la chaîne de signature l'enveloppe une fois dans la carte. */
    private function withFragment(Email $email, string $fragment): Email
    {
        $email->html($fragment);
        $email->getHeaders()->addTextHeader(EmailTemplateRenderer::HTML_FRAGMENT_HEADER, '1');

        return $email;
    }

    /**
     * Une tuile, à plat : titre de famille, mot de gravité et ses couleurs, sous-ligne « personne ·
     * jour date », blocs de côté (libellé en gras + détail, puis horaire en segments [texte, gras]),
     * ligne d'indisponibilité, pied de chevauchement.
     *
     * @param array<string, mixed> $conflict
     * @param array<string, Team>  $teams
     * @param array<string, Venue> $venues
     * @param array<string, Coach> $coaches
     *
     * @return array{family: string, word: string, color: string, ink: string, wash: string, sub: string, blocks: list<array{label: string, detail: string, time: list<array{0: string, 1: bool}>}>, line: list<array{0: string, 1: bool}>, overlap: string}
     */
    private function tile(array $conflict, array $teams, array $venues, array $coaches): array
    {
        $type = \is_string($conflict['type'] ?? null) ? $conflict['type'] : '';
        $personId = \is_string($conflict['coachId'] ?? null) ? $conflict['coachId'] : '';
        $person = $coaches[$personId] ?? null;
        $personName = $person instanceof Coach ? $this->shortName($person) : 'Un coach';
        $gender = $person instanceof Coach ? $person->getGender() : CoachGender::UNSPECIFIED;

        $blocks = [];
        $line = [];
        $overlap = '';
        if ('MATCH_COACH_UNAVAILABLE' === $type) {
            $fixture = $this->side($conflict, 'fixture');
            $line[] = [\sprintf('Indisponible pour le match de %s, %s à ', $this->teamName($teams, $fixture), $this->dayDate((string) ($fixture['matchDate'] ?? ''), false)), false];
            $line[] = $this->kickoffSegment($fixture);
            $opponent = $this->opponent($fixture);
            if ('' !== $opponent) {
                $line[] = [' · ' . $opponent, false];
            }
        } else {
            foreach (['left', 'right', 'fixture'] as $key) {
                if (\is_array($conflict[$key] ?? null)) {
                    $blocks[] = $this->matchBlock($this->side($conflict, $key), $teams, $gender);
                }
            }
            $training = $conflict['training'] ?? null;
            if (\is_array($training)) {
                $start = \is_string($training['startTime'] ?? null) ? $training['startTime'] : '';
                $duration = \is_int($training['durationMinutes'] ?? null) ? $training['durationMinutes'] : 0;
                $venueId = \is_string($training['venueId'] ?? null) ? $training['venueId'] : '';
                $venue = $venues[$venueId] ?? null;
                $blocks[] = [
                    'label' => 'Entraînement ' . $this->teamName($teams, $training),
                    'detail' => $venue instanceof Venue ? $venue->getName() : '',
                    'time' => [[\sprintf('%s → %s', $start, $this->addMinutes($start, $duration)), false]],
                ];
            }
            $overlap = $this->overlapLine($conflict);
        }

        $severity = \is_int($conflict['severity'] ?? null) ? $conflict['severity'] : 5;
        [$word, $color, $ink, $wash] = self::SEVERITY[max(3, min(5, $severity))];

        return [
            'family' => self::FAMILY[$type] ?? $type,
            'word' => $word,
            'color' => $color,
            'ink' => $ink,
            'wash' => $wash,
            'sub' => \sprintf('%s · %s', $personName, $this->dayDate(substr($this->moment($conflict), 0, 10), false)),
            'blocks' => $blocks,
            'line' => $line,
            'overlap' => $overlap,
        ];
    }

    /**
     * Un côté de match : « U15 (coach) » + « domicile · vs Les Loups », puis l'horaire — à
     * l'extérieur, le lieu et le trajet quand il est connu (« départ 16:00 · 17:00 · retour 19:30 »),
     * sinon « trajet inconnu ». Une heure estimée est un segment en gras.
     *
     * @param array<string, mixed> $side
     * @param array<string, Team>  $teams
     *
     * @return array{label: string, detail: string, time: list<array{0: string, 1: bool}>}
     */
    private function matchBlock(array $side, array $teams, CoachGender $gender): array
    {
        $role = $side['role'] ?? null;
        $label = $this->teamName($teams, $side);
        if (\is_string($role)) {
            $label .= \sprintf(' (%s)', 'PLAYER' === $role ? $this->playerWord($gender) : 'coach');
        }

        $details = [];
        $away = 'AWAY' === ($side['homeAway'] ?? null);
        if ($away) {
            $place = $side['opponentPlace'] ?? null;
            $details[] = \is_string($place) && '' !== trim($place) ? 'extérieur à ' . $place : 'extérieur (lieu inconnu)';
        } else {
            $details[] = 'domicile';
        }
        $opponent = $this->opponent($side);
        if ('' !== $opponent) {
            $details[] = $opponent;
        }

        $kickoff = $this->kickoff($side);
        $duration = \is_int($side['matchDurationMinutes'] ?? null) ? $side['matchDurationMinutes'] : null;
        $kickoffSegment = $this->kickoffSegment($side);
        if (!$away) {
            $time = null !== $kickoff && null !== $duration
                ? [$kickoffSegment, [' → ' . $this->addMinutes($kickoff, $duration), false]]
                : [$kickoffSegment];
        } else {
            $travel = \is_int($side['travelOneWayMinutes'] ?? null) ? $side['travelOneWayMinutes'] : null;
            $time = null !== $kickoff && null !== $travel
                ? [['départ ' . $this->addMinutes($kickoff, -$travel) . ' · ', false], $kickoffSegment, [' · retour ' . $this->addMinutes($kickoff, ($duration ?? 0) + $travel), false]]
                : [$kickoffSegment, [' · trajet inconnu', false]];
        }

        return ['label' => $label, 'detail' => implode(' · ', $details), 'time' => $this->mergeSegments($time)];
    }

    /**
     * Fusionne les segments voisins de même graisse (« 15:30 » + « → 17:25 » = un seul texte).
     *
     * @param list<array{0: string, 1: bool}> $segments
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function mergeSegments(array $segments): array
    {
        $out = [];
        foreach ($segments as [$text, $bold]) {
            $last = \count($out) - 1;
            if ($last >= 0 && $out[$last][1] === $bold) {
                $out[$last][0] .= $text;

                continue;
            }
            $out[] = [$text, $bold];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $side
     *
     * @return array{0: string, 1: bool} « 17:00 », ou « 17:00 (heure estimée) » en gras
     */
    private function kickoffSegment(array $side): array
    {
        $kickoff = $this->kickoff($side) ?? '';
        $estimated = true === ($side['estimatedKickoff'] ?? false) && !\is_string($side['kickoffTime'] ?? null);

        return $estimated ? [$kickoff . ' (heure estimée)', true] : [$kickoff, false];
    }

    /** @param array<string, mixed> $conflict */
    private function overlapLine(array $conflict): string
    {
        $from = $this->wallClock($conflict['start'] ?? null);
        $to = $this->wallClock($conflict['end'] ?? null);
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable) {
            return '';
        }
        $minutes = (int) (($to->getTimestamp() - $from->getTimestamp()) / 60);
        $range = $from->format('Y-m-d') === $to->format('Y-m-d')
            ? \sprintf('%s → %s', $from->format('H:i'), $to->format('H:i'))
            : \sprintf('%s %s → %s %s', $this->dayDate($from->format('Y-m-d'), true), $from->format('H:i'), $this->dayDate($to->format('Y-m-d'), true), $to->format('H:i'));

        return \sprintf('Chevauchement %s (%s)', $range, $this->duration($minutes));
    }

    /** Une borne servie par le radar, au format mural {@see ConflictMoments::WALL_CLOCK_FORMAT}. */
    private function wallClock(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        $moment = DateTimeImmutable::createFromFormat('!' . ConflictMoments::WALL_CLOCK_FORMAT, $value);

        return false === $moment ? null : $moment;
    }

    /**
     * @param array{family: string, word: string, color: string, ink: string, wash: string, sub: string, blocks: list<array{label: string, detail: string, time: list<array{0: string, 1: bool}>}>, line: list<array{0: string, 1: bool}>, overlap: string, mark: string} $tile
     */
    private function textTile(array $tile): string
    {
        // La marque (« Nouveau » / « Modifié ») FINIT la ligne-titre : son début reste intact.
        $lines = [\sprintf('%s — %s · %s', $tile['family'], $tile['sub'], $tile['word']) . ('' === $tile['mark'] ? '' : ' · ' . $tile['mark'])];
        foreach ($tile['blocks'] as $block) {
            $lines[] = implode(' · ', array_filter([$block['label'], $block['detail'], $this->plain($block['time'])], static fn (string $part): bool => '' !== $part));
        }
        if ([] !== $tile['line']) {
            $lines[] = $this->plain($tile['line']);
        }
        if ('' !== $tile['overlap']) {
            $lines[] = $tile['overlap'];
        }

        return implode("\n", $lines);
    }

    /**
     * La tuile HTML de la référence validée : filet de couleur, titre de famille en gras et pastille
     * de gravité, sous-ligne, blocs de côté, pied de chevauchement. Styles inline, couleurs en dur ; police
     * courte redéclarée par cellule (Outlook de bureau ne l'hérite pas des tables imbriquées).
     *
     * @param array{family: string, word: string, color: string, ink: string, wash: string, sub: string, blocks: list<array{label: string, detail: string, time: list<array{0: string, 1: bool}>}>, line: list<array{0: string, 1: bool}>, overlap: string, mark: string} $tile
     */
    private function htmlTile(array $tile): string
    {
        // Seconde pastille, neutre, à côté de la gravité : « Nouveau » / « Modifié ».
        $mark = '' === $tile['mark']
            ? ''
            : '<span style="display:inline-block;white-space:nowrap;margin-left:4px;font-size:12px;font-weight:700;color:#1a1a1a;background-color:#f3f4f6;border:1px solid #d1d5db;border-radius:10px;padding:2px 8px;">' . $this->h($tile['mark']) . '</span>';
        $rows = '';
        foreach ($tile['blocks'] as $block) {
            $detail = '' === $block['detail'] ? '' : '<span style="font-weight:400;color:#4b5563;"> · ' . $this->h($block['detail']) . '</span>';
            $rows .= '<tr><td style="' . self::FONT . 'padding:8px 0 0 0;font-size:14px;line-height:1.45;color:#1a1a1a;">'
                . '<div style="font-weight:700;">' . $this->h($block['label']) . $detail . '</div>'
                . '<div>' . $this->html($block['time']) . '</div></td></tr>';
        }
        if ([] !== $tile['line']) {
            $rows .= '<tr><td style="' . self::FONT . 'padding:8px 0 0 0;font-size:14px;line-height:1.45;color:#1a1a1a;">' . $this->html($tile['line']) . '</td></tr>';
        }
        if ('' !== $tile['overlap']) {
            $rows .= '<tr><td style="' . self::FONT . 'padding:8px 0 0 0;font-size:13px;font-weight:700;color:' . $tile['ink'] . ';">' . $this->h($tile['overlap']) . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px 0;"><tr>'
            . '<td width="5" style="width:5px;background-color:' . $tile['color'] . ';font-size:0;line-height:0;">&nbsp;</td>'
            . '<td style="' . self::FONT . 'padding:12px 16px;background-color:#ffffff;border-top:1px solid #e5e5e5;border-right:1px solid #e5e5e5;border-bottom:1px solid #e5e5e5;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="' . self::FONT . 'font-size:14px;font-weight:700;color:#1a1a1a;">' . $this->h($tile['family']) . '</td>'
            . '<td align="right" style="' . self::FONT . 'padding-left:8px;"><span style="display:inline-block;white-space:nowrap;font-size:12px;font-weight:700;color:' . $tile['ink'] . ';background-color:' . $tile['wash'] . ';border:1px solid ' . $tile['color'] . ';border-radius:10px;padding:2px 8px;">' . $this->h($tile['word']) . '</span>' . $mark . '</td></tr></table>'
            . '<div style="' . self::FONT . 'font-size:13px;color:#4b5563;margin-top:2px;">' . $this->h($tile['sub']) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
            . '</td></tr></table>';
    }

    /** @param list<array{0: string, 1: bool}> $segments */
    private function plain(array $segments): string
    {
        return implode('', array_column($segments, 0));
    }

    /** @param list<array{0: string, 1: bool}> $segments */
    private function html(array $segments): string
    {
        $out = '';
        foreach ($segments as [$text, $bold]) {
            $out .= $bold ? '<b>' . $this->h($text) . '</b>' : $this->h($text);
        }

        return $out;
    }

    private function paragraph(string $text): string
    {
        return '<p style="margin:0 0 12px 0;color:#1a1a1a;">' . $this->h($text) . '</p>';
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $conflict le moment de tri, au format mural servi (« Y-m-d\\TH:i:s ») */
    private function moment(array $conflict): string
    {
        if (\is_string($conflict['start'] ?? null)) {
            return $conflict['start'];
        }
        $fixture = $this->side($conflict, 'fixture');

        return \sprintf('%sT%s:00', (string) ($fixture['matchDate'] ?? ''), $this->kickoff($fixture) ?? '00:00');
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
            CoachGender::MALE, CoachGender::UNSPECIFIED => 'joueur',
        };
    }
}
