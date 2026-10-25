<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Chantier H — « le relais de l'adjoint tolère un retard » de bout en bout sur la stack (axe
 * constraint semantics). Le gestionnaire tolère, SUR LA SÉANCE rejointe, le retard d'un coach qui
 * enchaîne deux gymnases ; le récap (`POST /api/constraints/validate`) AVERTIT au lieu de bloquer
 * À CONDITION qu'un adjoint disponible ouvre la séance. Cas réel Rillieux : Sébastien finit à 20:15
 * au Mont Blanc (U21 19:00-20:15) et rejoint le Louison Bobet (SENIORS 20:00) ; trajet 15 +
 * chevauchement 15 → retard 30, toléré, Jules ouvre.
 *
 * Décor par l'API (le geste réel) pour équipes/gymnases/coachs/liens ; les créneaux, réservations
 * et le barème de trajet sont insérés en direct (pas d'endpoint de pose de créneau ici), puis la
 * tolérance est posée par le VRAI PATCH `reservations/{id}`. Tout est retiré en fin de scénario.
 */
final class CoachLateRelayContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    private string $token = '';

    private string $clubId = '';

    private string $seasonId = '';

    private string $u21Id = '';

    private string $seniorsId = '';

    private string $montBlancId = '';

    private string $louisonBobetId = '';

    private string $sebastienId = '';

    private string $julesId = '';

    private string $seniorsReservationId = '';

    /** @var list<string> ids à nettoyer (liens, coachs, équipes, gymnases) via l'API. */
    private array $apiCreated = [];

    /** @var array<string, list<string>> tables → ids insérés en direct à nettoyer. */
    private array $dbRows = ['reservation' => [], 'venue_training_slot' => [], 'venue_travel_time' => [], 'team_coach' => []];

    /** @var array{blockers: list<string>, warnings: list<string>} dernier récap obtenu. */
    private array $recap = ['blockers' => [], 'warnings' => []];

    #[Given('le club de démonstration, connecté, avec son planning de saison en vigueur')]
    public function leClubConnecte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);
        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;
        $this->seasonId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM season WHERE club_id=\'%s\' ORDER BY start_date DESC LIMIT 1', $this->clubId),
            admin: true,
        );
    }

    #[Given('Sébastien coache les U21 au Mont Blanc le mardi de 19h00 à 20h15, et les SENIORS au Louison Bobet le mardi à 20h00')]
    public function sebastienEnchaineDeuxGymnases(): void
    {
        $category = $this->firstCategory();
        $this->u21Id = $this->created($this->apiPost('teams', ['name' => 'U21 (relais)', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'U21');
        $this->seniorsId = $this->created($this->apiPost('teams', ['name' => 'SENIORS (relais)', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'SENIORS');
        $this->montBlancId = $this->created($this->apiPost('venues', ['name' => 'Mont Blanc (relais)', 'source' => 'manual'], $this->token), 'Mont Blanc');
        $this->louisonBobetId = $this->created($this->apiPost('venues', ['name' => 'Louison Bobet (relais)', 'source' => 'manual'], $this->token), 'Louison Bobet');

        // Sébastien principal des DEUX équipes : c'est lui qui se dédoublerait.
        $this->sebastienId = $this->created($this->apiPost('coaches', ['firstName' => 'Sébastien', 'lastName' => 'Relais'], $this->token), 'Sébastien');
        $this->linkCoach($this->u21Id, $this->sebastienId, 'MAIN');
        $this->linkCoach($this->seniorsId, $this->sebastienId, 'MAIN');

        // U21 mardi 19:00-20:15 (Mont Blanc) ; SENIORS mardi 20:00-21:30 (Louison Bobet).
        $this->placeSlotAndReservation($this->u21Id, $this->montBlancId, '19:00', 75, isSeniors: false);
        $this->seniorsReservationId = $this->placeSlotAndReservation($this->seniorsId, $this->louisonBobetId, '20:00', 90, isSeniors: true);
    }

    #[Given('le trajet entre le Mont Blanc et le Louison Bobet est de 15 minutes')]
    public function leTrajetEstDeQuinzeMinutes(): void
    {
        [$a, $b] = $this->montBlancId < $this->louisonBobetId ? [$this->montBlancId, $this->louisonBobetId] : [$this->louisonBobetId, $this->montBlancId];
        $id = $this->uuid();
        $this->dbalExec(
            \sprintf(
                'INSERT INTO venue_travel_time (id, version, created_at, updated_at, club_id, season_id, venue_a_id, venue_b_id, walking_minutes)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', \'%s\', 15)',
                $id,
                $this->clubId,
                $this->seasonId,
                $a,
                $b,
            ),
            admin: true,
        );
        $this->dbRows['venue_travel_time'][] = $id;
    }

    #[Given('Jules est adjoint disponible des SENIORS le mardi')]
    public function julesAdjointDisponible(): void
    {
        $this->julesId = $this->created($this->apiPost('coaches', ['firstName' => 'Jules', 'lastName' => 'Relais'], $this->token), 'Jules');
        $this->linkCoach($this->seniorsId, $this->julesId, 'ASSISTANT');
    }

    #[Given('les SENIORS n\'ont aucun adjoint disponible le mardi')]
    public function aucunAdjoint(): void
    {
        // Rien à faire : personne n'est lié en ASSISTANT aux SENIORS. Étape explicite pour la
        // lisibilité du scénario d'abus (sans adjoint → le récap bloque).
    }

    #[When('je tolère un retard de 30 minutes sur la séance des SENIORS et je demande le récap')]
    public function jeToléreEtDemandeLeRécap(): void
    {
        $patch = $this->apiPatch(\sprintf('reservations/%s', $this->seniorsReservationId), ['coachLateToleranceMinutes' => 30], $this->token);
        if (200 !== $patch['status']) {
            throw new RuntimeException(\sprintf('la pose de tolérance (PATCH) a répondu HTTP %d', $patch['status']));
        }

        $response = $this->apiPost('constraints/validate', [], $this->token);
        $json = $response['json'];
        $this->recap = [
            'blockers' => array_values(array_filter((array) ($json['blockers'] ?? []), 'is_string')),
            'warnings' => array_values(array_filter((array) ($json['warnings'] ?? []), 'is_string')),
        ];
    }

    #[Then('le récap ne bloque pas, et il avertit que Sébastien arrivera en retard aux SENIORS, Jules ouvrant la séance')]
    public function leRécapAvertitSansBloquer(): void
    {
        if ($this->mentions($this->recap['blockers'], 'deux créneaux en même temps')) {
            throw new RuntimeException('le récap n\'aurait PAS dû bloquer : le retard est toléré et Jules ouvre la séance');
        }
        $warnings = implode(' | ', $this->recap['warnings']);
        foreach (['Sébastien', 'en retard', 'SENIORS', 'Jules'] as $needle) {
            if (!str_contains($warnings, $needle)) {
                throw new RuntimeException(\sprintf('l\'avertissement de relais devrait mentionner « %s » : %s', $needle, $warnings));
            }
        }
    }

    #[Then('le récap bloque, car personne n\'ouvre la séance des SENIORS')]
    public function leRécapBloque(): void
    {
        if (!$this->mentions($this->recap['blockers'], 'deux créneaux en même temps')) {
            throw new RuntimeException('sans adjoint, le dédoublement de Sébastien aurait dû rester un bloqueur');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }
        foreach (['reservation', 'venue_training_slot', 'venue_travel_time', 'team_coach'] as $table) {
            foreach ($this->dbRows[$table] as $id) {
                $this->dbalExec(\sprintf('DELETE FROM %s WHERE id=\'%s\'', $table, $id), admin: true);
            }
        }
        foreach ($this->apiCreated as $path) {
            $this->apiDelete($path, $this->token);
        }
    }

    private function linkCoach(string $teamId, string $coachId, string $role): void
    {
        // Lien en direct (le nettoyage par table est plus sûr que l'ordre des DELETE d'API).
        $id = $this->uuid();
        $this->dbalExec(
            \sprintf(
                'INSERT INTO team_coach (id, version, created_at, updated_at, club_id, season_id, team_id, coach_id, role, is_required)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', true)',
                $id,
                $this->clubId,
                $this->seasonId,
                $teamId,
                $coachId,
                $role,
            ),
            admin: true,
        );
        $this->dbRows['team_coach'][] = $id;
    }

    /** Pose un créneau de saison PLUS une réservation socle (tolérance 0) ; rend l'id de la réservation. */
    private function placeSlotAndReservation(string $teamId, string $venueId, string $start, int $duration, bool $isSeniors): string
    {
        $slotId = $this->uuid();
        $this->dbalExec(
            \sprintf(
                'INSERT INTO venue_training_slot (id, version, created_at, updated_at, club_id, season_id, venue_id, day_of_week, start_time, duration_minutes, capacity)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', 2, \'%s:00\', %d, 1)',
                $slotId,
                $this->clubId,
                $this->seasonId,
                $venueId,
                $start,
                $duration,
            ),
            admin: true,
        );
        $this->dbRows['venue_training_slot'][] = $slotId;

        $resaId = $this->uuid();
        $this->dbalExec(
            \sprintf(
                'INSERT INTO reservation (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, team_id, label, venue_id, day_of_week, start_time, duration_minutes, coach_late_tolerance_minutes)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', NULL, \'%s\', NULL, \'%s\', 2, \'%s:00\', %d, 0)',
                $resaId,
                $this->clubId,
                $this->seasonId,
                $teamId,
                $venueId,
                $start,
                $duration,
            ),
            admin: true,
        );
        $this->dbRows['reservation'][] = $resaId;

        return $resaId;
    }

    private function firstCategory(): string
    {
        $categories = $this->apiGet('sport_categories', $this->token);
        $members = $categories['json']['member'] ?? $categories['json'];
        $first = \is_array($members) ? ($members[0]['id'] ?? null) : null;
        if (!\is_string($first) || '' === $first) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }

        return $first;
    }

    /**
     * @param array{status: int, json: array<mixed>} $response
     */
    private function created(array $response, string $what): string
    {
        if (!\in_array($response['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création %s refusée (HTTP %d)', $what, $response['status']));
        }
        $id = $response['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException(\sprintf('création %s sans identifiant', $what));
        }
        // Déduire le chemin de suppression du type d'entité créé.
        $resource = match (true) {
            str_contains($what, 'U21'), str_contains($what, 'SENIORS') => 'teams',
            str_contains($what, 'Blanc'), str_contains($what, 'Bobet') => 'venues',
            default => 'coaches',
        };
        $this->apiCreated[] = \sprintf('%s/%s', $resource, $id);

        return $id;
    }

    private function mentions(array $list, string $needle): bool
    {
        foreach ($list as $line) {
            if (\is_string($line) && str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
