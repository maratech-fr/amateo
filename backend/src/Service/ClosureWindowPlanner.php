<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Enum\SchedulePlanType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Le PLANIFICATEUR (lecture pure) d'une modification de fenêtre d'une FERMETURE — prolonger ou
 * raccourcir, par-delà les vacances (fondateur 2026-10-27, axe planning lifecycle). Patron
 * {@see SplitMotherRedatePlanner} : on annonce (aperçu + token), le contrôleur confirme.
 *
 * Le PASSÉ NE BOUGE PAS : le plancher ({@see self::minEndDate}) interdit de raccourcir une fenêtre
 * déjà commencée sous aujourd'hui, et le début reste figé.
 *
 * Les SEGMENTS de la nouvelle fenêtre = [newStart, newEnd] MOINS les fenêtres de vacances (une
 * pause dans la fermeture), chaque run restant découpé en semaines par le miroir {@see
 * WeekSegmentationRule} CLAMPÉ au run (une semaine entamée par le trou de vacances ou le bout de
 * fenêtre donne un segment partiel). Le PREMIER segment = le « milieu » que le plan existant
 * conserve (convert, version pointée intacte) ; les autres naissent en COPIE validée du milieu
 * ({@see PeriodPlanSiblingCopier}) — ou VIDE si le milieu n'est pas pointé. Une seule fenêtre
 * résultante = le plan « d'un bloc » rétrécit/glisse sans se découper (simpleRedate).
 *
 * READ-ONLY : aucune écriture. L'aperçu (contrôleur) et l'apply ({@see ClosureWindowApplier})
 * consomment le MÊME {@see ClosureWindowPlan} — l'aperçu == l'appliqué.
 */
final class ClosureWindowPlanner
{
    public const string KIND_UNCHANGED = 'unchanged';

    public const string KIND_CONVERT = 'convert';

    public const string KIND_BIRTH_COPY = 'birth_copy';

    public const string KIND_BIRTH_EMPTY = 'birth_empty';

    public const string KIND_SHIFT = 'shift';

    public const string KIND_VANISH = 'vanish';

