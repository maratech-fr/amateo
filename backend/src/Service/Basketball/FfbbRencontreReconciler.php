<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Competition;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\Team;
use App\Enum\FbiIngestionSource;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Service\FbiFixtureImporter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The FFBB-API reconciliation channel (RMM-4 PR-3). FBI (the xlsx) reste le focus
 * et fait FOI — the API is « pour plus de commodité » : it fetches the club's
 * published rencontres on demand and CROSSES them with what the app holds, feeding
 * the SAME reconciliation screen as the import dialog ({@see ReconciliationPanel}).
 *
 * What the API adds — the AMICAUX (real measured case: an « AMICAL » BCCL vs Bron
 * absent from the xlsx) — is PROPOSED at creation, never imposed. Coverage is never
 * promised: the screen says « what the FFBB publishes at this instant », never « no
 * match ». A fixture with NO API hit produces NOTHING (never an absence signal).
 *
 * Matching (D10) is 3-tier per rencontre, plus a tier-0 idempotence shortcut:
 *   0. an existing fixture already carrying this rencontre's national id — so a
 *      re-check never re-proposes a match already created;
 *   1. `competitionId.id` → a paired {@see Competition} resolves the TEAM (an
 *      unpaired competition = an amical → team unresolved → creatable);
 *   2. among that team's fixtures, an exact (team, date);
 *   3. the remainder, (team, normalized opponent) — catches a moved date.
 *
 * The app⇄file diff and its per-field decisions are the SAME engine as the xlsx
 * import (reused verbatim from {@see FbiFixtureImporter}) — never a second copy.
 * The API NEVER touches a reconciliation trace: its {@see FbiIngestion} is stamped
 * FFBB_API and carries no pending deviations (only an FBI_XLSX deposit governs a
 * trace, {@see FbiIngestionSource}).
 */
