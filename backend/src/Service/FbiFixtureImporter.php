<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\FbiCorrection;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\FbiCorrectionField;
use App\Enum\FbiIngestionSource;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureStatus;
use App\Exception\ImportRejectedException;
use App\Service\Basketball\FfbbRencontreReconciler;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Fbi\FbiArrivalReview;
use App\Service\Fbi\FbiDeviationService;
use App\Service\Fbi\FbiMappingGuards;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
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
 *
 * ⚑ The reconciliation deviation ENGINE (detect + take_file per field + record /
 * group / indexDecisions + the venue-name index) is exposed `public` and is the
 * ONE home of the app⇄file diff semantics: the FFBB-API channel (RMM-4 PR-3,
 * {@see FfbbRencontreReconciler}) reuses it verbatim on
 * rows of the same shape, so the two channels reconcile identically — never a
 * second copy that could drift.
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
        private readonly ClockInterface $clock,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly FbiArrivalReview $arrivalReview,
        private readonly FbiDeviationService $deviationService,
        private readonly FbiMappingGuards $mappingGuards,
        private readonly FbiCorrectionLedger $ledger,
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
        $suggester = $this->mappingGuards->buildSuggestionResolver();

        // Reconciliation (RMM-4): the deviations of home fixtures ALREADY placed
        // are computed against what is persisted, read-only. Only a resolvable
        // division can diverge — an unmapped one has no fixtures yet.
        $existingByTeamRef = $this->indexExistingByTeamRef();
        $venueNames = $this->venueNamesById();
        // « persisting » (dry-run) = la rencontre porte DÉJÀ un écart ouvert pour ce
        // champ (dépôt antérieur). La trace vit sur la fixture (PR-3a D7).
        /** @var array<string, true> $persistingSet */
        $persistingSet = [];
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
            $suggested = $competition instanceof Competition || $group['multiLabel'] ? null : $suggester($group['name']);
            $guard = $competition instanceof Competition ? $this->mappingGuards->pouleGuard($competition, $group['rows'], $group['name']) : null;
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
                if (null === $fields) {
                    // Hors du périmètre placé : un domicile UNPLACED dont la ligue change
                    // la salle est un écart aussi (jamais réécrit en silence).
                    $venueDeviation = $this->detectUnplacedVenueDeviation($existing, $row, $venueNames);
                    if (null === $venueDeviation) {
                        continue;
                    }
                    $fields = ['venue' => $venueDeviation];
                } elseif ([] === $fields) {
                    continue;
                }
                $seenFixtures[$existing->getId()] = true;
                foreach ($fields as $field => $vals) {
                    if (null !== $existing->getPendingDeviation($field)) {
                        $persistingSet[$existing->getId() . '|' . $field] = true;
                    }
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
    public function import(string $filePath, Club $club, array $mappings, array $decisions = [], ?string $seasonId = null): array
    {
        $parsed = $this->parseFile($filePath, $club);
        $errors = $parsed['errors'];
        $groups = $this->groupRows($parsed['rows']);

        // Reconciliation (RMM-4): verdicts keyed « fixtureId|field » and the venue
        // names for the fuzzy salle compare. The trace of open écarts lives on the
        // fixtures themselves now (PR-3a D7) — « persisting » is captured per field
        // as it is processed, before the fixture's entries are rewritten.
        $decisionMap = $this->indexDecisions($decisions);
        $venueNames = $this->venueNamesById();
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        // Décision P4-199 — la borne « FBI fait foi » (dimanche de la semaine ISO
        // en cours, fuseau club), calculée UNE fois pour ce dépôt.
        $weekEnd = $this->currentIsoWeekEnd($club);
        /** @var array<string, true> $persistingSet */
        $persistingSet = [];
        /** @var list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $deviationRecords */
        $deviationRecords = [];

        // P1-4 PR F2 (round 1 de revue) — le garde-fou PRÉCÈDE l'écriture des
        // mappings : un mapping dont la division est refusée n'est PAS persisté
        // (le dialog n'a pas de geste de re-mapping — une suggestion fautive
        // auto-envoyée collerait pour toujours).
        $blockedKeys = [];
        $mappings = $this->mappingGuards->rejectGuardBlockedMappings($mappings, $groups, $errors, $blockedKeys);

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
            $guard = $this->mappingGuards->pouleGuard($competition, $group['rows'], $group['name']);
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
                    $outcome = $this->applyDiff($existing, $row, $group['name'], $warnings, $decisionMap, $venueNames, $deviationRecords, $persistingSet, $now, $weekEnd);
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
                // P4-187a — un domicile dont le libellé égale un alias confirmé
                // naît AVEC son gymnase (mais UNPLACED : jamais placé d'office).
                $this->attachConfirmedVenue($fixture, $row['venueLabel']);
                // P4-199 — extérieur / passé / semaine ISO en cours → naît traité.
                $this->treatOnArrival($fixture, $now, $club);
                $this->entityManager->persist($fixture);
                ++$created;
            }
        }

        // Reconciliation bookkeeping (RMM-4): the report's unresolved écarts (no
        // decision) — left INTACT and reported, never overwritten by default. The
        // open-écart trace itself lives on the fixtures (PR-3a D7), so this deposit
        // no longer carries one; the ingestion keeps freshness + counters only.
        $unresolvedRecords = array_values(array_filter($deviationRecords, static fn (array $r): bool => 'none' === $r['effect']));
        $unresolvedDeviations = $this->groupDeviations($unresolvedRecords, $persistingSet);

        $ingestion = new FbiIngestion(
            $club->getId(),
            $seasonId ?? $this->resolveSeasonId($club, $groups),
            FbiIngestionSource::FBI_XLSX,
            $now,
            $created,
            $updated,
            $unchanged,
            \count($deviationRecords),
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
            // BCK-31 — COUNT natif (zéro hydratation) : la complétude ne lit qu'un nombre.
            $imported = $this->entityManager->getRepository(Fixture::class)->count(['competitionId' => $competition->getId()]);
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
    public function detectFieldDeviations(Fixture $existing, array $row, array $venueNames): ?array
    {
        return $this->deviationService->detectFieldDeviations($existing, $row, $venueNames);
    }

    /**
     * L'écart salle d'un domicile NON PLACÉ mais RATTACHÉ à un gymnase (venueId non
     * null) dont la source nomme une AUTRE salle : sinon le libellé serait réécrit en
     * silence sans jamais interroger le gestionnaire ni corriger le gymnase (les 20
     * cas mesurés — venueId JDR, libellé « SALLE RAPHAEL DE BARROS », alias confirmé de
     * Debarros). Complète {@see detectFieldDeviations} (qui, lui, ne couvre QUE le
     * placé) et partage le MÊME moteur d'arbitrage aux deux canaux (import xlsx + API).
     * Lecture seule. Lève ssi :
     *  - HOME des deux côtés · statut UNPLACED ;
     *  - venueId non null ET connu (un gymnase du club) · libellé fichier non null ;
     *  - date fichier == date app (un re-datage passe par le chemin actuel, décision
     *    fondateur : `unplace` y vide déjà le venueId) ;
     *  - divergence RÉELLE : ni le fuzzy nom↔libellé NI l'alias confirmé du libellé ne
     *    pointent le gymnase courant (la clause alias évite un faux écart quand le
     *    gymnase EST celui de l'alias) ;
     *  - le libellé normalisé n'est pas déjà « gardé » (idempotence keep_app, E).
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     * @param array<string, string>                                                                                                                                               $venueNames venueId → Venue name
     *
     * @return array{app: string, file: string}|null null = pas d'écart salle à arbitrer
     */
    public function detectUnplacedVenueDeviation(Fixture $existing, array $row, array $venueNames): ?array
    {
        return $this->deviationService->detectUnplacedVenueDeviation($existing, $row, $venueNames);
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
    public function applyFieldTakeFile(Fixture $existing, string $field, array $row, DateTimeImmutable $now): void
    {
        $this->deviationService->applyFieldTakeFile($existing, $field, $row, $now);
    }

    /**
     * « Garder l'appli » sur l'écart salle d'un domicile NON PLACÉ (E) : on garde le
     * gymnase courant, on adopte le libellé BRUT de la source dans `fbiVenueLabel`, on
     * MÉMORISE ce libellé normalisé dans `keptVenueLabel` (pense-bête d'idempotence :
     * un re-dépôt du même libellé ne repose plus la question) et l'écart salle est
     * retiré. Foyer partagé par le moteur d'import ({@see processPerimeterFields}) et
     * l'arbitrage hors dépôt ({@see App\Controller\ReviewFixtureDeviationController}).
     */
    public function applyVenueKeepApp(Fixture $fixture, string $fileLabel): void
    {
        $this->deviationService->applyVenueKeepApp($fixture, $fileLabel);
    }

    /**
     * @param array{app: string|null, file: string|null} $vals
     * @param string|null                                $status the status the manager saw (defaults to the live one — analyze never mutates)
     *
     * @return array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}
     */
    public function deviationRecord(Fixture $existing, string $field, array $vals, string $divisionName, string $effect, ?string $status = null): array
    {
        return $this->deviationService->deviationRecord($existing, $field, $vals, $divisionName, $effect, $status);
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
    public function groupDeviations(array $records, array $persistingSet): array
    {
        return $this->deviationService->groupDeviations($records, $persistingSet);
    }

    /**
     * venueId → Venue name, scoped to the club+season by the tenant/season
     * filters — for the fuzzy salle compare of a placed home fixture.
     *
     * @return array<string, string>
     */
    public function venueNamesById(): array
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
    public function indexDecisions(array $decisions): array
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
     * Whole-word containment of the club needle in a team label: both sides are
     * normalized and space-padded so "bc test" matches "bc test 1" but never
     * "bc testville" (word boundary, not substring).
     */
    public function containsClub(string $label, string $clubNeedle): bool
    {
        return $this->labelNormalizer->containsWord($label, $clubNeedle);
    }

    /**
     * Header labels AND team labels tolerate case/accents/spacing drift: FBI
     * exports are not under our control. Délégué au foyer unique de normalisation
     * ({@see VenueLabelNormalizer}) — signature publique conservée, appelants
     * inchangés (P4-187a D1).
     */
    public function normalizeLabel(string $value): string
    {
        return $this->labelNormalizer->normalize($value);
    }

    /**
     * Retire le suffixe FFBB « (n) » d'un libellé d'équipe — délégué au foyer unique
     * ({@see VenueLabelNormalizer::stripTeamNumberSuffix}, P4-199). Public pour que le
     * canal API ({@see FfbbRencontreReconciler}) le partage sans en recopier la regex.
     */
    public function stripTeamNumberSuffix(string $label): string
    {
        return $this->labelNormalizer->stripTeamNumberSuffix($label);
    }

    /**
     * Retire le suffixe d'équipe « - n » d'un libellé — délégué au foyer unique
     * ({@see VenueLabelNormalizer::stripTrailingTeamNumber}). Public pour que le
     * rapprochement au nom ({@see App\Service\Basketball\OpponentLocationResolver})
     * le partage sans recopier la regex.
     */
    public function stripTrailingTeamNumber(string $label): string
    {
        return $this->labelNormalizer->stripTrailingTeamNumber($label);
    }

    /**
     * Décision fondateur P4-199 — une rencontre naît DÉJÀ traitée (REVIEWED +
     * horodatée) dès sa création dans deux cas : (1) c'est un EXTÉRIEUR (le club ne
     * la place pas, rien à examiner) ; (2) sa date est passée OU tombe dans la
     * semaine ISO en cours (borne = dimanche de la semaine, fuseau du club) — une
     * rencontre déjà jouée ou imminente n'est pas « nouvelle ». Un domicile futur
     * hors de cette fenêtre reste NEW (à examiner et placer). Foyer unique appelé
     * aux deux canaux (import xlsx + canal API).
     */
    public function treatOnArrival(Fixture $fixture, DateTimeImmutable $now, Club $club): void
    {
        $this->arrivalReview->treatOnArrival($fixture, $now, $club);
    }

    /**
     * D2 (rattrapage) — une rencontre EXISTANTE restée « à traiter » (NEW) d'un
     * dépôt antérieur à la naissance-traitée est rattrapée : traitée (REVIEWED +
     * horodatée) dès qu'elle remplit le MÊME prédicat qu'{@see treatOnArrival}
     * (extérieur, ou date ≤ dimanche de la semaine ISO en cours — fenêtre passée
     * par l'appelant, fuseau du club). Ne touche JAMAIS un OUT_OF_SYNC ni un
     * REVIEWED (leur état de traitement est déjà posé) ; idempotent. Le rattrapage
     * n'est PAS une donnée de match : il ne compte pas dans created/updated/
     * unchanged du rapport d'import. Foyer partagé aux deux canaux (re-dépôt xlsx
     * + passage API) et à la commande de rattrapage hors ligne.
     */
    public function catchUpReview(Fixture $existing, DateTimeImmutable $now, DateTimeImmutable $weekEnd): bool
    {
        return $this->arrivalReview->catchUpReview($existing, $now, $weekEnd);
    }

    /**
     * Le dimanche (borne haute, date civile) de la semaine ISO EN COURS du club. « Quel
     * jour est-on pour ce club ? » vient du foyer {@see ClubDay} (une génération à
     * 00:30 UTC un lundi est encore dimanche à Paris — c'est lui qui le sait). La
     * semaine ISO commence le lundi (jour 1) : dimanche = aujourd'hui + (7 − jour ISO).
     */
    public function currentIsoWeekEnd(Club $club): DateTimeImmutable
    {
        return $this->arrivalReview->currentIsoWeekEnd($club);
    }

    /**
     * Décision fondateur P4-199 — sur un domicile PLACÉ déphasé, la source « fait
     * foi » (appliquée d'office, sans arbitrage) quand la date Amateo OU la date de
     * la source tombe dans la fenêtre (≤ dimanche de la semaine ISO en cours) :
     * « app OU source ». Un déphasage entièrement futur reste un arbitrage.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     */
    public function sourceIsAuthoritativeForWindow(Fixture $existing, array $row, DateTimeImmutable $weekEnd): bool
    {
        return $this->arrivalReview->sourceIsAuthoritativeForWindow($existing, $row, $weekEnd);
    }

    /**
     * The SHARED reconciliation engine of a matched, in-perimeter fixture with at
     * least one divergent field — used by BOTH channels (xlsx import + FFBB-API
     * apply), never a second copy. Per field: a `take_file` writes the source value
     * and RESOLVES the écart, a `keep_app` resolves it (the app value stays), and
     * NO decision leaves it INTACT as an open pending entry (« déphasée »). Fields
     * that stopped diverging drop their stale entry. The reviewState follows: still
     * pending ⇒ OUT_OF_SYNC, all resolved ⇒ REVIEWED + horodaté (D5). Populates
     * `$persistingSet` (before rewriting the entries) and the flat `$records`.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}       $row
     * @param array<string, array{app: string|null, file: string|null}>                                                                                                                 $fields                the CURRENT divergences (non-empty), keyed by field
     * @param array<string, string>                                                                                                                                                     $decisions             fixtureId|field → keep_app|take_file
     * @param 'FBI_XLSX'|'FFBB_API'                                                                                                                                                     $channel
     * @param list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records
     * @param array<string, true>                                                                                                                                                       $persistingSet
     * @param list<array{type: string, division: string, externalRef: string, message: string}>                                                                                         $warnings
     * @param bool                                                                                                                                                                      $sourceIsAuthoritative décision P4-199 « FBI fait foi » : un champ non tranché est appliqué D'OFFICE (voir {@see sourceIsAuthoritativeForWindow})
     * @param list<string>|null                                                                                                                                                         $scope                 champs dont la boucle de purge des entrées périmées s'occupe (défaut : tous les {@see DEVIATION_FIELDS}) — restreint à `['venue']` pour l'écart salle d'un non placé, sinon la purge effacerait les entrées `autoApplied` date/heure posées le même dépôt
     */
    public function processPerimeterFields(Fixture $existing, array $row, array $fields, array $decisions, string $channel, string $divisionName, array &$records, array &$persistingSet, array &$warnings, DateTimeImmutable $now, bool $sourceIsAuthoritative = false, ?array $scope = null): bool
    {
        $changed = false;
        // The status the manager SAW — captured before any take_file mutates it.
        $status = $existing->getStatus()->value;
        foreach ($fields as $field => $vals) {
            // « persisting » = the écart was already open before this deposit —
            // captured BEFORE the entry is rewritten below.
            if (null !== $existing->getPendingDeviation($field)) {
                $persistingSet[$existing->getId() . '|' . $field] = true;
            }
            $choice = $decisions[$existing->getId() . '|' . $field] ?? null;
            $effect = 'none';
            if ('take_file' === $choice) {
                // The only branch that mutates the rencontre DATA → « updated ».
                $this->applyFieldTakeFile($existing, $field, $row, $now);
                $warnings[] = $this->takeFileWarning($field, $divisionName, $row);
                $existing->removePendingDeviation($field);
                $changed = true;
                $effect = 'take_file';
            } elseif ('keep_app' === $choice) {
                // Keep the app value: the écart is resolved but NO data changes
                // (« unchanged » as far as the rencontre content goes).
                if ('venue' === $field && FixtureStatus::UNPLACED->value === $status) {
                    // Garder l'appli sur la salle d'un NON PLACÉ : on mémorise le
                    // libellé source pour l'idempotence (E) — le gymnase courant reste.
                    $this->applyVenueKeepApp($existing, (string) $vals['file']);
                } else {
                    $existing->removePendingDeviation($field);
                }
                // « Garder l'appli » = FBI est en retard → une entrée « à corriger dans
                // FBI ». Les DEUX canaux (xlsx + API) passent par ce moteur partagé.
                $this->ledger->open($existing, FbiCorrectionField::from($field), $vals['app'], $vals['file'], $now);
                $effect = 'keep_app';
            } elseif ($sourceIsAuthoritative) {
                // Décision P4-199 — « FBI fait foi » : la source est appliquée
                // d'OFFICE (date/salle dé-placent, heure en place), et une entrée
                // `autoApplied` remplace l'écart à arbitrer pour ALLUMER le bandeau
                // « la source a déplacé ce match » — la rencontre reste traitée.
                $this->applyFieldTakeFile($existing, $field, $row, $now);
                $existing->putPendingDeviation($this->deviationService->pendingEntry($existing, $field, $vals, $channel, $now, true));
                // (d) Fenêtre imminente : l'appli s'aligne d'office sur la source — toute
                // entrée « à corriger dans FBI » de ce champ n'a plus d'objet, elle se ferme.
                $this->deviationService->closeOpenCorrection($existing, $field, $now);
                $changed = true;
                $effect = 'take_file';
            } else {
                $openCorrection = $this->ledger->findOpen($existing, FbiCorrectionField::from($field));
                if ($openCorrection instanceof FbiCorrection && $this->ledger->stillShowsRecordedValue($openCorrection, $vals['file'])) {
                    // (a) FBI affiche TOUJOURS la valeur d'origine : le gestionnaire a déjà
                    // tranché « garder l'appli », il n'y a rien de neuf à traiter — on ne
                    // re-crée PAS d'écart, on re-date juste « vu dans FBI ».
                    $this->ledger->refreshSeen($openCorrection, $now);

                    continue;
                }
                if ($openCorrection instanceof FbiCorrection) {
                    // (c) FBI affiche une TROISIÈME valeur : l'ancienne correction est
                    // caduque (fermée par le dépôt) ET un écart normal s'ouvre à arbitrer.
                    $this->ledger->closeBySource($openCorrection, $now);
                }
                $existing->putPendingDeviation($this->deviationService->pendingEntry($existing, $field, $vals, $channel, $now, false));
            }
            $records[] = $this->deviationRecord($existing, $field, $vals, $divisionName, $effect, $status);
        }

        // A field that stopped diverging (the source came back to the app value)
        // drops its stale pending entry — the écart resolved itself (no data change).
        // La purge est bornée au SCOPE : pour l'écart salle d'un non placé (scope
        // ['venue']), elle ne touche PAS les entrées autoApplied date/heure posées le
        // même dépôt (piège vérifié).
        foreach ($scope ?? self::DEVIATION_FIELDS as $field) {
            if (isset($fields[$field])) {
                continue;
            }
            // (b) Le champ ne diverge plus : FBI reflète de nouveau l'appli → l'entrée
            // « à corriger dans FBI » est FAITE (fermée par le dépôt).
            $this->deviationService->closeOpenCorrection($existing, $field, $now);
            if (null !== $existing->getPendingDeviation($field)) {
                $existing->removePendingDeviation($field);
            }
        }

        if ($sourceIsAuthoritative) {
            // La source a fait foi : les entrées restantes sont toutes `autoApplied`
            // (bandeau), la rencontre est traitée — on contourne finalizeReview qui
            // la rangerait OUT_OF_SYNC sur ces pending.
            $existing->markReviewed($now);
        } else {
            $this->finalizeReview($existing, $now);
        }

        return $changed;
    }

    /**
     * A matched, in-perimeter fixture whose source shows NO divergence. Two effects
     * (shared by both channels): (1) D9 — a HOME match PLACED/SUBMITTED whose source
     * positively attests date AND kickoff AND venue is ATTESTED by the federation →
     * VALIDATED + traité; (2) any stale pending entry drops (the source agrees now),
     * and if that resolves the last one, the match becomes REVIEWED.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     * @param array<string, string>                                                                                                                                               $venueNames
     */
    public function reconcileNoDivergence(Fixture $existing, array $row, array $venueNames, DateTimeImmutable $now): bool
    {
        $changed = false;
        $hadPending = $existing->hasPendingDeviations();
        // Aucun écart : FBI reflète de nouveau l'appli sur TOUS les champs → toute
        // entrée « à corriger dans FBI » de cette rencontre est faite (fermée par dépôt).
        foreach ($this->ledger->findOpenByFixture($existing) as $entry) {
            $this->ledger->closeBySource($entry, $now);
        }
        foreach (self::DEVIATION_FIELDS as $field) {
            if (null !== $existing->getPendingDeviation($field)) {
                $existing->removePendingDeviation($field);
                $changed = true;
            }
        }

        $status = $existing->getStatus();
        if ((FixtureStatus::PLACED === $status || FixtureStatus::SUBMITTED === $status)
            && $this->sourceAttestsPlacement($existing, $row, $venueNames)) {
            // D9 — the FBI/FFBB source attests the placed match verbatim.
            $existing->setStatus(FixtureStatus::VALIDATED, $now);

            return true;
        }
        if ($hadPending && !$existing->hasPendingDeviations()) {
            // The last open écart resolved because the source returned to the app value.
            $existing->markReviewed($now);
            $changed = true;
        }

        return $changed;
    }

    /**
     * D9 — the source positively attests the three placed fields: a real kickoff
     * (not the 00:00 sentinel), a named salle that matches the placed Venue, and a
     * date (always present in a valid row). Called only when there is NO divergence,
     * so equality of the compared fields is already established.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     * @param array<string, string>                                                                                                                                               $venueNames
     */
    public function sourceAttestsPlacement(Fixture $existing, array $row, array $venueNames): bool
    {
        $venueId = $existing->getVenueId();

        return FixtureHomeAway::HOME === $existing->getHomeAway()
            && $row['kickoffTime'] instanceof DateTimeImmutable
            && null !== $row['venueLabel']
            && null !== $venueId
            && isset($venueNames[$venueId]);
    }

    /**
     * Façade conservée pour les consommateurs (p. ex.
     * {@see App\Service\Basketball\FfbbRencontreReconciler}) : délègue au foyer
     * {@see FbiDeviationService::attachConfirmedVenue} (P4-187a D3 — rattachement
     * d'un gymnase depuis un alias confirmé, jamais de placement d'office).
     *
     * @return bool vrai = un gymnase a été rattaché (la rencontre a « changé »)
     */
    public function attachConfirmedVenue(Fixture $fixture, ?string $venueLabel): bool
    {
        return $this->deviationService->attachConfirmedVenue($fixture, $venueLabel);
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
     * @param array<string, string>                                                                                                                                                     $decisions     fixtureId|field → keep_app|take_file
     * @param array<string, string>                                                                                                                                                     $venueNames    venueId → Venue name (for the fuzzy salle compare)
     * @param list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records       collected per-field deviation records
     * @param array<string, true>                                                                                                                                                       $persistingSet « fixtureId|field » of écarts already open before this deposit (populated here)
     */
    private function applyDiff(Fixture $existing, array $row, string $divisionName, array &$warnings, array $decisions, array $venueNames, array &$records, array &$persistingSet, DateTimeImmutable $now, DateTimeImmutable $weekEnd): string
    {
        // D2 (rattrapage) — un existant matché resté « à traiter » (NEW) d'un dépôt
        // antérieur à la naissance-traitée est rattrapé ici, avant tout diff. Son
        // retour n'alimente PAS updated/unchanged (état de traitement, pas donnée).
        $this->catchUpReview($existing, $now, $weekEnd);

        $fields = $this->detectFieldDeviations($existing, $row, $venueNames);
        if (null !== $fields) {
            $changed = [] === $fields
                ? $this->reconcileNoDivergence($existing, $row, $venueNames, $now)
                : $this->processPerimeterFields($existing, $row, $fields, $decisions, FbiIngestionSource::FBI_XLSX->value, $divisionName, $records, $persistingSet, $warnings, $now, $this->sourceIsAuthoritativeForWindow($existing, $row, $weekEnd));

            // xlsx-only silent updates (D3): the opponent label always, the raw
            // fbiVenueLabel only when the venue is not itself a divergence (a
            // venue decision owns the label write).
            if ($existing->getOpponentLabel() !== $row['opponentLabel']) {
                $existing->setOpponentLabel($row['opponentLabel']);
                $changed = true;
            }
            if (!isset($fields['venue']) && null !== $row['venueLabel'] && $existing->getFbiVenueLabel() !== $row['venueLabel']) {
                $existing->setFbiVenueLabel($row['venueLabel']);
                $changed = true;
            }
            // P4-187a — un domicile encore sans salle (donc jamais un écart venue)
            // retrouve son gymnase depuis un alias confirmé, sans le placer.
            if ($this->attachConfirmedVenue($existing, $existing->getFbiVenueLabel())) {
                $changed = true;
            }

            return $changed ? 'updated' : 'unchanged';
        }

        // ── NOT the deviation perimeter → pre-RMM-4 behaviour, integral ──────
        $changed = false;
        $wasPlaced = FixtureStatus::UNPLACED !== $existing->getStatus();
        // The three fields the source auto-applies out of the perimeter, captured
        // old→new to leave an « auto-applied » trace when the match was treated.
        /** @var array<string, array{app: string|null, file: string|null}> $autoApplied */
        $autoApplied = [];

        if ($existing->getMatchDate()->format('Y-m-d') !== $row['matchDate']->format('Y-m-d')) {
            $oldIso = $existing->getMatchDate()->format('Y-m-d');
            $oldDate = $existing->getMatchDate()->format('d/m/Y');
            $existing->setMatchDate($row['matchDate']);
            $this->deviationService->unplace($existing, $now);
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
            $autoApplied['date'] = ['app' => $oldIso, 'file' => $row['matchDate']->format('Y-m-d')];
            $changed = true;
        }

        if ($existing->getHomeAway() !== $row['homeAway']) {
            $existing->setHomeAway($row['homeAway']);
            $this->deviationService->unplace($existing, $now);
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
                $autoApplied['kickoff'] = ['app' => $current?->format('H:i'), 'file' => $row['kickoffTime']->format('H:i')];
                $changed = true;
            }
        }

        if ($existing->getOpponentLabel() !== $row['opponentLabel']) {
            $existing->setOpponentLabel($row['opponentLabel']);
            $changed = true;
        }

        // Un domicile UNPLACED rattaché à un gymnase dont la source nomme une AUTRE
        // salle est un écart à ARBITRER, jamais un libellé réécrit en silence (les 20
        // cas). La réécriture silencieuse ci-dessous est SAUTÉE quand l'écart lève —
        // « une décision de salle possède l'écriture du libellé » (même principe que
        // le périmètre placé).
        $venueDeviation = $this->detectUnplacedVenueDeviation($existing, $row, $venueNames);
        if (null === $venueDeviation && null !== $row['venueLabel'] && $existing->getFbiVenueLabel() !== $row['venueLabel']) {
            $existing->setFbiVenueLabel($row['venueLabel']);
            $changed = true;
        }

        $this->deviationService->recordAutoApplied($existing, $autoApplied, FbiIngestionSource::FBI_XLSX->value, $now);

        // P4-187a — un domicile UNPLACED (hors périmètre) dont le libellé égale un
        // alias confirmé retrouve son gymnase, toujours sans le placer. No-op quand un
        // écart salle est levé (venueId déjà posé, donc rien à rattacher).
        if ($this->attachConfirmedVenue($existing, $existing->getFbiVenueLabel())) {
            $changed = true;
        }

        if (null !== $venueDeviation) {
            // Écart salle d'un non placé : moteur d'arbitrage partagé, JAMAIS « FBI
            // fait foi » (une salle de non placé n'est jamais appliquée d'office), et
            // scope ['venue'] pour que la purge n'efface pas les entrées date/heure
            // autoApplied posées le même dépôt. Un take_file/keep_app éventuel (décision
            // du dialog) écrit une donnée → « updated ».
            if ($this->processPerimeterFields($existing, $row, ['venue' => $venueDeviation], $decisions, FbiIngestionSource::FBI_XLSX->value, $divisionName, $records, $persistingSet, $warnings, $now, false, ['venue'])) {
                $changed = true;
            }
        } elseif (null !== $existing->getPendingDeviation('venue')) {
            // Le libellé est redevenu conforme (le fuzzy/alias matche, ou le gymnase a
            // été rattaché) : l'entrée salle pendante tombe, et si c'était la dernière
            // la rencontre est traitée (miroir du placé).
            $existing->removePendingDeviation('venue');
            if (!$existing->hasPendingDeviations()) {
                $existing->markReviewed($now);
            }
            $changed = true;
        }

        return $changed ? 'updated' : 'unchanged';
    }

    /** Still pending ⇒ OUT_OF_SYNC ; all resolved ⇒ REVIEWED + horodaté (D5). */
    private function finalizeReview(Fixture $existing, DateTimeImmutable $now): void
    {
        if ($existing->hasPendingDeviations()) {
            $existing->setReviewState(FixtureReviewState::OUT_OF_SYNC);

            return;
        }
        $existing->markReviewed($now);
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
        if (!$season instanceof Season) {
            // Jamais atteint : la gate d'import exige un socle pointé, donc une
            // saison. Écrire un id de CLUB en season_id (l'ancien repli) aurait
            // fabriqué une ligne invisible des purges — on refuse net.
            throw new RuntimeException('Import sans saison résolue.');
        }

        return $season->getId();
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
            return ['rows' => [], 'exempted' => 0, 'errors' => ['Le fichier est vide.']];
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

            // P4-199 — le suffixe FFBB « (n) » (deux engagements homonymes) est retiré
            // du libellé du club ET de l'adversaire dès la lecture (foyer unique
            // {@see VenueLabelNormalizer::stripTeamNumberSuffix}) : ni la clé de
            // rapprochement ni le libellé stocké ne le portent plus.
            $clubLabel = $this->labelNormalizer->stripTeamNumberSuffix($matchesHome ? $equipe1 : $equipe2);
            $opponentLabel = $this->labelNormalizer->stripTeamNumberSuffix($matchesHome ? $equipe2 : $equipe1);
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
                'opponentLabel' => mb_substr($opponentLabel, 0, 180),
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
