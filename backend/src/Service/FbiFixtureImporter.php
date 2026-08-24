<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\FbiIngestionSource;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Exception\ImportRejectedException;
use App\Repository\FbiIngestionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\Clock\ClockInterface;

/**
 * Parses a REAL FBI club-wide fixtures export (.xlsx) — « Saisie des résultats
 * pour tout le club » — measured on specs/initiales/rechercherRencontre.xlsx
 * (cadrage P1-4 §3, facts F1-F9):
 *
 *   Division · N° de match · Equipe 1 (home) · Equipe 2 (away) ·
 *   Date de rencontre · Heure · Salle · e-Marque V2 · Scores/Forfaits (ignored).
 *
 * One-pass flow (décision fondateur 2026-08-02 — « une passe, pas 2 imports ») :
 * 1. analyze(): dry-run — parses, groups rows by division (+ club-team label when
 *    two club teams share one division), resolves each group against the
 *    persisted Division↔team mapping (= the Competition rows). Writes NOTHING.
 * 2. The manager completes the unresolved mappings in the dialog.
 * 3. import(): same file + the mappings — creates the missing Competitions and
 *    creates/updates every Fixture in one pass.
 *
 * Row semantics:
 * - HOME/AWAY: the club name (word-boundary normalized) must appear in exactly
 *   ONE of the two team labels; none or both (intra-club derby) → row error.
 * - « Exempt » as either team = a bye round → skipped, counted, never an error.
 * - Heure « 00:00 » = NOT SET (FBI sentinel, fact F2) → kickoffTime null; a
 *   real hour from the file always wins, but 00:00 never erases a kickoff the
 *   club has set (at home the CLUB proposes the hour).
 * - Salle → Fixture.fbiVenueLabel, HOME and AWAY (fact F3); absent on brassage
 *   rows (fact F4) → null.
 * - N° de match → Fixture.externalRef, scoped per team (fact F6: numbers repeat
 *   across divisions); known ref → DIFF/UPDATE, not skip:
 *     · date changed, or HOME↔AWAY switched → updated + un-placed
 *       (status UNPLACED, venue cleared) + warning — the league re-decided
 *       (« si le fichier reprogramme, c'est que c'est pas passé à la ligue »).
 *     · real hour changed → updated in place (venue kept: at home the hour is
 *       imposed, the venue stays the club's choice) + warning when it was placed.
 *     · salle/opponent label drift → silent update.
 * - Division → the persisted mapping; unmapped rows are neither created nor
 *   errors: they are reported per division for the mapping step.
 *
 * Per-row error report (never fail-fast past the header): valid rows import
 * even when others fail.
 */
final class FbiFixtureImporter
{
    /**
     * Header candidates per logical column, normalized. The real export titles
     * « N° de match » (trailing space included) where PR-4 assumed « Numéro » —
     * both are accepted (fact F8: labels are not under our control).
     */
    private const HEADER_CANDIDATES = [
        'division' => ['division'],
        'numero' => ['n de match', 'numero', 'no de match'],
        'equipe1' => ['equipe 1'],
        'equipe2' => ['equipe 2'],
        'date' => ['date de rencontre'],
        'heure' => ['heure'],
        'salle' => ['salle'],
    ];

    private const EXEMPT_LABEL = 'exempt';

    /** The reconciliation perimeter (RMM-4 D1/D3): only these three home fields become a CHOICE. */
    private const DEVIATION_FIELDS = ['date', 'kickoff', 'venue'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FbiIngestionRepository $ingestionRepository,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Dry-run: the division groups of the file, each resolved (or not) against
     * the persisted mapping, PLUS the reconciliation deviations (RMM-4): the
     * home fixtures ALREADY placed whose date/heure/salle diverge from the file.
     * Writes nothing.
     *
     * @return array{
     *     divisions: list<array{name: string, fbiTeamLabel: string|null, rowCount: int, teamId: string|null, competitionId: string|null, suggestedTeamId: string|null, suggestedCompetitionId: string|null, pouleError: string|null, pouleUnknownOpponents: list<string>}>,
     *     totalRows: int,
     *     exempted: int,
     *     errors: list<string>,
     *     deviations: list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}>,
     * }
     */
    public function analyze(string $filePath, Club $club): array
    {
        $parsed = $this->parseFile($filePath, $club);

        $groups = $this->groupRows($parsed['rows']);
        $resolver = $this->buildCompetitionResolver();
        $suggester = $this->buildSuggestionResolver();

        // Reconciliation (RMM-4): the deviations of home fixtures ALREADY placed
        // are computed against what is persisted, read-only. Only a resolvable
        // division can diverge — an unmapped one has no fixtures yet.
        $existingByTeamRef = $this->indexExistingByTeamRef();
        $venueNames = $this->venueNamesById();
        $persistingSet = $this->persistingSet($this->lastDepositPending());
        /** @var list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records */
        $records = [];
        /** @var array<string, true> $seenFixtures one deviation object per fixture */
        $seenFixtures = [];

        $divisions = [];
        foreach ($groups as $group) {
            $competition = $resolver($group['divisionKey'], $group['labelKey'], $group['multiLabel']);
            // Pre-fill (6.3): an UNMAPPED division whose label matches a paired
            // competition's canonical FFBB name → suggestion, never a resolution.
            // NEVER for a multi-label division: the canonical name cannot say
            // WHICH of the two club teams it is (same refusal as the resolver) —
            // a blind suggestion would import one team's calendar under the other.
            $suggested = $competition instanceof Competition || $group['multiLabel'] ? null : $suggester($group['divisionKey']);
            $guard = $competition instanceof Competition ? $this->pouleGuard($competition, $group['rows'], $group['name']) : null;
            $divisions[] = [
                'name' => $group['name'],
                'fbiTeamLabel' => $group['multiLabel'] ? $group['label'] : null,
                'rowCount' => $group['rowCount'],
                'teamId' => $competition?->getTeamId(),
                'competitionId' => $competition?->getId(),
                'suggestedTeamId' => $suggested?->getTeamId(),
                'suggestedCompetitionId' => $suggested?->getId(),
                'pouleError' => null !== $guard && $guard['blocking'] ? $guard['message'] : null,
                'pouleUnknownOpponents' => null !== $guard && !$guard['blocking'] ? $guard['unknown'] : [],
            ];

            if (!$competition instanceof Competition) {
                continue;
            }
            $teamId = $competition->getTeamId();
            foreach ($group['rows'] as $row) {
                $existing = $existingByTeamRef[$teamId . '|' . $row['numero']] ?? null;
                if (!$existing instanceof Fixture || isset($seenFixtures[$existing->getId()])) {
                    continue;
                }
                $fields = $this->detectFieldDeviations($existing, $row, $venueNames);
                if (null === $fields || [] === $fields) {
                    continue;
                }
                $seenFixtures[$existing->getId()] = true;
                foreach ($fields as $field => $vals) {
                    $records[] = $this->deviationRecord($existing, $field, $vals, $group['name'], 'none');
                }
            }
        }

        return [
            'divisions' => $divisions,
            'totalRows' => \count($parsed['rows']) + $parsed['exempted'],
            'exempted' => $parsed['exempted'],
            'errors' => $parsed['errors'],
            'deviations' => $this->groupDeviations($records, $persistingSet),
        ];
    }

