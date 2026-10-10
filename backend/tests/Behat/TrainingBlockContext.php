<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * L'unité de placement d'un entraînement mutualisé est le BLOC (P2-46 / P2-51 / P2-60 / P2-62),
 * sur la stack qui tourne.
 *
 * Trois promesses, éprouvées en HTTP contre la vraie API :
 *   - une équipe sans résidu solo (toutes ses séances dans le bloc) ne se réserve pas SEULE — le
 *     message renvoie au groupe (règle f, `ReservationGroupOccupancy`) ;
 *   - `POST /reservations/group` pose N réservations d'un coup (une par membre, même case) ;
 *   - `DELETE /reservations/{id}` d'une séance d'une case « bloc-complète » emporte TOUT le lot
 *     (décision fondateur P2-62 : « on ne retire pas une équipe d'un groupe, on défait le groupe »).
 *
 * Autosuffisant : deux équipes + un gymnase JETABLES, un bloc de mutualisation, tous créés par
 * l'API et retirés en fin de scénario (réservations en base, puis équipes — leur suppression
 * cascade le bloc —, puis gymnase), quoi qu'il arrive.
 */
final class TrainingBlockContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    private const int DAY = 2;

    private const string START_TIME = '18:00';

    private const int POLL_INTERVAL_SECONDS = 2;

    private const int TIMEOUT_SECONDS = 120;

    private string $token = '';

    private string $clubId = '';

    private string $teamA = '';

    private string $teamB = '';

    private string $venueId = '';

    private string $blockId = '';

    private int $individualStatus = 0;

    private string $individualError = '';

    /** @var array{status: int, json: array<mixed>} */
    private array $groupResult = ['status' => 0, 'json' => []];

    /** @var list<string> */
    private array $groupReservationIds = [];

    // Scénario « mutualiser une séance placée d'un plan de période » (lot 9, rail depuis la
    // génération) : décor jetable d'un plan de période transcrit du socle, nettoyé en fin de run.
    private bool $pointerSetBySelf = false;

    private string $periodEntryId = '';

    private string $periodPlanId = '';

    private string $periodVersionId = '';

    private string $periodSeasonId = '';

    private string $sourceSlotId = '';

    private string $sourceTeamId = '';

    private string $sourceVenueId = '';

    private int $sourceDay = 0;

    private string $sourceStart = '';

    private string $joinerSlotId = '';

    private string $joinerTeamId = '';

    private string $periodBlockId = '';

    #[Given('le club de démonstration et son gestionnaire connecté')]
    public function leClubEtSonGestionnaireConnecte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;
    }

    #[Given('un entraînement mutualisé de deux équipes qui ne s\'entraînent qu\'en groupe')]
    public function unEntrainementMutualise(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }

        // Une SEULE séance par semaine, toute dans le bloc (commonSessions = 1) ⇒ résidu solo nul :
        // l'équipe ne peut se réserver que via le groupe (règle f).
        $this->teamA = $this->createdId($this->apiPost('teams', ['name' => 'Bloc Jetable A', 'sportCategoryId' => $category, 'priorityTierId' => 1, 'sessionsPerWeek' => 1], $this->token), 'équipe A');
        $this->teamB = $this->createdId($this->apiPost('teams', ['name' => 'Bloc Jetable B', 'sportCategoryId' => $category, 'priorityTierId' => 1, 'sessionsPerWeek' => 1], $this->token), 'équipe B');
        $this->venueId = $this->createdId($this->apiPost('venues', ['name' => 'Gym Jetable Bloc', 'source' => 'manual'], $this->token), 'gymnase');

        $this->blockId = $this->createdId(
            $this->apiPost('shared_training_blocks', ['teamIds' => [$this->teamA, $this->teamB], 'commonSessions' => 1, 'schedulePlanId' => null], $this->token),
            'entraînement mutualisé',
        );
    }

    #[When('je réserve une seule de ces équipes sur un créneau libre')]
    public function jeReserveUneSeuleEquipe(): void
    {
        $result = $this->apiPost('reservations', [
            'teamId' => $this->teamA,
            'venueId' => $this->venueId,
            'dayOfWeek' => self::DAY,
            'startTime' => self::START_TIME,
            'durationMinutes' => 90,
        ], $this->token);
        $this->individualStatus = $result['status'];
        // Le rail unitaire est API Platform : un 422 se rend au format hydra (`hydra:description`),
        // pas sous `error` comme le rail de groupe. On lit le message où qu'il soit.
        foreach (['hydra:description', 'detail', 'error'] as $key) {
            if (\is_string($result['json'][$key] ?? null)) {
                $this->individualError = $result['json'][$key];

                break;
            }
        }
    }

    #[Then('la réservation individuelle est refusée en renvoyant vers le groupe')]
    public function laReservationIndividuelleEstRefusee(): void
    {
        if (422 !== $this->individualStatus) {
            throw new RuntimeException(\sprintf('la réservation individuelle aurait dû être refusée (422), obtenu %d', $this->individualStatus));
        }
        if (!str_contains($this->individualError, 'uniquement en groupe')) {
            throw new RuntimeException(\sprintf('le refus aurait dû renvoyer vers le groupe, message obtenu « %s »', $this->individualError));
        }
    }

    #[When('je réserve l\'entraînement mutualisé sur un créneau libre')]
    public function jeReserveLeGroupe(): void
    {
        $this->groupResult = $this->reserveGroup();
        if (201 !== $this->groupResult['status']) {
            throw new RuntimeException(\sprintf('la réservation mutualisée a répondu %d (201 attendu)', $this->groupResult['status']));
        }
        $this->captureReservationIds($this->groupResult['json']);
    }

    #[Then('la réservation mutualisée est acceptée pour les deux équipes du groupe')]
    public function laReservationMutualiseeEstAcceptee(): void
    {
        $count = $this->groupResult['json']['count'] ?? null;
        if (2 !== $count) {
            throw new RuntimeException(\sprintf('la réservation mutualisée aurait dû poser 2 séances (une par équipe), obtenu %s', \is_int($count) ? (string) $count : 'aucune'));
        }
        if (2 !== $this->reservationCountOnCase()) {
            throw new RuntimeException('les deux équipes du groupe auraient dû partager la case');
        }
    }

    #[Given('l\'entraînement mutualisé est réservé sur un créneau libre')]
    public function leGroupeEstReserve(): void
    {
        $result = $this->reserveGroup();
        if (201 !== $result['status']) {
            throw new RuntimeException(\sprintf('la mise en place de la réservation mutualisée a répondu %d (201 attendu)', $result['status']));
        }
        $this->captureReservationIds($result['json']);
        if (2 !== \count($this->groupReservationIds)) {
            throw new RuntimeException('la réservation mutualisée n\'a pas posé les deux séances attendues');
        }
    }

    #[When('je retire une seule séance du lot')]
    public function jeRetireUneSeuleSeance(): void
    {
        $removed = $this->apiDelete(\sprintf('reservations/%s', $this->groupReservationIds[0]), $this->token);
        if (204 !== $removed['status']) {
            throw new RuntimeException(\sprintf('le retrait d\'une séance du lot a répondu %d (204 attendu)', $removed['status']));
        }
    }

    #[Then('tout le lot mutualisé a disparu du créneau')]
    public function toutLeLotADisparu(): void
    {
        if (0 !== $this->reservationCountOnCase()) {
            throw new RuntimeException('retirer une séance d\'une case bloc-complète aurait dû emporter tout le lot');
        }
    }

    #[Given('le club de démonstration et un plan de période généré')]
    public function unPlanDePeriodeGenere(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        // Ouvrir un plan de période exige que le socle pointe une version (SocleGuard) : on le pose
        // si vide, et on le repose à NULL en fin de scénario.
        $chosen = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $chosen) {
            $completed = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM schedule WHERE club_id=\'%s\' AND status=\'COMPLETED\' AND schedule_plan_id=(SELECT id FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\') ORDER BY created_at DESC LIMIT 1', $this->clubId, $this->clubId),
                admin: true,
            );
            if ('' === $completed) {
                throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
            }
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=\'%s\' WHERE club_id=\'%s\' AND type=\'SEASON\'', $completed, $this->clubId),
                admin: true,
            );
            $this->pointerSetBySelf = true;
        }

        // Une période jetable et son plan ; la transcription depuis le socle garnit une PREMIÈRE
        // version de séances PLACÉES (copie de la grille de saison) — un décor déterministe, sans
        // dépendre d'un solve frais.
        $this->periodEntryId = $this->createClosurePeriod($this->holidayFreeMonday(56), 'Mutualiser depuis la génération (fonctionnel)');

        $plan = $this->apiPost('schedule_plans', ['calendarEntryId' => $this->periodEntryId], $this->token);
        $this->periodPlanId = $this->createdId($plan, 'plan de période');

        $transcribed = $this->apiPost(\sprintf('schedule_plans/%s/transcribe-from-socle', $this->periodPlanId), [], $this->token);
        $this->periodVersionId = $this->createdId($transcribed, 'transcription du socle');
        $status = $this->pollUntilTerminal($this->periodVersionId);
        if ('COMPLETED' !== $status) {
            throw new RuntimeException(\sprintf('la transcription du plan de période n\'a pas abouti (statut « %s »)', $status));
        }

        $this->periodSeasonId = $this->dbalScalar(
            \sprintf('SELECT season_id AS behatval FROM schedule WHERE id=\'%s\'', $this->periodVersionId),
            admin: true,
        );
    }

    #[Given('deux séances placées d\'équipes distinctes, chacune sur sa propre case')]
    public function deuxSeancesPlacees(): void
    {
        // La SOURCE : une séance SOLO (hors bloc) dont l'équipe n'a qu'UNE séance dans cette version,
        // sans réservation individuelle, sur une case qu'elle occupe SEULE — la capacité tiendra le
        // bloc sans déborder et le budget Σ (1 séance commune ≤ son volume) passe.
        $source = $this->pickCleanSoloSlot('', null);
        $parts = '' === $source ? [] : explode('|', $source);
        if (5 !== \count($parts)) {
            throw new RuntimeException('aucune séance solo exploitable dans le plan de période transcrit — décor non tenu');
        }
        [$this->sourceSlotId, $this->sourceTeamId, $this->sourceVenueId, $sourceDay, $this->sourceStart] = $parts;
        $this->sourceDay = (int) $sourceDay;

        // La JOINER : une AUTRE équipe, elle aussi SOLO et sans réservation, sur une AUTRE case.
        $joiner = $this->pickCleanSoloSlot($this->sourceTeamId, [$this->sourceVenueId, $this->sourceDay, $this->sourceStart]);
        $parts = '' === $joiner ? [] : explode('|', $joiner);
        if (5 !== \count($parts)) {
            throw new RuntimeException('aucune seconde séance solo exploitable (équipe distincte, autre case) — décor non tenu');
        }
        [$this->joinerSlotId, $this->joinerTeamId] = $parts;
    }

    #[When('je mutualise la première séance en y rattachant la seconde équipe')]
    public function jeMutualise(): void
    {
        $result = $this->apiPost(
            \sprintf('schedule-slots/%s/mutualize', $this->sourceSlotId),
            ['teamIds' => [$this->sourceTeamId, $this->joinerTeamId], 'replacedSlotIds' => [$this->joinerSlotId]],
            $this->token,
        );
        if (200 !== $result['status']) {
            $detail = json_encode($result['json'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            throw new RuntimeException(\sprintf('la mutualisation a répondu %d (200 attendu) — %s', $result['status'], \is_string($detail) ? $detail : '?'));
        }
        $blockId = $result['json']['blockId'] ?? null;
        if (!\is_string($blockId) || '' === $blockId) {
            throw new RuntimeException('la mutualisation n\'a pas renvoyé d\'identifiant de bloc');
        }
        $this->periodBlockId = $blockId;
    }

    #[Then('les deux séances partagent la même case, liées à un même bloc de mutualisation')]
    public function lesDeuxSeancesPartagentLaCase(): void
    {
        $onCaseLinked = (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template'
                . ' WHERE schedule_id=\'%s\' AND shared_training_block_id=\'%s\''
                . ' AND venue_id=\'%s\' AND day_of_week=%d AND start_time=\'%s\'',
                $this->periodVersionId,
                $this->periodBlockId,
                $this->sourceVenueId,
                $this->sourceDay,
                $this->sourceStart,
            ),
            admin: true,
        );
        if (2 !== $onCaseLinked) {
            throw new RuntimeException(\sprintf('les deux séances du groupe devraient siéger sur la case d\'ancrage, liées au bloc (%d trouvée[s])', $onCaseLinked));
        }
    }

    #[When('je supprime le bloc de mutualisation')]
    public function jeSupprimeLeBloc(): void
    {
        $removed = $this->apiDelete(\sprintf('shared_training_blocks/%s', $this->periodBlockId), $this->token);
        if (204 !== $removed['status']) {
            throw new RuntimeException(\sprintf('la suppression du bloc a répondu %d (204 attendu)', $removed['status']));
        }
    }

    #[Then('les deux séances du groupe ont disparu du plan')]
    public function lesSeancesDuGroupeOntDisparu(): void
    {
        $remaining = (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE id IN (\'%s\', \'%s\')',
                $this->sourceSlotId,
                $this->joinerSlotId,
            ),
            admin: true,
        );
        if (0 !== $remaining) {
            throw new RuntimeException(\sprintf('les séances liées au bloc auraient dû disparaître avec lui (%d subsiste[nt])', $remaining));
        }
    }

    /**
     * Retire les réservations posées (en base, pour couvrir la cascade), puis les équipes
     * jetables (leur suppression cascade le bloc de mutualisation) et le gymnase. Quoi qu'il arrive.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        foreach ([$this->teamA, $this->teamB] as $teamId) {
            if ('' !== $teamId) {
                $this->dbalExec(\sprintf('DELETE FROM reservation WHERE team_id=\'%s\'', $teamId), admin: true);
            }
        }
        // Le bloc d'abord (au cas où la cascade équipe ne le prendrait pas dans tel ordre), puis les
        // équipes, puis le gymnase — DELETE tolérant si une ressource a déjà disparu.
        if ('' !== $this->blockId) {
            $this->apiDelete(\sprintf('shared_training_blocks/%s', $this->blockId), $this->token);
        }
        foreach ([$this->teamA, $this->teamB] as $teamId) {
            if ('' !== $teamId) {
                $this->apiDelete(\sprintf('teams/%s', $teamId), $this->token);
            }
        }
        if ('' !== $this->venueId) {
            $this->apiDelete(\sprintf('venues/%s', $this->venueId), $this->token);
        }

        // Décor du scénario « plan de période » : la suppression de la période cascade son plan,
        // ses versions, leurs séances et le bloc ; puis on repose le pointeur du socle à NULL si
        // c'est nous qui l'avons posé. Guardé (champ vide = scénario socle, rien à faire).
        if ('' !== $this->periodEntryId) {
            $this->apiDelete(\sprintf('calendar_entries/%s', $this->periodEntryId), $this->token);
        }
        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    /**
     * @return array{status: int, json: array<mixed>}
     */
    private function reserveGroup(): array
    {
        return $this->apiPost('reservations/group', [
            'sharedTrainingBlockId' => $this->blockId,
            'venueId' => $this->venueId,
            'dayOfWeek' => self::DAY,
            'startTime' => self::START_TIME,
            'durationMinutes' => 90,
            'schedulePlanId' => null,
        ], $this->token);
    }

    /**
     * @param array<mixed> $json
     */
    private function captureReservationIds(array $json): void
    {
        $ids = $json['ids'] ?? [];
        $this->groupReservationIds = array_values(array_filter(\is_array($ids) ? $ids : [], 'is_string'));
    }

    private function reservationCountOnCase(): int
    {
        return (int) $this->dbalScalar(
            \sprintf(
                'SELECT COUNT(*) AS behatval FROM reservation WHERE venue_id=\'%s\' AND day_of_week=%d AND start_time=\'%s\' AND schedule_plan_id IS NULL',
                $this->venueId,
                self::DAY,
                self::START_TIME,
            ),
            admin: true,
        );
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function createdId(array $response, string $what): string
    {
        if (!\in_array($response['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création %s refusée (HTTP %d)', $what, $response['status']));
        }
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création %s sans identifiant en retour', $what));
        }

        return $id;
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $json): array
    {
        $members = $json['member'] ?? $json;

        return array_values(array_filter(\is_array($members) ? $members : [], 'is_array'));
    }

    private function createClosurePeriod(string $start, string $title): string
    {
        $end = date('Y-m-d', (int) strtotime($start . ' +4 days'));

        $entry = $this->apiPost('calendar_entries', [
            'kind' => 'period',
            'periodType' => 'closure',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
        ], $this->token);

        return $this->createdId($entry, 'période jetable');
    }

    /**
     * Le lundi (Y-m-d) d'une fenêtre de 4 jours SANS vacances scolaires de la zone du club, à partir
     * de « next monday +$offsetDays » — glisse de semaine en semaine jusqu'à en trouver une (un
     * décor relatif à aujourd'hui traverse les vacances au fil de l'année). Patron PeriodOverlayContext.
     */
    private function holidayFreeMonday(int $offsetDays): string
    {
        $monday = (int) strtotime(\sprintf('next monday +%d days', $offsetDays));
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $start = date('Y-m-d', $monday);
            $end = date('Y-m-d', (int) strtotime('+4 days', $monday));
            $hits = $this->dbalScalar(\sprintf(
                'SELECT count(*) AS behatval FROM school_holiday_period h JOIN club c ON c.school_zone = h.zone WHERE c.id=\'%s\' AND h.start_date <= \'%s\' AND h.end_date >= \'%s\'',
                $this->clubId,
                $end,
                $start,
            ), admin: true);
            if ('0' === $hits) {
                return $start;
            }
            $monday = (int) strtotime('+7 days', $monday);
        }

        throw new RuntimeException(\sprintf('aucune fenêtre de 4 jours sans vacances scolaires trouvée après « next monday +%d days »', $offsetDays));
    }

    private function pollUntilTerminal(string $scheduleId): string
    {
        $deadline = time() + self::TIMEOUT_SECONDS;
        $status = '';

        do {
            $response = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lecture de la version en échec (HTTP %d)', $response['status']));
            }

            $status = $response['json']['status'] ?? '';
            if (\is_string($status) && \in_array($status, ['COMPLETED', 'FAILED'], true)) {
                return $status;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('la génération n\'a pas abouti dans le délai imparti (dernier statut « %s »)', \is_string($status) ? $status : 'inconnu'));
    }

    /**
     * Une séance SOLO (hors bloc) du plan transcrit : son équipe n'a qu'UNE séance ici, aucune
     * réservation individuelle, et sa case n'a qu'UN occupant (capacité sereine). Rendu
     * « id|team|venue|day|start », ou '' si aucune. Enveloppé dans un SELECT extérieur
     * (`dbal:run-sql` traiterait un WITH comme une écriture, cf. PeriodOverlayContext).
     *
     * @param array{0: string, 1: int, 2: string}|null $excludeCase
     */
    private function pickCleanSoloSlot(string $excludeTeam, ?array $excludeCase): string
    {
        $filters = '';
        if ('' !== $excludeTeam) {
            $filters .= \sprintf(' AND s.team_id <> \'%s\'', $excludeTeam);
        }
        if (null !== $excludeCase) {
            $filters .= \sprintf(' AND NOT (s.venue_id = \'%s\' AND s.day_of_week = %d AND s.start_time = \'%s\')', $excludeCase[0], $excludeCase[1], $excludeCase[2]);
        }

        return $this->dbalScalar(
            \sprintf(
                'SELECT sub.behatval AS behatval FROM ('
                . ' SELECT s.id || \'|\' || s.team_id || \'|\' || s.venue_id || \'|\' || s.day_of_week || \'|\' || s.start_time AS behatval'
                . ' FROM schedule_slot_template s'
                . ' WHERE s.schedule_id = \'%1$s\' AND s.team_id IS NOT NULL AND s.shared_training_block_id IS NULL'
                . ' AND (SELECT COUNT(*) FROM schedule_slot_template o WHERE o.schedule_id = s.schedule_id AND o.team_id = s.team_id) = 1'
                . ' AND (SELECT COUNT(*) FROM schedule_slot_template c WHERE c.schedule_id = s.schedule_id AND c.venue_id = s.venue_id AND c.day_of_week = s.day_of_week AND c.start_time = s.start_time) = 1'
                . ' AND NOT EXISTS (SELECT 1 FROM shared_training_block_team bt WHERE bt.team_id = s.team_id AND bt.season_id = \'%2$s\')'
                . ' AND NOT EXISTS (SELECT 1 FROM reservation r WHERE r.team_id = s.team_id)'
                . '%3$s'
                . ' ORDER BY s.id LIMIT 1'
                . ') sub',
                $this->periodVersionId,
                $this->periodSeasonId,
                $filters,
            ),
            admin: true,
        );
    }
}
