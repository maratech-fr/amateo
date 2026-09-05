<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D3 v2 (P4-174, décision fondateur 2026-09-05) — LA MAISON UNIQUE du re-datage d'une
 * indisponibilité DÉCOUPÉE : on annonce, on confirme, on applique (jamais de refus, jamais de
 * destruction silencieuse).
 *
 * Une mère CLOSURE découpée en segments début·milieu·fin (chaque enfant = un segment, pouvant
 * porter son plan) se re-date : la nouvelle fenêtre offre de NOUVEAUX segments ({@see
 * ClosureSegmentation::fullSegments}, mêmes trous — vacances lun→ven, fenêtres d'autres familles).
 * On APPARIE les anciens enfants aux nouveaux segments, le RÔLE primant :
 *  - start ↔ start, end ↔ end (au plus un de chaque) ;
 *  - milieux (N runs de chaque côté) par chevauchement de lundis DÉCROISSANT, égalité → chronologique.
 *
 * Verdicts :
 *  - apparié, fenêtre IDENTIQUE → `keep` (rien ne bouge) ;
 *  - apparié, fenêtre DIFFÉRENTE → `shift` (GLISSEMENT : l'enfant et son plan suivent, versions et
 *    transcription conservées, marqué à régénérer) ;
 *  - ancien NON apparié dont ≥ 1 lundi est couvert par un nouveau segment → `absorb` (ABSORPTION :
 *    enfant supprimé en cascade, la ligne nomme le plan absorbant) ;
 *  - sinon → `vanish` (DISPARITION : enfant supprimé en cascade) ;
 *  - nouveau segment SANS enfant apparié → `birth` (NAISSANCE : enfant + plan neufs VIDES).
 *
 * Et « les vacances ont la main » : une partie de la nouvelle fenêtre sous vacances scolaires ne
 * donne AUCUN segment (déjà trouée par fullSegments) — pas de 422 ; l'aperçu ajoute une ligne
 * `holiday_takes_over` par plan de vacances (entrée HOLIDAY à plan) que la nouvelle fenêtre recoupe.
 *
 * READ-ONLY : ce service ne fait AUCUNE écriture. Il rend un {@see SplitMotherRedatePlan} (aperçu +
 * token + instructions d'application) consommé À L'IDENTIQUE par l'aperçu (contrôleur) et par
 * l'apply (processor). Le découpage lui-même est délégué à {@see ClosureSegmentation} (lecture seule).
 */
final class SplitMotherRedatePlanner
{
    public const string KEEP = 'keep';

    public const string SHIFT = 'shift';

    public const string ABSORB = 'absorb';

    public const string VANISH = 'vanish';

    public const string BIRTH = 'birth';

    public const string HOLIDAY_TAKES_OVER = 'holiday_takes_over';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClosureSegmentation $closureSegmentation,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Le plan d'effets du re-datage de la mère découpée `$mother` vers [`$newStart`, `$newEnd`].
     *
     * @throws UnprocessableEntityHttpException fenêtre hors saison, ou enfant orphelin de tout
     *                                          segment de l'ANCIENNE fenêtre (cas inexistant, garde défensive)
     */
    public function plan(CalendarEntry $mother, DateTimeImmutable $newStart, DateTimeImmutable $newEnd): SplitMotherRedatePlan
    {
        $clubId = $mother->getClubId();
        $seasonId = $mother->getSeasonId();
        $rootId = $mother->getId();
        $oldStart = $mother->getStartDate()->format('Y-m-d');
        $oldEnd = $mother->getEndDate()->format('Y-m-d');
        $newStartIso = $newStart->format('Y-m-d');
        $newEndIso = $newEnd->format('Y-m-d');

        [$seasonStart, $seasonEnd] = $this->seasonWindow($seasonId);

        // Rôle de chaque ancien enfant : le segment de l'ANCIENNE fenêtre dont il épouse les bornes
        // (géométrie PLEINE ∪ actionnable — un enfant né au cockpit peut avoir la tête révolue rognée).
        $oldRoles = $this->roleByWindow($clubId, $seasonId, $rootId, $oldStart, $oldEnd, $seasonStart, $seasonEnd);
        $children = $this->children($rootId);

        $newSegments = $this->closureSegmentation->fullSegments($clubId, $seasonId, $rootId, $newStartIso, $newEndIso, $seasonStart, $seasonEnd);

        // Partition anciens enfants / nouveaux segments par rôle.
        /** @var array<'start'|'middle'|'end', list<array{id: string, start: string, end: string, mondays: list<string>}>> $oldByKind */
        $oldByKind = ['start' => [], 'middle' => [], 'end' => []];
        foreach ($children as $child) {
            $kind = $oldRoles[$child['start'] . '|' . $child['end']] ?? null;
            if (null === $kind) {
                // Un enfant qui n'épouse aucun segment de l'ancienne fenêtre : cas inexistant (les
                // données sont reset). Garde défensive NEUTRE, jamais un cas produit.
                throw new UnprocessableEntityHttpException('Cette indisponibilité découpée a une semaine qui ne correspond plus à un segment connu : rechargez la page.');
            }
            $oldByKind[$kind][] = ['id' => $child['id'], 'start' => $child['start'], 'end' => $child['end'], 'mondays' => $this->mondaysBetween($child['start'], $child['end'])];
        }

        /** @var array<'start'|'middle'|'end', list<array{start: string, end: string, mondays: list<string>}>> $newByKind */
        $newByKind = ['start' => [], 'middle' => [], 'end' => []];
        foreach ($newSegments as $segment) {
            $newByKind[$segment['kind']][] = ['start' => $segment['startDate'], 'end' => $segment['endDate'], 'mondays' => $segment['weeks']];
        }

        // ── Appariement ──────────────────────────────────────────────────────────────────────
        /** @var list<array{old: array{id: string, start: string, end: string, mondays: list<string>}, new: array{start: string, end: string, mondays: list<string>}}> $pairs */
        $pairs = [];
        $pairedOldIds = [];
        $pairedNewKeys = [];

        // start/end : au plus un de chaque, appariement direct par rôle.
        foreach (['start', 'end'] as $role) {
            if ([] !== $oldByKind[$role] && [] !== $newByKind[$role]) {
                $old = $oldByKind[$role][0];
                $new = $newByKind[$role][0];
                $pairs[] = ['old' => $old, 'new' => $new];
                $pairedOldIds[$old['id']] = true;
                $pairedNewKeys[$new['start'] . '|' . $new['end']] = true;
            }
        }

        // milieux : chevauchement de lundis DÉCROISSANT, égalité → chronologique (start croissant).
        $middlePairs = $this->pairMiddles($oldByKind['middle'], $newByKind['middle']);
        foreach ($middlePairs as $pair) {
            $pairs[] = $pair;
            $pairedOldIds[$pair['old']['id']] = true;
            $pairedNewKeys[$pair['new']['start'] . '|' . $pair['new']['end']] = true;
        }

        // ── Verdicts ─────────────────────────────────────────────────────────────────────────
        /** @var list<array{sort: string, kind: string, label: string}> $effects */
        $effects = [];
        /** @var list<array{childId: string, start: string, end: string}> $shifts */
        $shifts = [];
        /** @var list<string> $deletions */
        $deletions = [];
        /** @var list<array{start: string, end: string}> $births */
        $births = [];

        foreach ($pairs as $pair) {
            $old = $pair['old'];
            $new = $pair['new'];
            if ($old['start'] === $new['start'] && $old['end'] === $new['end']) {
                $effects[] = ['sort' => $old['start'], 'kind' => self::KEEP, 'label' => \sprintf('%s : rien ne change.', $this->segLabel($new['start'], $new['end']))];

                continue;
            }
            $shifts[] = ['childId' => $old['id'], 'start' => $new['start'], 'end' => $new['end']];
            $effects[] = ['sort' => $new['start'], 'kind' => self::SHIFT, 'label' => \sprintf(
                '%s se décale sur %s — son planning est conservé et sera à régénérer.',
                $this->segLabel($old['start'], $old['end']),
                $this->segLabel($new['start'], $new['end']),
            )];
        }

        // Anciens NON appariés : absorption si un lundi est couvert par un nouveau segment, sinon disparition.
        foreach (['start', 'middle', 'end'] as $role) {
            foreach ($oldByKind[$role] as $old) {
                if (isset($pairedOldIds[$old['id']])) {
                    continue;
                }
                $absorber = $this->coveringSegment($old['mondays'], $newSegments);
                $deletions[] = $old['id'];
                if (null !== $absorber) {
                    $effects[] = ['sort' => $old['start'], 'kind' => self::ABSORB, 'label' => \sprintf(
                        '%s rejoint le planning %s : son propre planning est supprimé.',
                        $this->segLabel($old['start'], $old['end']),
                        $this->segLabel($absorber['startDate'], $absorber['endDate']),
                    )];

                    continue;
                }
                $effects[] = ['sort' => $old['start'], 'kind' => self::VANISH, 'label' => \sprintf(
                    '%s n’est plus couvert : son planning est supprimé.',
                    $this->segLabel($old['start'], $old['end']),
                )];
            }
        }

        // Nouveaux segments SANS enfant apparié : naissance d'un enfant + plan vides.
        foreach ($newSegments as $segment) {
            if (isset($pairedNewKeys[$segment['startDate'] . '|' . $segment['endDate']])) {
                continue;
            }
            $births[] = ['start' => $segment['startDate'], 'end' => $segment['endDate']];
            $effects[] = ['sort' => $segment['startDate'], 'kind' => self::BIRTH, 'label' => \sprintf(
                '%s est ajouté : un planning vierge est créé, à générer.',
                $this->segLabel($segment['startDate'], $segment['endDate']),
            )];
        }

        // « Les vacances ont la main » : une ligne par plan de vacances (entrée HOLIDAY à plan) que
        // la nouvelle fenêtre recoupe. Le marquage « à régénérer » est posé par le listener.
        foreach ($this->holidayPlansOverlapping($clubId, $seasonId, $newStartIso, $newEndIso) as $holiday) {
            $mondayIso = $this->mondayOf($holiday['start']);
            $effects[] = ['sort' => $holiday['start'], 'kind' => self::HOLIDAY_TAKES_OVER, 'label' => \sprintf(
                'Les vacances ont la main sur %s : le plan de la %s sera à régénérer.',
                $this->segLabel($holiday['start'], $holiday['end']),
                $this->segLabel($mondayIso, $this->sundayOf($mondayIso)),
            )];
        }

        // Ordre chronologique stable (clé de tri interne retirée du contrat servi).
        usort($effects, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $served = array_map(static fn (array $e): array => ['kind' => $e['kind'], 'label' => $e['label']], $effects);

        $token = $this->token($rootId, $oldStart, $oldEnd, $newStartIso, $newEndIso, $children);

        return new SplitMotherRedatePlan($served, $token, $shifts, $deletions, $births);
    }

    /**
     * Le token canonique de l'état lu : mère + ancienne/nouvelle fenêtre + par enfant TRIÉ (id,
     * fenêtre, planId, chosenScheduleId, max(schedule.updated_at)). Un enfant ajouté/supprimé, un
     * plan validé, une version régénérée entre l'aperçu et le PUT le changent → 409 au recalcul.
     *
     * @param list<array{id: string, start: string, end: string, planId: ?string, chosenScheduleId: ?string, maxUpdatedAt: ?string}> $children
     */
    private function token(string $rootId, string $oldStart, string $oldEnd, string $newStart, string $newEnd, array $children): string
    {
        $rows = $children;
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $parts = [$rootId, $oldStart, $oldEnd, $newStart, $newEnd];
        foreach ($rows as $row) {
            $parts[] = implode(',', [
                $row['id'],
                $row['start'],
                $row['end'],
                $row['planId'] ?? '',
                $row['chosenScheduleId'] ?? '',
                $row['maxUpdatedAt'] ?? '',
            ]);
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Les enfants-segments de la mère, avec l'état qui entre dans le token.
     *
     * @return list<array{id: string, start: string, end: string, planId: ?string, chosenScheduleId: ?string, maxUpdatedAt: ?string}>
     */
    private function children(string $rootId): array
    {
        /** @var list<array{id: string, start_date: string, end_date: string, plan_id: ?string, chosen_schedule_id: ?string, max_updated: ?string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT e.id, e.start_date, e.end_date, p.id AS plan_id, p.chosen_schedule_id, '
            . '(SELECT MAX(s.updated_at) FROM schedule s WHERE s.schedule_plan_id = p.id) AS max_updated '
            . 'FROM calendar_entry e LEFT JOIN schedule_plan p ON p.calendar_entry_id = e.id '
            . 'WHERE e.parent_entry_id = :root ORDER BY e.start_date ASC',
            ['root' => $rootId],
        );

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'start' => mb_substr((string) $r['start_date'], 0, 10),
            'end' => mb_substr((string) $r['end_date'], 0, 10),
            'planId' => null === $r['plan_id'] ? null : (string) $r['plan_id'],
            'chosenScheduleId' => null === $r['chosen_schedule_id'] ? null : (string) $r['chosen_schedule_id'],
            'maxUpdatedAt' => null === $r['max_updated'] ? null : (string) $r['max_updated'],
        ], $rows);
    }

    /**
     * Le rôle (kind) de chaque segment de la fenêtre [$start, $end], keyé « start|end ». Union des
     * géométries PLEINE et actionnable : un enfant né au cockpit couvre l'actionnable (tête rognée),
     * un enfant seedé/passé la pleine — les deux sont légitimes.
     *
     * @return array<string, 'start'|'middle'|'end'>
     */
    private function roleByWindow(string $clubId, string $seasonId, string $rootId, string $start, string $end, ?string $seasonStart, ?string $seasonEnd): array
    {
        $map = [];
        foreach ([
            $this->closureSegmentation->fullSegments($clubId, $seasonId, $rootId, $start, $end, $seasonStart, $seasonEnd),
            $this->closureSegmentation->segments($clubId, $seasonId, $rootId, $start, $end, $seasonStart, $seasonEnd),
        ] as $segments) {
            foreach ($segments as $segment) {
                $map[$segment['startDate'] . '|' . $segment['endDate']] = $segment['kind'];
            }
        }

        return $map;
    }

    /**
     * Apparie milieux anciens ↔ nouveaux par chevauchement de lundis DÉCROISSANT, égalité →
     * chronologique. Greedy : on classe tous les couples possibles, on retient les meilleurs sans
     * réutiliser un ancien ni un nouveau déjà pris.
     *
     * @param list<array{id: string, start: string, end: string, mondays: list<string>}> $olds
     * @param list<array{start: string, end: string, mondays: list<string>}>             $news
     *
     * @return list<array{old: array{id: string, start: string, end: string, mondays: list<string>}, new: array{start: string, end: string, mondays: list<string>}}>
     */
    private function pairMiddles(array $olds, array $news): array
    {
        $candidates = [];
        foreach ($olds as $oi => $old) {
            foreach ($news as $ni => $new) {
                $candidates[] = [
                    'oi' => $oi,
                    'ni' => $ni,
                    'overlap' => \count(array_intersect($old['mondays'], $new['mondays'])),
                    'start' => $new['start'],
                ];
            }
        }
        // Chevauchement décroissant, puis chronologique (start du nouveau segment croissant).
        usort($candidates, static function (array $a, array $b): int {
            if ($a['overlap'] !== $b['overlap']) {
                return $b['overlap'] <=> $a['overlap'];
            }

            return $a['start'] <=> $b['start'];
        });

        $pairs = [];
        $usedOld = [];
        $usedNew = [];
        foreach ($candidates as $c) {
            // Zéro lundi commun = pas d'appariement de milieu : la fenêtre a bougé sans recouvrement,
            // l'ancien milieu DISPARAÎT et le nouveau NAÎT (jamais un glissement d'un milieu à l'autre
            // sans lien). Les rôles start/end, eux, priment sans condition de chevauchement.
            if (0 === $c['overlap'] || isset($usedOld[$c['oi']]) || isset($usedNew[$c['ni']])) {
                continue;
            }
            $usedOld[$c['oi']] = true;
            $usedNew[$c['ni']] = true;
            $pairs[] = ['old' => $olds[$c['oi']], 'new' => $news[$c['ni']]];
        }

        return $pairs;
    }

    /**
     * Le nouveau segment qui couvre le PLUS de lundis de `$mondays`, ou null si aucun n'en couvre.
     *
     * @param list<string>                                                                                       $mondays
     * @param list<array{monday: string, startDate: string, endDate: string, kind: string, weeks: list<string>}> $newSegments
     *
     * @return array{startDate: string, endDate: string}|null
     */
    private function coveringSegment(array $mondays, array $newSegments): ?array
    {
        $best = null;
        $bestOverlap = 0;
        foreach ($newSegments as $segment) {
            $overlap = \count(array_intersect($mondays, $segment['weeks']));
            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $best = ['startDate' => $segment['startDate'], 'endDate' => $segment['endDate']];
            }
        }

        return $best;
    }

    /**
     * Les entrées HOLIDAY portant un plan dont la fenêtre recoupe [$start, $end]. SQL brut :
     * season_filter épinglerait la lecture à la saison active ; RLS scope le club.
     *
     * @return list<array{start: string, end: string}>
     */
    private function holidayPlansOverlapping(string $clubId, string $seasonId, string $start, string $end): array
    {
        /** @var list<array{start_date: string, end_date: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT e.start_date, e.end_date FROM calendar_entry e '
            . 'JOIN schedule_plan p ON p.calendar_entry_id = e.id '
            . 'WHERE e.club_id = :club AND e.season_id = :season AND e.period_type = \'holiday\' '
            . 'AND e.start_date <= :end AND e.end_date >= :start ORDER BY e.start_date ASC',
            ['club' => $clubId, 'season' => $seasonId, 'start' => $start, 'end' => $end],
        );

        return array_map(static fn (array $r): array => [
            'start' => mb_substr((string) $r['start_date'], 0, 10),
            'end' => mb_substr((string) $r['end_date'], 0, 10),
        ], $rows);
    }

    /** @return array{0: ?string, 1: ?string} [seasonStart, seasonEnd] en Y-m-d, ou [null, null] si introuvable */
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

    /** Les lundis ISO des semaines couvertes par [$start, $end] (chaque semaine calendaire touchée). */
    /** @return list<string> */
    private function mondaysBetween(string $start, string $end): array
    {
        $monday = new DateTimeImmutable($this->mondayOf($start));
        $mondays = [];
        while ($monday->format('Y-m-d') <= $end) {
            $mondays[] = $monday->format('Y-m-d');
            $monday = $monday->modify('+7 days');
        }

        return $mondays;
    }

    private function mondayOf(string $iso): string
    {
        $date = new DateTimeImmutable($iso);

        return $date->modify(\sprintf('-%d days', (int) $date->format('N') - 1))->format('Y-m-d');
    }

    private function sundayOf(string $mondayIso): string
    {
        return new DateTimeImmutable($mondayIso)->modify('+6 days')->format('Y-m-d');
    }

    /** Le libellé daté d'un segment, en clair (« Semaine du … » ou « du … au … »). Aucun identifiant interne. */
    private function segLabel(string $start, string $end): string
    {
        return $this->schedulePlanProvisioner->windowLabel(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }
}
