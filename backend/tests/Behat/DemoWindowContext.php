<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use RuntimeException;

/**
 * Démos, axe *auth & memberships* — la promesse de bout en bout sur la stack qui
 * tourne : un compte démo ne se connecte QUE pendant sa fenêtre d'activation.
 *
 * On inscrit et vérifie un compte à l'adresse animateur démo (`demo@amateo.fr`, jamais
 * seedée dans le bac à sable BCCL), on pose sa fenêtre d'activation par SQL admin
 * (PR B posera la vraie porte console), puis on éprouve `/api/login` : fenêtre fermée →
 * refus « Identifiants invalides. » (indiscernable d'un mauvais mot de passe) ; fenêtre
 * ouverte → connexion réussie. La garde ne dépend pas de `simulated_today` : la fenêtre est
 * confrontée à l'horloge réelle. Le contexte crée ET nettoie son propre compte (le bac
 * à sable dev n'est pas ré-initialisé entre deux runs), donc il rejoue à l'identique.
 */
final class DemoWindowContext extends BaseContext
{
    private const string DEMO_EMAIL = 'demo@amateo.fr';

    private const string PASSWORD = 'Password123!';

    private string $mailpitBase;

    private string $captureAra = '';

    private string $demoClubId = '';

    private string $realMembershipStatus = '';

    private ?string $realClubId = null;

    /** @var array{status: int, json: array<mixed>, headers: array<string, list<string>>} */
    private array $loginResponse = ['status' => 0, 'json' => [], 'headers' => []];

    public function __construct()
    {
        parent::__construct();
        $this->mailpitBase = rtrim((string) (getenv('BEHAT_MAILPIT_BASE') ?: 'http://mailpit:8025'), '/');
    }

    #[Given('un compte démo inscrit et vérifié')]
    public function unCompteDemoInscritEtVerifie(): void
    {
        // Défense : un run précédent interrompu a pu laisser le compte — on repart propre.
        $this->purgeDemoAccount();

        // La boîte Mailpit est PARTAGÉE et jamais purgée entre runs : un mail de
        // vérification périmé, adressé à la même adresse fixe, y traîne. On mémorise les
        // messages DÉJÀ présents pour cette adresse AVANT l'inscription, afin de ne lire
        // que le mail FRAÎCHEMENT émis par ce scénario (jamais un jeton d'un run passé).
        $knownMailIds = $this->mailboxMessageIds(self::DEMO_EMAIL);

        $ara = 'DEM' . time() . random_int(100, 999);
        $registered = $this->publicPost('register', [
            'email' => self::DEMO_EMAIL,
            'password' => self::PASSWORD,
            'firstName' => 'Démo',
            'lastName' => 'Amateo',
            'ara' => $ara,
            'club_name' => 'Démo ' . $ara,
            'consent' => true,
        ]);
        if (202 !== $registered['status']) {
            throw new RuntimeException(\sprintf('l\'inscription du compte démo a répondu %d (202 attendu)', $registered['status']));
        }

        $rawToken = $this->pullVerificationToken(self::DEMO_EMAIL, $knownMailIds);
        $verified = $this->publicPost('register/verify', ['token' => $rawToken]);
        if (!\in_array($verified['status'], [200, 201, 204], true)) {
            throw new RuntimeException(\sprintf('la vérification de l\'e-mail a répondu %d (2xx attendu)', $verified['status']));
        }

        $verifiedAt = $this->dbalScalar(
            'SELECT email_verified_at FROM app_user WHERE email = \'' . self::DEMO_EMAIL . '\'',
            true,
        );
        if ('' === $verifiedAt) {
            throw new RuntimeException('le compte démo n\'est pas vérifié après /register/verify');
        }
    }

    #[When('sa fenêtre d\'activation est fermée')]
    public function saFenetreEstFermee(): void
    {
        // NULL = inactif (défaut). PR B posera la vraie ouverture depuis la console.
        $this->dbalExec('UPDATE app_user SET demo_active_until = NULL WHERE email = \'' . self::DEMO_EMAIL . '\'', true);
    }

