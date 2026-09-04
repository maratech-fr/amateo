<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Placement des matchs de bout en bout, sur la stack qui tourne.
 *
 * Reproduit ce que faisait le smoke « place-matches » : rail SYNCHRONE de
 * POST /api/fixtures/place. On possède TOUTES les ressources dont dépendent les
 * assertions (deux équipes + un gymnase jetables, une fenêtre samedi, une
 * rotation samedi 15:30, deux fixtures) pour que la donnée WE réelle du seed ne
 * les perturbe pas. Deux gardes de restauration, exécutées quoi qu'il arrive :
 *   - la raison `no_access_window` est CLUB-WIDE → on sauve puis supprime toute
 *     fenêtre d'accès dominicale du club, et on la RECRÉE en fin de scénario ;
 *   - le module matchs exige un planning de saison en vigueur → si le pointeur
 *     était vide on le pose, et on le restaure (à NULL) en sortie.
 */
final class MatchPlacementContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private string $token = '';

    private string $clubId = '';

    private bool $pointerSetBySelf = false;

    private string $teamId = '';

    private string $secondTeamId = '';

    private string $venueId = '';

    private string $windowId = '';

    private string $rotationId = '';

    private string $fxSat = '';

    private string $fxSun = '';

    private string $saturday = '';

    private string $sunday = '';

    /** @var list<array{id: string, venueId: string, startTime: string, endTime: string}> */
    private array $sundayWindows = [];

    /** @var array<mixed> */
    private array $placeResult = [];

    /** @var array<mixed> */
    private array $satFixture = [];

    #[Given('le club de démonstration, connecté, dont le planning de saison est en vigueur')]
    public function leClubConnecteAvecSocleEnVigueur(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        // Le module matchs est gardé par un planning de saison EN VIGUEUR
        // (SocleGuard). S'il n'en pointe aucun, on en pose un le temps du scénario.
        $chosen = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' === $chosen) {
            $completed = $this->dbalScalar(
                \sprintf('SELECT id AS behatval FROM schedule WHERE club_id=\'%s\' AND status=\'COMPLETED\' ORDER BY created_at DESC LIMIT 1', $this->clubId),
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

        $this->saturday = date('Y-m-d', (int) strtotime('next saturday'));
        $this->sunday = date('Y-m-d', (int) strtotime($this->saturday . ' +1 day'));
    }

    #[Given('deux équipes et un gymnase jetables')]
    public function deuxEquipesEtUnGymnaseJetables(): void
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }

        $this->teamId = $this->createdId($this->apiPost('teams', ['name' => 'Match Jetable A', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe A');
        $this->secondTeamId = $this->createdId($this->apiPost('teams', ['name' => 'Match Jetable B', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe B');
        $this->venueId = $this->createdId($this->apiPost('venues', ['name' => 'Gym Jetable Matchs', 'source' => 'manual'], $this->token), 'gymnase');
    }

    #[Given('une fenêtre d\'accès le samedi de 14h00 à 18h00 sur ce gymnase')]
    public function uneFenetreLeSamedi(): void
    {
        $this->windowId = $this->createdId(
            $this->apiPost('venue_match_windows', ['venueId' => $this->venueId, 'dayOfWeek' => 6, 'startTime' => '14:00', 'endTime' => '18:00'], $this->token),
            'fenêtre d\'accès',
        );
    }

    #[Given('un créneau de rotation partagé le samedi à 15h30 réunissant les deux équipes')]
    public function uneRotationSamedi1530(): void
    {
        $this->rotationId = $this->createdId(
            $this->apiPost('match_slot_rotations', ['venueId' => $this->venueId, 'dayOfWeek' => 6, 'kickoffTime' => '15:30', 'teamIds' => [$this->teamId, $this->secondTeamId]], $this->token),
            'rotation',
        );
    }

    #[Given('un match à domicile le samedi et un autre le dimanche')]
    public function deuxMatchsADomicile(): void
    {
        $this->fxSat = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->saturday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire samedi'], $this->token),
            'match du samedi',
        );
        $this->fxSun = $this->createdId(
            $this->apiPost('fixtures', ['teamId' => $this->teamId, 'matchDate' => $this->sunday, 'homeAway' => 'HOME', 'opponentLabel' => 'Adversaire dimanche'], $this->token),
            'match du dimanche',
        );
    }

    #[Given('plus aucune fenêtre d\'accès le dimanche dans tout le club')]
    public function plusAucuneFenetreLeDimanche(): void
    {
        $windows = $this->apiGet('venue_match_windows?itemsPerPage=100', $this->token);
        foreach ($this->members($windows['json']) as $window) {
            if (7 !== ($window['dayOfWeek'] ?? null)) {
                continue;
            }
            $id = $window['id'] ?? null;
            $venueId = $window['venueId'] ?? null;
            $startTime = $window['startTime'] ?? null;
            $endTime = $window['endTime'] ?? null;
            if (!\is_string($id) || !\is_string($venueId) || !\is_string($startTime) || !\is_string($endTime)) {
                continue;
            }
            $this->sundayWindows[] = ['id' => $id, 'venueId' => $venueId, 'startTime' => $startTime, 'endTime' => $endTime];
            $this->apiDelete(\sprintf('venue_match_windows/%s', $id), $this->token);
        }
    }

    #[When('je lance le placement des matchs')]
    public function jeLanceLePlacement(): void
    {
        $result = $this->apiPost('fixtures/place', [], $this->token);
        if (200 !== $result['status']) {
            throw new RuntimeException(\sprintf('le placement des matchs a répondu %d (200 attendu)', $result['status']));
        }
        $this->placeResult = $result['json'];

        $fixture = $this->apiGet(\sprintf('fixtures/%s', $this->fxSat), $this->token);
        if (200 !== $fixture['status']) {
            throw new RuntimeException(\sprintf('lecture du match du samedi en échec (HTTP %d)', $fixture['status']));
        }
        $this->satFixture = $fixture['json'];
    }

    #[Then('le match du samedi est placé par le solveur entre 14h30 et 16h15')]
    public function leMatchDuSamediEstPlace(): void
    {
        $status = $this->satFixture['status'] ?? null;
        if ('PLACED' !== $status) {
            throw new RuntimeException(\sprintf('le match du samedi n\'est pas placé (statut « %s »)', \is_string($status) ? $status : 'inconnu'));
        }

        $source = $this->satFixture['placementSource'] ?? null;
        if ('SOLVER' !== $source) {
            throw new RuntimeException(\sprintf('le match du samedi n\'est pas marqué comme placé par le solveur (source « %s »)', \is_string($source) ? $source : 'inconnue'));
        }

        $kickoff = $this->kickoff();
        if ($kickoff <= '14:29' || $kickoff >= '16:16') {
            throw new RuntimeException(\sprintf('coup d\'envoi %s hors de la plage légale 14:30-16:15', $kickoff));
        }
    }

    #[Then('le match du samedi atterrit sur le créneau de rotation partagé, sur le gymnase à 15h30')]
    public function leMatchDuSamediSurLaRotation(): void
    {
        $kickoff = $this->kickoff();
        if (!str_starts_with($kickoff, '15:30')) {
            throw new RuntimeException(\sprintf('l\'attraction de rotation n\'a pas joué : coup d\'envoi %s au lieu de 15:30', $kickoff));
        }

        $venuePlaced = $this->satFixture['venueId'] ?? null;
        if ($venuePlaced !== $this->venueId) {
            throw new RuntimeException('le match n\'a pas atterri sur le gymnase du créneau de rotation');
        }
    }

    #[Then('le match du dimanche reste sans créneau, faute de fenêtre d\'accès ce jour-là')]
    public function leMatchDuDimancheResteSansCreneau(): void
    {
        $reason = null;
        $unplaced = $this->placeResult['unplaced'] ?? [];
        foreach (\is_array($unplaced) ? $unplaced : [] as $entry) {
            if (\is_array($entry) && ($entry['matchId'] ?? null) === $this->fxSun) {
                $reason = $entry['reason'] ?? null;

                break;
            }
        }

        if ('no_access_window' !== $reason) {
            throw new RuntimeException(\sprintf('le match du dimanche aurait dû rester sans fenêtre d\'accès, raison obtenue « %s »', \is_string($reason) ? $reason : 'aucune'));
        }
    }

    /**
     * Nettoyage dans l'ordre du smoke (`trap cleanup`) : les fixtures D'ABORD
     * (une équipe engagée refuse sa suppression), puis rotation, fenêtre,
     * équipes, gymnase ; on RECRÉE les fenêtres dominicales retirées ; enfin on
     * repose le pointeur du socle à NULL si c'est nous qui l'avons posé.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        foreach ([$this->fxSat, $this->fxSun] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('fixtures/%s', $id), $this->token);
            }
        }
        if ('' !== $this->rotationId) {
            $this->apiDelete(\sprintf('match_slot_rotations/%s', $this->rotationId), $this->token);
        }
        if ('' !== $this->windowId) {
            $this->apiDelete(\sprintf('venue_match_windows/%s', $this->windowId), $this->token);
        }
        foreach ([$this->teamId, $this->secondTeamId] as $id) {
            if ('' !== $id) {
                $this->apiDelete(\sprintf('teams/%s', $id), $this->token);
            }
        }
        if ('' !== $this->venueId) {
            $this->apiDelete(\sprintf('venues/%s', $this->venueId), $this->token);
        }

        foreach ($this->sundayWindows as $window) {
            $this->apiPost('venue_match_windows', [
                'venueId' => $window['venueId'],
                'dayOfWeek' => 7,
                'startTime' => $window['startTime'],
                'endTime' => $window['endTime'],
            ], $this->token);
        }

        if ($this->pointerSetBySelf && '' !== $this->clubId) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    private function kickoff(): string
    {
        $kickoff = $this->satFixture['kickoffTime'] ?? null;
        if (!\is_string($kickoff) || '' === $kickoff) {
            throw new RuntimeException('le match du samedi n\'a pas de coup d\'envoi');
        }

        return $kickoff;
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
