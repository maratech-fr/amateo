<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\Team;
use App\Enum\CompetitionType;
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
        // « persisting » (dry-run) = the fixture already carries an open écart for
        // that field (the trace lives on the fixture now, PR-3a D7).
        /** @var array<string, true> $persistingSet */
        $persistingSet = [];
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
                if (null !== $fixture->getPendingDeviation($field)) {
                    $persistingSet[$fixture->getId() . '|' . $field] = true;
                }
                $records[] = $this->importer->deviationRecord($fixture, $field, $vals, $row['competitionName'], 'none');
            }
        }

        return [
            'deviations' => $this->importer->groupDeviations($records, $persistingSet),
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
        $now = $this->now();
        // P4-199 — le club porte le fuseau : il gouverne la naissance « traitée »
        // (extérieur / passé / semaine ISO en cours) et la borne « FBI fait foi ».
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        $weekEnd = $club instanceof Club ? $this->importer->currentIsoWeekEnd($club) : null;

        /** @var list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records */
        $records = [];
        /** @var array<string, true> $persistingSet */
        $persistingSet = [];
        /** @var list<array{type: string, division: string, externalRef: string, message: string}> $discardedWarnings the shared engine emits warnings; the API channel does not surface them */
        $discardedWarnings = [];
        $consumed = [];
        // P4-194 — la carte des compétitions résolues-ou-créées PENDANT ce run,
        // clé (ffbbId|teamId) : deux rencontres de la MÊME coupe partagent UNE
        // compétition, et le rattachage rétroactif et la création la réutilisent.
        /** @var array<string, Competition> $createdByKey */
        $createdByKey = [];

        foreach ($rows as $row) {
            [$fixture] = $this->matchRow($row, $context, $consumed);
            if (!$fixture instanceof Fixture) {
                continue;
            }
            $consumed[$fixture->getId()] = true;

            // P4-187a — exception ÉTROITE au « the API never auto-applies » : sur un
            // domicile encore sans salle, on POSE le venueId depuis un alias confirmé
            // (et RIEN d'autre — jamais un statut, jamais une date). La rencontre
            // reste UNPLACED ; elle devient seulement visible de la collision et de
            // la fermeture. Vaut même hors périmètre (fixture UNPLACED, ci-dessous).
            $venueLabel = \is_string($row['venueLabel'] ?? null) ? $row['venueLabel'] : null;
            $this->importer->attachConfirmedVenue($fixture, $venueLabel);

            // P4-194 — exception ÉTROITE n°2 (patron attachConfirmedVenue) : une
            // fixture matchée SANS compétition, dont la row est une COUPE (non
            // amicale, competitionFfbbId non null), reçoit la compétition résolue-
            // ou-créée POUR SON équipe — et RIEN d'autre : jamais un statut, jamais
            // une date, jamais un re-pointage d'une fixture qui a DÉJÀ une compétition.
            if (null === $fixture->getCompetitionId()) {
                $competition = $this->resolveOrCreateCompetition($row, $fixture->getTeamId(), $clubId, $seasonId, $context, $createdByKey);
                if ($competition instanceof Competition) {
                    $fixture->setCompetitionId($competition->getId());
                }
            }

            $fields = $this->importer->detectFieldDeviations($fixture, $row, $venueNames);
            if (null === $fields) {
                continue; // out of the home-placed perimeter — the API never auto-applies (beyond the venue attach above)
            }
            // The SAME reconciliation engine as the xlsx import (never a copy):
            // per-field decisions + pending-écart maintenance + reviewState, or D9
            // (VALIDATED) when the source attests a placed match with no divergence.
            if ([] === $fields) {
                $this->importer->reconcileNoDivergence($fixture, $row, $venueNames, $now);

                continue;
            }
            $this->importer->processPerimeterFields($fixture, $row, $fields, $decisionMap, FbiIngestionSource::FFBB_API->value, $row['competitionName'], $records, $persistingSet, $discardedWarnings, $now, $weekEnd instanceof DateTimeImmutable && $this->importer->sourceIsAuthoritativeForWindow($fixture, $row, $weekEnd));
        }

        $created = $this->applyCreations($creations, $rowsByRencontreId, $context, $clubId, $seasonId, $createdByKey, $club, $now);

        // The unresolved écarts (no decision) — reported, never overwritten.
        $unresolvedRecords = array_values(array_filter($records, static fn (array $r): bool => 'none' === $r['effect']));
        $unresolved = $this->importer->groupDeviations($unresolvedRecords, $persistingSet);
        // « updated » = the écarts adopted from the source (take_file), as before.
        $updated = \count(array_filter($records, static fn (array $r): bool => 'take_file' === $r['effect']));

        $ingestion = new FbiIngestion(
            $clubId,
            $seasonId,
            FbiIngestionSource::FFBB_API,
            $now,
            $created,
            $updated,
            0,
            \count($records),
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
     * @param list<array{rencontreId: string, teamId: string}>                                                                                                                                                                                                                                                     $creations
     * @param array<string, array<string, mixed>>                                                                                                                                                                                                                                                                  $rowsByRencontreId
     * @param array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, list<string>>, competitionByFfbbId: array<string, list<Competition>>, competitionsByTeam: array<string, list<Competition>>, fixturesByTeam: array<string, list<Fixture>>} $context
     * @param array<string, Competition>                                                                                                                                                                                                                                                                           $createdByKey
     */
    private function applyCreations(array $creations, array $rowsByRencontreId, array $context, string $clubId, string $seasonId, array &$createdByKey, ?Club $club, DateTimeImmutable $now): int
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

            $this->entityManager->persist($this->newFixture($row, $teamId, $clubId, $seasonId, $context, $createdByKey, $club, $now));
            ++$created;
        }

        return $created;
    }

    /**
     * @param array<string, mixed>                                                                                                                                                                                                                                                                                 $row
     * @param array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, list<string>>, competitionByFfbbId: array<string, list<Competition>>, competitionsByTeam: array<string, list<Competition>>, fixturesByTeam: array<string, list<Fixture>>} $context
     * @param array<string, Competition>                                                                                                                                                                                                                                                                           $createdByKey
     */
    private function newFixture(array $row, string $teamId, string $clubId, string $seasonId, array $context, array &$createdByKey, ?Club $club, DateTimeImmutable $now): Fixture
    {
        /** @var DateTimeImmutable $matchDate */
        $matchDate = $row['matchDate'];
        // A paired championship / a resolved-or-created CUP materialises the
        // fixture's competitionId; an amical (label says so, or no competitionFfbbId)
        // stays null = friendly (Fixture contract).
        $competition = $this->resolveOrCreateCompetition($row, $teamId, $clubId, $seasonId, $context, $createdByKey);

        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competition instanceof Competition ? $competition->getId() : null);
        $fixture->setMatchDate($matchDate);
        $fixture->setHomeAway($row['homeAway'] instanceof FixtureHomeAway ? $row['homeAway'] : FixtureHomeAway::HOME);
        // P4-199 — le suffixe FFBB « (n) » est retiré du libellé adverse à la
        // création (foyer unique partagé avec l'import xlsx).
        $fixture->setOpponentLabel($this->importer->stripTeamNumberSuffix(\is_string($row['opponentLabel'] ?? null) ? $row['opponentLabel'] : ''));
        $fixture->setKickoffTime($row['kickoffTime'] instanceof DateTimeImmutable ? $row['kickoffTime'] : null);
        $fixture->setFbiVenueLabel(\is_string($row['venueLabel'] ?? null) ? $row['venueLabel'] : null);
        $fixture->setFfbbRencontreId(\is_string($row['rencontreId'] ?? null) ? $row['rencontreId'] : null);
        // Status is always UNPLACED (placing a home match requires a CLUB venue +
        // an explicit manager action — same rule as the FBI import).
        $fixture->setStatus(FixtureStatus::UNPLACED, $now);
        // P4-187a — un domicile dont le libellé égale un alias confirmé naît AVEC
        // son gymnase (jamais placé pour autant : reste UNPLACED). Moteur partagé.
        $this->importer->attachConfirmedVenue($fixture, \is_string($row['venueLabel'] ?? null) ? $row['venueLabel'] : null);
        // P4-199 — extérieur / passé / semaine ISO en cours → naît DÉJÀ traité
        // (REVIEWED). Un domicile futur hors fenêtre reste NEW (à traiter/placer).
        if ($club instanceof Club) {
            $this->importer->treatOnArrival($fixture, $now, $club);
        }

        return $fixture;
    }

    /**
     * The 3-tier match (+ tier-0 idempotence). Returns [matchedFixture|null,
     * suggestedTeamId|null] — the suggestion (tier-1 team) pre-fills the creatable
     * select when no fixture matched.
     *
     * @param array<string, mixed>                                                                                                                                                                                                                                                                                 $row
     * @param array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, list<string>>, competitionByFfbbId: array<string, list<Competition>>, competitionsByTeam: array<string, list<Competition>>, fixturesByTeam: array<string, list<Fixture>>} $context
     * @param array<string, true>                                                                                                                                                                                                                                                                                  $consumed
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

        // Tier 1 — the paired competition resolves the team. A ffbbId carried by
        // EXACTLY ONE competition suggests its team; zero (amical / unpaired) or
        // several (two teams in the same cup, P4-194 R4) → no suggestion, creatable.
        $competitionFfbbId = \is_string($row['competitionFfbbId'] ?? null) ? $row['competitionFfbbId'] : null;
        $teamIds = null !== $competitionFfbbId ? ($context['teamByFfbbCompetitionId'][$competitionFfbbId] ?? []) : [];
        $teamId = 1 === \count($teamIds) ? $teamIds[0] : null;
        if (null === $teamId) {
            return [null, null]; // creatable (amical / unpaired / ambiguous competition)
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
     * The matching indexes, all tenant+season scoped by the Doctrine filters. The
     * ffbbId indexes are LISTS (« last wins » was a bug when two teams share one
     * cup, P4-194 R4); `competitionsByTeam` holds ALL of a team's competitions —
     * the re-adopt fallback (refs cleared by a re-pairing) matches by exact name.
     *
     * @return array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, list<string>>, competitionByFfbbId: array<string, list<Competition>>, competitionsByTeam: array<string, list<Competition>>, fixturesByTeam: array<string, list<Fixture>>}
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
        $competitionsByTeam = [];
        foreach ($this->entityManager->getRepository(Competition::class)->findAll() as $competition) {
            $competitionsByTeam[$competition->getTeamId()][] = $competition;
            $ffbbId = $competition->getFfbbCompetitionId();
            if (null !== $ffbbId) {
                $teamByFfbbCompetitionId[$ffbbId][] = $competition->getTeamId();
                $competitionByFfbbId[$ffbbId][] = $competition;
            }
        }

        return [
            'fixturesByRencontreId' => $fixturesByRencontreId,
            'teamById' => $teamById,
            'teamByFfbbCompetitionId' => $teamByFfbbCompetitionId,
            'competitionByFfbbId' => $competitionByFfbbId,
            'competitionsByTeam' => $competitionsByTeam,
            'fixturesByTeam' => $fixturesByTeam,
        ];
    }

    /**
     * Resolve — or create (P4-194) — the competition a rencontre's fixture must
     * carry. R1: an amical (the normalized label carries the exact token « amical »,
     * or no competitionFfbbId) carries NONE (null = friendly, Fixture contract).
     * Otherwise (R2), for the fixture's TEAM: (1) an existing/just-created
     * competition with this (ffbbId, teamId); (2) a competition of the team with the
     * EXACT name whose refs were cleared by a re-pairing — re-adopted (else
     * uniq_competition_team_name would explode), its ffbbCompetitionId re-posted;
     * (3) else a fresh CUP, name clamped to the 180-char column, ffbbCompetitionId
     * posed, expectedMatchdays null. Never poule/opponents/matchdays — the pairing
     * confirm owns those.
     *
     * @param array<string, mixed>                                                                                                                                                                                                                                                                                 $row
     * @param array{fixturesByRencontreId: array<string, Fixture>, teamById: array<string, Team>, teamByFfbbCompetitionId: array<string, list<string>>, competitionByFfbbId: array<string, list<Competition>>, competitionsByTeam: array<string, list<Competition>>, fixturesByTeam: array<string, list<Fixture>>} $context
     * @param array<string, Competition>                                                                                                                                                                                                                                                                           $createdByKey
     */
    private function resolveOrCreateCompetition(array $row, string $teamId, string $clubId, string $seasonId, array $context, array &$createdByKey): ?Competition
    {
        $competitionFfbbId = \is_string($row['competitionFfbbId'] ?? null) ? $row['competitionFfbbId'] : null;
        $competitionName = \is_string($row['competitionName'] ?? null) ? $row['competitionName'] : '';
        // R1 — amical = le libellé le dit (token exact « amical »), OU aucune réf de
        // compétition fédérale. ⚠ un amical réel PORTE un competitionFfbbId : « non
        // apparié » ne suffit jamais, c'est le LIBELLÉ qui tranche.
        if (null === $competitionFfbbId || $this->isFriendlyLabel($competitionName)) {
            return null;
        }

        // (1) — (ffbbId, teamId) : la carte du run d'abord (deux rencontres de la
        // même coupe → UNE compétition), puis le contexte chargé en base.
        // Clé composite sans séparateur ambigu : un id fédéral portant « | » ferait
        // collision avec un autre couple (revue sécurité).
        $key = json_encode([$competitionFfbbId, $teamId], \JSON_THROW_ON_ERROR);
        if (isset($createdByKey[$key])) {
            return $createdByKey[$key];
        }
        foreach ($context['competitionByFfbbId'][$competitionFfbbId] ?? [] as $competition) {
            if ($competition->getTeamId() === $teamId) {
                return $createdByKey[$key] = $competition;
            }
        }

        // (2) — repli (teamId, name exact) : ré-adopte une compétition dont un
        // réappariement a effacé les refs, sinon uniq_competition_team_name saute.
        $name = mb_substr($competitionName, 0, 180);
        foreach ($context['competitionsByTeam'][$teamId] ?? [] as $competition) {
            if ($competition->getName() !== $name) {
                continue;
            }
            // ⚠ Ne ré-adopter QUE si la compétition n'a pas déjà une AUTRE réf fédérale
            // (revue sécurité) : deux compétitions distinctes dont les libellés se
            // clampent au même nom écraseraient sinon la première réf, en silence et
            // selon l'ordre de lecture. Dans ce cas pathologique on ne touche à rien —
            // la rencontre reste sans compétition, comme avant, jamais une donnée club
            // détruite.
            $existingRef = $competition->getFfbbCompetitionId();
            if (null !== $existingRef && $existingRef !== $competitionFfbbId) {
                return null;
            }
            $competition->setFfbbCompetitionId($competitionFfbbId);

            return $createdByKey[$key] = $competition;
        }

        // (3) — création : coupe, name clampé (colonne 180), réf posée, journées null.
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CUP);
        $competition->setFfbbCompetitionId($competitionFfbbId);
        $this->entityManager->persist($competition);

        return $createdByKey[$key] = $competition;
    }

    /**
     * Le libellé fédéral ANNONCE l'amical : son PREMIER token normalisé est « amical »
     * (P4-194 R1). Les libellés mesurés le disent tous en tête (« AMICAL PNM », « AMICAL
     * PNF », « AMICAL RM3 », et le repli « Amical » du lecteur). Exiger la tête plutôt
     * qu'un token quelconque évite qu'un nom de compétition contenant le mot ailleurs
     * (« … Parc Amical ») échappe à sa vraie nature (revue sécurité).
     */
    private function isFriendlyLabel(string $label): bool
    {
        return 'amical' === explode(' ', $this->normalizeCompetitionLabel($label))[0];
    }

    /**
     * Translit ASCII, lowercase, non-alphanumeric → space, spaces reduced — the
     * shared FFBB idiom ({@see FfbbEngagementsController::normalize},
     * {@see VenueLabelNormalizer}). Third assumed copy (P4-194 cadrage) — no
     * extraction: the reconciler must not depend on the controller.
     */
    private function normalizeCompetitionLabel(string $value): string
    {
        // Les libellés viennent de la fédération : on jette d'abord les octets non
        // UTF-8 (revue sécurité) — iconv rendrait false et mb_strtolower travaillerait
        // sur une chaîne cassée.
        $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
        $lower = mb_strtolower(false === $ascii ? $clean : $ascii, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $lower)));
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