final class FfbbRencontreReconciler
{
    public function __construct(
        private readonly FfbbRencontreReader $reader,
        private readonly FbiFixtureImporter $importer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Fetch + match + diff, read-only. `deviations` = home fixtures ALREADY placed
     * whose API values diverge (same shape as the xlsx analyze); `creatable` =
     * rencontres with no matching fixture (the amicaux), proposed for creation.
     *
     * @return array{
     *     deviations: list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}>,
     *     creatable: list<array{rencontreId: string, competitionNom: string, date: string, kickoff: string|null, homeAway: string, opponentLabel: string, venueLabel: string|null, numeroJournee: string|null, suggestedTeamId: string|null}>,
     *     fetchedAt: string,
     * }
     */
    public function analyze(string $clubCode, int $seasonYear): array
    {
        $rows = $this->reader->read($clubCode, $seasonYear);
        $venueNames = $this->importer->venueNamesById();
        $context = $this->matchingContext();

        /** @var list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records */
        $records = [];
        $creatable = [];
        $consumed = [];

        foreach ($rows as $row) {
            [$fixture, $suggestedTeamId] = $this->matchRow($row, $context, $consumed);
            if (!$fixture instanceof Fixture) {
                $creatable[] = $this->creatableEntry($row, $suggestedTeamId);
                continue;
            }
            $consumed[$fixture->getId()] = true;

            $fields = $this->importer->detectFieldDeviations($fixture, $row, $venueNames);
            if (null === $fields || [] === $fields) {
                continue; // matched but no divergence (or out of the home-placed perimeter)
            }
            foreach ($fields as $field => $vals) {
                $records[] = $this->importer->deviationRecord($fixture, $field, $vals, $row['competitionName'], 'none');
            }
        }

        return [
            // The API never touches a trace → no persisting flag (empty set).
            'deviations' => $this->importer->groupDeviations($records, []),
            'creatable' => $creatable,
            'fetchedAt' => $this->now()->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Re-fetch (SERVER truth — the client's values are never trusted), re-match,
     * apply the per-écart decisions and create the chosen rencontres (idempotent).
     * Writes a dated FFBB_API {@see FbiIngestion} (counters only, no trace).
     *
     * @param list<array{fixtureId: string, field: string, choice: string}> $decisions
     * @param list<array{rencontreId: string, teamId: string}>              $creations
     *
     * @return array{created: int, updated: int, unresolved: int, unresolvedDeviations: list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}>, depositedAt: string}
     */
    public function apply(string $clubCode, int $seasonYear, string $clubId, string $seasonId, array $decisions, array $creations): array
    {
        // RE-FETCH SERVER-SIDE (import idiom): a forged client payload can never
        // decide the values written — the diff is recomputed against the API now.
        $rows = $this->reader->read($clubCode, $seasonYear);
        $rowsByRencontreId = array_column($rows, null, 'rencontreId');
        $venueNames = $this->importer->venueNamesById();
        $decisionMap = $this->importer->indexDecisions($decisions);
        $context = $this->matchingContext();

        /** @var list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records */
        $records = [];
        $updated = 0;
        $consumed = [];

        foreach ($rows as $row) {
            [$fixture] = $this->matchRow($row, $context, $consumed);
            if (!$fixture instanceof Fixture) {
                continue;
            }
            $consumed[$fixture->getId()] = true;

            $fields = $this->importer->detectFieldDeviations($fixture, $row, $venueNames);
            if (null === $fields || [] === $fields) {
                continue;
            }
            $status = $fixture->getStatus()->value; // what the manager saw, before any mutation
            foreach ($fields as $field => $vals) {
                $choice = $decisionMap[$fixture->getId() . '|' . $field] ?? null;
                $effect = 'none';
                if ('take_file' === $choice) {
                    $this->importer->applyFieldTakeFile($fixture, $field, $row);
                    ++$updated;
                    $effect = 'take_file';
                } elseif ('keep_app' === $choice) {
                    $effect = 'keep_app';
                }
                $records[] = $this->importer->deviationRecord($fixture, $field, $vals, $row['competitionName'], $effect, $status);
            }
        }

        $created = $this->applyCreations($creations, $rowsByRencontreId, $context, $clubId, $seasonId);

        // The unresolved écarts (no decision) — reported, never overwritten.
        $unresolvedRecords = array_values(array_filter($records, static fn (array $r): bool => 'none' === $r['effect']));
        $unresolved = $this->importer->groupDeviations($unresolvedRecords, []);

        $now = $this->now();
        $ingestion = new FbiIngestion(
            $clubId,
            $seasonId,
            FbiIngestionSource::FFBB_API,
            $now,
            $created,
            $updated,
            0,
            \count($records),
            [], // FFBB_API never leaves a trace
        );
        $this->entityManager->persist($ingestion);
        $this->entityManager->flush();

        return [
            'created' => $created,
            'updated' => $updated,
            'unresolved' => \count($unresolved),
            'unresolvedDeviations' => $unresolved,
            'depositedAt' => $now->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Creates the chosen rencontres as UNPLACED fixtures, from the RE-FETCHED row
     * (never client values). Idempotent: a fixture already carrying this rencontre
     * id for this team is skipped; the partial unique index catches a concurrent
     * create (surfaced as a clean 409 by the controller).
     *
     * @param list<array{rencontreId: string, teamId: string}>                                                                                                                                                                                   $creations
     * @param array<string, array<string, mixed>>                                                                                                                                                                                                $rowsByRencontreId
     * @param array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, string>, competitionByFfbbId: array<string, Competition>, fixturesByTeam: array<string, list<Fixture>>} $context
     */
    private function applyCreations(array $creations, array $rowsByRencontreId, array $context, string $clubId, string $seasonId): int
    {
        $created = 0;
        $seen = [];
        foreach ($creations as $creation) {
            $rencontreId = $creation['rencontreId'];
            $teamId = $creation['teamId'];
            $key = $teamId . '|' . $rencontreId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            /** @var array<string, mixed>|null $row */
            $row = $rowsByRencontreId[$rencontreId] ?? null;
            // The rencontre must exist in the server re-fetch, and the team must be
            // a real team of the club (tenant filter makes a foreign id invisible).
            if (null === $row || !($context['teamById'][$teamId] ?? null) instanceof Team) {
                continue;
            }
            // Idempotence: never re-create a rencontre already materialised for this team.
            if (isset($context['fixturesByRencontreId'][$rencontreId])
                && $context['fixturesByRencontreId'][$rencontreId]->getTeamId() === $teamId) {
                continue;
            }

            $this->entityManager->persist($this->newFixture($row, $teamId, $clubId, $seasonId, $context));
            ++$created;
        }

        return $created;
    }

    /**
     * @param array<string, mixed>                                                                                                                                                                                                               $row
     * @param array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, string>, competitionByFfbbId: array<string, Competition>, fixturesByTeam: array<string, list<Fixture>>} $context
     */
    private function newFixture(array $row, string $teamId, string $clubId, string $seasonId, array $context): Fixture
    {
        /** @var DateTimeImmutable $matchDate */
        $matchDate = $row['matchDate'];
        $competitionFfbbId = \is_string($row['competitionFfbbId'] ?? null) ? $row['competitionFfbbId'] : null;
        // A paired competition materialises the fixture's competitionId; an
        // unpaired one (an amical) stays null = friendly (Fixture contract).
        $competition = null !== $competitionFfbbId ? ($context['competitionByFfbbId'][$competitionFfbbId] ?? null) : null;

        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competition instanceof Competition ? $competition->getId() : null);
        $fixture->setMatchDate($matchDate);
        $fixture->setHomeAway($row['homeAway'] instanceof FixtureHomeAway ? $row['homeAway'] : FixtureHomeAway::HOME);
        $fixture->setOpponentLabel(\is_string($row['opponentLabel'] ?? null) ? $row['opponentLabel'] : '');
        $fixture->setKickoffTime($row['kickoffTime'] instanceof DateTimeImmutable ? $row['kickoffTime'] : null);
        $fixture->setFbiVenueLabel(\is_string($row['venueLabel'] ?? null) ? $row['venueLabel'] : null);
        $fixture->setFfbbRencontreId(\is_string($row['rencontreId'] ?? null) ? $row['rencontreId'] : null);
        // Status is always UNPLACED (placing a home match requires a CLUB venue +
        // an explicit manager action — same rule as the FBI import).
        $fixture->setStatus(FixtureStatus::UNPLACED);

        return $fixture;
    }

    /**
     * The 3-tier match (+ tier-0 idempotence). Returns [matchedFixture|null,
     * suggestedTeamId|null] — the suggestion (tier-1 team) pre-fills the creatable
     * select when no fixture matched.
     *
     * @param array<string, mixed>                                                                                                                                                                                                               $row
     * @param array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, string>, competitionByFfbbId: array<string, Competition>, fixturesByTeam: array<string, list<Fixture>>} $context
     * @param array<string, true>                                                                                                                                                                                                                $consumed
     *
     * @return array{0: Fixture|null, 1: string|null}
     */
    private function matchRow(array $row, array $context, array $consumed): array
    {
        $rencontreId = \is_string($row['rencontreId'] ?? null) ? $row['rencontreId'] : '';

        // Tier 0 — a fixture already carrying this rencontre id (idempotence).
        $byId = $context['fixturesByRencontreId'][$rencontreId] ?? null;
        if ($byId instanceof Fixture) {
            return [$byId, $byId->getTeamId()];
        }

        // Tier 1 — the paired competition resolves the team; unpaired = unresolved.
        $competitionFfbbId = \is_string($row['competitionFfbbId'] ?? null) ? $row['competitionFfbbId'] : null;
        $teamId = null !== $competitionFfbbId ? ($context['teamByFfbbCompetitionId'][$competitionFfbbId] ?? null) : null;
        if (null === $teamId) {
            return [null, null]; // creatable (amical / unpaired competition)
        }

        $candidates = array_values(array_filter(
            $context['fixturesByTeam'][$teamId] ?? [],
            static fn (Fixture $f): bool => !isset($consumed[$f->getId()]),
        ));

        // Tier 2 — exact (team, date).
        /** @var DateTimeImmutable $matchDate */
        $matchDate = $row['matchDate'];
        foreach ($candidates as $candidate) {
            if ($candidate->getMatchDate()->format('Y-m-d') === $matchDate->format('Y-m-d')) {
                return [$candidate, $teamId];
            }
        }

        // Tier 3 — (team, normalized opponent) — catches a moved date.
        $opponent = \is_string($row['opponentLabel'] ?? null) ? $row['opponentLabel'] : '';
        $needle = $this->importer->normalizeLabel($opponent);
        if ('' !== $needle) {
            foreach ($candidates as $candidate) {
                if ($this->importer->containsClub($candidate->getOpponentLabel(), $needle)
                    || $this->importer->containsClub($opponent, $this->importer->normalizeLabel($candidate->getOpponentLabel()))) {
                    return [$candidate, $teamId];
                }
            }
        }

        return [null, $teamId]; // no fixture — creatable, with the resolved team as suggestion
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{rencontreId: string, competitionNom: string, date: string, kickoff: string|null, homeAway: string, opponentLabel: string, venueLabel: string|null, numeroJournee: string|null, suggestedTeamId: string|null}
     */
    private function creatableEntry(array $row, ?string $suggestedTeamId): array
    {
        /** @var DateTimeImmutable $matchDate */
        $matchDate = $row['matchDate'];
        $kickoff = $row['kickoffTime'] instanceof DateTimeImmutable ? $row['kickoffTime']->format('H:i') : null;
        $homeAway = $row['homeAway'] instanceof FixtureHomeAway ? $row['homeAway']->value : FixtureHomeAway::HOME->value;

        return [
            'rencontreId' => \is_string($row['rencontreId'] ?? null) ? $row['rencontreId'] : '',
            'competitionNom' => \is_string($row['competitionName'] ?? null) ? $row['competitionName'] : '',
            'date' => $matchDate->format('Y-m-d'),
            'kickoff' => $kickoff,
            'homeAway' => $homeAway,
            'opponentLabel' => \is_string($row['opponentLabel'] ?? null) ? $row['opponentLabel'] : '',
            'venueLabel' => \is_string($row['venueLabel'] ?? null) ? $row['venueLabel'] : null,
            'numeroJournee' => \is_string($row['numeroJournee'] ?? null) ? $row['numeroJournee'] : null,
            'suggestedTeamId' => $suggestedTeamId,
        ];
    }

    /**
     * The matching indexes, all tenant+season scoped by the Doctrine filters.
     *
     * @return array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, string>, competitionByFfbbId: array<string, Competition>, fixturesByTeam: array<string, list<Fixture>>}
     */
    private function matchingContext(): array
    {
        $fixturesByRencontreId = [];
        $fixturesByTeam = [];
        foreach ($this->entityManager->getRepository(Fixture::class)->findAll() as $fixture) {
            $rencontreId = $fixture->getFfbbRencontreId();
            if (null !== $rencontreId) {
                $fixturesByRencontreId[$rencontreId] = $fixture;
            }
            $fixturesByTeam[$fixture->getTeamId()][] = $fixture;
        }

        $teamById = [];
        foreach ($this->entityManager->getRepository(Team::class)->findAll() as $team) {
            $teamById[$team->getId()] = $team;
        }

        $teamByFfbbCompetitionId = [];
        $competitionByFfbbId = [];
        foreach ($this->entityManager->getRepository(Competition::class)->findAll() as $competition) {
            $ffbbId = $competition->getFfbbCompetitionId();
            if (null !== $ffbbId) {
                $teamByFfbbCompetitionId[$ffbbId] = $competition->getTeamId();
                $competitionByFfbbId[$ffbbId] = $competition;
            }
        }

        return [
            'fixturesByRencontreId' => $fixturesByRencontreId,
            'teamById' => $teamById,
            'teamByFfbbCompetitionId' => $teamByFfbbCompetitionId,
            'competitionByFfbbId' => $competitionByFfbbId,
            'fixturesByTeam' => $fixturesByTeam,
        ];
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
