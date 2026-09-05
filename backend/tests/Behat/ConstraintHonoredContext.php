<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Une contrainte saisie est honorée par le solveur — ou l'impossible est nommé (axe
 * constraint semantics), sur la stack qui tourne (rail asynchrone réel : Messenger → worker →
 * moteur → import).
 *
 * Deux promesses, éprouvées en HTTP contre le VRAI moteur (deux générations, ~30 s chacune) :
 *   - une contrainte d'ÉQUIPE « pas avant 20h30 » (TIME/HARD, `minStartTime`) → génération
 *     COMPLETED et AUCUNE séance de l'équipe placée avant 20h30 ;
 *   - une contrainte IMPOSSIBLE pour la même équipe (fenêtre horaire qu'aucun créneau ne satisfait,
 *     alors que ses séances sont un plancher dur) → génération FAILED avec un diagnostic nommé,
 *     jamais un planning bricolé.
 *
 * Sur le club de démonstration : on ROUVRE le socle le temps du scénario (générer une version de
 * saison l'exige), on ajoute la contrainte, on génère, puis on RETIRE la contrainte et on RESTAURE
 * le pointeur — quoi qu'il arrive.
 */
final class ConstraintHonoredContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string TEAM_NAME = 'SM1';

    private const int POLL_INTERVAL_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 650;

    private string $token = '';

    private string $clubId = '';

    private string $teamId = '';

    /** Version que le socle pointait à l'entrée (à restaurer). */
    private string $chosenAtEntry = '';

    private string $constraintId = '';

    private string $blockId = '';

    /** @var list<string> Équipes jetables du bloc impossible (à retirer). */
    private array $disposableTeams = [];

    private string $scheduleId = '';

    private string $finalStatus = '';

    #[Given('le club de démonstration, connecté, dont le planning de saison est rouvert')]
    public function leClubRouvert(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $me = $this->apiGet('me', $this->token);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubId = $clubId;

        $this->teamId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM team WHERE club_id=\'%s\' AND name=\'%s\' LIMIT 1', $this->clubId, self::TEAM_NAME),
            admin: true,
        );
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->teamId)) {
            throw new RuntimeException(\sprintf('équipe « %s » introuvable — la base est-elle seedée ?', self::TEAM_NAME));
        }

        // Générer une version de saison exige que le socle NE soit PAS en vigueur (on rouvre l'espace
        // de travail). On dépointe le temps du scénario, restauré en fin (comme SeasonGenerationContext).
        $this->chosenAtEntry = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );
        if ('' !== $this->chosenAtEntry) {
            $this->pointSocleTo('');
        }
    }

    #[Given('une contrainte qui interdit à l\'équipe SM1 de s\'entraîner avant 20h30')]
    public function uneContrainteFeasible(): void
    {
        $this->createTeamTimeConstraint('SM1 pas avant 20h30 (fonctionnel)', ['minStartTime' => '20:30']);
    }

    #[Given('un entraînement mutualisé qu\'aucun créneau ne peut accueillir')]
    public function unEntrainementMutualiseImpossible(): void
    {
        // Une séance d'ÉQUIPE seule est un plancher que le solveur peut rabaisser (cap de palier) ;
        // une séance de BLOC de mutualisation, elle, est un placement DUR — le moteur sort INFEASIBLE
        // si le bloc ne tient nulle part (engine/tests/semantic/test_shared_block_semantics.py). On
        // forge donc un entraînement mutualisé JETABLE (deux équipes neuves, sans réservation, pour
        // ne rien changer aux équipes réelles du club), puis on ferme sa fenêtre : aucun créneau
        // n'ouvre après 23h30 → le bloc ne tient nulle part → génération vouée à l'échec.
        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }

        foreach (['Bloc Impossible A', 'Bloc Impossible B'] as $name) {
            $team = $this->apiPost('teams', ['name' => $name, 'sportCategoryId' => $category, 'priorityTierId' => 1, 'sessionsPerWeek' => 1], $this->token);
            if (!\in_array($team['status'], [200, 201], true)) {
                throw new RuntimeException(\sprintf('création d\'une équipe jetable refusée (HTTP %d)', $team['status']));
            }
            $this->disposableTeams[] = $this->stringField($team['json'], 'id');
        }

        $block = $this->apiPost('shared_training_blocks', [
            'teamIds' => $this->disposableTeams,
            'commonSessions' => 1,
            'schedulePlanId' => null,
        ], $this->token);
        if (!\in_array($block['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de l\'entraînement mutualisé refusée (HTTP %d)', $block['status']));
        }
        $this->blockId = $this->stringField($block['json'], 'id');

        // Fenêtre impossible sur un membre du bloc : le bloc co-place ce membre, donc sa case commune
        // devrait ouvrir après 23h30 — or aucun créneau du club ne le fait.
        $this->createTeamTimeConstraint('Bloc impossible après 23h30 (fonctionnel)', ['minStartTime' => '23:30'], $this->disposableTeams[0]);
    }

    #[When('je génère le planning de saison')]
    public function jeGenereLePlanning(): void
    {
        $created = $this->apiPost('schedules', ['name' => 'Planning contrainte ' . date('Y-m-d_H:i:s'), 'status' => 'DRAFT'], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création du planning refusée (HTTP %d)', $created['status']));
        }
        $this->scheduleId = $this->stringField($created['json'], 'id');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $this->scheduleId), [], $this->token);
        if (!\in_array($launched['status'], [200, 202], true)) {
            throw new RuntimeException(\sprintf('déclenchement de la génération refusé (HTTP %d)', $launched['status']));
        }

        $this->finalStatus = $this->pollUntilTerminal($this->scheduleId);
    }

    #[Then('la génération aboutit et aucune séance de SM1 n\'est placée avant 20h30')]
    public function aucuneSeanceAvant2030(): void
    {
        if ('COMPLETED' !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('la génération aurait dû aboutir (COMPLETED), obtenu « %s »', $this->finalStatus));
        }

        $count = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'', $this->scheduleId, $this->teamId),
            admin: true,
        );
        if ($count < 1) {
            throw new RuntimeException('SM1 n\'a aucune séance : la contrainte serait honorée à vide, la preuve serait creuse');
        }

        $earliest = $this->dbalScalar(
            \sprintf('SELECT MIN(start_time)::text AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\' AND team_id=\'%s\'', $this->scheduleId, $this->teamId),
            admin: true,
        );
        if ($earliest < '20:30:00') {
            throw new RuntimeException(\sprintf('une séance de SM1 est placée à %s, avant l\'heure interdite 20:30', $earliest));
        }
    }

    #[Then('la génération échoue avec un diagnostic nommé, sans planning produit')]
    public function laGenerationEchoue(): void
    {
        if ('FAILED' !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('la génération aurait dû échouer (FAILED), obtenu « %s »', $this->finalStatus));
        }

        // Un diagnostic NOMMÉ (type non vide) accompagne l'échec.
        $namedDiagnostics = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_diagnostic WHERE schedule_id=\'%s\' AND type <> \'\'', $this->scheduleId),
            admin: true,
        );
        if ($namedDiagnostics < 1) {
            throw new RuntimeException('la génération échouée n\'a produit aucun diagnostic nommé');
        }

        // Aucun planning bricolé : la version échouée n'est pas devenue le socle.
        $chosen = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id=\'%s\'', $this->clubId, $this->scheduleId),
            admin: true,
        );
        if ('0' !== $chosen) {
            throw new RuntimeException('une génération échouée ne doit jamais devenir le planning de saison');
        }
    }

    /**
     * Retire la contrainte et restaure le pointeur du socle — quoi qu'il arrive (succès, échec,
     * exception). La version générée reste (espace de travail), comme dans SeasonGenerationContext.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        if ('' !== $this->constraintId) {
            $this->apiDelete(\sprintf('constraints/%s', $this->constraintId), $this->token);
        }
        if ('' !== $this->blockId) {
            $this->apiDelete(\sprintf('shared_training_blocks/%s', $this->blockId), $this->token);
        }
        foreach ($this->disposableTeams as $teamId) {
            $this->apiDelete(\sprintf('teams/%s', $teamId), $this->token);
        }

        if ('' !== $this->clubId && '' !== $this->chosenAtEntry) {
            $target = '1' === $this->dbalScalar(\sprintf('SELECT COUNT(*) AS behatval FROM schedule WHERE id=\'%s\'', $this->chosenAtEntry), admin: true)
                ? $this->chosenAtEntry
                : $this->dbalScalar(\sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId), admin: true);
            if (1 === preg_match('/^[0-9a-f-]{36}$/i', $target)) {
                $this->pointSocleTo($target);
            }
        }
    }

    /**
     * @param array<string, string> $config
     */
    private function createTeamTimeConstraint(string $name, array $config, ?string $targetTeamId = null): void
    {
        $created = $this->apiPost('constraints', [
            'name' => $name,
            'scope' => 'TEAM',
            'scopeTargetId' => $targetTeamId ?? $this->teamId,
            'family' => 'TIME',
            'ruleType' => 'HARD',
            'config' => $config,
            'isActive' => true,
        ], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de la contrainte refusée (HTTP %d)', $created['status']));
        }
        $this->constraintId = $this->stringField($created['json'], 'id');
    }

    private function pointSocleTo(string $scheduleId): void
    {
        $value = '' === $scheduleId ? 'NULL' : \sprintf('\'%s\'', $scheduleId);
        $this->dbalExec(
            \sprintf('UPDATE schedule_plan SET chosen_schedule_id=%s WHERE club_id=\'%s\' AND type=\'SEASON\'', $value, $this->clubId),
            admin: true,
        );
    }

    private function pollUntilTerminal(string $scheduleId): string
    {
        $deadline = time() + self::TIMEOUT_SECONDS;
        $status = '';

        do {
            $response = $this->apiGet(\sprintf('schedules/%s', $scheduleId), $this->token);
            if (200 !== $response['status']) {
                throw new RuntimeException(\sprintf('lecture du planning en échec (HTTP %d)', $response['status']));
            }

            $status = $this->stringField($response['json'], 'status');
            if (\in_array($status, ['COMPLETED', 'FAILED'], true)) {
                return $status;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException(\sprintf('la génération n\'a pas abouti dans le délai imparti (dernier statut « %s »)', $status));
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

    /**
     * @param array<mixed> $json
     */
    private function stringField(array $json, string $field): string
    {
        $value = $json[$field] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new RuntimeException(\sprintf('champ « %s » absent de la réponse de l\'API', $field));
        }

        return $value;
    }
}
