<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Service\CoachDoubleBookingDetector;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * P4-269 — « une personne à deux endroits en même temps » sur le planning d'entraînement EN
 * VIGUEUR (axe planning lifecycle), sur la stack qui tourne.
 *
 * Le bug de confiance gardé ici en HTTP : après génération, compléter son modèle sans régénérer
 * (lier un coach adjoint tardif, un joueur) peut mettre une personne sur deux séances DÉJÀ placées
 * qui se chevauchent — et rien ne le signalait ({@see CoachDoubleBookingDetector}
 * n'est consommé que pré-solve). Le radar (`GET /api/training/placed-conflicts`) le DIT désormais,
 * en lecture seule. Gymnases DIFFÉRENTS = conflit ; MÊME gymnase = mutualisation voulue, rien.
 *
 * Décor : le club de démonstration et sa version de saison en vigueur. On crée deux équipes, deux
 * gymnases et un coach JETABLES, on lie le coach aux deux équipes (MAIN puis ASSISTANT — l'exemple
 * fondateur), puis on pose deux séances placées sur la version en vigueur (insertion directe, comme
 * la génération l'aurait fait). Tout est retiré en fin de scénario, quoi qu'il arrive.
 */
final class PlacedPersonConflictContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private string $token = '';

    private string $clubId = '';

    private string $scheduleId = '';

    private string $seasonId = '';

    private string $coachId = '';

    private string $teamAId = '';

    private string $teamBId = '';

    private string $linkAId = '';

    private string $linkBId = '';

    private string $venue1Id = '';

    private string $venue2Id = '';

    /** @var list<string> ids des séances insérées à nettoyer */
    private array $slotIds = [];

    #[Given('le club de démonstration, connecté, avec son planning de saison en vigueur')]
    public function leClubAvecSonPlanningEnVigueur(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        // La version de saison EN VIGUEUR : celle que le socle pointe.
        $this->scheduleId = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id IS NOT NULL LIMIT 1', $this->clubId),
            admin: true,
        );
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->scheduleId)) {
            throw new RuntimeException('aucune version de saison pointée — le socle du bac à sable est-il validé ?');
        }
        $this->seasonId = $this->dbalScalar(
            \sprintf('SELECT season_id AS behatval FROM schedule WHERE id=\'%s\'', $this->scheduleId),
            admin: true,
        );

        // Catégorie sportive existante pour bâtir des équipes jetables.
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }

        $this->teamAId = $this->createdId($this->apiPost('teams', ['name' => 'U13F jetable (P4-269)', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe A');
        $this->teamBId = $this->createdId($this->apiPost('teams', ['name' => 'U11M1 jetable (P4-269)', 'sportCategoryId' => $category, 'priorityTierId' => 1], $this->token), 'équipe B');
        $this->venue1Id = $this->createdId($this->apiPost('venues', ['name' => 'Gymnase Un jetable', 'source' => 'manual'], $this->token), 'gymnase 1');
        $this->venue2Id = $this->createdId($this->apiPost('venues', ['name' => 'Gymnase Deux jetable', 'source' => 'manual'], $this->token), 'gymnase 2');

        $this->coachId = $this->createdId($this->apiPost('coaches', ['firstName' => 'Anna', 'lastName' => 'Jetable'], $this->token), 'coach');
        // MAIN de l'équipe A, ASSISTANT de l'équipe B : l'élargissement fondateur (une adjointe
        // liée tard compte comme présente), pas seulement le MAIN du solveur.
        $this->linkAId = $this->createdId($this->apiPost('team_coaches', ['teamId' => $this->teamAId, 'coachId' => $this->coachId, 'role' => 'MAIN'], $this->token), 'lien A');
        $this->linkBId = $this->createdId($this->apiPost('team_coaches', ['teamId' => $this->teamBId, 'coachId' => $this->coachId, 'role' => 'ASSISTANT'], $this->token), 'lien B');
    }

    #[When('je place deux séances qui se chevauchent dans deux gymnases différents, coachées par la même personne')]
    public function deuxSeancesDeuxGymnases(): void
    {
        $this->placeSlot($this->teamAId, $this->venue1Id);
        $this->placeSlot($this->teamBId, $this->venue2Id);
    }

    #[When('je place deux séances qui se chevauchent dans le même gymnase, coachées par la même personne')]
    public function deuxSeancesMemeGymnase(): void
    {
        $this->placeSlot($this->teamAId, $this->venue1Id);
        $this->placeSlot($this->teamBId, $this->venue1Id);
    }

    #[Then('le radar signale que cette personne est à deux endroits en même temps')]
    public function leRadarSignaleLaPersonne(): void
    {
        if (!$this->radarListsCoach()) {
            throw new RuntimeException('le radar aurait dû signaler la personne à deux endroits (gymnases différents, séances qui se chevauchent)');
        }
    }

    #[Then('le radar ne signale aucun conflit pour cette personne')]
    public function leRadarNeSignalePas(): void
    {
        if ($this->radarListsCoach()) {
            throw new RuntimeException('le radar n\'aurait PAS dû signaler la personne : même gymnase = mutualisation voulue');
        }
    }

    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        // Les séances insérées d'abord (elles référencent équipes et gymnases).
        foreach ($this->slotIds as $slotId) {
            $this->dbalExec(\sprintf('DELETE FROM schedule_slot_template WHERE id=\'%s\'', $slotId), admin: true);
        }
        foreach ([$this->linkAId, $this->linkBId] as $linkId) {
            if ('' !== $linkId) {
                $this->apiDelete(\sprintf('team_coaches/%s', $linkId), $this->token);
            }
        }
        if ('' !== $this->coachId) {
            $this->apiDelete(\sprintf('coaches/%s', $this->coachId), $this->token);
        }
        foreach ([$this->teamAId, $this->teamBId] as $teamId) {
            if ('' !== $teamId) {
                $this->apiDelete(\sprintf('teams/%s', $teamId), $this->token);
            }
        }
        foreach ([$this->venue1Id, $this->venue2Id] as $venueId) {
            if ('' !== $venueId) {
                $this->apiDelete(\sprintf('venues/%s', $venueId), $this->token);
            }
        }
    }

    /** Le radar courant liste-t-il notre coach jetable ? */
    private function radarListsCoach(): bool
    {
        $seen = $this->apiGet('training/placed-conflicts', $this->token);
        if (200 !== $seen['status']) {
            throw new RuntimeException(\sprintf('le radar a répondu HTTP %d', $seen['status']));
        }
        $conflicts = $seen['json']['conflicts'] ?? null;
        if (!\is_array($conflicts)) {
            throw new RuntimeException('la réponse du radar ne porte pas de liste « conflicts »');
        }
        foreach ($conflicts as $conflict) {
            if (\is_array($conflict) && ($conflict['personId'] ?? null) === $this->coachId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pose une séance PLACÉE sur la version en vigueur (le mardi à 18h00, 90 min) — telle que la
     * génération l'aurait écrite. Insertion directe : la séance n'a pas d'endpoint de création,
     * et c'est la version pointée qui est scannée par le radar.
     */
    private function placeSlot(string $teamId, string $venueId): void
    {
        $id = $this->uuid();
        $this->dbalExec(
            \sprintf(
                'INSERT INTO schedule_slot_template (id, version, created_at, updated_at, club_id, season_id, schedule_id, team_id, venue_id, day_of_week, start_time, duration_minutes, lock_level)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', 2, \'18:00:00\', 90, \'NONE\')',
                $id,
                $this->clubId,
                $this->seasonId,
                $this->scheduleId,
                $teamId,
                $venueId,
            ),
            admin: true,
        );
        $this->slotIds[] = $id;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

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
