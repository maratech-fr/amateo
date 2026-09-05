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
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const int DAY = 2;

    private const string START_TIME = '18:00';

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
}
