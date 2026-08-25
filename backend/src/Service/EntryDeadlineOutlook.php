<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\MatchModuleVisit;
use App\Entity\SharedCompetitionDeadline;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Repository\MatchModuleVisitRepository;
use App\Repository\SharedCompetitionDeadlineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The entry-deadline cockpit outlook (RMM-6) — THE single home of the J-7 rule
 * (REMINDER_WINDOW_DAYS): the front computes nothing. For each EFFECTIVE deadline
 * (the club value, else the community default) that is still owed, it serves the
 * competition names, how many home fixtures remain to enter, and whether we are
 * within the reminder window. A solved deadline (nothing left to enter) is absent.
 *
 * When at least one J-7 window is open, it also joins the guardian delta of the
 * CURRENT user — via {@see MatchModuleDeltaComputer::computeDelta}, WITHOUT
 * rotating the reference (the visit is not stamped: the guardian's own rotation
 * stays the only writer). No MatchModuleVisit reference yet → the block is
 * omitted. Outside the window the delta is not even computed.
 */
final class EntryDeadlineOutlook
{
    /** J-7 : the reminder window opens seven days before a deadline (overdue ones stay open). */
    public const int REMINDER_WINDOW_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedCompetitionDeadlineRepository $sharedDeadlineRepository,
        private readonly MatchModuleVisitRepository $visitRepository,
        private readonly MatchModuleDeltaComputer $deltaComputer,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return array{
     *     windows: list<array{deadline: string, source: string, competitionNames: list<string>, toEnterCount: int, withinWindow: bool}>,
     *     guardianDelta?: array{newFixturesCount: int, newConflictFingerprints: list<string>, planningChanged: bool}
     * }
     */
    public function compute(string $clubId, ?string $seasonId, string $userId): array
    {
        // Tenant + season Doctrine filters scope both reads to the club/season.
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy([]);
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->getRepository(Fixture::class)->findBy([]);

        $toEnterByCompetition = $this->countHomeToEnterByCompetition($fixtures);
        $sharedByFfbbId = $this->sharedDeadlineRepository->mapByFfbbCompetitionIds(
            array_values(array_filter(array_map(
                static fn (Competition $c): ?string => $c->getFfbbCompetitionId(),
                $competitions,
            ))),
        );

        $today = DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $windowThreshold = $today->modify('+' . self::REMINDER_WINDOW_DAYS . ' days');

        // Group by (effective deadline, source): a region deadline and a department
        // deadline are distinct windows; two competitions sharing a date+source merge.
        $groups = [];
        foreach ($competitions as $competition) {
            [$effective, $source] = $this->effectiveDeadline($competition, $sharedByFfbbId);
            if (!$effective instanceof DateTimeImmutable) {
                continue; // no deadline for this competition
            }
            $toEnter = $toEnterByCompetition[$competition->getId()] ?? 0;
            if (0 === $toEnter) {
                continue; // solved (or nothing to enter) → absent
            }

            $key = $effective->format('Y-m-d') . '|' . $source;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'deadline' => $effective->format('Y-m-d'),
                    'source' => $source,
                    'competitionNames' => [],
                    'toEnterCount' => 0,
                    'withinWindow' => $effective <= $windowThreshold,
                ];
            }
            $groups[$key]['competitionNames'][] = $competition->getName();
            $groups[$key]['toEnterCount'] += $toEnter;
        }

        $windows = array_values($groups);
        foreach ($windows as &$window) {
            sort($window['competitionNames']);
        }
        unset($window);
        usort($windows, static fn (array $a, array $b): int => [$a['deadline'], $a['source']] <=> [$b['deadline'], $b['source']]);

        $result = ['windows' => $windows];

        // Le bloc gardien n'est joint QUE si une fenêtre J-7 est ouverte ET que
        // l'utilisateur a déjà une référence de visite — jamais on ne la stampe ici.
        $anyWindowOpen = [] !== array_filter($windows, static fn (array $w): bool => $w['withinWindow']);
        if ($anyWindowOpen) {
            $visit = $this->visitRepository->findForUser($userId);
            if ($visit instanceof MatchModuleVisit) {
                $delta = $this->deltaComputer->computeDelta(
                    $clubId,
                    $seasonId,
                    $visit->getReferenceSnapshot(),
                    $visit->getReferenceTakenAt(),
                );
                $result['guardianDelta'] = [
                    'newFixturesCount' => $delta['newFixturesCount'],
                    'newConflictFingerprints' => $delta['newConflictFingerprints'],
                    'planningChanged' => $delta['planningChanged'],
                ];
            }
        }

        return $result;
    }

    /**
     * @param list<Fixture> $fixtures
     *
     * @return array<string, int> HOME fixtures not yet SUBMITTED/VALIDATED, by competitionId
     */
    private function countHomeToEnterByCompetition(array $fixtures): array
    {
        $counts = [];
        foreach ($fixtures as $fixture) {
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway()) {
                continue;
            }
            if (\in_array($fixture->getStatus(), [FixtureStatus::SUBMITTED, FixtureStatus::VALIDATED], true)) {
                continue;
            }
            $competitionId = $fixture->getCompetitionId();
            if (null === $competitionId) {
                continue; // a friendly carries no competition → no deadline
            }
            $counts[$competitionId] = ($counts[$competitionId] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The effective deadline of a competition and where it comes from:
     * the club value wins, else the community default (paired competitions only).
     *
     * @param array<string, SharedCompetitionDeadline> $sharedByFfbbId
     *
     * @return array{0: DateTimeImmutable|null, 1: string}
     */
    private function effectiveDeadline(Competition $competition, array $sharedByFfbbId): array
    {
        $club = $competition->getEntryDeadline();
        if ($club instanceof DateTimeImmutable) {
            return [$club, 'club'];
        }
        $ffbbCompetitionId = $competition->getFfbbCompetitionId();
        if (null !== $ffbbCompetitionId && isset($sharedByFfbbId[$ffbbCompetitionId])) {
            return [$sharedByFfbbId[$ffbbCompetitionId]->getEntryDeadline(), 'community'];
        }

        return [null, ''];
    }
}