    #[When('sa fenêtre d\'activation est ouverte')]
    public function saFenetreEstOuverte(): void
    {
        $this->dbalExec('UPDATE app_user SET demo_active_until = NOW() + INTERVAL \'1 day\' WHERE email = \'' . self::DEMO_EMAIL . '\'', true);
    }

    #[When('il tente de se connecter avec son bon mot de passe')]
    public function ilTenteDeSeConnecter(): void
    {
        $this->loginResponse = $this->publicPost('login', [
            'email' => self::DEMO_EMAIL,
            'password' => self::PASSWORD,
        ]);
    }

    #[Then('/^la connexion est refusée avec le message « (?P<message>[^»]+) »$/')]
    public function laConnexionEstRefuseeAvecLeMessage(string $message): void
    {
        if (401 !== $this->loginResponse['status']) {
            throw new RuntimeException(\sprintf('la connexion aurait dû être refusée (401 attendu), elle a répondu %d', $this->loginResponse['status']));
        }

        $actual = $this->loginResponse['json']['message'] ?? null;
        if ($message !== $actual) {
            throw new RuntimeException(\sprintf('message attendu « %s », obtenu « %s »', $message, \is_string($actual) ? $actual : var_export($actual, true)));
        }
    }

    #[Then('la connexion réussit')]
    public function laConnexionReussit(): void
    {
        if (204 !== $this->loginResponse['status']) {
            throw new RuntimeException(\sprintf('la connexion aurait dû réussir (204 attendu), elle a répondu %d', $this->loginResponse['status']));
        }
    }

    #[Given('un club de démonstration portant un code FFBB')]
    public function unClubDeDemonstrationPortantUnCodeFfbb(): void
    {
        $this->captureAra = 'DEMC' . time() . random_int(100, 999);
        $ownerEmail = 'demo-owner-' . strtolower($this->captureAra) . '@amateo.fr';

        // Inscription + vérification + approbation → un club réel naît, qu'on marque
        // ensuite comme démo (ce que fait app:demo:create ; ici par SQL admin).
        $known = $this->mailboxMessageIds($ownerEmail);
        $registered = $this->publicPost('register', [
            'email' => $ownerEmail, 'password' => self::PASSWORD,
            'firstName' => 'Démo', 'lastName' => 'Owner',
            'ara' => $this->captureAra, 'club_name' => 'Démo ' . $this->captureAra, 'consent' => true,
        ]);
        if (202 !== $registered['status']) {
            throw new RuntimeException(\sprintf('l\'inscription du propriétaire démo a répondu %d (202 attendu)', $registered['status']));
        }
        $rawToken = $this->pullVerificationToken($ownerEmail, $known);
        $this->publicPost('register/verify', ['token' => $rawToken]);

        $ownerToken = $this->mintToken($ownerEmail);
        $approved = $this->apiPost('dev/approve-club-request', [], $ownerToken);
        if (200 !== $approved['status']) {
            throw new RuntimeException(\sprintf('l\'approbation du club démo a répondu %d (200 attendu)', $approved['status']));
        }

        $me = $this->apiGet('me', $ownerToken);
        $club = $me['json']['club'] ?? null;
        $this->demoClubId = \is_array($club) && \is_string($club['id'] ?? null) ? $club['id'] : '';
        if ('' === $this->demoClubId) {
            throw new RuntimeException('le club démo n\'a pas été matérialisé par l\'approbation');
        }
        $this->dbalExec('UPDATE club SET is_demo = true WHERE id = \'' . $this->demoClubId . '\'', true);
    }

