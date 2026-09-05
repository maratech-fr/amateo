<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Le périmètre engagé est protégé (axe structurant §7.1), sur la stack qui tourne.
 *
 * Une équipe est « engagée » dès qu'elle porte au moins un match (l'engagement est DÉRIVÉ de
 * l'existence d'un match, jamais d'un statut) : la fédération connaît alors ses rencontres, donc
 *   - la supprimer est refusé (409) — sa cascade emporterait des matchs réels ;
 *   - changer son niveau est refusé (409) — c'est sous ce niveau qu'elle est inscrite ;
 * tandis qu'une équipe sans aucun match reste librement supprimable (204).
 *
 * Autosuffisant : une équipe JETABLE créée par l'API, engagée en semant UN match en base (le seul
 * geste sans écran dédié), tout retiré en fin de scénario — le match d'abord (sinon l'équipe reste
 * engagée), puis l'équipe —, quoi qu'il arrive.
 */
final class EngagedTeamContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private string $token = '';

    private string $clubId = '';

    private string $categoryId = '';

    private string $teamId = '';

    private string $seasonId = '';

    private int $lastStatus = 0;

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

        $categories = $this->apiGet('sport_categories', $this->token);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour bâtir une équipe jetable');
        }
        $this->categoryId = $category;
    }

    #[Given('une équipe jetable engagée en compétition')]
    public function uneEquipeEngagee(): void
    {
        $this->createTeam('Périmètre Jetable Engagée');
        $this->engageTeam();
    }

    #[Given('une équipe jetable qui ne joue aucun match')]
    public function uneEquipeNonEngagee(): void
    {
        $this->createTeam('Périmètre Jetable Libre');
    }

    #[When('je tente de supprimer cette équipe')]
    public function jeTenteDeSupprimer(): void
    {
        $this->lastStatus = $this->apiDelete(\sprintf('teams/%s', $this->teamId), $this->token)['status'];
    }

    #[When('je tente de changer le niveau de cette équipe')]
    public function jeTenteDeChangerLeNiveau(): void
    {
        $result = $this->apiPut(\sprintf('teams/%s', $this->teamId), [
            'name' => 'Périmètre Jetable Engagée',
            'sportCategoryId' => $this->categoryId,
            'priorityTierId' => 1,
            'level' => 'REGIONAL',
        ], $this->token, ['Content-Type' => 'application/ld+json']);
        $this->lastStatus = $result['status'];
    }

    #[Then('la suppression est refusée parce que l\'équipe joue')]
    public function laSuppressionEstRefusee(): void
    {
        if (409 !== $this->lastStatus) {
            throw new RuntimeException(\sprintf('la suppression d\'une équipe engagée aurait dû être refusée (409), obtenu %d', $this->lastStatus));
        }
        $this->assertTeamStillExists();
    }

    #[Then('le changement de niveau est refusé parce que l\'équipe joue')]
    public function leNiveauEstRefuse(): void
    {
        if (409 !== $this->lastStatus) {
            throw new RuntimeException(\sprintf('le changement de niveau d\'une équipe engagée aurait dû être refusé (409), obtenu %d', $this->lastStatus));
        }
        $this->assertTeamStillExists();
    }

    #[Then('la suppression est acceptée')]
    public function laSuppressionEstAcceptee(): void
    {
        if (204 !== $this->lastStatus) {
            throw new RuntimeException(\sprintf('la suppression d\'une équipe libre aurait dû être acceptée (204), obtenu %d', $this->lastStatus));
        }
        // Le nettoyage n'aura plus rien à retirer : l'équipe est déjà partie.
        $this->teamId = '';
    }

    /**
     * Retire le match semé (l'équipe redevient supprimable) puis l'équipe jetable — quoi qu'il
     * arrive. DELETE tolérant si l'équipe a déjà été supprimée par le scénario.
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' === $this->token || '' === $this->teamId) {
            return;
        }

        $this->dbalExec(\sprintf('DELETE FROM fixture WHERE team_id=\'%s\'', $this->teamId), admin: true);
        $this->apiDelete(\sprintf('teams/%s', $this->teamId), $this->token);
    }

    private function createTeam(string $name): void
    {
        $created = $this->apiPost('teams', [
            'name' => $name,
            'sportCategoryId' => $this->categoryId,
            'priorityTierId' => 1,
            'sessionsPerWeek' => 1,
        ], $this->token);
        if (!\in_array($created['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de l\'équipe jetable refusée (HTTP %d)', $created['status']));
        }
        $id = $created['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('création de l\'équipe jetable sans identifiant en retour');
        }
        $this->teamId = $id;

        $this->seasonId = $this->dbalScalar(
            \sprintf('SELECT season_id AS behatval FROM team WHERE id=\'%s\'', $this->teamId),
            admin: true,
        );
        if ('' === $this->seasonId) {
            throw new RuntimeException('impossible de résoudre la saison de l\'équipe jetable');
        }
    }

    private function engageTeam(): void
    {
        $this->dbalExec(
            \sprintf(
                'INSERT INTO fixture (id, version, created_at, updated_at, club_id, season_id, team_id, match_date, home_away, opponent_label, status)'
                . ' VALUES (\'%s\', 1, now(), now(), \'%s\', \'%s\', \'%s\', \'2026-11-15\', \'HOME\', \'Adversaire de test\', \'UNPLACED\')',
                $this->uuid(),
                $this->clubId,
                $this->seasonId,
                $this->teamId,
            ),
            admin: true,
        );
    }

    private function assertTeamStillExists(): void
    {
        $count = $this->dbalScalar(
            \sprintf('SELECT COUNT(*) AS behatval FROM team WHERE id=\'%s\'', $this->teamId),
            admin: true,
        );
        if ('1' !== $count) {
            throw new RuntimeException('l\'équipe engagée aurait dû survivre au refus');
        }
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

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
