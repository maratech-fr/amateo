<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\MatchModuleVisit;
use App\Entity\SharedCompetitionDeadline;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Repository\FbiCorrectionRepository;
use App\Repository\MatchModuleVisitRepository;
use App\Repository\SharedCompetitionDeadlineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The entry-deadline cockpit outlook (RMM-6) — THE single home of the J-7 rule
 * (REMINDER_WINDOW_DAYS): the front computes nothing. For each EFFECTIVE deadline
 * (the club value, else the community default) that is still owed, it serves the
 * competition names, how many home fixtures remain to PLACE (UNPLACED) and to ENTER
 * (PLACED, ready to copy into FBI — aligned with `fbiTodo.toEnter` and the FBI list),
 * and whether we are within the reminder window. A solved deadline (nothing left to
 * place NOR to enter) is absent.
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
        private readonly FbiCorrectionRepository $correctionRepository,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return array{
     *     windows: list<array{deadline: string, source: string, competitionNames: list<string>, toPlaceCount: int, toEnterCount: int, withinWindow: bool}>,
     *     fbiTodo: array{toEnter: int, toCorrect: int},
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

        $homeByCompetition = $this->countHomeByCompetition($fixtures);
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
            $toPlace = $homeByCompetition['toPlace'][$competition->getId()] ?? 0;
            $toEnter = $homeByCompetition['toEnter'][$competition->getId()] ?? 0;
            if (0 === $toPlace && 0 === $toEnter) {
                continue; // nothing owed (solved, or all already entered/validated) → absent
            }

            $key = $effective->format('Y-m-d') . '|' . $source;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'deadline' => $effective->format('Y-m-d'),
                    'source' => $source,
                    'competitionNames' => [],
                    'toPlaceCount' => 0,
                    'toEnterCount' => 0,
                    'withinWindow' => $effective <= $windowThreshold,
                ];
            }
            $groups[$key]['competitionNames'][] = $competition->getName();
            $groups[$key]['toPlaceCount'] += $toPlace;
            $groups[$key]['toEnterCount'] += $toEnter;
        }

        $windows = array_values($groups);
        foreach ($windows as &$window) {
            sort($window['competitionNames']);
        }
        unset($window);
        usort($windows, static fn (array $a, array $b): int => [$a['deadline'], $a['source']] <=> [$b['deadline'], $b['source']]);

        // Le « à faire dans FBI » GLOBAL (toutes semaines) — servi pour que le cockpit
        // ET la barre des compteurs n'aient JAMAIS à charger les fixtures : à saisir =
        // domiciles PLACED (ni UNPLACED « à placer », ni SUBMITTED/VALIDATED déjà saisis),
        // à corriger = entrées OUVERTES du registre (filtres tenant+saison Doctrine).
        $toEnter = 0;
        foreach ($fixtures as $fixture) {
            if (FixtureHomeAway::HOME === $fixture->getHomeAway() && FixtureStatus::PLACED === $fixture->getStatus()) {
                ++$toEnter;
            }
        }
        $result = [
            'windows' => $windows,
            'fbiTodo' => [
                'toEnter' => $toEnter,
                'toCorrect' => \count($this->correctionRepository->findBy(['closedAt' => null])),
            ],
        ];

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
     * HOME fixtures still owed against a deadline, split by placement stage so the cockpit
     * can name the RIGHT action: UNPLACED must first be PLACED (a scheduling gesture), PLACED
     * is ready to copy into FBI (aligned with `fbiTodo.toEnter` and the FBI list). SUBMITTED/
     * VALIDATED are already entered → counted in neither.
     *
     * @param list<Fixture> $fixtures
     *
     * @return array{toPlace: array<string, int>, toEnter: array<string, int>} counts by competitionId
     */
    private function countHomeByCompetition(array $fixtures): array
    {
        $toPlace = [];
        $toEnter = [];
        foreach ($fixtures as $fixture) {
            if (FixtureHomeAway::HOME !== $fixture->getHomeAway()) {
                continue;
            }
            $competitionId = $fixture->getCompetitionId();
            if (null === $competitionId) {
                continue; // a friendly carries no competition → no deadline
            }
            $status = $fixture->getStatus();
            if (FixtureStatus::UNPLACED === $status) {
                $toPlace[$competitionId] = ($toPlace[$competitionId] ?? 0) + 1;
            } elseif (FixtureStatus::PLACED === $status) {
                $toEnter[$competitionId] = ($toEnter[$competitionId] ?? 0) + 1;
            }
        }

        return ['toPlace' => $toPlace, 'toEnter' => $toEnter];
    }

    /**
     * The effective deadline of a competition and where it comes from — the club value
     * wins, else the community default (paired competitions only). The rule itself lives
     * in {@see CompetitionDeadlineResolver} (single home); here we only pick WHICH shared
     * default to feed it (the paired one, keyed by federation id).
     *
     * @param array<string, SharedCompetitionDeadline> $sharedByFfbbId
     *
     * @return array{0: DateTimeImmutable|null, 1: string}
     */
    private function effectiveDeadline(Competition $competition, array $sharedByFfbbId): array
    {
        $ffbbCompetitionId = $competition->getFfbbCompetitionId();
        $shared = null !== $ffbbCompetitionId ? ($sharedByFfbbId[$ffbbCompetitionId] ?? null) : null;

        [$effective, $source] = CompetitionDeadlineResolver::resolve($competition, $shared);

        return [$effective, $source ?? ''];
    }
}
