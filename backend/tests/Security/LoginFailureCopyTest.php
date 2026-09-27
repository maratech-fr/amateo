<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\StartsFreshBrowserSession;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P4-263, axe *auth & memberships* — le refus de connexion PARLE FRANÇAIS et ne
 * révèle rien.
 *
 * Deux garanties, tenues ensemble, que le socle de traduction (`symfony/translation`
 * + `default_locale: fr`) rend vraies :
 *
 * 1. un mauvais mot de passe est refusé avec « Identifiants invalides. » — le message
 *    que le gestionnaire lit sur `/connexion` (déclencheur du besoin : le fondateur a
 *    vu « Invalid credentials. »). Sans le socle, Lexik renvoie la clé anglaise brute.
 * 2. un compte NON vérifié (bon mot de passe) est refusé d'une manière BYTE-IDENTIQUE
 *    à un mauvais mot de passe — l'anti-énumération de `UserChecker` (VOLONTAIREMENT
 *    le même message) survit à la francisation : traduire les deux via le même
 *    catalogue `security` les garde indistinguables à l'octet. Ce test rougirait si un
 *    jour on donnait un message distinct à l'un des deux chemins.
 *
 * ⚠ Le throttling `login_throttling` est de 5 tentatives par compte : chaque test
 * emploie des e-mails distincts et reste bien sous ce seuil.
 */
#[Group('phase1')]
#[Group('integration')]
final class LoginFailureCopyTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private static int $ipCounter = 0;

    private KernelBrowser $client;

    public function testWrongPasswordMessageIsFrench(): void
    {
        $email = 'copy-wrong@club.fr';
        $this->register($email, 'Password123!', 'WRONGPW1', 'Copy Wrong Club');

        [$status, , $body] = $this->login($email, 'WrongPassword9!');

        self::assertSame(401, $status);
        self::assertSame('Identifiants invalides.', $body['message'] ?? null);
    }

    public function testUnverifiedAccountIsByteIdenticalToWrongPassword(): void
    {
        // Compte NON vérifié : /register crée le compte mais ne le vérifie pas.
        $email = 'copy-unverified@club.fr';
        $this->register($email, 'Password123!', 'UNVERBI1', 'Copy Unver Club');

        // Bon mot de passe → refus « compte non vérifié » ; mauvais mot de passe →
        // refus « identifiants ». Les deux doivent être indistinguables à l'octet.
        [$goodPwStatus, $goodPwRaw] = $this->login($email, 'Password123!');
        [$wrongPwStatus, $wrongPwRaw] = $this->login($email, 'WrongPassword9!');

        self::assertSame(401, $goodPwStatus);
        self::assertSame($wrongPwStatus, $goodPwStatus, 'le compte non vérifié partage le statut du mauvais mot de passe');
        self::assertSame($wrongPwRaw, $goodPwRaw, 'le compte non vérifié est byte-identique à un mauvais mot de passe (aucun oracle)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function register(string $email, string $password, string $ara, string $clubName): void
    {
        $this->requestJson('/api/register', [
            'email' => $email, 'password' => $password,
            'firstName' => 'Co', 'lastName' => 'Py', 'ara' => $ara, 'club_name' => $clubName, 'consent' => true,
        ]);
    }

    /**
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     */
    private function login(string $email, string $password): array
    {
        $this->requestJson('/api/login', ['email' => $email, 'password' => $password]);
        $raw = (string) $this->client->getResponse()->getContent();

        return [
            $this->client->getResponse()->getStatusCode(),
            $raw,
            json_decode($raw, true) ?? [],
        ];
    }

    /** @param array<string, mixed> $body */
    private function requestJson(string $path, array $body): void
    {
        // Navigateur neuf + IP unique par appel : ni le cookie JWT rejoué ni les
        // limiteurs par IP ne parasitent l'assertion (miroir d'AuthFlowTest).
        $this->startFreshBrowserSession($this->client);
        $ip = '10.1.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
