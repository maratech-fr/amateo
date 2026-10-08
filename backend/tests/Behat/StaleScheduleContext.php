<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Le planning se dit à régénérer quand sa structure change (axe planning lifecycle), sur la stack
 * qui tourne.
 *
 * Le signal n'est plus un drapeau en base posé par un listener (P4-266) : il se DÉRIVE de
 * l'empreinte de structure servie PAR PLAN (`GET /api/schedule_plans/{id}/structure-hash` →
 * `currentStructureHash`), que l'écran compare au `snapshotHash` figé de la version pointée. On
 * éprouve ici le cœur de la promesse en HTTP : rattacher un coach à une équipe (ce que le solveur
 * placerait change) FAIT DIVERGER l'empreinte du plan de saison, et détacher le coach la RAMÈNE à
 * son état initial — un aller-retour au résultat nul ne signale rien.
 *
 * Décor non destructif : on crée un coach JETABLE (non rattaché), on relève l'empreinte de départ,
 * on rattache (diverge), on détache (réaligne). Le coach jetable est retiré en fin de scénario,
 * quoi qu'il arrive.
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

    private string $planId = '';

    /** Empreinte de structure du plan de saison relevée à l'entrée (à retrouver après l'aller-retour). */
    private string $hashAtEntry = '';

    private string $coachId = '';

    private string $teamCoachId = '';

    #[Given('le club de démonstration connecté, avec un coach jetable non rattaché')]
    public function leClubAvecUnCoachJetable(): void
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

        // Le plan de saison qui POINTE une version : c'est lui qui sert son empreinte de structure
        // au cockpit (P4-266). Le socle du bac à sable pointe une version (profil dev).
        $this->planId = $this->dbalScalar(
            \sprintf('SELECT id AS behatval FROM schedule_plan WHERE club_id=\'%s\' AND type=\'SEASON\' AND chosen_schedule_id IS NOT NULL LIMIT 1', $this->clubId),
            admin: true,
        );
        if (1 !== preg_match('/^[0-9a-f-]{36}$/i', $this->planId)) {
            throw new RuntimeException('le plan de saison ne pointe pas de version — pas d\'empreinte à servir (socle non validé ?)');
        }

        // Un coach NEUF, non rattaché : le créer n'est PAS le geste mesuré. On relève l'empreinte
        // APRÈS sa création, pour que SEUL le rattachement sépare l'état initial de l'état divergé.
        $created = $this->apiPost('coaches', ['firstName' => self::COACH_FIRST_NAME, 'lastName' => self::COACH_LAST_NAME], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création du coach jetable refusée (HTTP %d)', $created['status']));
        }
        $coachId = $created['json']['id'] ?? null;
        if (!\is_string($coachId) || '' === $coachId) {
            throw new RuntimeException('le coach a été créé sans identifiant en retour');
        }
        $this->coachId = $coachId;

        $this->hashAtEntry = $this->structureHash();
        if ('' === $this->hashAtEntry) {
            throw new RuntimeException('le plan de saison ne sert aucune empreinte de structure — décor non tenu');
        }
    }

    #[When('je rattache ce coach à une équipe')]
    public function jeRattacheLeCoach(): void
    {
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

    #[Then('l\'empreinte de structure du plan de saison a divergé')]
    public function lEmpreinteADiverge(): void
    {
        $now = $this->structureHash();
        if ($now === $this->hashAtEntry) {
            throw new RuntimeException('rattacher un coach à une équipe aurait dû faire diverger l\'empreinte de structure du plan de saison');
        }
    }

    #[When('je détache ce coach de l\'équipe')]
    public function jeDetacheLeCoach(): void
    {
        if ('' === $this->teamCoachId) {
            throw new RuntimeException('aucun rattachement à détacher — le scénario n\'a pas rattaché le coach');
        }
        $this->apiDelete(\sprintf('team_coaches/%s', $this->teamCoachId), $this->token);
        $this->teamCoachId = '';
    }

    #[Then('l\'empreinte de structure du plan de saison est revenue à son état initial')]
    public function lEmpreinteEstRevenue(): void
    {
        $now = $this->structureHash();
        if ($now !== $this->hashAtEntry) {
            throw new RuntimeException('détacher le coach aurait dû ramener l\'empreinte de structure à son état initial (aller-retour au résultat nul)');
        }
    }

    /**
     * Nettoie le scénario : retire le rattachement s'il subsiste, puis le coach jetable — quoi
     * qu'il arrive. Aucun drapeau à restaurer : la péremption est dérivée, rien n'est écrit en base.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token) {
            return;
        }

        if ('' !== $this->teamCoachId) {
            $this->apiDelete(\sprintf('team_coaches/%s', $this->teamCoachId), $this->token);
        }
        if ('' !== $this->coachId) {
            $this->apiDelete(\sprintf('coaches/%s', $this->coachId), $this->token);
        }
    }

    /** L'empreinte de structure COURANTE du plan de saison, servie par la route structure-hash. */
    private function structureHash(): string
    {
        $seen = $this->apiGet(\sprintf('schedule_plans/%s/structure-hash', $this->planId), $this->token);
        $hash = $seen['json']['currentStructureHash'] ?? null;

        return \is_string($hash) ? $hash : '';
    }
}
