<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Tests\Integration\Api\RecapRefusesASecondSessionSameDayTest;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;
use stdClass;

/**
 * Le plafond de séances par jour d'une équipe, de bout en bout sur le petit club réaliste
 * ({@see SmallClubFixtureTrait}, palier 60 s, deux solves : la saison, puis la période).
 *
 * UNE promesse composée, à l'image du geste réel : autoriser deux séances le même jour lève le
 * blocage du récapitulatif (et lui seul : deux séances qui se chevauchent restent bloquées) ; la
 * saison générée tient les deux réservations collées en silence ; sous planning de saison en
 * vigueur le réglage de saison est figé, celui d'une période reste libre ; en période, une équipe
 * qui garde ses créneaux de saison garde ses deux créneaux collés et le planning le signale.
 *
 * Les cas nombreux sont prouvés plus bas : les phrases exactes du récapitulatif par
 * {@see RecapRefusesASecondSessionSameDayTest}, la paire gardée et ses variantes par le fichier
 * moteur `engine/tests/semantic/test_max_sessions_per_day.py` (A10ter).
 *
 * Décor par l'API ; SQL réservé au pointage des séances et diagnostics écrits.
 */
final class DailyCapSmallClubContext extends BaseContext
{
    use SmallClubFixtureTrait;

    private const string KEPT_FROM_SEASON = 'gardées du planning de saison';

    /** Les deux créneaux du lundi qui se suivent, au même gymnase (grille du petit club : 18:00 puis 19:30, 90 min). */
    private const array MONDAY_STARTS = ['18:00', '19:30'];

    private const string RESERVED_VENUE = 'Gymnase Centre';

    private const string OVERLAP_VENUE = 'Gymnase Nord';

    private string $overlappingReservationId = '';

    /** @var array<mixed> */
    private array $recapJson = [];

    private string $socleVersionId = '';

    private int $teamWriteStatus = 0;

    /** @var array<mixed> */
    private array $teamWriteJson = [];

    private string $periodPlanId = '';

    /** @var array{id: string, status: string} */
    private array $periodVersion = ['id' => '', 'status' => ''];

    #[Given('un petit club réaliste bâti par l\'API, pas encore généré')]
    public function unPetitClubPasEncoreGenere(): void
    {
        $this->buildSmallClub();
    }

    #[Given('je réserve SM1 deux fois le lundi, sur deux créneaux du même gymnase qui se suivent')]
    public function jeReserveSm1DeuxFoisLeLundi(): void
    {
        foreach (self::MONDAY_STARTS as $start) {
            $this->reserveSm1(self::RESERVED_VENUE, $start);
        }
    }

    #[When('je demande le récapitulatif')]
    public function jeDemandeLeRecapitulatif(): void
    {
        $this->readRecap();
    }

    #[Then('il bloque : SM1 est réservée deux fois le lundi, une seule séance par jour, et il propose d\'autoriser 2 séances par jour')]
    public function ilBloqueEtProposeDAutoriser(): void
    {
        $blocker = $this->sm1Blocker();
        foreach (['SM1 est réservée deux fois le lundi', 'une seule séance par jour', 'autorisez 2 séances par jour'] as $fragment) {
            if (!str_contains($blocker, $fragment)) {
                throw new RuntimeException(\sprintf('le blocage du récapitulatif devrait dire « %s » — obtenu : « %s »', $fragment, $blocker));
            }
        }
    }

    #[When('j\'autorise 2 séances par jour pour SM1')]
    public function jAutoriseDeuxSeancesParJour(): void
    {
        $this->writeSm1MaxSessionsPerDay(2);
        if (200 !== $this->teamWriteStatus) {
            throw new RuntimeException(\sprintf('autoriser 2 séances par jour a répondu %d (200 attendu) — %s', $this->teamWriteStatus, $this->encoded($this->teamWriteJson)));
        }
    }

    #[Then('le récapitulatif ne bloque plus')]
    public function leRecapitulatifNeBloquePlus(): void
    {
        $this->readRecap();
        $blockers = $this->blockers();
        if ([] !== $blockers) {
            throw new RuntimeException(\sprintf('le récapitulatif ne devrait plus rien bloquer — obtenu : %s', $this->encoded($blockers)));
        }
    }

