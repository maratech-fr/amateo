<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Enum\AuditAction;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\ScheduleStatus;
use App\Enum\VenuePeriodMode;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qu'une fermeture de gymnase TOUCHE (chantier « Fermetures de gymnase », C3/C4/C5/C8) — l'aperçu
 * du formulaire AVANT « Enregistrer » et l'impact de la fermeture enregistrée (carte, radar, écran
 * du plan) disent LE MÊME CHIFFRE : un seul calcul, la fermeture interrogée écartée avec toute sa
 * famille (`$ignoredEntryId`), sinon elle se capturerait elle-même.
 *
 *  - entraînements : les séances du planning EN VIGUEUR à chaque date ({@see EffectiveScheduleResolver},
 *    capture inversée : une fermeture sans version ne capture pas, les AUTRES fermetures excluent
 *    leurs gymnases) au gymnase fermé, par équipe avec son rang ;
 *  - matchs à déplacer : à domicile, au gymnase fermé, à venir, placés — triés par date (complet,
 *    le front borne) ;
 *  - plans de période qui gouvernent ces dates (JAMAIS le socle) : leurs séances au gymnase fermé
 *    (version pointée sinon chargée, à venir, hors fériés, jour non rouvert), et l'annonce EXACTE de
 *    ce que {@see ClosurePinRemover} retirerait (même service, mode lecture) ; `regeneratedWithout`
 *    borné par la correction finale 2.
 *
 * Lecture pure, recalculée à chaque appel.
 */
