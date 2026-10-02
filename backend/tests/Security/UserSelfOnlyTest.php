<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\EmailChangeToken;
use App\Entity\User;
use App\Service\EmailChangeVerifier;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEC-02 non-regression: the User resource is self-only. No collection (email
 * enumeration), no bare POST; only Get is exposed, restricted to the caller's own
 * id. PUT/DELETE are not exposed (profile edits go through PATCH /api/me).
 *
 * P4-74 (axe auth & memberships) — le changement d'e-mail « confirmer d'abord,
 * basculer ensuite » : le PATCH /api/me ne mute pas l'e-mail
 * en direct ; la demande stocke une adresse en attente sans toucher
 * l'actuelle (l'utilisateur reste connectable) ; le token confirme et bascule ;
 * un token invalide/expiré/rejoué échoue ; une adresse déjà prise → 409 ; le
 * pending d'un compte n'est ni lisible ni annulable par un autre.
 */
#[Group('phase1')]
#[Group('integration')]
final class UserSelfOnlyTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testUsersCollectionIsGone(): void
    {
        [$token] = $this->register('USRA');

        $this->request('GET', '/api/users', $token);
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [404, 405],
            'GET /api/users collection must not exist',
        );
    }

    public function testGetSelfSucceeds(): void
    {
        [$token, $userId] = $this->register('USRB');

        $this->request('GET', '/api/users/' . $userId, $token);
        self::assertResponseIsSuccessful();
    }

    public function testPutIsGone(): void
    {
        [$token, $userId] = $this->register('USRI');

        // PUT retiré (nettoyage API) : le profil s'édite via PATCH /api/me, jamais
        // cette ressource — seul Get item reste exposé.
        $this->request('PUT', '/api/users/' . $userId, $token, ['firstName' => 'Renamed', 'lastName' => 'Self']);
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [404, 405],
            'PUT /api/users/{id} must not exist',
        );
    }

    public function testGetOtherUserReturns404(): void
    {
        [$tokenA] = $this->register('USRC');
        [, $userB] = $this->register('USRD');

        $this->request('GET', '/api/users/' . $userB, $tokenA);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteIsGone(): void
    {
        [$tokenA, $userA] = $this->register('USRG');

        // No Delete operation is exposed (would orphan ClubUser memberships).
        $this->request('DELETE', '/api/users/' . $userA, $tokenA);
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [404, 405],
            'DELETE /api/users/{id} must not exist',
        );
    }

    public function testPatchMeRejectsAnEmailChangeAndLeavesTheEmailUntouched(): void
    {
        [$token, , $email] = $this->register('USRH');

        // PATCH /api/me avec une adresse DIFFÉRENTE → 422 (le changement passe par
        // POST /api/me/email), l'adresse courante ne bouge pas.
        $this->request('PATCH', '/api/me', $token, ['email' => 'hijack-' . $email]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($email, $this->me($token)['email'], 'le PATCH ne change pas l\'e-mail');

        // Un e-mail IDENTIQUE à l'actuel est ignoré (no-op), le reste passe (200).
        $this->request('PATCH', '/api/me', $token, ['email' => $email, 'firstName' => 'Renamed']);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed', $this->me($token)['firstName']);
    }

    public function testEmailChangeStoresPendingKeepsCurrentActiveThenSwitchesOnConfirm(): void
    {
        [$token, $userId, $email] = $this->register('USRM');
        $newEmail = 'new-' . substr(md5(uniqid('', true)), 0, 6) . '@test.fr';

        // Demande : stocke le pending, ne touche PAS l'adresse courante.
        $this->request('POST', '/api/me/email', $token, ['email' => $newEmail, 'currentPassword' => 'Password123!']);
        self::assertResponseIsSuccessful();
        $me = $this->me($token);
        self::assertSame($email, $me['email'], 'l\'adresse courante reste inchangée');
        self::assertSame($newEmail, $me['pendingEmail']);

        // Toujours connectable pendant l'attente : login sur l'ANCIENNE adresse marche
        // (emailVerifiedAt intact — le gate UserChecker ne se referme pas).
        self::assertSame(204, $this->login($email, 'Password123!'));

        // Confirmer : le clic sur le lien bascule l'e-mail et efface le pending.
        $raw = $this->mintConfirmToken($userId);
        $this->confirm($raw);
        self::assertResponseIsSuccessful();

        // Nouvelle adresse active, ancienne inerte.
        self::assertSame(204, $this->login($newEmail, 'Password123!'), 'la nouvelle adresse authentifie');
        self::assertSame(401, $this->login($email, 'Password123!'), 'l\'ancienne adresse ne résout plus');
    }

    public function testInvalidExpiredAndReplayedConfirmTokensFail(): void
    {
        [$token, $userId] = $this->register('USRN');
        $newEmail = 'new-' . substr(md5(uniqid('', true)), 0, 6) . '@test.fr';

        // Token inconnu → 400.
        $this->confirm('deadbeef' . str_repeat('0', 56));
        self::assertResponseStatusCodeSame(400, 'token inconnu → 400');

        // Token expiré → 400 (la bascule n'a pas lieu).
        $this->request('POST', '/api/me/email', $token, ['email' => $newEmail, 'currentPassword' => 'Password123!']);
        $rawExpired = $this->mintConfirmToken($userId);
        $this->em()->getConnection()->executeStatement('UPDATE email_change_token SET expires_at = NOW() - INTERVAL \'1 hour\'');
        $this->confirm($rawExpired);
        self::assertResponseStatusCodeSame(400, 'token expiré → 400');
        self::assertSame($newEmail, $this->me($token)['pendingEmail'], 'un token expiré ne bascule ni n\'efface le pending');

        // Rejeu : une confirmation valide consomme le token ; le rejouer → 400.
        $raw = $this->mintConfirmToken($userId);
        $this->confirm($raw);
        self::assertResponseIsSuccessful();
        $this->confirm($raw);
        self::assertResponseStatusCodeSame(400, 'token déjà consommé → 400');
    }

    public function testRequestingAnEmailTakenByAnotherAccountIs409(): void
    {
        [, , $emailA] = $this->register('USRQ');
        [$tokenB] = $this->register('USRR');

        // B demande l'adresse ACTIVE de A → 409, rien n'est mis en attente.
        $this->request('POST', '/api/me/email', $tokenB, ['email' => $emailA, 'currentPassword' => 'Password123!']);
        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->me($tokenB)['pendingEmail']);
    }

    public function testPendingEmailIsSelfOnly(): void
    {
        [$tokenA, , $emailA] = $this->register('USRS');
        [$tokenB] = $this->register('USRT');
        $target = 'shared-' . substr(md5(uniqid('', true)), 0, 6) . '@test.fr';

        $this->request('POST', '/api/me/email', $tokenA, ['email' => $target, 'currentPassword' => 'Password123!']);
        self::assertResponseIsSuccessful();

        // Le /api/me de B ne voit JAMAIS le pending de A (self-only par construction).
        self::assertNull($this->me($tokenB)['pendingEmail']);

        // B annule SON pending (aucun) : celui de A est intact.
        $this->request('DELETE', '/api/me/email', $tokenB);
        self::assertResponseIsSuccessful();
        self::assertSame($target, $this->me($tokenA)['pendingEmail'], 'un autre compte n\'annule pas mon pending');

        // A annule le sien.
        $this->request('DELETE', '/api/me/email', $tokenA);
        self::assertResponseIsSuccessful();
        self::assertNull($this->me($tokenA)['pendingEmail']);
    }

    /**
     * Revue sécu P4-74 — changer d'adresse TRANSFÈRE le compte (l'e-mail est
     * l'identifiant de connexion) : un JWT emprunté ne suffit pas, le mot de
     * passe courant est exigé, comme pour DELETE /api/me.
     */
    public function testEmailChangeRequiresTheCurrentPassword(): void
    {
        [$token, , $email] = $this->register('USRW');
        $newEmail = 'pw-' . substr(md5(uniqid('', true)), 0, 6) . '@test.fr';

        $this->request('POST', '/api/me/email', $token, ['email' => $newEmail]);
        self::assertResponseStatusCodeSame(400, 'sans mot de passe, la demande est refusée');
        self::assertNull($this->me($token)['pendingEmail'], 'aucun pending posé');

        $this->request('POST', '/api/me/email', $token, ['email' => $newEmail, 'currentPassword' => 'MauvaisMotDePasse!1']);
        self::assertResponseStatusCodeSame(400, 'mot de passe faux → refus');
        self::assertNull($this->me($token)['pendingEmail']);

        $this->request('POST', '/api/me/email', $token, ['email' => $newEmail, 'currentPassword' => 'Password123!']);
        self::assertResponseIsSuccessful('avec le bon mot de passe, la demande passe');
        self::assertSame($newEmail, $this->me($token)['pendingEmail']);
        self::assertSame($email, $this->me($token)['email'], 'et l\'adresse courante ne bouge toujours pas');
    }

    /**
     * Revue sécu P4-74 (finding HIGH) — l'effacement RGPD est TERMINAL : un lien
     * de changement d'e-mail encore vivant ne doit pas réécrire une vraie adresse
     * sur le compte anonymisé ni rendre un cookie de session.
     */
    public function testErasureKillsAPendingEmailChangeAndItsToken(): void
    {
        [$token, $userId] = $this->register('USRE');
        $newEmail = 'resurrect-' . substr(md5(uniqid('', true)), 0, 6) . '@test.fr';

        $this->request('POST', '/api/me/email', $token, ['email' => $newEmail, 'currentPassword' => 'Password123!']);
        self::assertResponseIsSuccessful();
        // Un lien VIVANT au moment de l'effacement (même fabrique que le mail).
        $rawToken = $this->mintConfirmToken($userId);

        $this->request('DELETE', '/api/me', $token, ['password' => 'Password123!']);
        self::assertResponseIsSuccessful('le compte est effacé');

        // La route de confirmation est PUBLIQUE (le token EST la preuve) : on repart
        // d'une session vierge, sinon browser-kit rejoue le cookie du compte effacé
        // et le firewall répond 401 avant que le garde ne s'exprime.
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/me/email/confirm', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['token' => $rawToken], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400, 'un compte effacé ne se ressuscite pas par un lien');
        self::assertNull(
            $this->em()->getRepository(User::class)->findOneBy(['email' => $newEmail]),
            'l\'adresse réelle n\'a PAS été réécrite sur le compte anonymisé',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /** @return array<string, mixed> */
    private function me(string $token): array
    {
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** Login by credentials (SEC-16: 204 on success, the JWT is a cookie). Returns the status code. */
    private function login(string $email, string $password): int
    {
        $this->client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomIp(),
        ], json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
    }

    private function confirm(string $rawToken): void
    {
        $this->client->request('POST', '/api/me/email/confirm', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->randomIp(),
        ], json_encode(['token' => $rawToken], \JSON_THROW_ON_ERROR));
    }

    /**
     * Re-mint the RAW confirmation token (the emailed value is unavailable in
     * tests) for the user's CURRENT pending change — mirrors how
     * VerifiesRegistration re-mints the registration token via EmailVerifier.
     */
    private function mintConfirmToken(string $userId): string
    {
        $container = self::getContainer();
        $em = $this->em();
        $user = $em->getRepository(User::class)->find($userId);
        \assert($user instanceof User);
        $verifier = $container->get(EmailChangeVerifier::class);
        \assert($verifier instanceof EmailChangeVerifier);
        $raw = $verifier->generateToken($user);
        $em->flush();

        // Sanity: the token row exists for this user.
        \assert($em->getRepository(EmailChangeToken::class)->findOneBy(['user' => $user]) instanceof EmailChangeToken);

        return $raw;
    }

    private function randomIp(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, email]
     */
    private function register(string $ara): array
    {
        // High-entropy IP: the register rate-limiter lives in Redis and is NOT
        // rolled back between test runs, so deterministic IPs eventually throttle.
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'U', 'lastName' => 'Self', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $suffix . '@test.fr'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $uri, string $token, array $body = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], [] === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
