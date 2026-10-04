<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * P4-301, axe *auth & memberships* — un compte qui a perdu son dernier club est
 * prévenu par e-mail, puis supprimé 30 jours plus tard, sauf s'il regagne un accès.
 *
 * Joué contre la stack réelle : un gestionnaire crée son club (register + verify +
 * approbation dev), un membre actif y est inséré, puis désactivé par le gestionnaire
 * — ce qui le laisse sans aucun club. On éprouve alors le préavis (Mailpit), le saut
 * de 30 jours (stamp posé dans le passé par SQL — jamais d'horloge réelle), la purge
 * (cron `app:users:purge-orphaned`) et l'annulation par réactivation. Le contexte crée
 * ET nettoie ses propres comptes (le bac à sable n'est pas réinitialisé entre runs).
 */
final class OrphanAccountContext extends BaseContext
{
    private const string MANAGER_EMAIL = 'orphan-manager@amateo.fr';

    private const string MEMBER_EMAIL = 'orphan-member@amateo.fr';

    private const string PASSWORD = 'Password123!';

    private string $mailpitBase;

    private string $managerToken = '';

    private string $clubId = '';

    private string $membershipId = '';

    /** @var list<string> */
    private array $knownMemberMailIds = [];

    public function __construct()
    {
        parent::__construct();
        $this->mailpitBase = rtrim((string) (getenv('BEHAT_MAILPIT_BASE') ?: 'http://mailpit:8025'), '/');
    }

    #[Given('un gestionnaire de club et un membre actif de son club')]
    public function unGestionnaireEtUnMembre(): void
    {
        $this->purgeAccounts();

        $ara = 'ORPH' . time() . random_int(100, 999);
        $knownManager = $this->mailboxMessageIds(self::MANAGER_EMAIL);
        $registered = $this->publicPost('register', [
            'email' => self::MANAGER_EMAIL, 'password' => self::PASSWORD,
            'firstName' => 'Gest', 'lastName' => 'Ionnaire',
            'ara' => $ara, 'club_name' => 'Club ' . $ara, 'consent' => true,
        ]);
        if (202 !== $registered['status']) {
            throw new RuntimeException(\sprintf('inscription gestionnaire : %d (202 attendu)', $registered['status']));
        }
        $rawToken = $this->pullVerificationToken(self::MANAGER_EMAIL, $knownManager);
        $this->publicPost('register/verify', ['token' => $rawToken]);

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

        // Membre actif inséré en base (connexion admin — WITH CHECK RLS).
        $this->dbalExec(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name, email_verified_at)'
            . ' VALUES (gen_random_uuid(), 1, NOW(), NOW(), \'' . self::MEMBER_EMAIL . '\', \'x\', \'Mem\', \'Bre\', NOW())',
            true,
        );
        $this->dbalExec(
            'INSERT INTO club_user (id, version, created_at, updated_at, club_id, user_id, role, joined_at, is_active)'
            . ' VALUES (gen_random_uuid(), 1, NOW(), NOW(), \'' . $this->clubId . '\','
            . ' (SELECT id FROM app_user WHERE email = \'' . self::MEMBER_EMAIL . '\'), \'editor\', NOW(), true)',
            true,
        );

        // `AS behatval` : dbalScalar saute cette étiquette d'en-tête pour rendre la VALEUR,
        // pas le nom de colonne (même idiome que la garde bac-à-sable `SELECT … AS behatval`).
        $this->membershipId = $this->dbalScalar(
            'SELECT id AS behatval FROM club_user WHERE club_id = \'' . $this->clubId . '\''
            . ' AND user_id = (SELECT id FROM app_user WHERE email = \'' . self::MEMBER_EMAIL . '\')',
            true,
        );
        if ('' === $this->membershipId) {
            throw new RuntimeException('l\'adhésion du membre n\'a pas été créée');
        }

        $this->knownMemberMailIds = $this->mailboxMessageIds(self::MEMBER_EMAIL);
    }

    #[When('le gestionnaire désactive l\'accès du membre')]
    public function leGestionnaireDesactiveLeMembre(): void
    {
        $response = $this->apiPost('memberships/' . $this->membershipId . '/deactivate', [], $this->managerToken);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('désactivation : %d (200 attendu)', $response['status']));
        }
    }

    #[Then('le membre reçoit un e-mail l\'informant de la date de suppression de son compte')]
    public function leMembreRecoitLePreavis(): void
    {
        $body = $this->pullFreshMailBody(self::MEMBER_EMAIL, $this->knownMemberMailIds);
        if (!str_contains($body, 'supprimé le ')) {
            throw new RuntimeException('le préavis ne nomme pas la date de suppression du compte');
        }
    }

    #[When('la date d\'échéance de suppression est atteinte')]
    public function laDateEcheanceEstAtteinte(): void
    {
        // Saut de 30 jours SANS horloge réelle : on vieillit le préavis par SQL.
        $this->dbalExec(
            'UPDATE app_user SET orphan_notice_sent_at = NOW() - INTERVAL \'31 days\' WHERE email = \'' . self::MEMBER_EMAIL . '\'',
            true,
        );
    }

    #[When('le gestionnaire réactive l\'accès du membre avant l\'échéance')]
    public function leGestionnaireReactiveLeMembre(): void
    {
        $response = $this->apiPost('memberships/' . $this->membershipId . '/reactivate', [], $this->managerToken);
        if (200 !== $response['status']) {
            throw new RuntimeException(\sprintf('réactivation : %d (200 attendu)', $response['status']));
        }
    }

    #[When('la purge des comptes sans club s\'exécute')]
    public function laPurgeSExecute(): void
    {
        $this->runConsole(['php', 'bin/console', 'app:users:purge-orphaned']);
    }

    #[Then('le compte du membre a disparu')]
    public function leCompteDuMembreADisparu(): void
    {
        $email = $this->dbalScalar(
            'SELECT email AS behatval FROM app_user WHERE id = (SELECT user_id FROM club_user WHERE id = \'' . $this->membershipId . '\')',
            true,
        );
        if (!str_ends_with($email, '@anonymized.invalid')) {
            throw new RuntimeException(\sprintf('le compte aurait dû être anonymisé, e-mail obtenu « %s »', $email));
        }
    }

    #[Then('le compte du membre est conservé')]
    public function leCompteDuMembreEstConserve(): void
    {
        $email = $this->dbalScalar(
            'SELECT email AS behatval FROM app_user WHERE email = \'' . self::MEMBER_EMAIL . '\'',
            true,
        );
        if (self::MEMBER_EMAIL !== $email) {
            throw new RuntimeException('le compte réactivé aurait dû être conservé, il a été anonymisé');
        }
    }

    #[AfterScenario]
    public function cleanup(): void
    {
        $this->purgeAccounts();
    }

    private function purgeAccounts(): void
    {
        foreach ([self::MANAGER_EMAIL, self::MEMBER_EMAIL] as $email) {
            $selector = '(SELECT id FROM app_user WHERE email = \'' . $email . '\')';
            $this->dbalExec('DELETE FROM email_verification_token WHERE user_id IN ' . $selector, true);
            $this->dbalExec('DELETE FROM club_creation_request WHERE user_id IN ' . $selector, true);
            $this->dbalExec('DELETE FROM club_user WHERE user_id IN ' . $selector, true);
        }
        // Le club créé par le gestionnaire (identité FFBB) : supprimé avec son workspace.
        if ('' !== $this->clubId) {
            $this->dbalExec('DELETE FROM club_user WHERE club_id = \'' . $this->clubId . '\'', true);
        }
        $this->dbalExec('DELETE FROM app_user WHERE email IN (\'' . self::MANAGER_EMAIL . '\', \'' . self::MEMBER_EMAIL . '\')', true);
        $this->clubId = '';
    }

    /**
     * Le corps texte du mail FRAÎCHEMENT émis à `$email` (ignore les messages déjà présents
     * avant le geste). Boucle d'attente bornée : la file d'envoi met un instant à arriver.
     *
     * @param list<string> $ignoreIds
     */
    private function pullFreshMailBody(string $email, array $ignoreIds): string
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
                    if (\is_string($text) && '' !== $text) {
                        return $text;
                    }
                }
            }
            sleep(1);
        }

        throw new RuntimeException('aucun e-mail frais trouvé pour ' . $email . ' — la file d\'envoi est-elle consommée ?');
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

    /** @param list<string> $ignoreIds */
    private function pullVerificationToken(string $email, array $ignoreIds): string
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
                    if (\is_string($text) && 1 === preg_match('#verify-email/([a-f0-9]{64})#', $text, $m)) {
                        return $m[1];
                    }
                }
            }
            sleep(1);
        }

        throw new RuntimeException('aucun e-mail de vérification frais trouvé pour ' . $email);
    }
}
