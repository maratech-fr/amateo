<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Démos, axe *auth & memberships* (P4-294) — la promesse de bout en bout sur la stack qui
 * tourne : un club démo CONSERVÉ est repris par l'approbation du contact officiel, jamais
 * recréé à neuf.
 *
 * On matérialise un vrai club (register + verify + approbation du propriétaire), on le marque
 * démo CONSERVÉ comme le fait la console superadmin (`is_demo`, `demo_retained_until` à J+14,
 * animateur DÉTACHÉ) — par SQL admin, façon {@see DemoWindowContext} qui pose la fenêtre de la
 * même manière. Puis le contact officiel s'inscrit RÉELLEMENT avec le même code et approuve
 * (relais dev) : son /api/me doit pointer le MÊME club (jamais un 2e), redevenu un vrai club
 * (`is_demo` false) dont il est gestionnaire. Le contexte crée ET nettoie ses propres comptes
 * et le club (le bac à sable dev n'est pas ré-initialisé entre deux runs), donc il rejoue.
 */
final class DemoRetainedContext extends BaseContext
{
    private const string PASSWORD = 'Password123!';

    private string $mailpitBase;

    private string $captureAra = '';

    private string $retainedClubId = '';

    private string $ownerEmail = '';

    private string $officialEmail = '';

    private ?string $reclaimedClubId = null;

    public function __construct()
    {
        parent::__construct();
        $this->mailpitBase = rtrim((string) (getenv('BEHAT_MAILPIT_BASE') ?: 'http://mailpit:8025'), '/');
    }

    #[Given('un club de démonstration conservé pour 14 jours')]
    public function unClubDeDemonstrationConservePour14Jours(): void
    {
        $this->captureAra = 'DEMR' . time() . random_int(100, 999);
        $this->ownerEmail = 'demo-retain-owner-' . strtolower($this->captureAra) . '@amateo.fr';

        // Un vrai club naît (register + verify + approbation), puis on le marque démo CONSERVÉ :
        // is_demo, échéance J+14, et l'animateur DÉTACHÉ (la console le détache au « Conserver »).
        $this->registerAndVerify($this->ownerEmail);
        $ownerToken = $this->mintToken($this->ownerEmail);
        $approved = $this->apiPost('dev/approve-club-request', [], $ownerToken);
        if (200 !== $approved['status']) {
            throw new RuntimeException(\sprintf('l\'approbation du club propriétaire a répondu %d (200 attendu)', $approved['status']));
        }

        $me = $this->apiGet('me', $ownerToken);
        $club = $me['json']['club'] ?? null;
        $this->retainedClubId = \is_array($club) && \is_string($club['id'] ?? null) ? $club['id'] : '';
        if ('' === $this->retainedClubId) {
            throw new RuntimeException('le club n\'a pas été matérialisé par l\'approbation');
        }

        $this->dbalExec('UPDATE club SET is_demo = true, demo_retained_until = CURRENT_DATE + INTERVAL \'14 days\' WHERE id = \'' . $this->retainedClubId . '\'', true);
        // L'animateur est détaché (comme le geste console) : le club n'a plus de membre actif.
        $this->dbalExec('DELETE FROM club_user WHERE club_id = \'' . $this->retainedClubId . '\' AND user_id = (SELECT id FROM app_user WHERE email = \'' . $this->ownerEmail . '\')', true);
    }

    #[When('le contact officiel du club s\'inscrit avec le même code FFBB et son approbation passe')]
    public function leContactOfficielSInscritEtApprouve(): void
    {
        $this->officialEmail = 'contact-officiel-' . strtolower($this->captureAra) . '@amateo.fr';
        $membershipStatus = $this->registerAndVerify($this->officialEmail);
        if ('club_pending' !== $membershipStatus) {
            throw new RuntimeException(\sprintf('l\'inscription du contact officiel aurait dû ouvrir une demande (club_pending), statut « %s »', $membershipStatus));
        }

        $officialToken = $this->mintToken($this->officialEmail);
        $approved = $this->apiPost('dev/approve-club-request', [], $officialToken);
        if (200 !== $approved['status']) {
            throw new RuntimeException(\sprintf('l\'approbation du contact officiel a répondu %d (200 attendu)', $approved['status']));
        }
        $this->reclaimedClubId = \is_string($approved['json']['clubId'] ?? null) ? $approved['json']['clubId'] : null;
    }

    #[Then('il reprend le club conservé, désormais un vrai club dont il est gestionnaire')]
    public function ilReprendLeClubConserve(): void
    {
        // JAMAIS un 2e club : l'approbation a repris le club CONSERVÉ (même id).
        if ($this->reclaimedClubId !== $this->retainedClubId) {
            throw new RuntimeException(\sprintf('l\'approbation a créé un club « %s » au lieu de reprendre le club conservé « %s »', (string) $this->reclaimedClubId, $this->retainedClubId));
        }

        // Le club est redevenu un VRAI club (plus de démo, plus de conservation).
        $isDemo = $this->dbalScalar('SELECT is_demo AS behatval FROM club WHERE id = \'' . $this->retainedClubId . '\'', true);
        if (!\in_array($isDemo, ['f', 'false', '0', ''], true)) {
            throw new RuntimeException(\sprintf('le club repris est resté un club démo (is_demo = « %s »)', $isDemo));
        }

        // Le contact officiel est gestionnaire ACTIF du club repris (son /api/me le pointe).
        $me = $this->apiGet('me', $this->mintToken($this->officialEmail));
        $club = $me['json']['club'] ?? null;
        $clubId = \is_array($club) && \is_string($club['id'] ?? null) ? $club['id'] : null;
        if ($clubId !== $this->retainedClubId) {
            throw new RuntimeException('le contact officiel n\'est pas rattaché au club repris');
        }
        $role = $me['json']['membership']['role'] ?? ($me['json']['role'] ?? null);
        if (\is_string($role) && 'admin' !== $role && 'manager' !== $role) {
            throw new RuntimeException(\sprintf('le contact officiel n\'est pas gestionnaire (rôle « %s »)', $role));
        }
    }

    #[AfterScenario]
    public function cleanup(): void
    {
        foreach ([$this->ownerEmail, $this->officialEmail] as $email) {
            if ('' === $email) {
                continue;
            }
            $selector = '(SELECT id FROM app_user WHERE email = \'' . $email . '\')';
            $this->dbalExec('DELETE FROM email_verification_token WHERE user_id IN ' . $selector, true);
            $this->dbalExec('DELETE FROM club_creation_request WHERE user_id IN ' . $selector, true);
            $this->dbalExec('DELETE FROM club_user WHERE user_id IN ' . $selector, true);
        }
        if ('' !== $this->retainedClubId) {
            $this->dbalExec('DELETE FROM club_user WHERE club_id = \'' . $this->retainedClubId . '\'', true);
            $this->dbalExec('DELETE FROM club WHERE id = \'' . $this->retainedClubId . '\'', true);
        }
        foreach ([$this->ownerEmail, $this->officialEmail] as $email) {
            if ('' !== $email) {
                $this->dbalExec('DELETE FROM app_user WHERE email = \'' . $email . '\'', true);
            }
        }
    }

    /** Register + verify par l'API réelle ; retourne le `membershipStatus` de la vérification. */
    private function registerAndVerify(string $email): string
    {
        $known = $this->mailboxMessageIds($email);
        $registered = $this->publicPost('register', [
            'email' => $email, 'password' => self::PASSWORD,
            'firstName' => 'Contact', 'lastName' => 'Officiel',
            'ara' => $this->captureAra, 'club_name' => 'Club ' . $this->captureAra, 'consent' => true,
        ]);
        if (202 !== $registered['status']) {
            throw new RuntimeException(\sprintf('l\'inscription a répondu %d (202 attendu)', $registered['status']));
        }
        $rawToken = $this->pullVerificationToken($email, $known);
        $verified = $this->publicPost('register/verify', ['token' => $rawToken]);
        if (!\in_array($verified['status'], [200, 201, 204], true)) {
            throw new RuntimeException(\sprintf('la vérification a répondu %d (2xx attendu)', $verified['status']));
        }

        return \is_string($verified['json']['membershipStatus'] ?? null) ? $verified['json']['membershipStatus'] : '';
    }

    /**
     * Le jeton de vérification du mail FRAÎCHEMENT émis (Mailpit trie du plus récent au plus
     * ancien) : le premier message absent de `$ignoreIds`, donc neuf. Boucle d'attente bornée.
     *
     * @param list<string> $ignoreIds messages déjà présents AVANT l'inscription
     */
    private function pullVerificationToken(string $email, array $ignoreIds): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $search = $this->httpGet($this->mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $email));
            $messages = $search['json']['messages'] ?? [];
            if (\is_array($messages)) {
                foreach ($messages as $message) {
                    if (!\is_array($message) || !isset($message['ID']) || !\is_string($message['ID']) || \in_array($message['ID'], $ignoreIds, true)) {
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

        throw new RuntimeException('aucun e-mail de vérification frais trouvé dans le webmail — la file d\'envoi est-elle consommée ?');
    }

    /**
     * Les identifiants Mailpit des messages actuellement adressés à `$email` (photo AVANT
     * l'inscription, pour distinguer le mail neuf des jetons périmés de la boîte partagée).
     *
     * @return list<string>
     */
    private function mailboxMessageIds(string $email): array
    {
        $search = $this->httpGet($this->mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $email));
        $messages = $search['json']['messages'] ?? [];
        $ids = [];
        if (\is_array($messages)) {
            foreach ($messages as $message) {
                if (\is_array($message) && isset($message['ID']) && \is_string($message['ID'])) {
                    $ids[] = $message['ID'];
                }
            }
        }

        return $ids;
    }
}