final class VenueClosureImpact
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrainingCalendarContext $trainingCalendarContext,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
        private readonly ClosurePinRemover $closurePinRemover,
        private readonly PlanVenueClosures $planVenueClosures,
        private readonly ClubPublicHolidays $clubPublicHolidays,
        private readonly ClubDay $clubDay,
    ) {}

    /**
     * @param list<string> $venueIds
     *
     * @return array{
     *   venueIds: list<string>, startDate: string, endDate: string, seasonPlanChosen: bool,
     *   teams: list<array{teamId: string, teamName: string, tierLabel: string, tierWeight: int, occurrences: int}>,
     *   trainingOccurrences: int, trainingSlotCount: int,
     *   matchesToMove: list<array{fixtureId: string, teamId: string, teamName: string, opponentLabel: string, matchDate: string, kickoffTime: string|null}>,
     *   governingPlans: list<array{entryId: string, title: string, startDate: string, endDate: string, chosenScheduleId: string|null, loadedScheduleId: string|null, hasVersions: bool, sessions: list<array{teamId: string, teamName: string, date: string, venueId: string}>, toRemove: array{locks: int, reservations: list<array{teamId: string|null, teamName: string, dayOfWeek: int}>}, regeneratedWithout: bool, regeneratedWithoutWeekdays: list<int>}>,
     *   fullyGoverned: bool,
     *   closedSessions: list<array{constraintId: string, venueId: string, venueName: string, closureTitle: string, date: string, teamId: string, teamName: string}>,
     *   removedAtDeclaration: bool
     * }
     */
    public function compute(array $venueIds, DateTimeImmutable $start, DateTimeImmutable $end, string $clubId, string $seasonId, ?string $ignoredEntryId): array
    {
        $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        $from = $start->format('Y-m-d');
        $until = $end->format('Y-m-d');
        // Seules les dates EN SAISON comptent (une fermeture à cheval sur la fin de saison).
        $dates = [];
        if ($season instanceof Season) {
            $seasonStart = $season->getStartDate()->format('Y-m-d');
            $seasonEnd = $season->getEndDate()->format('Y-m-d');
            foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $day) {
                $iso = $day->format('Y-m-d');
                if ($iso >= $seasonStart && $iso <= $seasonEnd) {
                    $dates[] = $iso;
                }
            }
        }
        $today = $club instanceof Club ? $this->clubDay->todayFor($club)->format('Y-m-d') : '';
        $context = $this->trainingCalendarContext->load($seasonId, $ignoredEntryId);
        $teamsById = [];
        foreach ($this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            $teamsById[$team->getId()] = $team;
        }
        $teamName = static fn (?string $teamId): string => null !== $teamId && isset($teamsById[$teamId]) ? $teamsById[$teamId]->getName() : '';

        // 1. Entraînements du planning en vigueur, date par date.
        $occurrencesByTeam = [];
        $slotIds = [];
        $occurrences = 0;
        foreach ($dates as $iso) {
            $date = new DateTimeImmutable($iso);
            ['scheduleId' => $scheduleId, 'excludedVenueIds' => $excluded] = $this->effectiveScheduleResolver->resolve($date, $context['activePeriods'], $context['seasonScheduleId'], $context['closedDatesByVenue']);
            if (null === $scheduleId) {
                continue;
            }
            $weekday = (int) $date->format('N');
            foreach ($context['slotsBySchedule'][$scheduleId] ?? [] as $slot) {
                if (!\in_array($slot->getVenueId(), $venueIds, true) || \in_array($slot->getVenueId(), $excluded, true) || $slot->getDayOfWeek() !== $weekday) {
                    continue;
                }
                ++$occurrences;
                $slotIds[$slot->getId()] = true;
                $occurrencesByTeam[$slot->getTeamId()] = ($occurrencesByTeam[$slot->getTeamId()] ?? 0) + 1;
            }
        }
        $tiers = [];
        foreach ($this->entityManager->getRepository(PriorityTier::class)->findAll() as $tier) {
            $tiers[$tier->getId()] = $tier;
        }
        $teams = [];
        foreach ($occurrencesByTeam as $teamId => $count) {
            $team = $teamsById[$teamId] ?? null;
            $tier = $team instanceof Team ? ($tiers[$team->getPriorityTierId()] ?? null) : null;
            $teams[] = [
                'teamId' => (string) $teamId,
                'teamName' => $teamName((string) $teamId),
                'tierLabel' => $tier instanceof PriorityTier ? $tier->getLabel() : '',
                'tierWeight' => $tier instanceof PriorityTier ? $tier->getOrToolsWeight() : 0,
                'occurrences' => $count,
            ];
        }
        usort($teams, static fn (array $a, array $b): int => [$b['tierWeight'], $a['teamName'], $a['teamId']] <=> [$a['tierWeight'], $b['teamName'], $b['teamId']]);

        // 2. Matchs à déplacer.
        $matches = [];
        foreach ($this->entityManager->getRepository(Fixture::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'homeAway' => FixtureHomeAway::HOME]) as $fixture) {
            $matchDate = $fixture->getMatchDate()->format('Y-m-d');
            if (!\in_array($fixture->getVenueId(), $venueIds, true) || $matchDate < $from || $matchDate > $until
                || $matchDate < $today || FixtureStatus::UNPLACED === $fixture->getStatus()) {
                continue;
            }
            $matches[] = [
                'fixtureId' => $fixture->getId(),
                'teamId' => $fixture->getTeamId(),
                'teamName' => $teamName($fixture->getTeamId()),
                'opponentLabel' => (string) $fixture->getOpponentLabel(),
                'matchDate' => $matchDate,
                'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
            ];
        }
        usort($matches, static fn (array $a, array $b): int => [$a['matchDate'], $a['kickoffTime'] ?? '99:99', $a['teamName']] <=> [$b['matchDate'], $b['kickoffTime'] ?? '99:99', $b['teamName']]);

        // 3. Plans de période qui gouvernent ces dates (jamais le socle) et l'annonce du retrait.
        $closure = null !== $ignoredEntryId ? $this->closureOf($ignoredEntryId, $venueIds) : null;
        $publicHolidays = [] !== $dates ? $this->clubPublicHolidays->datesInWindow($clubId, new DateTimeImmutable($dates[0]), new DateTimeImmutable($dates[\count($dates) - 1])) : [];
        $archived = $season instanceof Season && $season->getStatus()->isFrozen();
        $plans = [];
        foreach ($venueIds as $venueId) {
            $newDates = [];
            foreach ($dates as $iso) {
                if (!isset($context['closedDatesByVenue'][$venueId][$iso])) {
                    $newDates[$iso] = true;
                }
            }
            $lines = [] !== $dates ? $this->closurePinRemover->preview($clubId, $seasonId, $venueId, array_fill_keys($dates, true), $ignoredEntryId) : [];
            $toRemoveByEntry = [];
            foreach ([] !== $newDates ? $this->closurePinRemover->preview($clubId, $seasonId, $venueId, $newDates, $ignoredEntryId) : [] as $line) {
                $toRemoveByEntry[$line['entryId']] = $line;
            }
            foreach ($lines as $line) {
                $entryId = $line['entryId'];
                $plans[$entryId] ??= [
                    'entryId' => $entryId,
                    'title' => $line['title'],
                    'startDate' => $line['startDate'],
                    'endDate' => $line['endDate'],
                    'chosenScheduleId' => $line['chosenScheduleId'],
                    'loadedScheduleId' => $line['loadedScheduleId'],
                    'hasVersions' => $this->planHasVersions($line['planId']),
                    'sessions' => [],
                    'toRemove' => ['locks' => 0, 'reservations' => []],
                    'regeneratedWithout' => false,
                    'regeneratedWithoutWeekdays' => [],
                ];
                $removal = $toRemoveByEntry[$entryId] ?? null;
                if (null !== $removal) {
                    $plans[$entryId]['toRemove']['locks'] += $removal['locks'];
                    foreach ($removal['reservations'] as $reservation) {
                        $plans[$entryId]['toRemove']['reservations'][] = ['teamId' => $reservation['teamId'], 'teamName' => $reservation['teamName'], 'dayOfWeek' => $reservation['dayOfWeek']];
                    }
                }
                $sessions = $archived ? [] : $this->planSessions($line['planId'], $line['chosenScheduleId'] ?? $line['loadedScheduleId'], $venueId, $dates, $line['startDate'], $line['endDate'], $today, $publicHolidays, $teamName);
                $plans[$entryId]['sessions'] = [...$plans[$entryId]['sessions'], ...$sessions];
                $lostWeekdays = null !== $closure ? $this->regeneratedWithout($line['planId'], $line['chosenScheduleId'], $venueId, $dates, $line['startDate'], $line['endDate'], $context['seasonScheduleId'], $closure['createdAt']) : [];
                if ([] !== $lostWeekdays) {
                    $plans[$entryId]['regeneratedWithout'] = true;
                    $merged = array_values(array_unique([...$plans[$entryId]['regeneratedWithoutWeekdays'], ...$lostWeekdays]));
                    sort($merged);
                    $plans[$entryId]['regeneratedWithoutWeekdays'] = $merged;
                }
            }
        }
        $plans = array_values($plans);

        $fullyGoverned = [] !== $dates;
        foreach ($dates as $iso) {
            $covered = false;
            foreach ($plans as $plan) {
                if ($iso >= $plan['startDate'] && $iso <= $plan['endDate']) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $fullyGoverned = false;
                break;
            }
        }

        $venueNames = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findBy(['id' => $venueIds]) as $venue) {
            $venueNames[$venue->getId()] = $venue->getName();
        }
        $closedSessions = [];
        foreach ($plans as $plan) {
            foreach ($plan['sessions'] as $session) {
                $closedSessions[] = [
                    'constraintId' => null !== $closure ? ($closure['constraintIds'][$session['venueId']] ?? '') : '',
                    'venueId' => $session['venueId'],
                    'venueName' => $venueNames[$session['venueId']] ?? '',
                    'closureTitle' => null !== $closure ? $closure['title'] : '',
                    'date' => $session['date'],
                    'teamId' => $session['teamId'],
                    'teamName' => $session['teamName'],
                ];
            }
        }

        return [
            'venueIds' => $venueIds,
            'startDate' => $from,
            'endDate' => $until,
            'seasonPlanChosen' => null !== $context['seasonScheduleId'],
            'teams' => $teams,
            'trainingOccurrences' => $occurrences,
            'trainingSlotCount' => \count($slotIds),
            'matchesToMove' => $matches,
            'governingPlans' => $plans,
            'fullyGoverned' => $fullyGoverned,
            'closedSessions' => $closedSessions,
            'removedAtDeclaration' => null !== $closure && $this->removedAtDeclaration(array_values($closure['constraintIds'])),
        ];
    }

    /**
     * Les gymnases d'une fermeture enregistrée : les contraintes `venue_closed` de sa FAMILLE (la
     * mère d'une indisponibilité découpée porte la contrainte).
     *
     * @return list<string>
     */
    public function venueIdsOfEntry(string $entryId): array
    {
        $closure = $this->closureOf($entryId, null);

        return null !== $closure ? array_map(strval(...), array_keys($closure['constraintIds'])) : [];
    }

    /**
     * @param list<string>|null $venueIds
     *
     * @return array{title: string, createdAt: DateTimeImmutable, constraintIds: array<string, string>}|null
     */
    private function closureOf(string $entryId, ?array $venueIds): ?array
    {
        $root = $this->planVenueClosures->rootEntryIdOf($entryId);
        if (null === $root) {
            return null;
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT c.id FROM "constraint" c JOIN calendar_entry e ON e.id = c.calendar_entry_id '
            . 'WHERE COALESCE(e.parent_entry_id, e.id) = :root ORDER BY c.created_at, c.id',
            ['root' => $root],
        );
        $constraintIds = [];
        $createdAt = null;
        $title = '';
        foreach ($rows as $row) {
            $constraint = $this->entityManager->getRepository(Constraint::class)->find((string) $row['id']);
            if (!$constraint instanceof Constraint || 'venue_closed' !== ($constraint->getConfig()['type'] ?? null)) {
                continue;
            }
            $venueId = $constraint->getScopeTargetId();
            if (null === $venueId || (null !== $venueIds && !\in_array($venueId, $venueIds, true))) {
                continue;
            }
            $constraintIds[$venueId] ??= $constraint->getId();
            if (!$createdAt instanceof DateTimeImmutable || $constraint->getCreatedAt() < $createdAt) {
                $createdAt = $constraint->getCreatedAt();
                $title = $constraint->getName();
            }
        }
        if (!$createdAt instanceof DateTimeImmutable) {
            return null;
        }

        return ['title' => $title, 'createdAt' => $createdAt, 'constraintIds' => $constraintIds];
    }

    private function planHasVersions(string $planId): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM schedule WHERE schedule_plan_id = :pid LIMIT 1', ['pid' => $planId]);
    }

    /**
     * Les séances d'un plan au gymnase fermé, aux dates fermées qu'il gouverne : à venir, hors
     * fériés, version COMPLETED, jour non rouvert, gymnase non désactivé.
     *
     * @param list<string>              $dates
     * @param list<string>              $publicHolidays
     * @param callable(?string): string $teamName
     *
     * @return list<array{teamId: string, teamName: string, date: string, venueId: string}>
     */
    private function planSessions(string $planId, ?string $scheduleId, string $venueId, array $dates, string $planStart, string $planEnd, string $today, array $publicHolidays, callable $teamName): array
    {
        if (null === $scheduleId || $this->venueDisabled($planId, $venueId)) {
            return [];
        }
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);
        if (!$schedule instanceof Schedule || ScheduleStatus::COMPLETED !== $schedule->getStatus()) {
            return [];
        }
        $reopened = $this->planVenueClosures->reopenedWeekdaysByPlans([$planId])[$planId][$venueId] ?? [];
        $slotsByDay = [];
        foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId, 'venueId' => $venueId], ['startTime' => 'ASC']) as $slot) {
            $slotsByDay[$slot->getDayOfWeek()][] = $slot;
        }
        $sessions = [];
        foreach ($dates as $iso) {
            if ($iso < $planStart || $iso > $planEnd || $iso < $today || \in_array($iso, $publicHolidays, true)) {
                continue;
            }
            $weekday = (int) new DateTimeImmutable($iso)->format('N');
            if (isset($reopened[$weekday])) {
                continue;
            }
            foreach ($slotsByDay[$weekday] ?? [] as $slot) {
                $sessions[] = ['teamId' => $slot->getTeamId(), 'teamName' => $teamName($slot->getTeamId()), 'date' => $iso, 'venueId' => $venueId];
            }
        }

        return $sessions;
    }

    private function venueDisabled(string $planId, string $venueId): bool
    {
        $override = $this->entityManager->getRepository(VenuePeriodOverride::class)->findOneBy(['schedulePlanId' => $planId, 'venueId' => $venueId]);

        return $override instanceof VenuePeriodOverride && VenuePeriodMode::DISABLED === $override->getMode();
    }

    /**
     * Correction finale 2 — le plan a été « régénéré sans » le gymnase : (a) sa version pointée est
     * née APRÈS la fermeture, (b) la fermeture recoupe la fenêtre du plan, (c) le gymnase n'y est pas
     * désactivé, et un jour fermé où le socle avait une séance à ce gymnase n'en a plus dans la version.
     * Rend ces jours ISO (vide = pas « régénéré sans ») — la confirmation de suppression les nomme.
     *
     * @param list<string> $dates
     *
     * @return list<int>
     */
    private function regeneratedWithout(string $planId, ?string $chosenScheduleId, string $venueId, array $dates, string $planStart, string $planEnd, ?string $seasonScheduleId, DateTimeImmutable $closureCreatedAt): array
    {
        if (null === $chosenScheduleId || null === $seasonScheduleId || $this->venueDisabled($planId, $venueId)) {
            return [];
        }
        $version = $this->entityManager->getRepository(Schedule::class)->find($chosenScheduleId);
        if (!$version instanceof Schedule || $version->getCreatedAt() <= $closureCreatedAt) {
            return [];
        }
        $weekdays = [];
        foreach ($dates as $iso) {
            if ($iso >= $planStart && $iso <= $planEnd) {
                $weekdays[(int) new DateTimeImmutable($iso)->format('N')] = true;
            }
        }
        if ([] === $weekdays) {
            return [];
        }
        $daysOf = function (string $scheduleId) use ($venueId): array {
            $days = [];
            foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId, 'venueId' => $venueId]) as $slot) {
                $days[$slot->getDayOfWeek()] = true;
            }

            return $days;
        };
        $socleDays = $daysOf($seasonScheduleId);
        $versionDays = $daysOf($chosenScheduleId);
        $lost = [];
        foreach (array_keys($weekdays) as $weekday) {
            if (isset($socleDays[$weekday]) && !isset($versionDays[$weekday])) {
                $lost[] = $weekday;
            }
        }
        sort($lost);

        return $lost;
    }

    /**
     * Correction E4 — la déclaration de cette fermeture a-t-elle réellement retiré des épingles ?
     * Lu dans l'audit (`LOCK_CHANGED` / `ENTITY_DELETED` portant le `constraintId`).
     *
     * @param list<string> $constraintIds
     */
    private function removedAtDeclaration(array $constraintIds): bool
    {
        if ([] === $constraintIds) {
            return false;
        }

        return false !== $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM audit_log WHERE action IN (:actions) AND details->>\'constraintId\' IN (:ids) LIMIT 1',
            ['actions' => [AuditAction::LOCK_CHANGED->value, AuditAction::ENTITY_DELETED->value], 'ids' => $constraintIds],
            ['actions' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::STRING],
        );
    }
}