    #[When('une personne s\'inscrit réellement avec ce même code FFBB')]
    public function unePersonneSInscritReellementAvecCeCode(): void
    {
        $email = 'real-' . strtolower($this->captureAra) . '@amateo.fr';
        $known = $this->mailboxMessageIds($email);
        $registered = $this->publicPost('register', [
            'email' => $email, 'password' => self::PASSWORD,
            'firstName' => 'Vrai', 'lastName' => 'Inscrit',
            'ara' => $this->captureAra, 'club_name' => 'Vrai club', 'consent' => true,
        ]);
        if (202 !== $registered['status']) {
            throw new RuntimeException(\sprintf('la vraie inscription a répondu %d (202 attendu)', $registered['status']));
        }
        $rawToken = $this->pullVerificationToken($email, $known);
        $verified = $this->publicPost('register/verify', ['token' => $rawToken]);
        $this->realMembershipStatus = \is_string($verified['json']['membershipStatus'] ?? null) ? $verified['json']['membershipStatus'] : '';

        // /api/me du vrai inscrit : aucune adhésion ne doit pointer vers la démo.
        $me = $this->apiGet('me', $this->mintToken($email));
        $club = $me['json']['club'] ?? null;
        $this->realClubId = \is_array($club) && \is_string($club['id'] ?? null) ? $club['id'] : null;
    }

    #[Then('elle n\'entre pas dans la démo mais ouvre sa propre demande de club')]
    public function elleNEntrePasDansLaDemo(): void
    {
        if ('club_pending' !== $this->realMembershipStatus) {
            throw new RuntimeException(\sprintf('la vraie inscription aurait dû ouvrir une demande (club_pending), statut obtenu « %s » — a-t-elle rejoint la démo ?', $this->realMembershipStatus));
        }
        if (null !== $this->realClubId) {
            throw new RuntimeException(\sprintf('la vraie inscription a rejoint un club (« %s ») au lieu de rester sans club — capture par la démo ?', $this->realClubId));
        }
    }

    #[AfterScenario]
    public function cleanupDemoAccount(): void
    {
        $this->purgeDemoAccount();
    }

    /**
     * Supprime le compte démo et ses dépendances (jetons, demande de club, adhésions)
     * via la connexion admin — le rail /register+verify n'en crée pas d'autres.
     */
    private function purgeDemoAccount(): void
    {
        $selector = '(SELECT id FROM app_user WHERE email = \'' . self::DEMO_EMAIL . '\')';
        $this->dbalExec('DELETE FROM email_verification_token WHERE user_id IN ' . $selector, true);
        $this->dbalExec('DELETE FROM club_creation_request WHERE user_id IN ' . $selector, true);
        $this->dbalExec('DELETE FROM club_user WHERE user_id IN ' . $selector, true);
        $this->dbalExec('DELETE FROM app_user WHERE email = \'' . self::DEMO_EMAIL . '\'', true);
    }

    /**
     * Le jeton de vérification du mail FRAÎCHEMENT émis. Mailpit trie ses résultats du
     * plus récent au plus ancien : on retient le PREMIER message qui n'existait pas avant
     * l'inscription (`$ignoreIds`), donc le mail neuf de ce scénario — jamais un jeton
     * périmé d'un run précédent resté dans la boîte partagée. Boucle d'attente : le mail
     * met un instant à être consommé et à arriver dans le webmail.
     *
     * @param list<string> $ignoreIds messages déjà présents pour cette adresse AVANT l'inscription
     */
    private function pullVerificationToken(string $email, array $ignoreIds): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $search = $this->httpGet($this->mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $email));
            $messages = $search['json']['messages'] ?? [];

            if (\is_array($messages)) {
                foreach ($messages as $message) {
                    if (!\is_array($message) || !isset($message['ID']) || !\is_string($message['ID']) || \in_array($message['ID'], $ignoreIds, true)) {
                        continue; // vide, illisible, ou mail périmé d'un run précédent
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
     * Les identifiants Mailpit des messages actuellement adressés à `$email`. Sert à
     * photographier la boîte partagée AVANT l'inscription, pour distinguer le mail neuf
     * des jetons périmés qui y traînent.
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
