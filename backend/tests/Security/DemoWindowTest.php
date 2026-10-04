<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\User;
use App\Tests\StartsFreshBrowserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Démos, axe *auth & memberships* (§7.1) — la connexion démo NE S'OUVRE QUE PENDANT SA
 * FENÊTRE D'ACTIVATION (`app_user.demo_active_until`), falsifié dans les deux sens.
 *
 * Deux comptes démo (animateur `demo@`, BCCL `demo-bccl@`) et EUX SEULS sont gardés :
 *  - fenêtre NULLE ou ÉCHUE → `/api/login` refuse d'une manière OCTET-IDENTIQUE à un
 *    mauvais mot de passe (statut + corps), même bon mot de passe : aucun oracle
 *    « fenêtre fermée » (`UserChecker` lève le MÊME message que lexik) ;
 *  - fenêtre OUVERTE + bon mot de passe → connecté (204) ;
 *  - une adresse NON démo est insensible à la colonne (elle se connecte même avec une
 *    valeur qui FERMERAIT une fenêtre démo) ;
 *  - le RACCOURCI démo du register (`/api/dev/demo-register`) en mode NON-debug (prod) :
 *    fenêtre fermée → 422 `not_demo_account` IDENTIQUE à celui d'une adresse quelconque
 *    (jamais un 404, jamais « fenêtre fermée ») ; fenêtre ouverte → il matérialise le
 *    club démo ;
 *  - `/api/register/config` hors debug n'expose `demoShortcut`/`demoEmail` QUE fenêtre
 *    ouverte.
 *
 * L'horloge est TOUJOURS réelle (`UserChecker`/contrôleurs passent
 * `new DateTimeImmutable('now')`), jamais `simulated_today` : un club démo ne rouvre pas sa
 * propre porte. La branche non-debug est éprouvée en bootant le noyau de test avec
 * `debug: false` (moyen propre, pas un contournement de garde).
 *
 * ⚠ FALSIFICATION : retirer la branche fenêtre de `UserChecker::checkPostAuth` fait
 * répondre 204 à la connexion « bon mot de passe + fenêtre fermée » → l'assertion
 * octet-identique de testClosedWindowLoginIsByteIdenticalToWrongPassword rougit.
 * Retirer la garde fenêtre du contrôleur en non-debug rend un 200 là où 422 est exigé.
 *
 * ⚠ BLOQUANT : step du job `blocking-tests` (`.github/workflows/ci.yml`) + ligne de
 * `docs/testing/blocking-tests.md`, gardés jumeaux par `BlockingTestsListMatchesCiTest`.
 */
#[Group('phase1')]
#[Group('integration')]
final class DemoWindowTest extends WebTestCase
{
    use StartsFreshBrowserSession;

    private const string ANIMATOR_EMAIL = 'demo@amateo.fr';

    private const string BCCL_EMAIL = 'demo-bccl@amateo.fr';

    private const string PASSWORD = 'Password123!';

    private static int $ipCounter = 0;

    /** Fenêtre NULLE : bon mot de passe refusé exactement comme un mauvais (aucun oracle). */
    public function testClosedWindowLoginIsByteIdenticalToWrongPassword(): void
    {
        $client = self::createClient();
        $this->seedDemoUser(self::ANIMATOR_EMAIL, null);

        [$goodStatus, $goodRaw] = $this->login($client, self::ANIMATOR_EMAIL, self::PASSWORD);
        [$wrongStatus, $wrongRaw] = $this->login($client, self::ANIMATOR_EMAIL, 'WrongPassword9!');

        self::assertSame(401, $goodStatus, 'fenêtre fermée : le bon mot de passe est tout de même refusé');
        self::assertSame($wrongStatus, $goodStatus, 'même statut qu\'un mauvais mot de passe');
        self::assertSame($wrongRaw, $goodRaw, 'fenêtre fermée = byte-identique à un mauvais mot de passe (aucun oracle)');
    }

    /** L'autre compte démo (BCCL) est gardé de la même façon. */
    public function testClosedWindowLoginForBcclAccountIsByteIdenticalToWrongPassword(): void
    {
        $client = self::createClient();
        $this->seedDemoUser(self::BCCL_EMAIL, null);

        [$goodStatus, $goodRaw] = $this->login($client, self::BCCL_EMAIL, self::PASSWORD);
        [$wrongStatus, $wrongRaw] = $this->login($client, self::BCCL_EMAIL, 'WrongPassword9!');

        self::assertSame(401, $goodStatus);
        self::assertSame($wrongStatus, $goodStatus);
        self::assertSame($wrongRaw, $goodRaw, 'demo-bccl@ hors fenêtre = byte-identique à un mauvais mot de passe');
    }