    #[When('je réserve SM1 une troisième fois le lundi, sur un créneau d\'un autre gymnase qui chevauche l\'un des deux')]
    public function jeReserveUneTroisiemeFoisEnChevauchement(): void
    {
        // Une réservation d'équipe consomme une séance de sa semaine : à 2 séances, une troisième est refusée
        // au geste (`ReservationGroupOccupancy::assertSoloBudgetAllows`, budget = séances de l'équipe). Le
        // gestionnaire suit ce que l'écran lui dit (« augmentez son nombre de séances ») : SM1 passe à 3
        // séances, le temps de cette réservation ; le retrait la remet à 2.
        $this->writeSm1(['sessionsPerWeek' => 3]);
        if (200 !== $this->teamWriteStatus) {
            throw new RuntimeException(\sprintf('passer SM1 à 3 séances a répondu %d — %s', $this->teamWriteStatus, $this->encoded($this->teamWriteJson)));
        }
        // Gymnase Nord, lundi 18:00-19:30 : chevauche 18:00-19:30 au Gymnase Centre (le créneau existe
        // dans la grille du petit club, aucun créneau à créer).
        $this->overlappingReservationId = $this->reserveSm1(self::OVERLAP_VENUE, self::MONDAY_STARTS[0]);
    }

    #[Then('le récapitulatif bloque : les créneaux se chevauchent, sans proposer d\'autoriser davantage')]
    public function leRecapitulatifBloqueLeChevauchement(): void
    {
        $this->readRecap();
        $blocker = $this->sm1Blocker();
        if (!str_contains($blocker, 'sur des créneaux qui se chevauchent')) {
            throw new RuntimeException(\sprintf('le blocage devrait nommer le chevauchement — obtenu : « %s »', $blocker));
        }
        if (str_contains($blocker, 'autorisez')) {
            throw new RuntimeException(\sprintf('un chevauchement ne se lève pas en autorisant davantage — le blocage ne doit pas le proposer : « %s »', $blocker));
        }
    }

    #[When('je retire cette troisième réservation')]
    public function jeRetireLaTroisiemeReservation(): void
    {
        $deleted = $this->apiDelete(\sprintf('reservations/%s', $this->overlappingReservationId), $this->smallClubToken);
        if (204 !== $deleted['status']) {
            throw new RuntimeException(\sprintf('le retrait de la réservation a répondu %d (204 attendu)', $deleted['status']));
        }
        $this->writeSm1(['sessionsPerWeek' => 2]);
        if (200 !== $this->teamWriteStatus) {
            throw new RuntimeException(\sprintf('remettre SM1 à 2 séances a répondu %d — %s', $this->teamWriteStatus, $this->encoded($this->teamWriteJson)));
        }
    }

    #[When('je génère et valide le planning de saison du petit club')]
    public function jeGenereEtValideLaSaison(): void
    {
        $this->socleVersionId = $this->generateAndValidateSmallClubSocle();
        fwrite(\STDERR, \sprintf("\n[DailyCap] solve de saison en %.1fs\n", $this->smallClubLastSolveSeconds));
    }

    #[Then('les deux séances de SM1 tiennent le lundi, et le planning ne signale aucun écart pour SM1')]
    public function lesDeuxSeancesTiennentLeLundi(): void
    {
        $starts = $this->sm1MondayStarts($this->socleVersionId, $this->smallClubVenueIds[self::RESERVED_VENUE]);
        if (self::MONDAY_STARTS !== $starts) {
            throw new RuntimeException(\sprintf('SM1 devrait tenir ses deux réservations du lundi (%s) au %s — obtenu : %s', implode(' et ', self::MONDAY_STARTS), self::RESERVED_VENUE, $this->encoded($starts)));
        }
        $gapMessages = $this->sm1GapDiagnostics($this->socleVersionId);
        if ([] !== $gapMessages) {
            throw new RuntimeException(\sprintf('deux réservations collées sont honorées en silence — le planning ne devrait rien signaler pour SM1 : %s', $this->encoded($gapMessages)));
        }
    }

    #[When('j\'essaie de remettre SM1 à 1 séance par jour')]
    public function jEssaieDeRemettreAUne(): void
    {
        $this->writeSm1MaxSessionsPerDay(1);
    }

    /** Lot 5, PR 5 (ligne 35 du plan) : sous planning de saison en vigueur, le plafond par jour se modifie et attend la validation. */
    #[Then('c\'est accepté sans rouvrir : SM1 est à 1 séance par jour, et le planning de saison reste en vigueur')]
    public function cEstAccepteSousSocle(): void
    {
        if ($this->teamWriteStatus < 200 || $this->teamWriteStatus >= 300) {
            throw new RuntimeException(\sprintf('sous planning de saison en vigueur, changer le plafond est accepté — reçu %d, corps : %s', $this->teamWriteStatus, $this->encoded($this->teamWriteJson)));
        }
        if (1 !== $this->sm1SeasonMaxSessionsPerDay()) {
            throw new RuntimeException('SM1 devrait être à 1 séance par jour dans le planning de travail');
        }
        $chosen = $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id IS NOT NULL', $this->smallClubId), admin: true);
        if ('1' !== $chosen) {
            throw new RuntimeException('le planning de saison devrait rester en vigueur');
        }
    }