    /**
     * One-pass import: persists the new mappings (missing Competitions), then
     * creates/updates every resolvable Fixture. Reconciliation (RMM-4): a
     * date/heure/salle divergence on a home fixture ALREADY placed is no longer
     * applied silently — it needs a per-écart DECISION (keep_app | take_file).
     * The diff is RECALCULATED here (the DB may have moved since analyze): a
     * perimeter écart WITHOUT a decision is NOT written and lands in
     * `unresolvedDeviations` — never an écrasement by default. Every deposit
     * writes a dated {@see FbiIngestion} (freshness + reconciliation trace).
     *
     * @param list<array{division: string, fbiTeamLabel: string|null, teamId: string, competitionId: string|null}> $mappings
     * @param list<array{fixtureId: string, field: string, choice: string}>                                        $decisions per-écart verdicts from the screen
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     exempted: int,
     *     errors: list<string>,
     *     warnings: list<array{type: string, division: string, externalRef: string, message: string}>,
     *     unmappedDivisions: list<array{name: string, fbiTeamLabel: string|null, rowCount: int}>,
     *     completeness: list<array{competitionId: string, name: string, imported: int, expected: int}>,
     *     unresolvedDeviations: list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}>,
     *     depositedAt: string,
     * }
     */
    public function import(string $filePath, Club $club, array $mappings, array $decisions = []): array
    {
        $parsed = $this->parseFile($filePath, $club);
        $errors = $parsed['errors'];
        $groups = $this->groupRows($parsed['rows']);

        // Reconciliation (RMM-4): verdicts keyed « fixtureId|field », the venue
        // names for the fuzzy salle compare, and the écarts the last deposit left
        // pending (to flag « persisting » and carry a still-diverging trace).
        $decisionMap = $this->indexDecisions($decisions);
        $venueNames = $this->venueNamesById();
        $lastPending = $this->lastDepositPending();
        $persistingSet = $this->persistingSet($lastPending);
        /** @var list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $deviationRecords */
        $deviationRecords = [];

        // P1-4 PR F2 (round 1 de revue) — le garde-fou PRÉCÈDE l'écriture des
        // mappings : un mapping dont la division est refusée n'est PAS persisté
        // (le dialog n'a pas de geste de re-mapping — une suggestion fautive
        // auto-envoyée collerait pour toujours).
        $blockedKeys = [];
        $mappings = $this->rejectGuardBlockedMappings($mappings, $groups, $errors, $blockedKeys);

        $this->persistMappings($mappings, $club);
        $resolver = $this->buildCompetitionResolver();

        // Existing FBI refs of the whole club, keyed team|ref (fact F6: the
        // number is only unique within a division → within its team).
        /** @var array<string, Fixture> $existingByTeamRef */
        $existingByTeamRef = [];
        foreach ($this->entityManager->getRepository(Fixture::class)->findAll() as $existing) {
            if (null !== $existing->getExternalRef()) {
                $existingByTeamRef[$existing->getTeamId() . '|' . $existing->getExternalRef()] = $existing;
            }
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $warnings = [];
        $unmapped = [];
        /** @var array<string, true> $seenInFile intra-file duplicate guard, team|ref */
        $seenInFile = [];

        $touchedCompetitions = [];
        foreach ($groups as $group) {
            $competition = $resolver($group['divisionKey'], $group['labelKey'], $group['multiLabel']);
            if (!$competition instanceof Competition) {
                // A division whose mapping the guard just refused is already
                // ERRORED — reporting it unmapped too would say « re-map me »
                // about a mapping deliberately not written.
                if (isset($blockedKeys[$group['divisionKey'] . '|' . $group['labelKey']])) {
                    continue;
                }
                $unmapped[] = [
                    'name' => $group['name'],
                    'fbiTeamLabel' => $group['multiLabel'] ? $group['label'] : null,
                    'rowCount' => $group['rowCount'],
                ];
                continue;
            }
            // Poule guard (P1-4 PR F2, 6.1 — founder decision): a division whose
            // opponents do not belong to the PAIRED poule is a wrong file/team/
            // phase — refused NAMED and SKIPPED, the other divisions go through.
            // Offline by construction: the poule club list was copied at pairing.
            $guard = $this->pouleGuard($competition, $group['rows'], $group['name']);
            if (null !== $guard) {
                if ($guard['blocking']) {
                    $errors[] = $guard['message'];
                    continue;
                }
                $warnings[] = [
                    'type' => 'POULE_MISMATCH',
                    'division' => $group['name'],
                    'externalRef' => '',
                    'message' => $guard['message'],
                ];
            }
            $teamId = $competition->getTeamId();
            $touchedCompetitions[$competition->getId()] = $competition;

            foreach ($group['rows'] as $row) {
                $key = $teamId . '|' . $row['numero'];
                if (isset($seenInFile[$key])) {
                    continue;
                }
                $seenInFile[$key] = true;

                $existing = $existingByTeamRef[$key] ?? null;
                if ($existing instanceof Fixture) {
                    $outcome = $this->applyDiff($existing, $row, $group['name'], $warnings, $decisionMap, $venueNames, $deviationRecords);
                    if ('updated' === $outcome) {
                        ++$updated;
                    } else {
                        ++$unchanged;
                    }
                    continue;
                }

                $fixture = new Fixture;
                $fixture->setClubId($club->getId());
                $fixture->setSeasonId($competition->getSeasonId());
                $fixture->setTeamId($teamId);
                $fixture->setCompetitionId($competition->getId());
                $fixture->setMatchDate($row['matchDate']);
                $fixture->setHomeAway($row['homeAway']);
                $fixture->setOpponentLabel($row['opponentLabel']);
                $fixture->setKickoffTime($row['kickoffTime']);
                $fixture->setExternalRef($row['numero']);
                $fixture->setFbiVenueLabel($row['venueLabel']);
                $this->entityManager->persist($fixture);
                ++$created;
            }
        }

        // Reconciliation bookkeeping (RMM-4): the report's unresolved écarts and
        // the trace this deposit leaves. A trace = a « garder l'app » écart, OR a
        // previous trace that STILL diverges (persisting) — a take_file resolves
        // it (the value is written), a file back to the app value / a deleted
        // fixture drops it. Only THIS deposit (FBI_XLSX) tués/reports a trace.
        $unresolvedRecords = array_values(array_filter($deviationRecords, static fn (array $r): bool => 'none' === $r['effect']));
        $unresolvedDeviations = $this->groupDeviations($unresolvedRecords, $persistingSet);
        $newPending = $this->carryForwardTrace($deviationRecords, $persistingSet, $lastPending);

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $ingestion = new FbiIngestion(
            $club->getId(),
            $this->resolveSeasonId($club, $groups),
            FbiIngestionSource::FBI_XLSX,
            $now,
            $created,
            $updated,
            $unchanged,
            \count($deviationRecords),
            $newPending,
        );
        $this->entityManager->persist($ingestion);
        // The ingestion is always written (every deposit is a dated ingestion),
        // so the flush is unconditional now.
        $this->entityManager->flush();

        // Completeness (6.2): « 9/22 journées — fichier partiel ou phase pas
        // sortie » — counted on the PERSISTED fixtures (the data that is true),
        // only for competitions carrying a pairing expectation.
        $completeness = [];
        foreach ($touchedCompetitions as $competition) {
            $expected = $competition->getExpectedMatchdays();
            if (null === $expected) {
                continue;
            }
            $imported = \count($this->entityManager->getRepository(Fixture::class)->findBy(['competitionId' => $competition->getId()]));
            if ($imported < $expected) {
                $completeness[] = [
                    'competitionId' => $competition->getId(),
                    'name' => $competition->getName(),
                    'imported' => $imported,
                    'expected' => $expected,
                ];
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'exempted' => $parsed['exempted'],
            'errors' => $errors,
            'warnings' => $warnings,
            'unmappedDivisions' => $unmapped,
            'completeness' => $completeness,
            'unresolvedDeviations' => $unresolvedDeviations,
            'depositedAt' => $now->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Guard-before-write (revue F2 round 1): a mapping whose division the poule
     * guard REFUSES is dropped (named error) instead of persisted — the dialog
     * has no remap gesture, a wrong write would stick. The target competition
     * is resolved WITHOUT writing: the suggestion's competitionId, else the
     * exact (team, name) lookup persistMappings would use. A target without
     * pairing has no poule → never checked, mapping passes.
     *
     * @param list<array{division: string, fbiTeamLabel: string|null, teamId: string, competitionId: string|null}>                                                                                                                                                                                              $mappings
     * @param list<array{name: string, divisionKey: string, label: string, labelKey: string, multiLabel: bool, rowCount: int, rows: list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>}> $groups
     * @param list<string>                                                                                                                                                                                                                                                                                      $errors
     * @param array<string, true>                                                                                                                                                                                                                                                                               $blockedKeys divisionKey|labelKey of refused divisions
     *
     * @return list<array{division: string, fbiTeamLabel: string|null, teamId: string, competitionId: string|null}> the surviving mappings
     */
    private function rejectGuardBlockedMappings(array $mappings, array $groups, array &$errors, array &$blockedKeys): array
    {
        if ([] === $mappings) {
            return [];
        }
        $competitionRepository = $this->entityManager->getRepository(Competition::class);

        $survivors = [];
        foreach ($mappings as $mapping) {
            $divisionKey = $this->normalizeLabel($mapping['division']);
            $labelKey = null !== $mapping['fbiTeamLabel'] ? $this->normalizeLabel($mapping['fbiTeamLabel']) : null;
            $group = null;
            foreach ($groups as $candidate) {
                if ($candidate['divisionKey'] === $divisionKey && (null === $labelKey || $candidate['labelKey'] === $labelKey)) {
                    $group = $candidate;
                    break;
                }
            }

            $target = null;
            $mappingCompetitionId = $mapping['competitionId'] ?? null;
            if (null !== $mappingCompetitionId) {
                $byId = $competitionRepository->findOneBy(['id' => $mappingCompetitionId]);
                if ($byId instanceof Competition && $byId->getTeamId() === $mapping['teamId']) {
                    $target = $byId;
                }
            }
            $target ??= $competitionRepository->findOneBy(['teamId' => $mapping['teamId'], 'name' => mb_substr(trim($mapping['division']), 0, 180)]);

            $guard = null !== $group && $target instanceof Competition ? $this->pouleGuard($target, $group['rows'], $group['name']) : null;
            if (null !== $guard && $guard['blocking']) {
                $errors[] = $guard['message'];
                $blockedKeys[$group['divisionKey'] . '|' . $group['labelKey']] = true;
                continue;
            }
            $survivors[] = $mapping;
        }

        return $survivors;
    }

    /**
     * The poule guard (6.1): confront the division's DISTINCT opponents to the
     * paired poule's club list (whole-word normalized containment via
     * {@see containsClub} — « FIRMINY CHAZEAU-FAYOL AL - 1 » matches the poule
     * club « FIRMINY CHAZEAU-FAYOL AL »). > 50 % unknown → blocking; 1..50 % →
     * warning; competition without a paired opponent list → never checked
     * (today's behaviour). Null = nothing to report.
     *
     * @param list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}> $rows
     *
     * @return array{blocking: bool, message: string, unknown: list<string>}|null
     */
    private function pouleGuard(Competition $competition, array $rows, string $divisionName): ?array
    {
        $pouleClubs = $competition->getFfbbPouleOpponents();
        if (null === $pouleClubs || [] === $pouleClubs) {
            return null;
        }
        $needles = array_map(fn (string $club): string => $this->normalizeLabel($club), $pouleClubs);

        $unknown = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = $this->normalizeLabel($row['opponentLabel']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $known = false;
            foreach ($needles as $needle) {
                // The SAME whole-word join as the club-side detection — one idiom.
                if ('' !== $needle && $this->containsClub($row['opponentLabel'], $needle)) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                $unknown[] = $row['opponentLabel'];
            }
        }

        if ([] === $unknown) {
            return null;
        }
        $total = \count($seen);
        $blocking = \count($unknown) * 2 > $total;
        $pouleName = $competition->getFfbbPouleName() ?? '?';
        $message = $blocking
            ? \sprintf(
                'Division « %s » ignorée : %d adversaire(s) sur %d hors de la poule « %s » (%s) — mauvais fichier, mauvaise équipe ou mauvaise phase ? Données de la ligue — un écart se corrige auprès d\'elle.',
                $divisionName,
                \count($unknown),
                $total,
                $pouleName,
                implode(', ', \array_slice($unknown, 0, 5)),
            )
            : \sprintf(
                'Division « %s » : %d adversaire(s) sur %d hors de la poule « %s » (%s).',
                $divisionName,
                \count($unknown),
                $total,
                $pouleName,
                implode(', ', \array_slice($unknown, 0, 5)),
            );

        return ['blocking' => $blocking, 'message' => $message, 'unknown' => $unknown];
    }

    /**
     * Suggestion resolver (6.3): normalized division label → a competition whose
     * CANONICAL FFBB name matches. A suggestion, never a resolution — the
     * manager confirms in the dialog (mapping stays the contract). Two paired
     * competitions sharing one normalized canonical name = ambiguous → NO
     * suggestion (never guess between teams).
     */
    private function buildSuggestionResolver(): callable
    {
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy([]);
        /** @var array<string, Competition|null> $byCanonical null = ambiguous */
        $byCanonical = [];
        foreach ($competitions as $competition) {
            $canonical = $competition->getFfbbCompetitionName();
            if (null === $canonical) {
                continue;
            }
            $key = $this->normalizeLabel($canonical);
            $byCanonical[$key] = \array_key_exists($key, $byCanonical) ? null : $competition;
        }

        return static fn (string $divisionKey): ?Competition => $byCanonical[$divisionKey] ?? null;
    }

    /**
     * Diff an existing fixture against a file row.
     *
     * Reconciliation perimeter (RMM-4 D1): a date/heure/salle divergence on a
     * HOME fixture ALREADY placed (status !== UNPLACED, row still HOME) is NOT
     * applied automatically — it becomes a deviation the manager decides
     * (keep_app | take_file). Everything else keeps the pre-RMM-4 behaviour
     * INTEGRAL: extérieurs, home fixtures UNPLACED, the HOME↔AWAY switch and the
     * opponent label (D3) all let the file win. INVARIANT (fondateur): an AWAY
     * fixture NEVER produces a deviation — its écart is written directly here.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}       $row
     * @param list<array{type: string, division: string, externalRef: string, message: string}>                                                                                         $warnings
     * @param array<string, string>                                                                                                                                                     $decisions  fixtureId|field → keep_app|take_file
     * @param array<string, string>                                                                                                                                                     $venueNames venueId → Venue name (for the fuzzy salle compare)
     * @param list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records    collected per-field deviation records
     */
    private function applyDiff(Fixture $existing, array $row, string $divisionName, array &$warnings, array $decisions, array $venueNames, array &$records): string
    {
        $fields = $this->detectFieldDeviations($existing, $row, $venueNames);
        if (null !== $fields) {
            return $this->applyDeviationMode($existing, $row, $divisionName, $fields, $warnings, $decisions, $records);
        }

        // ── NOT the deviation perimeter → pre-RMM-4 behaviour, integral ──────
        $changed = false;
        $wasPlaced = FixtureStatus::UNPLACED !== $existing->getStatus();

        if ($existing->getMatchDate()->format('Y-m-d') !== $row['matchDate']->format('Y-m-d')) {
            $oldDate = $existing->getMatchDate()->format('d/m/Y');
            $existing->setMatchDate($row['matchDate']);
            $this->unplace($existing);
            $warnings[] = [
                'type' => 'RESCHEDULED',
                'division' => $divisionName,
                'externalRef' => $row['numero'],
                'message' => \sprintf(
                    '%s n°%s : re-programmé du %s au %s%s.',
                    $divisionName,
                    $row['numero'],
                    $oldDate,
                    $row['matchDate']->format('d/m/Y'),
                    $wasPlaced ? ' — placement annulé' : '',
                ),
            ];
            $changed = true;
        }

        if ($existing->getHomeAway() !== $row['homeAway']) {
            $existing->setHomeAway($row['homeAway']);
            $this->unplace($existing);
            $warnings[] = [
                'type' => 'SWITCHED',
                'division' => $divisionName,
                'externalRef' => $row['numero'],
                'message' => FixtureHomeAway::AWAY === $row['homeAway']
                    ? \sprintf('%s n°%s : devient un match à l\'EXTÉRIEUR le %s%s.', $divisionName, $row['numero'], $row['matchDate']->format('d/m/Y'), $wasPlaced ? ' — le créneau placé est libéré' : '')
                    : \sprintf('%s n°%s : devient un match à DOMICILE le %s — à placer.', $divisionName, $row['numero'], $row['matchDate']->format('d/m/Y')),
            ];
            $changed = true;
        }

        // A real hour from the file always wins; the 00:00 sentinel (parsed to
        // null) never erases an hour the club has set (fact F2).
        if ($row['kickoffTime'] instanceof DateTimeImmutable) {
            $current = $existing->getKickoffTime();
            if (!$current instanceof DateTimeImmutable || $current->format('H:i') !== $row['kickoffTime']->format('H:i')) {
                $existing->setKickoffTime($row['kickoffTime']);
                if ($wasPlaced && FixtureStatus::UNPLACED !== $existing->getStatus()) {
                    $warnings[] = [
                        'type' => 'RESCHEDULED',
                        'division' => $divisionName,
                        'externalRef' => $row['numero'],
                        'message' => \sprintf('%s n°%s : la ligue enregistre %s comme heure de la rencontre.', $divisionName, $row['numero'], $row['kickoffTime']->format('H:i')),
                    ];
                }
                $changed = true;
            }
        }

        if ($existing->getOpponentLabel() !== $row['opponentLabel']) {
            $existing->setOpponentLabel($row['opponentLabel']);
            $changed = true;
        }
        if (null !== $row['venueLabel'] && $existing->getFbiVenueLabel() !== $row['venueLabel']) {
            $existing->setFbiVenueLabel($row['venueLabel']);
            $changed = true;
        }

        return $changed ? 'updated' : 'unchanged';
    }

    /** The league re-decided: the match goes back to « à placer ». */
    private function unplace(Fixture $fixture): void
    {
        $fixture->setStatus(FixtureStatus::UNPLACED);
        $fixture->setVenueId(null);
    }

    /**
     * The reconciliation perimeter (RMM-4 D1): the divergent date/kickoff/venue
     * fields of a HOME fixture already placed, comparing the file to the app —
     * OR null when the fixture is OUT of the perimeter (extérieur, or home but
     * UNPLACED, or the row switches side). Read-only. AWAY never enters (the
     * fondateur invariant): the perimeter requires the fixture to BE home and the
     * row to STAY home.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     * @param array<string, string>                                                                                                                                               $venueNames venueId → Venue name
     *
     * @return array<string, array{app: string|null, file: string|null}>|null keyed by field (date/kickoff/venue); null = out of perimeter
     */
    private function detectFieldDeviations(Fixture $existing, array $row, array $venueNames): ?array
    {
        $inPerimeter = FixtureHomeAway::HOME === $existing->getHomeAway()
            && FixtureHomeAway::HOME === $row['homeAway']
            && FixtureStatus::UNPLACED !== $existing->getStatus();
        if (!$inPerimeter) {
            return null;
        }

        $fields = [];

        if ($existing->getMatchDate()->format('Y-m-d') !== $row['matchDate']->format('Y-m-d')) {
            $fields['date'] = ['app' => $existing->getMatchDate()->format('Y-m-d'), 'file' => $row['matchDate']->format('Y-m-d')];
        }

        // The 00:00 sentinel is parsed to null upstream (fact F2): a null file
        // kickoff is « not set », never a divergence.
        if ($row['kickoffTime'] instanceof DateTimeImmutable) {
            $current = $existing->getKickoffTime();
            if (!$current instanceof DateTimeImmutable || $current->format('H:i') !== $row['kickoffTime']->format('H:i')) {
                $fields['kickoff'] = ['app' => $current?->format('H:i'), 'file' => $row['kickoffTime']->format('H:i')];
            }
        }

        // Salle (D13): app = the placed Venue's name, file = the free FBI label.
        // A placed home fixture without a venue id, or an unknown venue, cannot be
        // compared → no deviation (degrade safe). Fuzzy: normalized equality OR
        // whole-word containment either way (« Coubertin » ≈ « GYMNASE … COUBERTIN »).
        $venueId = $existing->getVenueId();
        $fileLabel = $row['venueLabel'];
        if (null !== $venueId && null !== $fileLabel && isset($venueNames[$venueId])) {
            $appLabel = $venueNames[$venueId];
            if (!$this->venueMatches($appLabel, $fileLabel)) {
                $fields['venue'] = ['app' => $appLabel, 'file' => $fileLabel];
            }
        }

        return $fields;
    }

    /**
     * Applies the manager's per-écart verdicts on a fixture inside the perimeter.
     * A field WITHOUT a decision is left INTACT and recorded « none » (it will be
     * reported unresolved — never an écrasement by default). The opponent label
     * stays a silent update (D3, out of the screen), and the raw fbiVenueLabel is
     * silently refreshed only when the venue is NOT itself under a decision.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}       $row
     * @param array<string, array{app: string|null, file: string|null}>                                                                                                                 $fields
     * @param list<array{type: string, division: string, externalRef: string, message: string}>                                                                                         $warnings
     * @param array<string, string>                                                                                                                                                     $decisions
     * @param list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records
     */
    private function applyDeviationMode(Fixture $existing, array $row, string $divisionName, array $fields, array &$warnings, array $decisions, array &$records): string
    {
        $changed = false;
        // The status the manager SAW — captured before any take_file mutates it.
        $status = $existing->getStatus()->value;
        foreach ($fields as $field => $vals) {
            $choice = $decisions[$existing->getId() . '|' . $field] ?? null;
            $effect = 'none';
            if ('take_file' === $choice) {
                $this->applyFieldTakeFile($existing, $field, $row);
                $warnings[] = $this->takeFileWarning($field, $divisionName, $row);
                $changed = true;
                $effect = 'take_file';
            } elseif ('keep_app' === $choice) {
                $effect = 'keep_app';
            }
            $records[] = $this->deviationRecord($existing, $field, $vals, $divisionName, $effect, $status);
        }

        // Opponent label drift stays a silent update (D3 — never a choice).
        if ($existing->getOpponentLabel() !== $row['opponentLabel']) {
            $existing->setOpponentLabel($row['opponentLabel']);
            $changed = true;
        }
        // Raw FBI label: silently mirror it only when the venue is not itself a
        // decided écart (a venue decision owns the label write).
        if (!isset($fields['venue']) && null !== $row['venueLabel'] && $existing->getFbiVenueLabel() !== $row['venueLabel']) {
            $existing->setFbiVenueLabel($row['venueLabel']);
            $changed = true;
        }

        return $changed ? 'updated' : 'unchanged';
    }

    /**
     * « Prendre le fichier » on one field. Retained semantics (RMM-4):
     * - DATE: la ligue a re-décidé → write the date AND un-place (UNPLACED, venue
     *   cleared) — exactly today's reschedule; the placement is invalidated.
     * - KICKOFF: write the hour IN PLACE (venue kept); a SUBMITTED/VALIDATED
     *   fixture drops to PLACED (D2 — the FBI checkmark was on a wrong hour).
     * - VENUE: the file names a different room → un-place (venue cleared, raw
     *   label adopted) so the manager re-places; this makes take_file RESOLVE the
     *   écart (next deposit: home UNPLACED → file wins, no deviation).
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     */
    private function applyFieldTakeFile(Fixture $existing, string $field, array $row): void
    {
        switch ($field) {
            case 'date':
                $existing->setMatchDate($row['matchDate']);
                $this->unplace($existing);
                break;
            case 'kickoff':
                if ($row['kickoffTime'] instanceof DateTimeImmutable) {
                    $existing->setKickoffTime($row['kickoffTime']);
                }
                $this->demoteSubmitted($existing);
                break;
            case 'venue':
                if (null !== $row['venueLabel']) {
                    $existing->setFbiVenueLabel($row['venueLabel']);
                }
                $this->unplace($existing);
                break;
        }
    }

    /** D2: an in-place take_file un-submits a SUBMITTED/VALIDATED fixture to PLACED. */
    private function demoteSubmitted(Fixture $fixture): void
    {
        if (FixtureStatus::SUBMITTED === $fixture->getStatus() || FixtureStatus::VALIDATED === $fixture->getStatus()) {
            $fixture->setStatus(FixtureStatus::PLACED);
        }
    }

    /**
     * The take_file confirmation warning per field (RESCHEDULED = the league's data was adopted).
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     *
     * @return array{type: string, division: string, externalRef: string, message: string}
     */
    private function takeFileWarning(string $field, string $divisionName, array $row): array
    {
        $message = match ($field) {
            'date' => \sprintf('%s n°%s : re-programmé du fichier au %s — placement annulé.', $divisionName, $row['numero'], $row['matchDate']->format('d/m/Y')),
            'kickoff' => \sprintf('%s n°%s : heure du fichier retenue (%s).', $divisionName, $row['numero'], $row['kickoffTime']?->format('H:i') ?? '—'),
            default => \sprintf('%s n°%s : salle du fichier retenue (%s) — placement à revoir.', $divisionName, $row['numero'], $row['venueLabel'] ?? '—'),
        };

        return ['type' => 'RESCHEDULED', 'division' => $divisionName, 'externalRef' => $row['numero'], 'message' => $message];
    }

    /**
     * Fuzzy salle match (D13): normalized equality OR whole-word containment
     * either direction, reusing the {@see containsClub} idiom. Degrades to « no
     * deviation » when a side is empty. NAMED fallback if real-world false
     * positives appear: compare the stored fbiVenueLabel (old) to the new one
     * instead of the placed Venue name.
     */
    private function venueMatches(string $appLabel, string $fileLabel): bool
    {
        $a = $this->normalizeLabel($appLabel);
        $b = $this->normalizeLabel($fileLabel);
        if ('' === $a || '' === $b) {
            return true;
        }

        return $a === $b || $this->containsClub($fileLabel, $a) || $this->containsClub($appLabel, $b);
    }

    /**
     * @param array{app: string|null, file: string|null} $vals
     * @param string|null                                $status the status the manager saw (defaults to the live one — analyze never mutates)
     *
     * @return array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}
     */
    private function deviationRecord(Fixture $existing, string $field, array $vals, string $divisionName, string $effect, ?string $status = null): array
    {
        return [
            'fixtureId' => $existing->getId(),
            'externalRef' => (string) $existing->getExternalRef(),
            'division' => $divisionName,
            'teamId' => $existing->getTeamId(),
            'status' => $status ?? $existing->getStatus()->value,
            'field' => $field,
            'app' => $vals['app'],
            'file' => $vals['file'],
            'effect' => $effect,
        ];
    }

    /**
     * Groups flat per-field records into one deviation object per fixture, with a
     * `persisting` flag (any of its fields was pending on the previous deposit).
     * The `status` is captured at detection (before any take_file mutation) — the
     * FIRST record of the fixture wins, so it reflects what the manager saw.
     *
     * @param list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records
     * @param array<string, true>                                                                                                                                                       $persistingSet
     *
     * @return list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}>
     */
    private function groupDeviations(array $records, array $persistingSet): array
    {
        /** @var array<string, array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}> $byFixture */
        $byFixture = [];
        foreach ($records as $record) {
            $id = $record['fixtureId'];
            if (!isset($byFixture[$id])) {
                $byFixture[$id] = [
                    'fixtureId' => $id,
                    'externalRef' => $record['externalRef'],
                    'division' => $record['division'],
                    'teamId' => $record['teamId'],
                    'status' => $record['status'],
                    'persisting' => false,
                    'fields' => [],
                ];
            }
            $byFixture[$id]['fields'][$record['field']] = ['app' => $record['app'], 'file' => $record['file']];
            if (isset($persistingSet[$id . '|' . $record['field']])) {
                $byFixture[$id]['persisting'] = true;
            }
        }

        return array_values($byFixture);
    }

    /**
     * The trace THIS deposit leaves for the next one: every écart still diverging
     * after this import — a « garder l'app » verdict, or a previous trace that
     * still diverges even undecided (persisting). A take_file resolves the écart
     * (its value was written) so it is NOT carried; a file back to the app value
     * or a deleted fixture simply never appears here. decidedAt is preserved for a
     * carried-forward trace, fresh for a new keep_app.
     *
     * @param list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records
     * @param array<string, true>                                                                                                                                                       $persistingSet
     * @param list<array{fixtureId: string, field: string, appValue: string|null, fileValue: string|null, decidedAt: string}>                                                           $lastPending
     *
     * @return list<array{fixtureId: string, field: string, appValue: string|null, fileValue: string|null, decidedAt: string}>
     */
    private function carryForwardTrace(array $records, array $persistingSet, array $lastPending): array
    {
        $previousDecidedAt = [];
        foreach ($lastPending as $entry) {
            $previousDecidedAt[$entry['fixtureId'] . '|' . $entry['field']] = $entry['decidedAt'];
        }
        $now = DateTimeImmutable::createFromInterface($this->clock->now())->format(DateTimeImmutable::ATOM);

        $trace = [];
        foreach ($records as $record) {
            $key = $record['fixtureId'] . '|' . $record['field'];
            $carry = 'keep_app' === $record['effect']
                || ('none' === $record['effect'] && isset($persistingSet[$key]));
            if (!$carry) {
                continue;
            }
            $trace[] = [
                'fixtureId' => $record['fixtureId'],
                'field' => $record['field'],
                'appValue' => $record['app'],
                'fileValue' => $record['file'],
                'decidedAt' => $previousDecidedAt[$key] ?? $now,
            ];
        }

        return $trace;
    }

    /**
     * Existing FBI-referenced fixtures of the club+season, keyed « teamId|ref »
     * (fact F6: the number is only unique within its team). Shared by analyze
     * (read-only detection) and import.
     *
     * @return array<string, Fixture>
     */
    private function indexExistingByTeamRef(): array
    {
        $index = [];
        foreach ($this->entityManager->getRepository(Fixture::class)->findAll() as $existing) {
            if (null !== $existing->getExternalRef()) {
                $index[$existing->getTeamId() . '|' . $existing->getExternalRef()] = $existing;
            }
        }

        return $index;
    }

    /**
     * venueId → Venue name, scoped to the club+season by the tenant/season
     * filters — for the fuzzy salle compare of a placed home fixture.
     *
     * @return array<string, string>
     */
    private function venueNamesById(): array
    {
        $names = [];
        foreach ($this->entityManager->getRepository(Venue::class)->findAll() as $venue) {
            $names[$venue->getId()] = $venue->getName();
        }

        return $names;
    }

    /**
     * Normalizes the multipart decisions into a « fixtureId|field » → choice map.
     * Unknown fields or choices are dropped (a forged decision can never write).
     *
     * @param list<array{fixtureId: string, field: string, choice: string}> $decisions
     *
     * @return array<string, string>
     */
    private function indexDecisions(array $decisions): array
    {
        $map = [];
        foreach ($decisions as $decision) {
            if (!\in_array($decision['field'], self::DEVIATION_FIELDS, true)) {
                continue;
            }
            if ('keep_app' !== $decision['choice'] && 'take_file' !== $decision['choice']) {
                continue;
            }
            $map[$decision['fixtureId'] . '|' . $decision['field']] = $decision['choice'];
        }

        return $map;
    }

    /**
     * The écarts the last FBI deposit left pending — the source of the persisting
     * flag and of the carried decidedAt.
     *
     * @return list<array{fixtureId: string, field: string, appValue: string|null, fileValue: string|null, decidedAt: string}>
     */
    private function lastDepositPending(): array
    {
        return $this->ingestionRepository->latestXlsx()?->getPendingDeviations() ?? [];
    }

    /**
     * @param list<array{fixtureId: string, field: string, appValue: string|null, fileValue: string|null, decidedAt: string}> $lastPending
     *
     * @return array<string, true> « fixtureId|field » set
     */
    private function persistingSet(array $lastPending): array
    {
        $set = [];
        foreach ($lastPending as $entry) {
            $set[$entry['fixtureId'] . '|' . $entry['field']] = true;
        }

        return $set;
    }

    /**
     * The season the ingestion is stamped with: a touched Competition's season
     * (they are all the current season — tenant+season filtered). Falls back to
     * the club's active season resolved from any Fixture, then to an empty guard
     * that never happens in practice (import always runs in a season context).
     *
     * @param list<array{name: string, divisionKey: string, label: string, labelKey: string, multiLabel: bool, rowCount: int, rows: list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>}> $groups
     */
    private function resolveSeasonId(Club $club, array $groups): string
    {
        // A resolvable division points at a Competition carrying the season.
        $resolver = $this->buildCompetitionResolver();
        foreach ($groups as $group) {
            $competition = $resolver($group['divisionKey'], $group['labelKey'], $group['multiLabel']);
            if ($competition instanceof Competition) {
                return $competition->getSeasonId();
            }
        }

        // No mapped division (first deposit before any mapping): read the season
        // from any Competition/Fixture of the club, then from the club's season
        // row — all tenant+season scoped, so they name the current season.
        $competition = $this->entityManager->getRepository(Competition::class)->findOneBy([]);
        if ($competition instanceof Competition) {
            return $competition->getSeasonId();
        }
        $fixture = $this->entityManager->getRepository(Fixture::class)->findOneBy([]);
        if ($fixture instanceof Fixture) {
            return $fixture->getSeasonId();
        }
        $season = $this->entityManager->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);

        return $season instanceof Season ? $season->getId() : $club->getId();
    }

    /**
     * Persists the manager's mapping choices as Competition rows (the durable
     * Division↔team correspondence, fact F7). Reuses an existing row for the
     * same (team, division) — or, when the mapping carries the SUGGESTION's
     * competitionId, the PAIRED competition itself (its name moves to the FBI
     * division label, the resolver key; the canonical FFBB name stays in
     * ffbbCompetitionName — refs/expectation/poule are reused, never duplicated).
     * Type inferred from the name (« Brassage »).
     *
     * @param list<array{division: string, fbiTeamLabel: string|null, teamId: string, competitionId: string|null}> $mappings
     */
    private function persistMappings(array $mappings, Club $club): void
    {
        if ([] === $mappings) {
            return;
        }
        $teamRepository = $this->entityManager->getRepository(Team::class);
        $competitionRepository = $this->entityManager->getRepository(Competition::class);
        $dirty = false;
        /** @var array<string, true> $seenBatch teamId|normalized name — the DB lookup cannot see unflushed siblings */
        $seenBatch = [];

        foreach ($mappings as $mapping) {
            $name = mb_substr(trim($mapping['division']), 0, 180);
            if ('' === $name) {
                throw ImportRejectedException::badRequest('Correspondance invalide : division vide.');
            }
            // Tenant+season filters scope the lookup: a foreign teamId is
            // invisible → clean rejection, no cross-tenant write.
            $team = $teamRepository->find($mapping['teamId']);
            if (!$team instanceof Team) {
                throw ImportRejectedException::badRequest(\sprintf('Correspondance « %s » : équipe introuvable.', $name));
            }

            // In-batch dedupe: two mappings sharing (team, division) in ONE call
            // would both miss the findOneBy (nothing flushed yet) and create
            // duplicate rows — the resolver ambiguity P4-67 warns about.
            $batchKey = $team->getId() . '|' . $this->normalizeLabel($name);
            if (isset($seenBatch[$batchKey])) {
                continue;
            }
            $seenBatch[$batchKey] = true;

            $label = null !== $mapping['fbiTeamLabel'] ? mb_substr(trim($mapping['fbiTeamLabel']), 0, 180) : null;
            $existing = null;
            $mappingCompetitionId = $mapping['competitionId'] ?? null;
            if (null !== $mappingCompetitionId) {
                $byId = $competitionRepository->findOneBy(['id' => $mappingCompetitionId]);
                // Honoured ONLY for the same team the manager chose — a drifted
                // suggestion must not hijack another team's pairing.
                if ($byId instanceof Competition && $byId->getTeamId() === $team->getId()) {
                    $existing = $byId;
                    if ($existing->getName() !== $name) {
                        $existing->setName($name);
                        $dirty = true;
                    }
                }
            }
            $existing ??= $competitionRepository->findOneBy(['teamId' => $team->getId(), 'name' => $name]);
            if ($existing instanceof Competition) {
                // The manager's LATEST choice wins: only refreshing a null label
                // would leave a drifted FBI label permanently unresolvable — the
                // manager re-maps, the import still reports the division
                // unmapped, forever (label mismatch loop).
                if (null !== $label && $existing->getFbiTeamLabel() !== $label) {
                    $existing->setFbiTeamLabel($label);
                    $dirty = true;
                }
                continue;
            }

            $competition = new Competition;
            $competition->setClubId($club->getId());
            $competition->setSeasonId($team->getSeasonId());
            $competition->setTeamId($team->getId());
            $competition->setName($name);
            $competition->setCompetitionType(
                str_contains($this->normalizeLabel($name), 'brassage') ? CompetitionType::BRASSAGE : CompetitionType::CHAMPIONSHIP,
            );
            $competition->setFbiTeamLabel($label);
            $this->entityManager->persist($competition);
            $dirty = true;
        }

        if ($dirty) {
            $this->entityManager->flush();
        }
    }

    /**
     * The persisted Division↔team resolver. Rules:
     * - single club label in the division → any Competition whose normalized
     *   name matches (label ignored: the nominal case stores none);
     * - several club labels in the division (two club teams, fact F7bis) → the
     *   Competition whose fbiTeamLabel matches the row's club label; a
     *   label-less Competition never resolves a multi-label division (it cannot
     *   say WHICH team it is — the manager re-maps once, per label).
     *
     * @return callable(string, string, bool): (Competition|null)
     */
    private function buildCompetitionResolver(): callable
    {
        /** @var array<string, list<Competition>> $byName tenant+season filters scope findAll() */
        $byName = [];
        foreach ($this->entityManager->getRepository(Competition::class)->findAll() as $competition) {
            $byName[$this->normalizeLabel($competition->getName())][] = $competition;
        }

        return function (string $divisionKey, string $labelKey, bool $multiLabel) use ($byName): ?Competition {
            $candidates = $byName[$divisionKey] ?? [];
            if ([] === $candidates) {
                return null;
            }
            if (!$multiLabel) {
                return $candidates[0];
            }
            foreach ($candidates as $candidate) {
                $candidateLabel = $candidate->getFbiTeamLabel();
                if (null !== $candidateLabel && $this->normalizeLabel($candidateLabel) === $labelKey) {
                    return $candidate;
                }
            }

            return null;
        };
    }

    /**
     * Groups parsed rows by division, splitting per club-team label when the
     * division carries several distinct ones (two club teams in one division).
     *
     * @param list<array{divisionName: string, divisionKey: string, clubLabel: string, clubLabelKey: string, numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}> $rows
     *
     * @return list<array{name: string, divisionKey: string, label: string, labelKey: string, multiLabel: bool, rowCount: int, rows: list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>}>
     */
    private function groupRows(array $rows): array
    {
        /** @var array<string, array<string, array{name: string, label: string, rows: list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>}>> $tree division → labelKey → bucket */
        $tree = [];
        foreach ($rows as $row) {
            $bucket = $tree[$row['divisionKey']][$row['clubLabelKey']] ?? ['name' => $row['divisionName'], 'label' => $row['clubLabel'], 'rows' => []];
            $bucket['rows'][] = [
                'numero' => $row['numero'],
                'matchDate' => $row['matchDate'],
                'homeAway' => $row['homeAway'],
                'opponentLabel' => $row['opponentLabel'],
                'kickoffTime' => $row['kickoffTime'],
                'venueLabel' => $row['venueLabel'],
            ];
            $tree[$row['divisionKey']][$row['clubLabelKey']] = $bucket;
        }

        $groups = [];
        foreach ($tree as $divisionKey => $byLabel) {
            $multiLabel = \count($byLabel) > 1;
            foreach ($byLabel as $labelKey => $bucket) {
                $groups[] = [
                    'name' => $bucket['name'],
                    'divisionKey' => $divisionKey,
                    'label' => $bucket['label'],
                    'labelKey' => $labelKey,
                    'multiLabel' => $multiLabel,
                    'rowCount' => \count($bucket['rows']),
                    'rows' => $bucket['rows'],
                ];
            }
        }

        return $groups;
    }

    /**
     * Parses the file into normalized rows + per-row errors + the exempt count.
     * Shared by analyze() and import() so both see the exact same rows.
     *
     * @return array{
     *     rows: list<array{divisionName: string, divisionKey: string, clubLabel: string, clubLabelKey: string, numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>,
     *     exempted: int,
     *     errors: list<string>,
     * }
     */
    private function parseFile(string $filePath, Club $club): array
    {
        // Reader pinned to Xlsx (no auto-detection): the upload check only
        // gates on name/mime, so an arbitrary payload must never reach the
        // Html/Csv/Xml readers (defense-in-depth, security-review PR-4).
        $spreadsheet = IOFactory::load($filePath, 0, [IOFactory::READER_XLSX]);
        $sheetRows = $spreadsheet->getActiveSheet()->toArray();

        if ([] === $sheetRows || $this->isEmptyRow($sheetRows[0] ?? [])) {
            return ['rows' => [], 'exempted' => 0, 'errors' => ['Excel file is empty.']];
        }

        $header = array_shift($sheetRows);
        $columnMap = $this->buildColumnMap($header);
        $columns = [];
        foreach (self::HEADER_CANDIDATES as $field => $candidates) {
            foreach ($candidates as $candidate) {
                if (isset($columnMap[$candidate])) {
                    $columns[$field] = $columnMap[$candidate];
                    break;
                }
            }
        }
        foreach (['division', 'numero', 'equipe1', 'equipe2', 'date'] as $required) {
            if (!isset($columns[$required])) {
                throw ImportRejectedException::badRequest('Colonnes requises manquantes : Division, N° de match, Equipe 1, Equipe 2, Date de rencontre.');
            }
        }

        $clubNeedle = $this->normalizeLabel($club->getName());
        $rows = [];
        $errors = [];
        $exempted = 0;

        foreach ($sheetRows as $rowIndex => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $line = $rowIndex + 2; // header consumed, xlsx rows are 1-based

            $divisionName = mb_substr($this->stringValue($row[$columns['division']] ?? null), 0, 180);
            $numero = $this->stringValue($row[$columns['numero']] ?? null);
            $equipe1 = $this->stringValue($row[$columns['equipe1']] ?? null);
            $equipe2 = $this->stringValue($row[$columns['equipe2']] ?? null);
            $rawDate = $row[$columns['date']] ?? null;
            $rawTime = isset($columns['heure']) ? ($row[$columns['heure']] ?? null) : null;
            $venueLabel = isset($columns['salle']) ? $this->stringValue($row[$columns['salle']] ?? null) : '';

            if (\in_array('', [$divisionName, $numero, $equipe1, $equipe2], true)) {
                $errors[] = \sprintf('Ligne %d : Division, N° de match, Equipe 1 et Equipe 2 sont requis.', $line);
                continue;
            }

            // A bye round (« Exempt » on either side) is not a match (fact F5).
            if (self::EXEMPT_LABEL === $this->normalizeLabel($equipe1) || self::EXEMPT_LABEL === $this->normalizeLabel($equipe2)) {
                ++$exempted;
                continue;
            }

            // Column is VARCHAR(64): an over-length number must be a row error,
            // not a DBAL exception aborting the whole import (security-review PR-4).
            if (mb_strlen($numero) > 64) {
                $errors[] = \sprintf('Ligne %d : numéro de rencontre trop long (max 64 caractères).', $line);
                continue;
            }

            // Word-boundary containment (space-padded), NOT raw substring:
            // club "BC Test" must not match opponent "BC Testville".
            $matchesHome = $this->containsClub($equipe1, $clubNeedle);
            $matchesAway = $this->containsClub($equipe2, $clubNeedle);
            if ($matchesHome === $matchesAway) {
                $errors[] = $matchesHome
                    ? \sprintf('Ligne %d : les deux équipes correspondent au club « %s » (derby intra-club) — saisissez ce match manuellement.', $line, $club->getName())
                    : \sprintf('Ligne %d : aucune équipe ne correspond au club « %s » — vérifiez le nom du club.', $line, $club->getName());
                continue;
            }

            $matchDate = $this->parseDate($rawDate);
            if (!$matchDate instanceof DateTimeImmutable) {
                $errors[] = \sprintf('Ligne %d : date de rencontre invalide (attendu jj/mm/aaaa).', $line);
                continue;
            }

            $kickoffTime = null;
            if (null !== $rawTime && '' !== $this->stringValue($rawTime)) {
                $kickoffTime = $this->parseTime($rawTime);
                if (!$kickoffTime instanceof DateTimeImmutable) {
                    $errors[] = \sprintf('Ligne %d : heure invalide (attendu HH:MM).', $line);
                    continue;
                }
                // « 00:00 » is the FBI sentinel for « not set yet » (fact F2:
                // 45/124 rows of the real export) — storing midnight would
                // fabricate placements. No real match kicks off at midnight.
                if ('00:00' === $kickoffTime->format('H:i')) {
                    $kickoffTime = null;
                }
            }

            $clubLabel = $matchesHome ? $equipe1 : $equipe2;
            $rows[] = [
                'divisionName' => $divisionName,
                'divisionKey' => $this->normalizeLabel($divisionName),
                'clubLabel' => mb_substr($clubLabel, 0, 180),
                'clubLabelKey' => $this->normalizeLabel($clubLabel),
                'numero' => $numero,
                'matchDate' => $matchDate,
                'homeAway' => $matchesHome ? FixtureHomeAway::HOME : FixtureHomeAway::AWAY,
                // Column is VARCHAR(180) — clamp instead of failing the row on
                // an absurdly long label.
                'opponentLabel' => mb_substr($matchesHome ? $equipe2 : $equipe1, 0, 180),
                'kickoffTime' => $kickoffTime,
                'venueLabel' => '' === $venueLabel ? null : mb_substr($venueLabel, 0, 180),
            ];
        }

        return ['rows' => $rows, 'exempted' => $exempted, 'errors' => $errors];
    }

    /**
     * "03/10/2026" or "3/10/2026" (single digits tolerated — an Excel d/m/yyyy
     * cell format renders unpadded), optionally followed by a time, or an Excel
     * serial → the match date. Calendar-invalid dates (31/02) are rejected
     * instead of silently rolling over.
     */
    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (is_numeric($value) && !\is_string($value)) {
            return DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $value))->setTime(0, 0);
        }

        $text = $this->stringValue($value);
        if (1 !== preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $text, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!j/n/Y', \sprintf('%d/%d/%d', (int) $m[1], (int) $m[2], (int) $m[3]));

        return false === $date ? null : $date;
    }

    /** "15:30" / "9:30" or an Excel day-fraction → the kickoff time. */
    private function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (is_numeric($value) && !\is_string($value)) {
            $minutes = (int) round(((float) $value) * 24 * 60);

            return new DateTimeImmutable('1970-01-01')->setTime(intdiv($minutes, 60) % 24, $minutes % 60);
        }

        $text = $this->stringValue($value);
        if (1 === preg_match('/^(\d{1,2}):([0-5]\d)/', $text, $m) && (int) $m[1] <= 23) {
            return new DateTimeImmutable('1970-01-01')->setTime((int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /**
     * Whole-word containment of the club needle in a team label: both sides are
     * normalized and space-padded so "bc test" matches "bc test 1" but never
     * "bc testville" (word boundary, not substring).
     */
    private function containsClub(string $label, string $clubNeedle): bool
    {
        return str_contains(' ' . $this->normalizeLabel($label) . ' ', ' ' . $clubNeedle . ' ');
    }

    /**
     * Header labels AND team labels tolerate case/accents/spacing drift: FBI
     * exports are not under our control.
     */
    private function normalizeLabel(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = mb_strtolower(false === $ascii ? $value : $ascii, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $lower)));
    }

    /**
     * @param array<mixed> $header
     *
     * @return array<string, int>
     */
    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $value) {
            $key = $this->normalizeLabel($this->stringValue($value));
            if ('' !== $key) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    private function stringValue(mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (\is_string($value)) {
            return trim($value);
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    /** @param array<mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ('' !== $this->stringValue($value)) {
                return false;
            }
        }

        return true;
    }
}
