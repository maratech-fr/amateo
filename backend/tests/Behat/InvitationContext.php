<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * P4-299, axes *auth & memberships* + *tenant isolation* — un gestionnaire invite une
 * adresse e-mail, l'invité entre membre ACTIF du club par un lien à usage unique.
 *
 * Joué contre la stack réelle : le gestionnaire crée son club (register + verify +
 * approbation dev), invite une adresse (API), l'invité ouvre le lien tiré de Mailpit et
 * crée son compte (ou l'accepte connecté s'il en a déjà un). Le contexte crée ET nettoie
 * ses propres comptes/club/invitations (le bac à sable n'est pas réinitialisé entre runs).
 */
final class InvitationContext extends BaseContext
{
    private const string MANAGER_EMAIL = 'invitation-manager@amateo.fr';

    private const string NEWCOMER_EMAIL = 'invitation-newcomer@amateo.fr';

    private const string EXISTING_EMAIL = 'invitation-existing@amateo.fr';

    private const string PASSWORD = 'Password123!';

    private string $mailpitBase;

    private string $managerToken = '';

    private string $clubId = '';

    private string $invitationId = '';

    private string $rawToken = '';

    public function __construct()
    {
        parent::__construct();
        $this->mailpitBase = rtrim((string) (getenv('BEHAT_MAILPIT_BASE') ?: 'http://mailpit:8025'), '/');
    }

    #[Given('un gestionnaire de club')]
    public function unGestionnaireDeClub(): void
    {
        $this->seedManager();
    }

    #[Given('un gestionnaire de club et une personne qui a déjà un compte')]
    public function unGestionnaireEtUnePersonneAvecCompte(): void
    {
        $this->seedManager();
        // Un compte VÉRIFIÉ existant, membre d'aucun club de ce gestionnaire.
        $this->dbalExec(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name, email_verified_at)'
            . ' VALUES (gen_random_uuid(), 1, NOW(), NOW(), \'' . self::EXISTING_EMAIL . '\', \'x\', \'Déjà\', \'Inscrit\', NOW())',
            true,
        );
    }

    #[Given('un gestionnaire de club qui a invité une adresse')]
    public function unGestionnaireQuiAInvite(): void
    {
        $this->seedManager();
        $this->inviteAddress(self::NEWCOMER_EMAIL, 'member');
    }

    #[When('le gestionnaire invite l\'adresse e-mail d\'un nouveau venu')]
    public function leGestionnaireInviteUnNouveauVenu(): void
    {
        $this->inviteAddress(self::NEWCOMER_EMAIL, 'member');
    }

    #[When('le gestionnaire invite cette personne comme gestionnaire')]
    public function leGestionnaireInvitePersonneCommeGestionnaire(): void
    {
        $this->inviteAddress(self::EXISTING_EMAIL, 'admin');
    }

    #[When('le nouveau venu ouvre son lien et crée son compte')]
    public function leNouveauVenuCreeSonCompte(): void
    {
        $response = $this->publicPost('invitations/public/' . $this->rawToken . '/accept', [
            'firstName' => 'Nouveau', 'lastName' => 'Venu', 'password' => self::PASSWORD, 'consent' => true,
        ]);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('acceptation sans compte : %d (200 attendu)', $response['status']));
        }
    }

    #[When('la personne connectée accepte son invitation')]
    public function laPersonneConnecteeAccepte(): void
    {
        $token = $this->mintToken(self::EXISTING_EMAIL);
        $response = $this->apiPost('invitations/' . $this->rawToken . '/accept', [], $token);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('acceptation connectée : %d (200 attendu)', $response['status']));
        }
    }

    #[When('le gestionnaire révoque l\'invitation')]
    public function leGestionnaireRevoque(): void
    {
        $response = $this->apiDelete('invitations/' . $this->invitationId, $this->managerToken);
        if (204 !== $response['status']) {
            throw new RuntimeException(\sprintf('révocation : %d (204 attendu)', $response['status']));
        }
    }

    #[Then('le nouveau venu est membre actif du club')]
    public function leNouveauVenuEstMembreActif(): void
    {
        $this->assertActiveMembership(self::NEWCOMER_EMAIL, 'member');
    }

    #[Then('la personne est gestionnaire actif du club')]
    public function laPersonneEstGestionnaireActif(): void
    {
        $this->assertActiveMembership(self::EXISTING_EMAIL, 'admin');
    }

    #[Then('l\'invitation ne figure plus dans la liste du gestionnaire')]
    public function invitationAbsenteDeLaListe(): void
    {
        $response = $this->apiGet('invitations', $this->managerToken);
        $invitations = $response['json']['invitations'] ?? [];
        if (!\is_array($invitations)) {
            throw new RuntimeException('réponse de liste inattendue');
        }
        foreach ($invitations as $invitation) {
            if (\is_array($invitation) && (self::NEWCOMER_EMAIL === ($invitation['email'] ?? null))) {
                throw new RuntimeException('l\'invitation acceptée aurait dû disparaître de la liste (jeton consommé)');
            }
        }
    }

    #[Then('le lien d\'invitation est mort')]
    public function leLienEstMort(): void
    {
        $response = $this->publicGet('invitations/public/' . $this->rawToken);
        if (404 !== $response['status']) {
            throw new RuntimeException(\sprintf('un lien révoqué doit rendre 404, obtenu %d', $response['status']));
        }
    }

    #[AfterScenario]
    public function cleanup(): void
    {
        $this->purgeAll();
    }

    /** Crée un gestionnaire et son club réel (register + verify + approbation dev). */
    private function seedManager(): void
    {
        $this->purgeAll();

        $ara = 'INVI' . time() . random_int(100, 999);
        $known = $this->mailboxMessageIds(self::MANAGER_EMAIL);
        $registered = $this->publicPost('register', [
            'email' => self::MANAGER_EMAIL, 'password' => self::PASSWORD,
            'firstName' => 'Gest', 'lastName' => 'Ionnaire',
            'ara' => $ara, 'club_name' => 'Club ' . $ara, 'consent' => true,
        ]);
        if (202 !== $registered['status']) {
            throw new RuntimeException(\sprintf('inscription gestionnaire : %d (202 attendu)', $registered['status']));
        }
        $rawVerify = $this->pullTokenFromMail(self::MANAGER_EMAIL, $known, '#verify-email/([a-f0-9]{64})#');
        $this->publicPost('register/verify', ['token' => $rawVerify]);

        $this->managerToken = $this->mintToken(self::MANAGER_EMAIL);
        $approved = $this->apiPost('dev/approve-club-request', [], $this->managerToken);
        if (200 !== $approved['status']) {
            throw new RuntimeException(\sprintf('approbation du club : %d (200 attendu)', $approved['status']));
        }

        $me = $this->apiGet('me', $this->managerToken);
        $club = $me['json']['club'] ?? null;
        $this->clubId = \is_array($club) && \is_string($club['id'] ?? null) ? $club['id'] : '';
        if ('' === $this->clubId) {
            throw new RuntimeException('le club du gestionnaire n\'a pas été matérialisé');
        }
    }

    /** Émet une invitation et tire le jeton brut du mail (jamais rendu par l'API). */
    private function inviteAddress(string $email, string $role): void
    {
        $known = $this->mailboxMessageIds($email);
        $response = $this->apiPost('invitations', ['email' => $email, 'role' => $role], $this->managerToken);
        if (201 !== $response['status']) {
            throw new RuntimeException(\sprintf('émission de l\'invitation : %d (201 attendu)', $response['status']));
        }
        $this->invitationId = \is_string($response['json']['id'] ?? null) ? $response['json']['id'] : '';
        if ('' === $this->invitationId) {
            throw new RuntimeException('l\'invitation émise n\'a pas d\'identifiant');
        }
        $this->rawToken = $this->pullTokenFromMail($email, $known, '#/invitation/([a-f0-9]{64})#');
    }

    private function assertActiveMembership(string $email, string $expectedRole): void
    {
        $row = $this->dbalScalar(
            'SELECT (is_active::text || \':\' || role) AS behatval FROM club_user'
            . ' WHERE club_id = \'' . $this->clubId . '\''
            . ' AND user_id = (SELECT id FROM app_user WHERE email = \'' . $email . '\')',
            true,
        );
        if ('true:' . $expectedRole !== $row) {
            throw new RuntimeException(\sprintf('adhésion attendue active/%s, obtenue « %s »', $expectedRole, '' === $row ? '<aucune>' : $row));
        }
    }

    /**
     * Tire un jeton brut d'un e-mail FRAÎCHEMENT émis (ignore les messages antérieurs).
     * Boucle d'attente bornée : la file d'envoi met un instant à arriver.
     *
     * @param list<string> $ignoreIds
     */
    private function pullTokenFromMail(string $email, array $ignoreIds, string $pattern): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $search = $this->httpGet($this->mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $email));
            $messages = $search['json']['messages'] ?? [];
            if (\is_array($messages)) {
                foreach ($messages as $message) {
                    if (!\is_array($message) || !\is_string($message['ID'] ?? null) || \in_array($message['ID'], $ignoreIds, true)) {
                        continue;
                    }
                    $body = $this->httpGet($this->mailpitBase . '/api/v1/message/' . $message['ID']);
                    $text = $body['json']['Text'] ?? '';
                    if (\is_string($text) && 1 === preg_match($pattern, $text, $m)) {
                        return $m[1];
                    }
                }
            }
            sleep(1);
        }

        throw new RuntimeException('aucun e-mail frais trouvé pour ' . $email);
    }

    /** @return list<string> */
    private function mailboxMessageIds(string $email): array
    {
        $search = $this->httpGet($this->mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $email));
        $messages = $search['json']['messages'] ?? [];
        $ids = [];
        if (\is_array($messages)) {
            foreach ($messages as $message) {
                if (\is_array($message) && \is_string($message['ID'] ?? null)) {
                    $ids[] = $message['ID'];
                }
            }
        }

        return $ids;
    }

    private function purgeAll(): void
    {
        if ('' !== $this->clubId) {
            $this->dbalExec('DELETE FROM club_invitation WHERE club_id = \'' . $this->clubId . '\'', true);
            $this->dbalExec('DELETE FROM club_user WHERE club_id = \'' . $this->clubId . '\'', true);
        }
        foreach ([self::MANAGER_EMAIL, self::NEWCOMER_EMAIL, self::EXISTING_EMAIL] as $email) {
            $selector = '(SELECT id FROM app_user WHERE email = \'' . $email . '\')';
            $this->dbalExec('DELETE FROM email_verification_token WHERE user_id IN ' . $selector, true);
            $this->dbalExec('DELETE FROM club_creation_request WHERE user_id IN ' . $selector, true);
            $this->dbalExec('DELETE FROM club_user WHERE user_id IN ' . $selector, true);
        }
        $this->dbalExec(
            'DELETE FROM app_user WHERE email IN (\'' . self::MANAGER_EMAIL . '\', \'' . self::NEWCOMER_EMAIL . '\', \'' . self::EXISTING_EMAIL . '\')',
            true,
        );
        $this->clubId = '';
        $this->invitationId = '';
        $this->rawToken = '';
    }
}
