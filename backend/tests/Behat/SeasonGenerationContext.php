<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Génération du planning de saison de bout en bout, sur la stack qui tourne.
 *
 * Reproduit ce que faisait le smoke « season solver » : on forge un jeton pour
 * le gestionnaire du club dev, on rouvre le socle en vigueur le temps du
 * scénario, on crée une version puis on lance la génération (rail asynchrone
 * réel : Messenger → worker → moteur → import), et on attend le statut terminal.
 * Le pointeur du socle est TOUJOURS restauré en fin de scénario, succès ou échec.
 */
final class SeasonGenerationContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const int POLL_INTERVAL_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 650;

    private string $token = '';

    private string $clubId = '';

    /** Version que le socle pointait à l'entrée du scénario (à restaurer). */
    private string $chosenAtEntry = '';

    private string $finalStatus = '';

    #[Given('le club de démonstration et son gestionnaire connecté')]
    public function leClubEtSonGestionnaireConnecte(): void
    {
        $this->token = $this->mintToken(self::USER_EMAIL);

        $this->clubId = $this->dbalScalar(
            \sprintf(
                'SELECT c.id AS behatval FROM club c JOIN club_user cu ON cu.club_id = c.id JOIN app_user u ON u.id = cu.user_id WHERE u.email = \'%s\' LIMIT 1',
                self::USER_EMAIL,
            ),
            admin: true,
        );

        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $this->clubId)) {
            throw new RuntimeException('aucun club rattaché au gestionnaire de démonstration — la base est-elle seedée ?');
        }
    }

    #[Given('le planning de saison en vigueur est rouvert pour la durée du scénario')]
    public function leSocleEstRouvert(): void
    {
        $this->chosenAtEntry = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' LIMIT 1', $this->clubId),
            admin: true,
        );

        if ('' !== $this->chosenAtEntry) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id=\'%s\' AND type=\'SEASON\'', $this->clubId),
                admin: true,
            );
        }
    }

    #[When('je lance la génération du planning de saison')]
    public function jeLanceLaGeneration(): void
    {
        $created = $this->apiPost(
            'schedules',
            ['name' => 'Planning fonctionnel ' . date('Y-m-d_H:i:s'), 'status' => 'DRAFT'],
            $this->token,
            ['X-Club-Id' => $this->clubId],
        );
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création du planning refusée (HTTP %d)', $created['status']));
        }
        $scheduleId = $this->stringField($created['json'], 'id');

        $launched = $this->apiPost(\sprintf('schedules/%s/generate', $scheduleId), [], $this->token);
        if (!\in_array($launched['status'], [200, 202], true)) {
            throw new RuntimeException(\sprintf('déclenchement de la génération refusé (HTTP %d)', $launched['status']));
        }

        $this->finalStatus = $this->pollUntilTerminal($scheduleId);
    }

    #[Then('/^la génération aboutit avec le statut « (?P<statut>[A-Z]+) »$/')]
    public function laGenerationAboutitAvecLeStatut(string $statut): void
    {
        if ($statut !== $this->finalStatus) {
            throw new RuntimeException(\sprintf('statut attendu « %s », obtenu « %s »', $statut, $this->finalStatus));
        }
    }

    /**
     * Restaure le pointeur du socle exactement comme le faisait `trap restore_pointer` :
     * on ne repointe qu'une version qui EXISTE encore, sinon on retombe sur la
     * version COMPLETED la plus fraîche du socle. Ce qui compte pour le scénario
     * suivant, c'est « socle en vigueur », pas quelle version précise le porte.
     * Exécuté quoi qu'il arrive (succès, échec, exception).
     */
    #[AfterScenario]
    public function restaurerLePointeurDuSocle(): void
    {
        if ('' === $this->chosenAtEntry || '' === $this->clubId) {
            return;
        }

        $target = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM schedule WHERE id=\'%s\'', $this->chosenAtEntry),
            admin: true,
        );

        if ('' === $target) {
            $target = $this->dbalScalar(
                \sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId),
                admin: true,
            );
        }

        if ('' !== $target) {
            $this->dbalExec(
                \sprintf('UPDATE schedule_plan SET chosen_schedule_id=\'%s\' WHERE club_id=\'%s\' AND type=\'SEASON\'', $target, $this->clubId),
                admin: true,
            );
        }
    }

    private function pollUntilTerminal(string $scheduleId): string
    {
        $deadline = time() + self::TIMEOUT_SECONDS;

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