    #[When('j\'ouvre une période où SM1 garde ses créneaux de saison, et j\'y autorise 3 séances par jour pour SM1')]
    public function jOuvreUnePeriodeEtAutoriseTrois(): void
    {
        $start = $this->smallClubFutureMonday(49);
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));
        $entryId = $this->createSmallClubClosureEntry($start, $end, 'Période — deux séances par jour (petit club)');
        $this->periodPlanId = $this->openSmallClubPeriodPlan($entryId);

        $keep = $this->apiPost('constraints', [
            'name' => 'SM1 garde ses créneaux de saison',
            'scope' => 'TEAM',
            'scopeTargetId' => $this->smallClubTeamIds['SM1'],
            'family' => 'SEASON_SLOTS',
            'ruleType' => 'PREFERRED',
            'config' => new stdClass,
            'isActive' => true,
            'calendarEntryId' => $entryId,
        ], $this->smallClubToken);
        if (!\in_array($keep['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('« garder ses créneaux de saison » pour SM1 a répondu %d — %s', $keep['status'], $this->encoded($keep['json'])));
        }

        $override = $this->apiPost('team_period_overrides', [
            'schedulePlanId' => $this->periodPlanId,
            'teamId' => $this->smallClubTeamIds['SM1'],
            'isActive' => true,
            'sessionsPerWeek' => null,
            'maxSessionsPerDay' => 3,
        ], $this->smallClubToken);
        if (!\in_array($override['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('autoriser 3 séances par jour en période a répondu %d — %s', $override['status'], $this->encoded($override['json'])));
        }
    }

    #[Then('la période porte 3 séances par jour pour SM1, la saison reste à 1')]
    public function laPeriodePorteTrois(): void
    {
        $response = $this->apiGet(\sprintf('team_period_overrides?schedulePlanId=%s&teamId=%s', $this->periodPlanId, $this->smallClubTeamIds['SM1']), $this->smallClubToken);
        $rows = $this->members($response['json']);
        $periodValue = $rows[0]['maxSessionsPerDay'] ?? null;
        if (3 !== $periodValue) {
            throw new RuntimeException(\sprintf('la période devrait porter 3 séances par jour pour SM1 — obtenu : %s', $this->encoded($rows)));
        }
        if (1 !== $this->sm1SeasonMaxSessionsPerDay()) {
            throw new RuntimeException('le réglage de période ne touche jamais la saison : SM1 doit rester à 1 en saison');
        }
    }

    #[When('je génère le planning de cette période')]
    public function jeGenereLaPeriode(): void
    {
        $this->periodVersion = $this->generateSmallClubPeriodVersion($this->periodPlanId);
        fwrite(\STDERR, \sprintf("\n[DailyCap] solve de période %s en %.1fs\n", $this->periodVersion['status'], $this->smallClubLastSolveSeconds));
        if ('COMPLETED' !== $this->periodVersion['status']) {
            throw new RuntimeException(\sprintf('la génération de la période aurait dû aboutir (COMPLETED), obtenu « %s »', $this->periodVersion['status']));
        }
    }

    #[Then('SM1 garde ses deux créneaux du lundi et le planning signale « gardées du planning de saison »')]
    public function sm1GardeSesDeuxCreneaux(): void
    {
        // Gymnase indifférent : « garder » lit le jour et l'heure de début (cas C du plan).
        $starts = $this->sm1MondayStarts($this->periodVersion['id'], null);
        if (self::MONDAY_STARTS !== $starts) {
            throw new RuntimeException(\sprintf('en période, SM1 devrait garder ses deux créneaux de saison du lundi (%s) — obtenu : %s', implode(' et ', self::MONDAY_STARTS), $this->encoded($starts)));
        }
        $kept = array_filter($this->sm1GapDiagnostics($this->periodVersion['id']), static fn (string $m): bool => str_contains($m, 'le lundi') && str_contains($m, self::KEPT_FROM_SEASON));
        if ([] === $kept) {
            throw new RuntimeException(\sprintf('le planning de période devrait signaler la paire collée de SM1, « %s » — diagnostics de SM1 : %s', self::KEPT_FROM_SEASON, $this->encoded($this->sm1GapDiagnostics($this->periodVersion['id']))));
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        $this->teardownSmallClub();
    }

    private function reserveSm1(string $venueName, string $start): string
    {
        $created = $this->apiPost('reservations', [
            'teamId' => $this->smallClubTeamIds['SM1'],
            'venueId' => $this->smallClubVenueIds[$venueName],
            'dayOfWeek' => 1,
            'startTime' => $start,
            'durationMinutes' => 90,
            'schedulePlanId' => null,
        ], $this->smallClubToken);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('réserver SM1 le lundi %s au %s a répondu %d — %s', $start, $venueName, $created['status'], $this->encoded($created['json'])));
        }

        return $this->smallClubStringField($created['json'], 'id');
    }

    private function readRecap(): void
    {
        $response = $this->apiPost('constraints/validate', [], $this->smallClubToken);
        // 200 = valide, 422 = invalide (blocages ou erreurs) : les deux portent le même corps.
        if (!\in_array($response['status'], [200, 422], true)) {
            throw new RuntimeException(\sprintf('le récapitulatif a répondu %d (200 ou 422 attendu)', $response['status']));
        }
        $this->recapJson = $response['json'];
    }

    /** @return list<string> */
    private function blockers(): array
    {
        $blockers = $this->recapJson['blockers'] ?? [];

        return array_values(array_filter(\is_array($blockers) ? $blockers : [], 'is_string'));
    }

    /** Le blocage qui nomme SM1 (le récapitulatif doit en porter un, et un seul). */
    private function sm1Blocker(): string
    {
        $mine = array_values(array_filter($this->blockers(), static fn (string $b): bool => str_contains($b, 'SM1')));
        if (1 !== \count($mine)) {
            throw new RuntimeException(\sprintf('le récapitulatif devrait porter exactement un blocage pour SM1 — blocages : %s', $this->encoded($this->blockers())));
        }

        return $mine[0];
    }

    /** PUT de l'équipe SM1 : son plafond de séances par jour, le reste en écho. */
    private function writeSm1MaxSessionsPerDay(int $value): void
    {
        $this->writeSm1(['maxSessionsPerDay' => $value]);
    }

    /**
     * PUT de l'équipe SM1 : écho des champs que le PUT remet à défaut s'ils manquent, plus les champs changés.
     *
     * @param array<string, int> $changes
     */
    private function writeSm1(array $changes): void
    {
        $teamId = $this->smallClubTeamIds['SM1'];
        $current = $this->apiGet(\sprintf('teams/%s', $teamId), $this->smallClubToken)['json'];
        $response = $this->apiPut(\sprintf('teams/%s', $teamId), [
            'name' => $current['name'] ?? 'SM1',
            'sportCategoryId' => $current['sportCategoryId'] ?? null,
            'priorityTierId' => $current['priorityTierId'] ?? null,
            'sessionsPerWeek' => $current['sessionsPerWeek'] ?? null,
            'level' => $current['level'] ?? null,
            'gender' => $current['gender'] ?? null,
            'maxSessionsPerDay' => $current['maxSessionsPerDay'] ?? null,
            ...$changes,
        ], $this->smallClubToken);
        $this->teamWriteStatus = $response['status'];
        $this->teamWriteJson = $response['json'];
    }

    private function sm1SeasonMaxSessionsPerDay(): ?int
    {
        $team = $this->apiGet(\sprintf('teams/%s', $this->smallClubTeamIds['SM1']), $this->smallClubToken)['json'];
        $value = $team['maxSessionsPerDay'] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * Les heures de début (HH:MM, triées) des séances de SM1 le lundi dans la version, au gymnase
     * donné ou dans tous.
     *
     * @return list<string>
     */
    private function sm1MondayStarts(string $scheduleId, ?string $venueId): array
    {
        $raw = $this->dbalScalar(
            \sprintf(
                'SELECT COALESCE(string_agg(to_char(start_time, \'HH24:MI\'), \',\' ORDER BY start_time), \'\') AS behatval FROM schedule_slot_template'
                . ' WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND day_of_week=1%s',
                $scheduleId,
                $this->smallClubTeamIds['SM1'],
                null === $venueId ? '' : \sprintf(' AND venue_id=\'%s\'', $venueId),
            ),
            admin: true,
        );

        return '' === $raw ? [] : explode(',', $raw);
    }

    /**
     * Les messages des diagnostics d'écart (« à moins de 2 h d'écart ») écrits pour SM1 sur la version.
     *
     * @return list<string>
     */
    private function sm1GapDiagnostics(string $scheduleId): array
    {
        $raw = $this->dbalScalar(
            \sprintf(
                'SELECT COALESCE(string_agg(message, \'§\'), \'\') AS behatval FROM schedule_diagnostic'
                . ' WHERE schedule_id=\'%s\' AND team_id=\'%s\' AND type=\'implicit_rule_not_honored\' AND message LIKE \'%%moins de 2 h%%\'',
                $scheduleId,
                $this->smallClubTeamIds['SM1'],
            ),
            admin: true,
        );

        return '' === $raw ? [] : explode('§', $raw);
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $json): array
    {
        $rows = $json['member'] ?? $json;

        return array_values(array_filter(\is_array($rows) ? $rows : [], 'is_array'));
    }

    private function encoded(mixed $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