    public const string KIND_HOLIDAY_PAUSE = 'holiday_pause';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClosureSegmentation $closureSegmentation,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ClockInterface $clock,
        private readonly SeasonResolver $seasonResolver,
    ) {}

    /**
     * Le plan d'effets de la fenêtre [`$newStart`, `$newEnd`] pour la fermeture que porte `$plan`.
     */
    public function plan(SchedulePlan $plan, DateTimeImmutable $newStart, DateTimeImmutable $newEnd): ClosureWindowPlan
    {
        $clubId = (string) $plan->getClubId();
        $seasonId = $plan->getSeasonId();
        $rootEntryId = $this->rootEntryId($plan);
        $oldStart = $plan->getStartDate()->format('Y-m-d');
        $oldEnd = $plan->getEndDate()->format('Y-m-d');
        $newStartIso = $newStart->format('Y-m-d');
        $newEndIso = $newEnd->format('Y-m-d');

        [$seasonStart, $seasonEnd] = $this->seasonWindow($seasonId);
        $holidayWindows = $this->overlappingHolidayWindows($clubId, $seasonId, $newStartIso, $newEndIso, $seasonStart, $seasonEnd);

        $runs = $this->subtractWindows($newStartIso, $newEndIso, $holidayWindows);
        $segments = [];
        foreach ($runs as $run) {
            foreach ($this->segmentsOfRun($run[0], $run[1]) as $segment) {
                $segments[] = $segment;
            }
        }

        $pointed = null !== $this->schedulePlanProvisioner->chosenScheduleIdOfPlan($plan->getId());
        $today = $this->clock->now()->format('Y-m-d');

        /** @var list<array{kind: string, startDate: string, endDate: string, label: string}> $effects */
        $effects = [];
        $convertSegment = null;
        /** @var list<array{startDate: string, endDate: string}> $copySegments */
        $copySegments = [];
        $simpleRedate = \count($segments) <= 1;

        if ($simpleRedate) {
            // Le plan « d'un bloc » rétrécit/glisse : une seule fenêtre résultante, aucun découpage.
            $window = $segments[0] ?? ['startDate' => $newStartIso, 'endDate' => $newEndIso];
            if ($oldStart === $window['startDate'] && $oldEnd === $window['endDate']) {
                $effects[] = $this->effect(self::KIND_UNCHANGED, $window['startDate'], $window['endDate'], \sprintf('%s : rien ne change.', $this->label($window['startDate'], $window['endDate'])));
            } else {
                $kind = $window['endDate'] < $oldEnd ? self::KIND_VANISH : self::KIND_SHIFT;
                $effects[] = $this->effect($kind, $window['startDate'], $window['endDate'], \sprintf(
                    '%s remplace %s — son planning est conservé.',
                    $this->label($window['startDate'], $window['endDate']),
                    $this->label($oldStart, $oldEnd),
                ));
            }
        } else {
            // Plusieurs runs : le PREMIER segment conserve le plan (convert), les autres naissent en copie.
            $convertSegment = ['startDate' => $segments[0]['startDate'], 'endDate' => $segments[0]['endDate']];
            $effects[] = $this->effect(self::KIND_CONVERT, $convertSegment['startDate'], $convertSegment['endDate'], \sprintf(
                '%s : le planning déjà travaillé est conservé.',
                $this->label($convertSegment['startDate'], $convertSegment['endDate']),
            ));
            foreach (\array_slice($segments, 1) as $segment) {
                $copySegments[] = ['startDate' => $segment['startDate'], 'endDate' => $segment['endDate']];
                $kind = $pointed ? self::KIND_BIRTH_COPY : self::KIND_BIRTH_EMPTY;
                $label = $pointed
                    ? \sprintf('%s : une copie validée du planning est créée.', $this->label($segment['startDate'], $segment['endDate']))
                    : \sprintf('%s : un planning vierge est créé, à générer.', $this->label($segment['startDate'], $segment['endDate']));
                $effects[] = $this->effect($kind, $segment['startDate'], $segment['endDate'], $label);
            }
            // La fermeture PAUSE pendant les vacances (elle ne les couvre JAMAIS, plan de vacances ou
            // non). UNE ligne par période de vacances : les fenêtres qui se recoupent OU se touchent
            // (entrée HOLIDAY ∪ feed scolaire ∪ semaines filles) fusionnent, bornes clampées à la
            // nouvelle fenêtre, libellé NOMMÉ et promesse honnête (cf. {@see self::holidayPauses}).
            foreach ($this->holidayPauses($clubId, $seasonId, $seasonStart, $seasonEnd, $newStartIso, $newEndIso) as $pause) {
                $effects[] = $this->effect(self::KIND_HOLIDAY_PAUSE, $pause['startDate'], $pause['endDate'], $pause['label']);
            }
        }

        usort($effects, static fn (array $a, array $b): int => $a['startDate'] <=> $b['startDate']);

        $futurePlansWarning = false;
        foreach ($copySegments as $segment) {
            if ($segment['startDate'] > $today) {
                $futurePlansWarning = true;

                break;
            }
        }

        $summary = $this->summary($simpleRedate, \count($copySegments), [] !== $holidayWindows);
        $token = $this->token($plan->getId(), $oldStart, $oldEnd, $newStartIso, $newEndIso, $segments, $clubId, $seasonId);

        return new ClosureWindowPlan(
            effects: $effects,
            summary: $summary,
            futurePlansWarning: $futurePlansWarning,
            token: $token,
            simpleRedate: $simpleRedate,
            convertSegment: $convertSegment,
            copySegments: $copySegments,
            rootEntryId: $rootEntryId,
            oldStart: $oldStart,
            oldEnd: $oldEnd,
            newStart: $newStartIso,
            newEnd: $newEndIso,
        );
    }

    /**
     * Le PLANCHER « le passé ne bouge pas » : on ne raccourcit jamais la fin d'une fenêtre sous
     * aujourd'hui ni sous son propre début. Calculé SERVEUR via l'horloge (jamais par le front).
     */
    public function minEndDate(SchedulePlan $plan): string
    {
        $today = $this->clock->now()->format('Y-m-d');
        $start = $plan->getStartDate()->format('Y-m-d');

        return max($today, $start);
    }

    /**
     * La fenêtre demandée viole-t-elle le plancher « le passé ne bouge pas » ? Message FR si oui :
     * une fermeture DÉJÀ COMMENCÉE a son début figé, et on ne raccourcit jamais la fin sous le
     * plancher (aujourd'hui / le début). null si la fenêtre est admissible.
     */
    public function floorViolation(SchedulePlan $plan, DateTimeImmutable $newStart, DateTimeImmutable $newEnd): ?string
    {
        $today = $this->clock->now()->format('Y-m-d');
        $oldStart = $plan->getStartDate()->format('Y-m-d');
        if ($oldStart <= $today) {
            // Fenêtre déjà commencée : son début est FIGÉ sur l'ancien (le passé reste joué).
            if ($newStart->format('Y-m-d') !== $oldStart) {
                return 'Cette fermeture a commencé : son début ne bouge plus.';
            }
        } elseif ($newStart->format('Y-m-d') < $today) {
            // Fenêtre future : son début peut bouger, mais jamais dans le passé.
            return 'Une fermeture ne commence pas dans le passé.';
        }
        if ($newEnd->format('Y-m-d') < $this->minEndDate($plan)) {
            return 'On ne raccourcit jamais une fermeture sous le plancher du passé.';
        }

        return null;
    }

    /** La fermeture est-elle éditable, et sinon pourquoi ? (génération en vol / saison archivée). */
    public function windowEditable(SchedulePlan $plan): bool
    {
        return null === $this->windowLockedReason($plan);
    }

    public function windowLockedReason(SchedulePlan $plan): ?string
    {
        if (SchedulePlanType::CLOSURE !== $plan->getType()) {
            return 'Seule une fermeture modifie sa fenêtre ainsi.';
        }
        // « Saison archivée » a UNE seule définition : le mécanisme GLOBAL par l'ANNÉE
        // ({@see SeasonResolver::isReadonly}, clock-aware), jamais le statut. Le même verrou refuse
        // l'écriture côté serveur via {@see SeasonReadonlyGuardListener} ; ici on le reflète pour le
        // front (masquer l'édition d'une fermeture d'une saison passée).
        if ($this->seasonReadonly($plan->getSeasonId())) {
            return 'Cette saison est archivée — elle est en lecture seule.';
        }
        if ($this->hasInFlightGeneration($this->rootEntryId($plan))) {
            return 'Un planning est en cours de génération.';
        }

        return null;
    }

    /** L'identité de la FERMETURE : le titre de la RACINE (partagé par tous ses segments). */
    public function closureTitle(SchedulePlan $plan): string
    {
        $rootId = $this->rootEntryId($plan);
        $title = $this->entityManager->getConnection()->fetchOne(
            'SELECT title FROM calendar_entry WHERE id = :id',
            ['id' => $rootId],
        );

        return \is_string($title) ? $title : $plan->getName();
    }

    /**
     * Les segments d'un run [rs, re] : semaines calendaires clampées AU RUN, découpées par le
     * miroir {@see WeekSegmentationRule} (début·milieu·fin). Clamp au run → une semaine entamée par
     * un trou de vacances ou le bout de fenêtre donne un segment partiel.
     *
     * @return list<array{startDate: string, endDate: string, kind: string}>
     */
    private function segmentsOfRun(string $rs, string $re): array
    {
        $weeks = [];
        $monday = new DateTimeImmutable($rs)->modify(\sprintf('-%d days', (int) new DateTimeImmutable($rs)->format('N') - 1));
        while ($monday->format('Y-m-d') <= $re) {
            $mondayIso = $monday->format('Y-m-d');
            $sundayIso = $monday->modify('+6 days')->format('Y-m-d');
            $start = max($mondayIso, $rs);
            $end = min($sundayIso, $re);
            if ($start <= $end) {
                $weeks[] = ['monday' => $mondayIso, 'startDate' => $start, 'endDate' => $end];
            }
            $monday = $monday->modify('+7 days');
        }

        return array_map(
            static fn (array $s): array => ['startDate' => $s['startDate'], 'endDate' => $s['endDate'], 'kind' => $s['kind']],
            WeekSegmentationRule::segments($weeks, $rs, $re),
        );
    }

    /**
     * [start, end] MOINS les fenêtres (trouées), en Y-m-d → liste de runs contigus.
     *
     * @param list<array{0: string, 1: string}> $holes
     *
     * @return list<array{0: string, 1: string}>
     */
    private function subtractWindows(string $start, string $end, array $holes): array
    {
        $clamped = [];
        foreach ($holes as $hole) {
            $hs = max($hole[0], $start);
            $he = min($hole[1], $end);
            if ($hs <= $he) {
                $clamped[] = [$hs, $he];
            }
        }
        usort($clamped, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $runs = [];
        $cursor = $start;
        foreach ($clamped as $hole) {
            if ($hole[0] > $cursor) {
                $runs[] = [$cursor, $this->addDays($hole[0], -1)];
            }
            $cursor = max($cursor, $this->addDays($hole[1], 1));
        }
        if ($cursor <= $end) {
            $runs[] = [$cursor, $end];
        }

        return $runs;
    }

    /**
     * Les fenêtres de vacances recoupant [start, end] (entrées HOLIDAY + feed scolaire de la zone).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function overlappingHolidayWindows(string $clubId, string $seasonId, string $start, string $end, ?string $seasonStart, ?string $seasonEnd): array
    {
        if (null === $seasonStart || null === $seasonEnd) {
            return [];
        }
        $windows = [];
        foreach ($this->closureSegmentation->holidayWindowsForSeason($clubId, $seasonId, $seasonStart, $seasonEnd) as $window) {
            if ($window[0] <= $end && $window[1] >= $start) {
                $windows[] = $window;
            }
        }

        return $windows;
    }

    /**
     * Les PAUSES de vacances à afficher : EXACTEMENT UNE ligne par période de vacances. Les fenêtres
     * nommées ({@see ClosureSegmentation::namedHolidayWindowsForSeason}) qui se recoupent OU se
     * touchent (entrée HOLIDAY ∪ feed scolaire ∪ semaines filles d'une même vacance) fusionnent en
     * une seule pause, bornes clampées à [`$newStart`, `$newEnd`]. Le NOM vient du feed scolaire si
     * présent, sinon du titre de l'entrée HOLIDAY (suffixe « — du … » retiré). La phrase promet « vos
     * plannings de vacances restent tels quels. » ssi au moins un PLAN de vacances (HOLIDAY) recoupe
     * la pause — sinon « les vacances gardent leur propre fonctionnement. » (jamais la promesse d'un
     * planning de reprise qui n'existe pas).
     *
     * @return list<array{startDate: string, endDate: string, label: string}>
     */
    private function holidayPauses(string $clubId, string $seasonId, ?string $seasonStart, ?string $seasonEnd, string $newStart, string $newEnd): array
    {
        if (null === $seasonStart || null === $seasonEnd) {
            return [];
        }
        $clamped = [];
        foreach ($this->closureSegmentation->namedHolidayWindowsForSeason($clubId, $seasonId, $seasonStart, $seasonEnd) as $window) {
            $start = max($window['start'], $newStart);
            $end = min($window['end'], $newEnd);
            if ($start > $end) {
                continue;
            }
            $clamped[] = ['start' => $start, 'end' => $end, 'label' => $window['label'], 'source' => $window['source']];
        }
        if ([] === $clamped) {
            return [];
        }
        usort($clamped, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        // Fusionner les fenêtres qui se recoupent OU se touchent (jointives : trou d'un jour toléré).
        /** @var list<array{start: string, end: string, windows: list<array{start: string, end: string, label: string, source: string}>}> $groups */
        $groups = [];
        /** @var array{start: string, end: string, windows: list<array{start: string, end: string, label: string, source: string}>}|null $current */
        $current = null;
        foreach ($clamped as $window) {
            if (null !== $current && $window['start'] <= $this->addDays($current['end'], 1)) {
                $current['end'] = max($current['end'], $window['end']);
                $current['windows'][] = $window;

                continue;
            }
            if (null !== $current) {
                $groups[] = $current;
            }
            $current = ['start' => $window['start'], 'end' => $window['end'], 'windows' => [$window]];
        }
        // $clamped est non vide (retour anticipé plus haut) → la boucle tourne, $current est posé.
        $groups[] = $current;

        $holidayPlanWindows = $this->holidayPlanWindows($clubId, $seasonId);
        $pauses = [];
        foreach ($groups as $group) {
            $phrase = $this->anyOverlap($holidayPlanWindows, $group['start'], $group['end'])
                ? 'vos plannings de vacances restent tels quels.'
                : 'les vacances gardent leur propre fonctionnement.';
            $pauses[] = [
                'startDate' => $group['start'],
                'endDate' => $group['end'],
                'label' => \sprintf('Pause — %s (%s) : %s', $this->holidayName($group['windows']), $this->compactRange($group['start'], $group['end']), $phrase),
            ];
        }

        return $pauses;
    }

    /**
     * Le NOM d'une période de vacances fusionnée : le libellé du feed scolaire si l'une des fenêtres
     * en vient, sinon le titre de l'entrée HOLIDAY débarrassé de son suffixe « — du … ».
     *
     * @param list<array{start: string, end: string, label: string, source: string}> $windows
     */
    private function holidayName(array $windows): string
    {
        foreach ($windows as $window) {
            if ('feed' === $window['source'] && '' !== $window['label']) {
                return $window['label'];
            }
        }
        foreach ($windows as $window) {
            if ('' !== $window['label']) {
                return (string) preg_replace('/\s*—\s*du\b.*$/u', '', $window['label']);
            }
        }

        return 'Vacances';
    }

    /**
     * Les fenêtres des PLANS de vacances (HOLIDAY) du club+saison — décident si une pause peut
     * promettre « vos plannings de vacances restent tels quels. ».
     *
     * @return list<array{0: string, 1: string}>
     */
    private function holidayPlanWindows(string $clubId, string $seasonId): array
    {
        /** @var list<array{start_date: string, end_date: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT start_date, end_date FROM schedule_plan WHERE club_id = :club AND season_id = :season AND type = \'HOLIDAY\'',
            ['club' => $clubId, 'season' => $seasonId],
        );

        return array_map(
            static fn (array $row): array => [mb_substr((string) $row['start_date'], 0, 10), mb_substr((string) $row['end_date'], 0, 10)],
            $rows,
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $windows
     */
    private function anyOverlap(array $windows, string $start, string $end): bool
    {
        foreach ($windows as $window) {
            if ($window[0] <= $end && $window[1] >= $start) {
                return true;
            }
        }

        return false;
    }

    /** Bornes compactes « à la française » : « 16 → 31 oct. », « 28 déc. → 3 janv. », « 31 août ». */
    private function compactRange(string $start, string $end): string
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        if ($start === $end) {
            return \sprintf('%s %s', $this->frDay($startDate), $this->frMonth((int) $startDate->format('n')));
        }
        if ($startDate->format('Y-m') === $endDate->format('Y-m')) {
            return \sprintf('%s → %s %s', $this->frDay($startDate), $this->frDay($endDate), $this->frMonth((int) $endDate->format('n')));
        }

        return \sprintf('%s %s → %s %s', $this->frDay($startDate), $this->frMonth((int) $startDate->format('n')), $this->frDay($endDate), $this->frMonth((int) $endDate->format('n')));
    }

    private function frDay(DateTimeImmutable $d): string
    {
        return '1' === $d->format('j') ? '1er' : $d->format('j');
    }

    private function frMonth(int $month): string
    {
        return [1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.'][$month] ?? '';
    }

    /**
     * Le token canonique de l'état lu : plan + ancienne/nouvelle fenêtre + segments + une empreinte
     * de TOUTES les entrées closure/holiday du club+saison et de leurs plans (un segment ajouté, une
     * reprise de vacances posée, un plan validé entre l'aperçu et le PUT le changent → 409).
     *
     * @param list<array{startDate: string, endDate: string, kind: string}> $segments
     */
    private function token(string $planId, string $oldStart, string $oldEnd, string $newStart, string $newEnd, array $segments, string $clubId, string $seasonId): string
    {
        $parts = [$planId, $oldStart, $oldEnd, $newStart, $newEnd];
        foreach ($segments as $segment) {
            $parts[] = implode(',', [$segment['startDate'], $segment['endDate'], $segment['kind']]);
        }
        /** @var list<array{id: string, start_date: string, end_date: string, parent_entry_id: ?string, period_type: ?string, plan_id: ?string, chosen_schedule_id: ?string, max_updated: ?string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT e.id, e.start_date, e.end_date, e.parent_entry_id, e.period_type, p.id AS plan_id, p.chosen_schedule_id, '
            . '(SELECT MAX(s.updated_at) FROM schedule s WHERE s.schedule_plan_id = p.id) AS max_updated '
            . 'FROM calendar_entry e LEFT JOIN schedule_plan p ON p.calendar_entry_id = e.id '
            . 'WHERE e.club_id = :club AND e.season_id = :season AND e.period_type IN (\'closure\', \'holiday\') '
            . 'ORDER BY e.id ASC',
            ['club' => $clubId, 'season' => $seasonId],
        );
        foreach ($rows as $row) {
            $parts[] = implode(',', [
                (string) $row['id'],
                mb_substr((string) $row['start_date'], 0, 10),
                mb_substr((string) $row['end_date'], 0, 10),
                (string) ($row['parent_entry_id'] ?? ''),
                (string) ($row['period_type'] ?? ''),
                (string) ($row['plan_id'] ?? ''),
                (string) ($row['chosen_schedule_id'] ?? ''),
                (string) ($row['max_updated'] ?? ''),
            ]);
        }

        return hash('sha256', implode('|', $parts));
    }

    private function summary(bool $simpleRedate, int $copyCount, bool $hasHoliday): string
    {
        if ($simpleRedate) {
            return 'La fermeture change de dates ; son planning est conservé.';
        }
        // copyCount > 0 ici ⇒ total = copyCount + 1 ≥ 2, toujours pluriel.
        $planningWord = $copyCount > 0 ? \sprintf('%d plannings', $copyCount + 1) : '1 planning';
        $pause = $hasHoliday ? ', avec une pause pendant les vacances' : '';

        return \sprintf('La fermeture couvre %s%s ; le planning déjà travaillé est conservé.', $planningWord, $pause);
    }

    /** @return array{kind: string, startDate: string, endDate: string, label: string} */
    private function effect(string $kind, string $startDate, string $endDate, string $label): array
    {
        return ['kind' => $kind, 'startDate' => $startDate, 'endDate' => $endDate, 'label' => $label];
    }

    private function label(string $start, string $end): string
    {
        return $this->schedulePlanProvisioner->windowLabel(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }

    /** L'entrée RACINE de la fermeture que porte le plan : sa propre entrée si racine, sinon sa mère. */
    private function rootEntryId(SchedulePlan $plan): string
    {
        $entryId = $plan->getCalendarEntryId();
        if (null === $entryId) {
            return '';
        }
        $parent = $this->entityManager->getConnection()->fetchOne(
            'SELECT parent_entry_id FROM calendar_entry WHERE id = :id',
            ['id' => $entryId],
        );

        return \is_string($parent) ? $parent : $entryId;
    }

    private function hasInFlightGeneration(string $rootEntryId): bool
    {
        if ('' === $rootEntryId) {
            return false;
        }

        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM schedule s JOIN schedule_plan p ON p.id = s.schedule_plan_id '
            . 'JOIN calendar_entry e ON e.id = p.calendar_entry_id '
            . 'WHERE (e.id = :root OR e.parent_entry_id = :root) AND s.status IN (\'PENDING\', \'GENERATING\') LIMIT 1',
            ['root' => $rootEntryId],
        );
    }

    /**
     * La saison est-elle en LECTURE SEULE ? Dérivé par l'ANNÉE via le mécanisme GLOBAL
     * ({@see SeasonResolver::isReadonly}), jamais du statut. Jamais un oracle d'existence : une
     * saison introuvable (ou cachée par la RLS) n'est pas « archivée ».
     */
    private function seasonReadonly(string $seasonId): bool
    {
        $season = $this->entityManager->find(Season::class, $seasonId);

        return $season instanceof Season && $this->seasonResolver->isReadonly($season);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function seasonWindow(string $seasonId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT start_date, end_date FROM season WHERE id = :sid',
            ['sid' => $seasonId],
        );
        if (false === $row) {
            return [null, null];
        }

        return [mb_substr((string) $row['start_date'], 0, 10), mb_substr((string) $row['end_date'], 0, 10)];
    }

    private function addDays(string $iso, int $n): string
    {
        return new DateTimeImmutable($iso)->modify(\sprintf('%+d days', $n))->format('Y-m-d');
    }
}
