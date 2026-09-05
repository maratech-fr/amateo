<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Isolation multi-tenant de bout en bout, sur la stack qui tourne (RLS + filtre Doctrine +
 * providers scopés — backend/docs/TENANT.md).
 *
 * Prouve qu'un club ne voit RIEN d'un autre : il ne liste pas ses équipes, ne peut en lire une par
 * son id (404 « introuvable » — jamais 403, l'existence n'est pas révélée), ni la supprimer ; et
 * qu'un membre sans rôle de gestion ne modifie rien (403, SEC-07).
 *
 * Le second club et le membre simple sont SEMÉS en base (club + saison + gestionnaire pour l'un,
 * adhésion « membre » pour l'autre) plutôt que par le rail d'inscription : deux vrais tenants sous
 * RLS suffisent à la promesse d'isolement, et un décor semé se RETIRE proprement (ce que le rail
 * d'inscription, qui matérialise un club entier, ne permet pas). Tout est retiré en fin de scénario.
 */
final class TenantIsolationContext extends BaseContext
{
    private const string USER_EMAIL = 'mara.mb@bccl.fr';

    private string $tokenA = '';

    private string $clubIdA = '';

    private string $teamA = '';

    // Second club (semé).
    private string $tokenB = '';

    private string $clubIdB = '';

    private string $seasonIdB = '';

    private string $userIdB = '';

    // Membre simple du club de démonstration (semé).
    private string $memberUserId = '';

    private string $memberToken = '';

    private int $listStatus = 0;

    private bool $teamVisibleToB = true;

    private int $readStatus = 0;

    private int $deleteStatus = 0;

    private int $memberWriteStatus = 0;

    #[Given('le club de démonstration et un autre club, chacun connecté')]
    public function lesDeuxClubsConnectes(): void
    {
        $this->tokenA = $this->mintToken(self::USER_EMAIL);
        $me = $this->apiGet('me', $this->tokenA);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubIdA = $clubId;

        $this->seedSecondClub();
    }

    #[Given('une équipe créée par le club de démonstration')]
    public function uneEquipeCreeeParLeClubDeDemonstration(): void
    {
        $categories = $this->apiGet('sport_categories', $this->tokenA);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive pour créer une équipe');
        }

        $team = $this->apiPost('teams', ['name' => 'Équipe isolée (fonctionnel)', 'sportCategoryId' => $category, 'priorityTierId' => 1, 'sessionsPerWeek' => 1], $this->tokenA);
        if (!\in_array($team['status'], [200, 201], true)) {
            throw new RuntimeException(\sprintf('création de l\'équipe refusée (HTTP %d)', $team['status']));
        }
        $id = $team['json']['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new RuntimeException('création de l\'équipe sans identifiant en retour');
        }
        $this->teamA = $id;

        // Ce que l'autre club voit / peut faire — mesuré une fois, asserté ensuite.
        $list = $this->apiGet('teams?itemsPerPage=200', $this->tokenB);
        $this->listStatus = $list['status'];
        $this->teamVisibleToB = false;
        foreach ($this->members($list['json']) as $t) {
            if (($t['id'] ?? null) === $this->teamA) {
                $this->teamVisibleToB = true;

                break;
            }
        }

        $this->readStatus = $this->apiGet(\sprintf('teams/%s', $this->teamA), $this->tokenB)['status'];
        $this->deleteStatus = $this->apiDelete(\sprintf('teams/%s', $this->teamA), $this->tokenB)['status'];
    }

    #[Then('l\'autre club ne voit pas cette équipe dans sa liste')]
    public function lautreClubNeVoitPasLequipe(): void
    {
        if (200 !== $this->listStatus) {
            throw new RuntimeException(\sprintf('la liste des équipes de l\'autre club a répondu %d (200 attendu)', $this->listStatus));
        }
        if ($this->teamVisibleToB) {
            throw new RuntimeException('l\'autre club voit une équipe qui ne lui appartient pas — fuite d\'isolement');
        }
    }

    #[Then('l\'autre club ne peut pas lire cette équipe : elle est introuvable')]
    public function lautreClubNePeutPasLireLequipe(): void
    {
        if (404 !== $this->readStatus) {
            throw new RuntimeException(\sprintf('lire l\'équipe d\'un autre club aurait dû être introuvable (404), obtenu %d (jamais 403 : l\'existence ne se révèle pas)', $this->readStatus));
        }
    }

    #[Then('l\'autre club ne peut pas supprimer cette équipe : elle est introuvable')]
    public function lautreClubNePeutPasSupprimerLequipe(): void
    {
        if (404 !== $this->deleteStatus) {
            throw new RuntimeException(\sprintf('supprimer l\'équipe d\'un autre club aurait dû être introuvable (404), obtenu %d', $this->deleteStatus));
        }
    }

    #[Given('le club de démonstration et l\'un de ses membres sans rôle de gestion')]
    public function leClubEtUnMembreSimple(): void
    {
        $this->tokenA = $this->mintToken(self::USER_EMAIL);
        $me = $this->apiGet('me', $this->tokenA);
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) ? ($club['id'] ?? null) : null;
        if (!\is_string($clubId) || '' === $clubId) {
            throw new RuntimeException('aucun club pour le gestionnaire de démonstration — la base est-elle seedée ?');
        }
        $this->clubIdA = $clubId;

        $this->memberUserId = $this->uuid();
        $email = 'membre-simple-' . substr(md5(uniqid('', true)), 0, 8) . '@fonctionnel.test';
        $this->insertUser($this->memberUserId, $email);
        $this->insertMembership($this->memberUserId, $this->clubIdA, 'member');
        $this->memberToken = $this->mintToken($email);
    }

    #[When('ce membre tente de créer une équipe')]
    public function ceMembreTenteDeCreerUneEquipe(): void
    {
        // Corps VALIDE (catégorie réelle) pour que la requête franchisse la validation du DTO et
        // atteigne le gate de rôle : c'est le refus MANAGEMENT (403) qu'on veut prouver, pas un 422
        // de forme. Un membre lit tout, donc il peut lire les catégories.
        $categories = $this->apiGet('sport_categories', $this->memberToken);
        $category = $this->members($categories['json'])[0]['id'] ?? null;
        if (!\is_string($category) || '' === $category) {
            throw new RuntimeException('aucune catégorie sportive lisible par le membre');
        }

        $result = $this->apiPost('teams', ['name' => 'Écriture interdite', 'sportCategoryId' => $category, 'priorityTierId' => 1, 'sessionsPerWeek' => 1], $this->memberToken);
        $this->memberWriteStatus = $result['status'];
    }

    #[Then('la modification lui est refusée')]
    public function laModificationLuiEstRefusee(): void
    {
        if (403 !== $this->memberWriteStatus) {
            throw new RuntimeException(\sprintf('un membre sans rôle de gestion aurait dû se voir refuser l\'écriture (403), obtenu %d', $this->memberWriteStatus));
        }
    }

    /**
     * Retire tout ce qui a été semé/créé — quoi qu'il arrive (succès, échec, exception) :
     * l'équipe du club A, le membre simple (adhésion puis compte), le second club (adhésion,
     * saison, club, puis compte du gestionnaire).
     */
    #[AfterScenario]
    public function nettoyer(): void
    {
        if ('' !== $this->teamA && '' !== $this->tokenA) {
            $this->apiDelete(\sprintf('teams/%s', $this->teamA), $this->tokenA);
        }

        if ('' !== $this->memberUserId) {
            $this->dbalExec(\sprintf('DELETE FROM club_user WHERE user_id=\'%s\'', $this->memberUserId), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM app_user WHERE id=\'%s\'', $this->memberUserId), admin: true);
        }

        if ('' !== $this->clubIdB) {
            $this->dbalExec(\sprintf('DELETE FROM club_user WHERE club_id=\'%s\'', $this->clubIdB), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM season WHERE club_id=\'%s\'', $this->clubIdB), admin: true);
            $this->dbalExec(\sprintf('DELETE FROM club WHERE id=\'%s\'', $this->clubIdB), admin: true);
        }
        if ('' !== $this->userIdB) {
            $this->dbalExec(\sprintf('DELETE FROM app_user WHERE id=\'%s\'', $this->userIdB), admin: true);
        }
    }

    private function seedSecondClub(): void
    {
        $this->clubIdB = $this->uuid();
        $this->seasonIdB = $this->uuid();
        $this->userIdB = $this->uuid();
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $slug = 'autre-club-fonctionnel-' . $suffix;
        $email = 'autre-club-' . $suffix . '@fonctionnel.test';

        $this->dbalExec(
            \sprintf(
                'INSERT INTO club (id, created_at, updated_at, name, slug, generation_count_season, timezone, locale, onboarding_completed)'
                . ' VALUES (\'%s\', now(), now(), \'Autre club (fonctionnel)\', \'%s\', 0, \'Europe/Paris\', \'fr\', true)',
                $this->clubIdB,
                $slug,
            ),
            admin: true,
        );
        // Saison courante active : le résolveur de saison la sélectionne pour les lectures de B.
        $seasonStart = date('Y-m-d', (int) strtotime('-40 days'));
        $seasonEnd = date('Y-m-d', (int) strtotime('+300 days'));
        $this->dbalExec(
            \sprintf(
                'INSERT INTO season (id, created_at, updated_at, club_id, name, start_date, end_date, status, transition_data)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'Saison fonctionnelle\', \'%s\', \'%s\', \'active\', \'{}\')',
                $this->seasonIdB,
                $this->clubIdB,
                $seasonStart,
                $seasonEnd,
            ),
            admin: true,
        );
        $this->insertUser($this->userIdB, $email);
        $this->insertMembership($this->userIdB, $this->clubIdB, 'admin');
        $this->tokenB = $this->mintToken($email);
    }

    private function insertUser(string $userId, string $email): void
    {
        $this->dbalExec(
            \sprintf(
                'INSERT INTO app_user (id, created_at, updated_at, email, password_hash, first_name, last_name)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'$2y$13$abcdefghijklmnopqrstuv\', \'Fonc\', \'Tionnel\')',
                $userId,
                $email,
            ),
            admin: true,
        );
    }

    private function insertMembership(string $userId, string $clubId, string $role): void
    {
        $this->dbalExec(
            \sprintf(
                'INSERT INTO club_user (id, created_at, updated_at, club_id, user_id, role, joined_at, is_active)'
                . ' VALUES (\'%s\', now(), now(), \'%s\', \'%s\', \'%s\', now(), true)',
                $this->uuid(),
                $clubId,
                $userId,
                $role,
            ),
            admin: true,
        );
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
