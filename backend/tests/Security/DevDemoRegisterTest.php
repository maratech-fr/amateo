<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\DevDemoRegisterController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Repository\ClubRepository;
use App\Repository\EmailVerificationTokenRepository;
use App\Security\JwtCookieFactory;
use App\Service\AuditTrail;
use App\Service\DemoClubMaterializer;
use App\Service\PasswordPolicy;
use App\Tests\ReadsJwtCookie;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P2-4 — NR d'axe « auth & memberships » (§7.1) : le RACCOURCI DÉMO du register
 * (DevDemoRegisterController), falsifié DANS LES DEUX SENS.
 *
 * Sens « ça fait ce que ça annonce » : après le 202 neutre du register, la route
 * démo crée un club is_demo peuplé, membership MANAGER active UNIQUE, cookie JWT
 * posé, compte vérifié ; un 2e passage DÉTRUIT le club démo précédent EN BASE
 * (ligne club ET adhésion absentes — l'effet est mesuré en base) et en crée un neuf,
 * et le mot de passe retapé authentifie.
 *
 * Sens « ça ne peut pas détruire le réel » : un ARA tenu par un club NON-démo → 409
 * données byte-intactes ; un club lié non-démo → refus sans destruction ; un club
 * démo PARTAGÉ (cas BCCL) → refus ; une adresse ≠ adresse démo → 422 sans effet ;
 * hors debug SANS fenêtre ouverte → 422 not_demo_account (plus un 404 — la fenêtre
 * d'activation garde désormais la route en prod ; couverture complète dans
 * DemoWindowTest). Et le rail register n'a fait qu'AJOUTER un champ à sa config.
 *
 * ⚠ N'est PAS un step bloquant (arbitrage fondateur) : tourne dans `unit-tests`.
 */
#[Group('integration')]
final class DevDemoRegisterTest extends WebTestCase
{
    use ReadsJwtCookie;
    use StartsFreshBrowserSession;
    use TenantGucTrait;

    private const string DEMO_EMAIL = 'demo@amateo.fr';

    private static int $ipCounter = 0;

    /** SENS 1 — le raccourci matérialise le club démo, prêt au login, cookie posé. */
    public function testDemoShortcutMaterialisesTheClubReadyToLogIn(): void
    {
        $client = self::createClient();
        $ara = $this->freshAra();

        $this->register($client, self::DEMO_EMAIL, $ara, 'Prospect Basket');

        $this->startFreshBrowserSession($client);
        [$status, $body] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Password123!', 'ara' => $ara, 'clubName' => 'Prospect Basket']);

        self::assertSame(200, $status, 'le raccourci démo doit répondre 200 avec la session ouverte');
        self::assertSame('active', $body['membershipStatus'] ?? null);
        $clubId = $body['clubId'] ?? null;
        self::assertIsString($clubId);
        self::assertNotSame('', $this->jwtFromCookie($client), 'un cookie JWT doit être posé (session ouverte sans détour par verify)');

        $this->em()->clear();
        $club = $this->em()->getRepository(Club::class)->find($clubId);
        self::assertInstanceOf(Club::class, $club);
        self::assertTrue($club->isDemo(), 'le club naît is_demo');
        self::assertFalse($club->getOnboardingCompleted(), 'non onboardé : le wizard guidé EST la démo');

        $animator = $this->em()->getRepository(User::class)->findOneBy(['email' => self::DEMO_EMAIL]);
        self::assertInstanceOf(User::class, $animator);
        self::assertNotNull($animator->getEmailVerifiedAt(), 'le raccourci court-circuite le lien e-mail : le compte est réputé vérifié');

        // Adhésion MANAGER active UNIQUE (raw DBAL — club_user se lit cross-tenant).
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT club_id, role, is_active FROM club_user WHERE user_id = :uid',
            ['uid' => $animator->getId()],
        );
        self::assertCount(1, $rows, 'une seule adhésion : l\'animateur est DÉPLACÉ, jamais multi-adhérent');
        self::assertSame($clubId, $rows[0]['club_id']);
        self::assertSame('admin', $rows[0]['role'], 'MANAGER');
        self::assertTrue((bool) $rows[0]['is_active']);

        // Saison présente = login immédiat (season est RLS → lire sous le GUC du club).
        $this->scopeGucToClub($clubId);
        $seasonCount = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM season WHERE club_id = :cid', ['cid' => $clubId]);
        $this->clearGuc();
        self::assertSame(1, $seasonCount, 'la saison courante existe — login immédiat');
    }

    /** SENS 1 (2e passage) — l'ancien club démo est DÉTRUIT en base, le nouveau naît, le mot de passe PROUVÉ authentifie. */
    public function testSecondDemoReplacesThePreviousClubAndTheProvenPasswordAuthenticates(): void
    {
        $client = self::createClient();

        $araA = $this->freshAra();
        $this->register($client, self::DEMO_EMAIL, $araA, 'Prospect A');
        $this->startFreshBrowserSession($client);
        [, $bodyA] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Password123!', 'ara' => $araA, 'clubName' => 'Prospect A']);
        $oldClubId = $bodyA['clubId'];
        self::assertIsString($oldClubId);

        $animatorId = $this->userIdByEmail(self::DEMO_EMAIL);
        $hashBefore = $this->passwordHashByEmail(self::DEMO_EMAIL);

        // 2e passage : autre ARA, MÊME mot de passe (il est VÉRIFIÉ, jamais réécrit — la
        // route n'est plus un rail de réinitialisation ; le raccourci prouve l'identité).
        $araB = $this->freshAra();
        $this->startFreshBrowserSession($client);
        [$status, $bodyB] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Password123!', 'ara' => $araB, 'clubName' => 'Prospect B']);
        self::assertSame(200, $status);
        $newClubId = $bodyB['clubId'];
        self::assertIsString($newClubId);
        self::assertNotSame($oldClubId, $newClubId);

        $this->em()->clear();
        // L'ANCIEN club est détruit EN BASE (ligne club ET adhésion) — l'effet est mesuré ici, pas au site d'appel.
        self::assertNull($this->em()->getRepository(Club::class)->find($oldClubId), 'la ligne du club démo précédent est SUPPRIMÉE (code FFBB libéré)');
        self::assertInstanceOf(Club::class, $this->em()->getRepository(Club::class)->find($newClubId));
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT club_id FROM club_user WHERE user_id = :uid', ['uid' => $animatorId]);
        self::assertCount(1, $rows, 'une seule adhésion, sur le NOUVEAU club');
        self::assertSame($newClubId, $rows[0]['club_id']);

        // Le hash n'a PAS bougé (aucune réécriture) et le mot de passe prouvé authentifie.
        self::assertSame($hashBefore, $this->passwordHashByEmail(self::DEMO_EMAIL), 'le hash du compte existant n\'est JAMAIS réécrit');
        // Depuis la fenêtre d'activation démo, /api/login est GARDÉ pour un compte démo :
        // on ouvre la fenêtre pour prouver via login que le mot de passe (jamais réécrit)
        // authentifie encore. Le raccourci lui-même a déjà ouvert une session par cookie ;
        // ici on éprouve le rail /api/login. (La garde fenêtre : DemoWindowTest.)
        $this->em()->getConnection()->executeStatement(
            'UPDATE app_user SET demo_active_until = now() + interval \'1 day\' WHERE email = :email',
            ['email' => self::DEMO_EMAIL],
        );
        $this->startFreshBrowserSession($client);
        $client->request('POST', '/api/login', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode(['email' => self::DEMO_EMAIL, 'password' => 'Password123!'], \JSON_THROW_ON_ERROR));
        // 204 = login réussi (le jeton part en cookie, corps vide) ; un mauvais mot de passe rendrait 401.
        self::assertSame(204, $client->getResponse()->getStatusCode(), 'le mot de passe prouvé doit authentifier');
    }

    /**
     * SENS 2 (C-1) — un MAUVAIS mot de passe (mais valide en politique) sur le compte
     * démo EXISTANT → 401, et RIEN n'est touché : hash inchangé, compte non re-vérifié,
     * jeton de vérification toujours présent, aucun club créé/détruit. Le raccourci
     * n'écrase JAMAIS le mot de passe d'un compte qui existe déjà.
     */
    public function testWrongPasswordOnExistingAccountIsRejectedWithoutEffect(): void
    {
        $client = self::createClient();

        // Le register crée le compte démo (non vérifié, jeton pendant) avec Password123!.
        $this->register($client, self::DEMO_EMAIL, $this->freshAra(), 'Prospect');
        $animatorId = $this->userIdByEmail(self::DEMO_EMAIL);
        $hashBefore = $this->passwordHashByEmail(self::DEMO_EMAIL);
        self::assertNull($this->emailVerifiedAt($animatorId), 'après register : compte NON vérifié');
        self::assertSame(1, $this->verificationTokenCount($animatorId), 'après register : un jeton de vérification pendant');
        $clubsBefore = $this->rowCount('club');

        // Autre mot de passe, valide en politique mais FAUX pour ce compte.
        $this->startFreshBrowserSession($client);
        [$status, $body] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Wr0ngButValid!x', 'ara' => $this->freshAra(), 'clubName' => 'Squat']);

        self::assertSame(401, $status, 'un mot de passe faux sur le compte démo existant → 401');
        self::assertSame('invalid_credentials', $body['error'] ?? null);
        self::assertSame($clubsBefore, $this->rowCount('club'), 'aucun club créé ni détruit');
        self::assertSame($hashBefore, $this->passwordHashByEmail(self::DEMO_EMAIL), 'le hash n\'est PAS réécrit');
        self::assertNull($this->emailVerifiedAt($animatorId), 'le compte n\'est PAS forcé vérifié');
        self::assertSame(1, $this->verificationTokenCount($animatorId), 'le jeton de vérification survit (aucun nettoyage avant la preuve)');
    }

    /**
     * SENS 2 (F-2) — un mot de passe ABSENT → 400 (jamais un 500 sur un `new User` sans
     * hash), et un mot de passe TROP FAIBLE → 400 (même politique que /api/register).
     */
    public function testMissingOrWeakPasswordIsA400(): void
    {
        $client = self::createClient();
        $clubsBefore = $this->rowCount('club');

        $this->startFreshBrowserSession($client);
        [$statusMissing, $bodyMissing] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => '', 'ara' => $this->freshAra(), 'clubName' => 'X']);
        self::assertSame(400, $statusMissing, 'mot de passe absent → 400 (jamais un 500)');
        self::assertArrayHasKey('error', $bodyMissing);

        $this->startFreshBrowserSession($client);
        [$statusWeak] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'short', 'ara' => $this->freshAra(), 'clubName' => 'X']);
        self::assertSame(400, $statusWeak, 'mot de passe trop faible → 400');

        self::assertSame($clubsBefore, $this->rowCount('club'), 'aucun effet');
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => self::DEMO_EMAIL]), 'aucun compte démo créé');
    }

    /**
     * SENS 2 (E-1) — le code FFBB visé est tenu par un club démo d'un AUTRE animateur
     * (faute de frappe : cas « Démo Basket Club » ARA9999999). La garde ARA élargie
     * refuse 409 AVANT tout teardown : le club démo de l'AUTRE survit ET le propre club
     * démo de l'animateur aussi (l'ancienne destruction non atomique le rasait avant de
     * heurter l'index unique → 500).
     */
    public function testAraHeldByAnotherAnimatorsDemoIsRefusedAndBothDemosSurvive(): void
    {
        $client = self::createClient();

        // L'animateur a DÉJÀ son propre club démo (sur araA).
        $araA = $this->freshAra();
        $this->register($client, self::DEMO_EMAIL, $araA, 'Prospect A');
        $this->startFreshBrowserSession($client);
        [, $bodyA] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Password123!', 'ara' => $araA, 'clubName' => 'Prospect A']);
        $ownClubId = $bodyA['clubId'];
        self::assertIsString($ownClubId);

        // Un AUTRE animateur tient un club démo sur araX.
        $otherUserId = $this->seedUser('autre-animateur@test.local');
        $araX = $this->freshAra();
        $otherDemoId = $this->seedClub($araX, 'Démo Autre', isDemo: true, memberUserId: $otherUserId);

        // L'animateur vise araX (faute de frappe) → 409, rien détruit.
        $this->startFreshBrowserSession($client);
        [$status, $body] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Password123!', 'ara' => $araX, 'clubName' => 'Collision']);

        self::assertSame(409, $status, 'un ARA tenu par le club démo d\'un autre animateur → 409');
        self::assertSame('ara_taken', $body['error'] ?? null, 'la garde ARA élargie couvre le club démo d\'autrui');
        $this->em()->clear();
        self::assertInstanceOf(Club::class, $this->em()->getRepository(Club::class)->find($otherDemoId), 'le club démo de l\'autre animateur survit');
        self::assertInstanceOf(Club::class, $this->em()->getRepository(Club::class)->find($ownClubId), 'le propre club démo de l\'animateur survit (aucune destruction non atomique)');
    }

    /** SENS 2 — un ARA tenu par un club NON-démo → 409, données byte-intactes, aucun compte démo créé. */
    public function testAraHeldByRealClubIsRefusedWithoutTouchingAnything(): void
    {
        $client = self::createClient();
        $ara = $this->freshAra();
        $realClubId = $this->seedClub($ara, 'Vrai Club', isDemo: false);

        $clubsBefore = $this->rowCount('club');
        $membersBefore = $this->rowCount('club_user');

        $this->startFreshBrowserSession($client);
        [$status, $body] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Password123!', 'ara' => $ara, 'clubName' => 'Squat']);

        self::assertSame(409, $status, 'un ARA tenu par un club réel est refusé (409)');
        self::assertSame('ara_taken', $body['error'] ?? null, 'code stable, message générique (aucun nom de club divulgué)');
        self::assertSame($clubsBefore, $this->rowCount('club'), 'aucun club créé ni détruit');
        self::assertSame($membersBefore, $this->rowCount('club_user'), 'aucune adhésion touchée');
        self::assertInstanceOf(Club::class, $this->em()->getRepository(Club::class)->find($realClubId), 'le vrai club est intact');
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => self::DEMO_EMAIL]), 'le 409 tombe AVANT toute résolution/création de l\'animateur');
    }

    /** SENS 2 — l'animateur lié à un club NON-démo : le teardown REFUSE, rien détruit. */
    public function testTeardownRefusesANonDemoLinkedClub(): void
    {
        $client = self::createClient();

        $this->register($client, self::DEMO_EMAIL, $this->freshAra(), 'Peu importe');
        $animatorId = $this->userIdByEmail(self::DEMO_EMAIL);
        $hashBefore = $this->passwordHashByEmail(self::DEMO_EMAIL);

        // L'animateur devient membre d'un club NON-démo (scénario de sûreté).
        $realClubId = $this->seedClub($this->freshAra(), 'Club Réel Lié', isDemo: false, memberUserId: $animatorId);

        $this->startFreshBrowserSession($client);
        [$status, $body] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Password123!', 'ara' => $this->freshAra(), 'clubName' => 'Nouveau']);

        self::assertSame(409, $status, 'un club lié non-démo fait échouer le teardown (409)');
        self::assertSame('teardown_refused', $body['error'] ?? null, 'cause distincte de la garde ARA, message générique (pas de nom de club)');
        self::assertInstanceOf(Club::class, $this->em()->getRepository(Club::class)->find($realClubId), 'le club réel lié est intact');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM club_user WHERE club_id = :cid', ['cid' => $realClubId]), 'l\'adhésion survit');

        // E-2 — le 409 laisse le COMPTE intact : la validation du teardown précède toute
        // écriture (hash inchangé, compte non re-vérifié, jeton toujours présent).
        self::assertSame($hashBefore, $this->passwordHashByEmail(self::DEMO_EMAIL), 'le hash du compte est inchangé');
        self::assertNull($this->emailVerifiedAt($animatorId), 'le compte n\'est PAS forcé vérifié avant le refus');
        self::assertSame(1, $this->verificationTokenCount($animatorId), 'le jeton de vérification survit (aucun nettoyage avant le refus)');
    }

    /** SENS 2 — un club démo PARTAGÉ (un autre membre : cas BCCL) : le teardown REFUSE. */
    public function testTeardownRefusesASharedDemoClub(): void
    {
        $client = self::createClient();

        $this->register($client, self::DEMO_EMAIL, $this->freshAra(), 'Peu importe');
        $animatorId = $this->userIdByEmail(self::DEMO_EMAIL);
        $otherUserId = $this->seedUser('autre-membre@test.local');

        // Club démo dont l'animateur est membre, MAIS partagé avec un autre membre.
        $sharedClubId = $this->seedClub($this->freshAra(), 'Démo Partagé', isDemo: true, memberUserId: $animatorId);
        $this->scopeGucToClub($sharedClubId);
        $other = (new ClubUser)->setClubId($sharedClubId)->setUserId($otherUserId)->setIsActive(true);
        $other->setRole(ClubRole::MEMBER->value);
        $this->em()->persist($other);
        $this->em()->flush();
        $this->clearGuc();

        $this->startFreshBrowserSession($client);
        [$status, $body] = $this->demoRegister($client, ['email' => self::DEMO_EMAIL, 'password' => 'Password123!', 'ara' => $this->freshAra(), 'clubName' => 'Nouveau']);

        self::assertSame(409, $status, 'un club démo partagé est protégé (409)');
        self::assertSame('teardown_refused', $body['error'] ?? null, 'cause teardown, message générique');
        self::assertInstanceOf(Club::class, $this->em()->getRepository(Club::class)->find($sharedClubId), 'le club démo partagé est intact');
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM club_user WHERE club_id = :cid', ['cid' => $sharedClubId]), 'les deux adhésions survivent');
    }

    /** SENS 2 — une adresse ≠ adresse démo → 422 sans le moindre effet (inoffensif pour les e2e register). */
    public function testNonDemoEmailIsA422WithoutEffect(): void
    {
        $client = self::createClient();
        $clubsBefore = $this->rowCount('club');

        $this->startFreshBrowserSession($client);
        [$status, $body] = $this->demoRegister($client, ['email' => 'quelquun@club.fr', 'password' => 'Password123!', 'ara' => $this->freshAra(), 'clubName' => 'X']);

        self::assertSame(422, $status);
        self::assertSame('not_demo_account', $body['error'] ?? null);
        self::assertSame($clubsBefore, $this->rowCount('club'), 'aucun effet');
    }

    /**
     * SENS 2 — hors debug, la route N'EST PLUS un 404 : elle est gardée par la FENÊTRE
     * d'activation du compte animateur. SANS fenêtre ouverte (ici l'animateur n'existe
     * même pas), une adresse démo reçoit le MÊME 422 not_demo_account qu'une adresse
     * quelconque — aucun oracle, jamais « fenêtre fermée ». Contrôleur instancié avec
     * debug:false. (La fenêtre OUVERTE et la byte-identité complète : DemoWindowTest.).
     */
    public function testRouteOutsideDebugIsGatedByTheWindowNotA404(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        $controller = new DevDemoRegisterController(
            $c->get(EntityManagerInterface::class),
            $c->get(UserPasswordHasherInterface::class),
            $c->get(ClubRepository::class),
            $c->get(EmailVerificationTokenRepository::class),
            $c->get(DemoClubMaterializer::class),
            $c->get(JWTTokenManagerInterface::class),
            $c->get(JwtCookieFactory::class),
            $c->get('limiter.auth_register'),
            $c->get(ClockInterface::class),
            $c->get(PasswordPolicy::class),
            $c->get(AuditTrail::class),
            null,
            debug: false,
            demoAnimatorEmail: self::DEMO_EMAIL,
        );
        // Le chemin non-debug atteint $this->json() (contrairement à l'ancien 404) :
        // le contrôleur instancié à la main a besoin de son conteneur.
        $controller->setContainer($c);

        $request = Request::create('/api/dev/demo-register', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.55.' . random_int(0, 254) . '.' . random_int(1, 254),
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'email' => self::DEMO_EMAIL, 'password' => 'Password123!',
            'ara' => $this->freshAra(), 'clubName' => 'Hors fenêtre',
        ], \JSON_THROW_ON_ERROR));

        $response = $controller($request);

        self::assertSame(422, $response->getStatusCode(), 'hors debug sans fenêtre ouverte : 422, jamais 404');
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('not_demo_account', \is_array($body) ? ($body['error'] ?? null) : null);
    }

    /** Neutralité du rail réel : register/config n'a fait qu'AJOUTER `demoShortcut`. */
    public function testRegisterConfigOnlyAddedAField(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/register/config', [], [], ['REMOTE_ADDR' => $this->nextIp()]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('turnstileSiteKey', $body, 'le champ historique reste');
        self::assertArrayHasKey('demoShortcut', $body, 'le champ ADDITIF est là');
        // P2-4 (revue sécu) : le front n'appelle le raccourci QUE si l'adresse saisie EST
        // l'adresse démo — il la connaît via `demoEmail`, exposée SEULEMENT en debug (pas
        // d'oracle en prod, où elle est nulle avec demoShortcut=false).
        self::assertArrayHasKey('demoEmail', $body, 'le champ ADDITIF demoEmail est là');
        self::assertSame(['turnstileSiteKey', 'demoShortcut', 'demoEmail'], array_keys($body), 'RIEN d\'autre n\'a changé dans la config');
        // En test (kernel.debug=true), l'adresse démo est exposée pour le garde front.
        self::assertSame(self::DEMO_EMAIL, $body['demoEmail'] ?? null, 'en debug, l\'adresse démo est exposée au front');
    }

    // --- Helpers -----------------------------------------------------------

    private function register(KernelBrowser $client, string $email, string $ara, string $clubName): void
    {
        $this->startFreshBrowserSession($client);
        $client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Démo', 'lastName' => 'Amateo',
            'ara' => $ara, 'club_name' => $clubName, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(202, $client->getResponse()->getStatusCode(), 'le register nominal répond 202');
    }

    /**
     * @param array{email: string, password: string, ara: string, clubName: string} $payload
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function demoRegister(KernelBrowser $client, array $payload): array
    {
        $client->request('POST', '/api/dev/demo-register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->nextIp(),
        ], (string) json_encode($payload, \JSON_THROW_ON_ERROR));
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        return [$client->getResponse()->getStatusCode(), \is_array($body) ? $body : []];
    }

    /** Persist a club (hors RLS), optionally with an active MANAGER membership. */
    private function seedClub(string $ara, string $name, bool $isDemo, ?string $memberUserId = null): string
    {
        $club = (new Club)->setName($name)->setSlug(strtolower($name) . '-' . bin2hex(random_bytes(4)))->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setFfbbClubCode($ara);
        $club->setIsDemo($isDemo);
        $this->em()->persist($club);
        $this->em()->flush();

        if (null !== $memberUserId) {
            $this->scopeGucToClub($club->getId());
            $membership = (new ClubUser)->setClubId($club->getId())->setUserId($memberUserId)->setIsActive(true);
            $membership->setRole(ClubRole::MANAGER->value);
            $this->em()->persist($membership);
            $this->em()->flush();
            $this->clearGuc();
        }

        return $club->getId();
    }

    private function seedUser(string $email): string
    {
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Autre');
        $user->setLastName('Membre');
        $user->setPasswordHash('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user->getId();
    }

    private function userIdByEmail(string $email): string
    {
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user->getId();
    }

    private function passwordHashByEmail(string $email): string
    {
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user->getPasswordHash();
    }

    private function emailVerifiedAt(string $userId): ?string
    {
        return $this->em()->getConnection()->fetchOne(
            'SELECT email_verified_at FROM app_user WHERE id = :id',
            ['id' => $userId],
        ) ?: null;
    }

    private function verificationTokenCount(string $userId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM email_verification_token WHERE user_id = :uid',
            ['uid' => $userId],
        );
    }

    private function rowCount(string $table): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function freshAra(): string
    {
        return 'ZZ' . str_pad((string) random_int(0, 99_999_999), 8, '0', \STR_PAD_LEFT);
    }

    private function nextIp(): string
    {
        $ip = '10.9.' . intdiv(self::$ipCounter, 254) . '.' . (self::$ipCounter % 254 + 1);
        ++self::$ipCounter;

        return $ip;
    }
}