    /** Fenêtre ÉCHUE (passée) : même refus indiscernable. */
    public function testExpiredWindowLoginIsByteIdenticalToWrongPassword(): void
    {
        $client = self::createClient();
        $this->seedDemoUser(self::ANIMATOR_EMAIL, new DateTimeImmutable('-1 day'));

        [$goodStatus, $goodRaw] = $this->login($client, self::ANIMATOR_EMAIL, self::PASSWORD);
        [$wrongStatus, $wrongRaw] = $this->login($client, self::ANIMATOR_EMAIL, 'WrongPassword9!');

        self::assertSame(401, $goodStatus, 'fenêtre échue : refusée');
        self::assertSame($wrongStatus, $goodStatus);
        self::assertSame($wrongRaw, $goodRaw, 'fenêtre échue = byte-identique à un mauvais mot de passe');
    }

    /** Fenêtre OUVERTE + bon mot de passe → connecté (204, jeton en cookie). */
    public function testOpenWindowLetsTheDemoAccountLogIn(): void
    {
        $client = self::createClient();
        $this->seedDemoUser(self::ANIMATOR_EMAIL, new DateTimeImmutable('+1 day'));

        [$status] = $this->login($client, self::ANIMATOR_EMAIL, self::PASSWORD);

        self::assertSame(204, $status, 'dans la fenêtre, le bon mot de passe connecte (jeton en cookie, corps vide)');
    }

    /** Une adresse NON démo est insensible à la colonne — même avec une valeur qui fermerait une fenêtre. */
    public function testNonDemoAccountIsInsensitiveToTheColumn(): void
    {
        $client = self::createClient();
        // Valeur passée (fermerait une fenêtre démo) posée sur un compte NON démo :
        // il doit se connecter quand même — la garde ne le concerne pas.
        $this->seedDemoUser('someone@club.fr', new DateTimeImmutable('-1 day'), isDemo: false);

        [$status] = $this->login($client, 'someone@club.fr', self::PASSWORD);

        self::assertSame(204, $status, 'un compte non démo se connecte quelle que soit la colonne demo_active_until');
    }

    /**
     * SEC-28 — un compte de DÉMONSTRATION ne peut ni modifier son profil (prénom/nom,
     * e-mail, mot de passe) ni se supprimer : chacun des quatre gestes répond 403.
     */
    public function testDemoAccountCannotMutateItsProfileNorDeleteItself(): void
    {
        $client = self::createClient();
        $token = $this->tokenFor($this->seedDemoUser(self::ANIMATOR_EMAIL, new DateTimeImmutable('+1 day')));

        self::assertSame(403, $this->authedStatus($client, 'PATCH', '/api/me', $token, ['firstName' => 'Nouveau', 'lastName' => 'Nom']), 'prénom/nom refusés');
        self::assertSame(403, $this->authedStatus($client, 'POST', '/api/me/password', $token, ['currentPassword' => self::PASSWORD, 'newPassword' => 'AnotherPass123!']), 'mot de passe refusé');
        self::assertSame(403, $this->authedStatus($client, 'POST', '/api/me/email', $token, ['currentPassword' => self::PASSWORD, 'email' => 'new-demo@club.fr']), 'changement d\'e-mail refusé');
        self::assertSame(403, $this->authedStatus($client, 'DELETE', '/api/me', $token, ['password' => self::PASSWORD]), 'suppression refusée');
    }

    /**
     * Un compte ORDINAIRE garde tous ses gestes de profil : le drapeau démo ne le
     * concerne pas (jamais de 403 sur le drapeau — tout au plus un 400 métier).
     */
    public function testNonDemoAccountKeepsItsProfileGestures(): void
    {
        $client = self::createClient();
        $token = $this->tokenFor($this->seedDemoUser('normal@club.fr', null, isDemo: false));

        self::assertSame(200, $this->authedStatus($client, 'PATCH', '/api/me', $token, ['firstName' => 'Nouveau', 'lastName' => 'Nom']), 'un compte ordinaire change son prénom/nom');
        self::assertNotSame(403, $this->authedStatus($client, 'POST', '/api/me/password', $token, ['currentPassword' => self::PASSWORD, 'newPassword' => 'AnotherPass123!']), 'le mot de passe d\'un compte ordinaire n\'est jamais refusé sur le drapeau démo');
    }

    /**
     * RACCOURCI en mode NON-debug : fenêtre fermée → 422 `not_demo_account`, IDENTIQUE
     * à celui d'une adresse quelconque (jamais 404, jamais « fenêtre fermée »).
     */
    public function testShortcutOutsideDebugRefusesClosedWindowIdenticallyToANonDemoEmail(): void
    {
        $client = self::createClient(['debug' => false]);
        $this->seedDemoUser(self::ANIMATOR_EMAIL, null);

        [$closedStatus, $closedRaw] = $this->demoRegister($client, self::ANIMATOR_EMAIL);
        [$nonDemoStatus, $nonDemoRaw] = $this->demoRegister($client, 'quelquun@club.fr');

        self::assertSame(422, $closedStatus, 'fenêtre fermée en prod → 422 (pas 404)');
        self::assertSame($nonDemoStatus, $closedStatus);
        self::assertSame($nonDemoRaw, $closedRaw, 'fenêtre fermée = byte-identique à une adresse non démo (aucun oracle)');
        self::assertSame('not_demo_account', json_decode($closedRaw, true)['error'] ?? null);
    }

