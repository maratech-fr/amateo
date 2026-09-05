<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Modifier une contrainte marque le planning à régénérer, sans le détruire (axe planning
 * lifecycle), sur la stack qui tourne.
 *
 * Le bug de confiance qu'on garde ici en HTTP : après génération, ajouter une contrainte rend le
 * planning PÉRIMÉ — pas faux, mais antérieur aux règles courantes. L'application doit le DIRE
 * (`constraintsChangedSinceGeneration`, exposé sur GET /schedules/{id}) sans effacer un créneau.
 *
 * Décor : le club de démonstration et sa version de saison en vigueur. On pose son drapeau de
 * péremption à faux (état « non marqué » du départ), on ajoute une contrainte jetable, puis on la
 * retire et on restaure le drapeau — quoi qu'il arrive. Le retrait d'une contrainte re-marque le
 * planning (le résultat résolu AVEC elle est lui aussi périmé du fait qu'elle a disparu) : la
 * restauration se fait donc en base, sur la version ciblée, exactement comme le fait la preuve
 * PHPUnit (`ConstraintChangeStaleScheduleTest`).
 */
final class StaleScheduleContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private const string TEAM_NAME = 'SM1';

    private string $token = '';

    private string $clubId = '';

    private string $teamId = '';

    private string $scheduleId = '';

    /** Valeur du drapeau de péremption à l'entrée (à restaurer). */
    private bool $flagAtEntry = false;

    private int $slotCountAtEntry = 0;

    private string $statusAtEntry = '';

    private string $constraintId = '';

    #[Given('le club de démonstration, connecté, dont le planning de saison en vigueur n\'est pas marqué')]
    public function leClubAvecUnPlanningNonMarque(): void
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

        // La version de saison EN VIGUEUR : celle que le socle pointe, sinon la COMPLETED la plus
        // fraîche (le marquage vise TOUTES les COMPLETED du club+saison, indépendamment du pointeur).
        $this->scheduleId = $this->dbalScalar(
            \sprintf('SELECT chosen_schedule_id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id IS NOT NULL LIMIT 1', $this->clubId),
            admin: true,
        );
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->scheduleId)) {
            $this->scheduleId = $this->dbalScalar(
                \sprintf('SELECT s.id AS behatval FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id=\'%s\' AND p.type=\'SEASON\' AND s.status=\'COMPLETED\' ORDER BY s.created_at DESC LIMIT 1', $this->clubId),
                admin: true,
            );
        }
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->scheduleId)) {
            throw new RuntimeException('aucun planning de saison COMPLETED — la base est-elle seedée ?');
        }

        // Photographie de l'état de départ, pour prouver l'intégrité ET restaurer en fin.
        $this->flagAtEntry = 'oui' === $this->dbalScalar(
            \sprintf('SELECT CASE WHEN constraints_changed_since_generation THEN \'oui\' ELSE \'non\' END AS behatval FROM schedule WHERE id=\'%s\'', $this->scheduleId),
            admin: true,
        );
        $this->slotCountAtEntry = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\'', $this->scheduleId),
            admin: true,
        );
        $this->statusAtEntry = $this->dbalScalar(
            \sprintf('SELECT status AS behatval FROM schedule WHERE id=\'%s\'', $this->scheduleId),
            admin: true,
        );

        // État « non marqué » du départ : on remet le drapeau à faux pour que le scénario prouve la
        // TRANSITION (faux → vrai), quel que soit l'héritage d'un run précédent.
        $this->setFlag(false);

        $seen = $this->apiGet(\sprintf('schedules/%s', $this->scheduleId), $this->token);
        if (true === ($seen['json']['constraintsChangedSinceGeneration'] ?? null)) {
            throw new RuntimeException('le planning en vigueur est déjà marqué à régénérer — décor non tenu');
        }
    }

    #[When('j\'ajoute une contrainte au club')]
    public function jAjouteUneContrainte(): void
    {
        $created = $this->apiPost('constraints', [
            'name' => 'SM1 pas avant 20h30 (fonctionnel péremption)',
            'scope' => 'TEAM',
            'scopeTargetId' => $this->teamId,
            'family' => 'TIME',
            'ruleType' => 'HARD',
            'config' => ['minStartTime' => '20:30'],
            'isActive' => true,
        ], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de la contrainte refusée (HTTP %d)', $created['status']));
        }
        $id = $created['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('la contrainte a été créée sans identifiant en retour');
        }
        $this->constraintId = $id;
    }

    #[Then('le planning en vigueur est marqué à régénérer')]
    public function lePlanningEstMarque(): void
    {
        $seen = $this->apiGet(\sprintf('schedules/%s', $this->scheduleId), $this->token);
        if (true !== ($seen['json']['constraintsChangedSinceGeneration'] ?? null)) {
            throw new RuntimeException('le planning en vigueur aurait dû être marqué à régénérer après l\'ajout d\'une contrainte');
        }
    }

    #[Then('le planning en vigueur est intact, mêmes créneaux et même statut')]
    public function lePlanningEstIntact(): void
    {
        $slots = (int) $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM schedule_slot_template WHERE schedule_id=\'%s\'', $this->scheduleId),
            admin: true,
        );
        if ($slots !== $this->slotCountAtEntry) {
            throw new RuntimeException(\sprintf('le planning a perdu ou gagné des créneaux (%d → %d) : marquer n\'est pas détruire', $this->slotCountAtEntry, $slots));
        }

        $status = $this->dbalScalar(
            \sprintf('SELECT status AS behatval FROM schedule WHERE id=\'%s\'', $this->scheduleId),
            admin: true,
        );
        if ($status !== $this->statusAtEntry) {
            throw new RuntimeException(\sprintf('le statut du planning a changé (« %s » → « %s ») : marquer n\'est pas détruire', $this->statusAtEntry, $status));
        }
    }

    /**
     * Retire la contrainte jetable et restaure le drapeau de péremption dans son état d'entrée —
     * quoi qu'il arrive. Le retrait re-marque le planning ; on remet donc le drapeau en base, sur
     * la version ciblée, comme le fait la preuve PHPUnit.
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

        if ('' !== $this->scheduleId) {
            $this->setFlag($this->flagAtEntry);
        }
    }

    private function setFlag(bool $value): void
    {
        $this->dbalExec(
            \sprintf(
                'UPDATE schedule SET constraints_changed_since_generation=%s WHERE id=\'%s\'',
                $value ? 'true' : 'false',
                $this->scheduleId,
            ),
            admin: true,
        );
    }
}
