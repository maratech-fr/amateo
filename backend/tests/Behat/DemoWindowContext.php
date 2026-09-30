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
 * ouverte → connexion réussie. La garde ne dépend pas de `demo_today` : la fenêtre est
 * confrontée à l'horloge réelle. Le contexte crée ET nettoie son propre compte (le bac
 * à sable dev n'est pas ré-initialisé entre deux runs), donc il rejoue à l'identique.
 */
final class DemoWindowContext extends BaseContext
{
    private const string DEMO_EMAIL = 'demo@amateo.fr';

    private const string PASSWORD = 'Password123!';

    private string $mailpitBase;

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

        $rawToken = $this->pullVerificationToken(self::DEMO_EMAIL);
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

    private function pullVerificationToken(string $email): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $search = $this->httpGet($this->mailpitBase . '/api/v1/search?query=' . rawurlencode('to:' . $email));
            $messages = $search['json']['messages'] ?? [];
            $messageId = \is_array($messages) && isset($messages[0]['ID']) && \is_string($messages[0]['ID']) ? $messages[0]['ID'] : '';

            if ('' !== $messageId) {
                $message = $this->httpGet($this->mailpitBase . '/api/v1/message/' . $messageId);
                $text = $message['json']['Text'] ?? '';
                if (\is_string($text) && 1 === preg_match('#verify-email/([a-f0-9]{64})#', $text, $m)) {
                    return $m[1];
                }
            }

            sleep(1);
        }

        throw new RuntimeException('aucun e-mail de vérification trouvé dans le webmail — la file d\'envoi est-elle consommée ?');
    }
}
