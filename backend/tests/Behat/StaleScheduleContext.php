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
 *
 * Second décor (péremption CIBLÉE du coach, P4-268) : compléter son modèle de coachs ne trompe
 * pas. Ajouter un coach neuf et le rattacher à une équipe ne dit PAS le planning périmé (rien de
 * placé n'a changé) ; renseigner qu'il est véhiculé — vrai levier de trajet — le dit périmé. On
 * agit ici sur le drapeau RESSOURCE (`resourcesChangedSinceGeneration`, distinct du drapeau
 * contrainte), exposé sur GET /schedules/{id}. Le coach et son rattachement jetables sont retirés
 * et le drapeau restauré en fin, comme pour la contrainte.
 */
final class StaleScheduleContext extends BaseContext
{
    private const string USER_EMAIL = 'dev-bccl@amateo.local';

    private const string TEAM_NAME = 'SM1';

    private const string COACH_FIRST_NAME = 'Nadia';

    private const string COACH_LAST_NAME = 'Déclarée tard';

    private string $token = '';

    private string $clubId = '';

    private string $teamId = '';

    private string $scheduleId = '';

    private string $planId = '';

    /** Valeur du drapeau de péremption à l'entrée (à restaurer). */
    private bool $flagAtEntry = false;

    private int $slotCountAtEntry = 0;

    private string $statusAtEntry = '';

    private string $constraintId = '';

    /** Scénario coach (péremption ciblée, P4-268) : état à nettoyer/restaurer. */
    private bool $coachScenario = false;

    /** Valeur du drapeau de péremption RESSOURCE à l'entrée (à restaurer). */
    private bool $resourceFlagAtEntry = false;

    private string $coachId = '';

    private string $teamCoachId = '';

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

        // Le plan de saison qui POINTE cette version : c'est lui qui sert sa péremption au cockpit
        // (P4-173). Le socle du bac à sable pointe une version (profil dev) — sa fenêtre est devant.
        $this->planId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id=\'%s\' LIMIT 1', $this->clubId, $this->scheduleId),
            admin: true,
        );

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

    #[Then('le cockpit le sait : le plan de saison sert lui-même sa péremption')]
    public function leCockpitSaitViaLePlan(): void
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->planId)) {
            throw new RuntimeException('le plan de saison ne pointe pas de version — le cockpit ne peut pas servir sa péremption (socle non validé ?)');
        }

        $seen = $this->apiGet(\sprintf('schedule_plans/%s', $this->planId), $this->token);
        $staleness = $seen['json']['staleness'] ?? null;
        if (!\is_array($staleness) || true !== ($staleness['constraintsChanged'] ?? null)) {
            throw new RuntimeException('le plan de saison aurait dû servir staleness.constraintsChanged=vrai au cockpit après l\'ajout d\'une contrainte');
        }
    }

    #[Given('le club de démonstration, connecté, dont le planning de saison en vigueur n\'est pas dit périmé')]
    public function leClubAvecUnPlanningNonPerime(): void
    {
        $this->coachScenario = true;
        $this->resolveInForceSeasonSchedule();

        // État « non périmé » du départ : on remet le drapeau RESSOURCE à faux (indépendant du
        // drapeau CONTRAINTE) pour que le scénario prouve la TRANSITION, quel que soit l'héritage.
        $this->resourceFlagAtEntry = 'oui' === $this->dbalScalar(
            \sprintf('SELECT CASE WHEN resources_changed_since_generation THEN \'oui\' ELSE \'non\' END AS behatval FROM schedule WHERE id=\'%s\'', $this->scheduleId),
            admin: true,
        );
        $this->setResourceFlag(false);

        $seen = $this->apiGet(\sprintf('schedules/%s', $this->scheduleId), $this->token);
        if (true === ($seen['json']['resourcesChangedSinceGeneration'] ?? null)) {
            throw new RuntimeException('le planning en vigueur est déjà dit périmé (ressource) — décor non tenu');
        }
    }

    #[When('j\'ajoute un coach au club et le rattache à une équipe')]
    public function jAjouteUnCoachEtLeRattache(): void
    {
        // Un coach NEUF (postPersist non écouté) + son rattachement à une équipe (team_coach non
        // écouté) : ni l'un ni l'autre ne doit périmer le planning en vigueur (P4-268).
        $created = $this->apiPost('coaches', ['firstName' => self::COACH_FIRST_NAME, 'lastName' => self::COACH_LAST_NAME], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création du coach refusée (HTTP %d)', $created['status']));
        }
        $coachId = $created['json']['id'] ?? null;
        if (!\is_string($coachId) || '' === $coachId) {
            throw new RuntimeException('le coach a été créé sans identifiant en retour');
        }
        $this->coachId = $coachId;

        $linked = $this->apiPost('team_coaches', [
            'teamId' => $this->teamId,
            'coachId' => $this->coachId,
            'role' => 'MAIN',
        ], $this->token);
        if (!\in_array($linked['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('rattachement du coach à l\'équipe refusé (HTTP %d)', $linked['status']));
        }
        $teamCoachId = $linked['json']['id'] ?? null;
        if (!\is_string($teamCoachId) || '' === $teamCoachId) {
            throw new RuntimeException('le rattachement coach↔équipe a été créé sans identifiant en retour');
        }
        $this->teamCoachId = $teamCoachId;
    }

    #[Then('le planning en vigueur n\'est pas dit périmé')]
    public function lePlanningNestPasPerime(): void
    {
        $seen = $this->apiGet(\sprintf('schedules/%s', $this->scheduleId), $this->token);
        if (false !== ($seen['json']['resourcesChangedSinceGeneration'] ?? null)) {
            throw new RuntimeException('ajouter un coach et le rattacher à une équipe n\'aurait PAS dû dire le planning périmé (P4-268)');
        }
    }

    #[When('je renseigne que ce coach est véhiculé')]
    public function jeRenseigneCoachVehicule(): void
    {
        // Renseigner le statut véhiculé change le barème de trajet que le solveur appliquerait :
        // vrai levier de placement → le planning se dit périmé (postUpdate hors champs cosmétiques).
        // Le PUT rejoue l'identité inchangée (firstName est NotBlank sur CoachInput) : seul
        // isVehicled bouge dans le changeset, donc SEUL lui déclenche le marquage.
        $updated = $this->apiPut(\sprintf('coaches/%s', $this->coachId), [
            'firstName' => self::COACH_FIRST_NAME,
            'lastName' => self::COACH_LAST_NAME,
            'isVehicled' => true,
        ], $this->token);
        if (!\in_array($updated['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('mise à jour du statut véhiculé refusée (HTTP %d)', $updated['status']));
        }
    }

    #[Then('le planning en vigueur est dit périmé')]
    public function lePlanningEstPerime(): void
    {
        $seen = $this->apiGet(\sprintf('schedules/%s', $this->scheduleId), $this->token);
        if (true !== ($seen['json']['resourcesChangedSinceGeneration'] ?? null)) {
            throw new RuntimeException('renseigner qu\'un coach est véhiculé aurait dû dire le planning en vigueur périmé');
        }
    }

    /**
     * Nettoie le scénario coach : retire le rattachement puis le coach jetable, et restaure le
     * drapeau RESSOURCE dans son état d'entrée — quoi qu'il arrive. Supprimer le coach re-marque le
     * planning (postRemove) : on remet donc le drapeau en base APRÈS suppression, sur la version
     * ciblée, exactement comme le fait la preuve PHPUnit.
     */
    #[AfterScenario]
    public function nettoyerCoach(): void
    {
        if (!$this->coachScenario || '' === $this->token) {
            return;
        }

        if ('' !== $this->teamCoachId) {
            $this->apiDelete(\sprintf('team_coaches/%s', $this->teamCoachId), $this->token);
        }
        if ('' !== $this->coachId) {
            $this->apiDelete(\sprintf('coaches/%s', $this->coachId), $this->token);
        }
        if ('' !== $this->scheduleId) {
            $this->setResourceFlag($this->resourceFlagAtEntry);
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

    /**
     * Résout le décor commun : jeton, club, équipe SM1 et version de saison EN VIGUEUR (celle que
     * le socle pointe, sinon la COMPLETED la plus fraîche). Miroir de la résolution du Given
     * contrainte, pour que le scénario coach cible EXACTEMENT le même planning.
     */
    private function resolveInForceSeasonSchedule(): void
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
    }

    private function setResourceFlag(bool $value): void
    {
        $this->dbalExec(
            \sprintf(
                'UPDATE schedule SET resources_changed_since_generation=%s WHERE id=\'%s\'',
                $value ? 'true' : 'false',
                $this->scheduleId,
            ),
            admin: true,
        );
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