    /** RACCOURCI en mode NON-debug + fenêtre OUVERTE → il matérialise le club démo (200). */
    public function testShortcutOutsideDebugWithOpenWindowMaterialisesTheClub(): void
    {
        $client = self::createClient(['debug' => false]);
        $this->seedDemoUser(self::ANIMATOR_EMAIL, new DateTimeImmutable('+1 day'));

        [$status, $raw] = $this->demoRegister($client, self::ANIMATOR_EMAIL);

        self::assertSame(200, $status, 'dans la fenêtre, le raccourci s\'exécute en prod');
        $body = json_decode($raw, true);
        self::assertSame('active', $body['membershipStatus'] ?? null);
        $clubId = $body['clubId'] ?? null;
        self::assertIsString($clubId);

        $this->em()->clear();
        $club = $this->em()->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertTrue($club->isDemo(), 'le club matérialisé est is_demo');
    }

    /** `/api/register/config` hors debug : `demoShortcut` seulement quand la fenêtre est ouverte. */
    public function testRegisterConfigOutsideDebugExposesShortcutOnlyWhenWindowOpen(): void
    {
        $client = self::createClient(['debug' => false]);

        $closed = $this->registerConfig($client);
        self::assertArrayHasKey('demoShortcut', $closed);
        self::assertFalse($closed['demoShortcut'], 'sans fenêtre ouverte, pas de raccourci hors debug');
        self::assertNull($closed['demoEmail'], 'l\'adresse démo n\'est pas exposée hors fenêtre');

        $this->seedDemoUser(self::ANIMATOR_EMAIL, new DateTimeImmutable('+1 day'));

        $open = $this->registerConfig($client);
        self::assertTrue($open['demoShortcut'], 'fenêtre ouverte → raccourci exposé même hors debug');
        self::assertSame(self::ANIMATOR_EMAIL, $open['demoEmail'], 'l\'adresse démo est exposée quand la fenêtre est ouverte');
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * Persiste un compte VÉRIFIÉ (global, hors RLS) avec un mot de passe et une fenêtre
     * donnée. `$isDemo` pose le drapeau d'identité SEC-28 (la garde de connexion s'y
     * appuie désormais, plus sur l'adresse) ; false pour un compte ordinaire.
     */
    private function seedDemoUser(string $email, ?DateTimeImmutable $window, bool $isDemo = true): string
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Démo');
        $user->setLastName('Amateo');
        $user->setPasswordHash($hasher->hashPassword($user, self::PASSWORD));
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $user->setTermsAcceptedAt(new DateTimeImmutable);
        $user->setDemoActiveUntil($window);
        $user->setIsDemo($isDemo);
        $em->persist($user);
        $em->flush();
        $id = $user->getId();
        $em->clear();

        return $id;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function login(KernelBrowser $client, string $email, string $password): array
    {
        $this->startFreshBrowserSession($client);
        $client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR));

        return [$client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent()];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function demoRegister(KernelBrowser $client, string $email): array
    {
        $this->startFreshBrowserSession($client);
        $client->request('POST', '/api/dev/demo-register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode([
            'email' => $email,
            'password' => self::PASSWORD,
            'ara' => 'ZZ' . str_pad((string) random_int(0, 99_999_999), 8, '0', \STR_PAD_LEFT),
            'clubName' => 'Prospect',
        ], \JSON_THROW_ON_ERROR));

        return [$client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent()];
    }

    /** @return array<string, mixed> */
    private function registerConfig(KernelBrowser $client): array
    {
        $this->startFreshBrowserSession($client);
        $client->request('GET', '/api/register/config', [], [], ['REMOTE_ADDR' => $this->nextIp()]);
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    /** Mint un JWT pour le compte d'id donné (test direct du contrôleur, sans login). */
    private function tokenFor(string $userId): string
    {
        $user = $this->em()->getRepository(User::class)->find($userId);
        \assert($user instanceof User);
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($manager instanceof JWTTokenManagerInterface);

        return $manager->create($user);
    }

    /**
     * Rejoue une requête authentifiée (Bearer) et rend son statut.
     *
     * @param array<string, mixed> $body
     */
    private function authedStatus(KernelBrowser $client, string $method, string $uri, string $token, array $body): int
    {
        $this->startFreshBrowserSession($client);
        $client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode($body, \JSON_THROW_ON_ERROR));

        return $client->getResponse()->getStatusCode();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function nextIp(): string
    {
        $ip = '10.7.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;

        return $ip;
    }
}
