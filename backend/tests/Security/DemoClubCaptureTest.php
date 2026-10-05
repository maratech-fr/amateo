<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubUser;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Service\TenantConnectionContext;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR d'axe « auth & memberships » / « tenant isolation » (§7.1) — BCK-33 : une VRAIE
 * inscription sur le code FFBB d'un club de DÉMONSTRATION n'entre JAMAIS dans la démo.
 *
 * L'unicité du code FFBB est partielle (`WHERE NOT is_demo`) et tous les chemins
 * d'inscription/approbation résolvent le club par `ClubRepository::findRealByFfbbCode`
 * (is_demo = false). Un `findOneBy(['ffbbClubCode' => …])` nu happerait la démo et ferait
 * du vrai inscrit un membre pending de l'espace vendeur — ce garde le falsifie : sur
 * l'ancien code, l'inscrit rejoignait la démo (même id) ; désormais il obtient son PROPRE
 * club réel, la démo restant intacte.
 *
 * P4-304 (axe auth & memberships) — les DURÉES et HORODATAGES de SÉCURITÉ ne suivent
 * JAMAIS l'horloge simulée d'un club démo. Le piège : TenantFilterListener pose `_club_id`
 * même sur une route PUBLIQUE quand un JWT est présent, donc un gestionnaire de club démo
 * (horloge simulée EXTRÊME, ici 2000-01-01) qui porte son JWT sur ces routes ferait
 * retourner la date simulée par `ClubClock::now()`. Trois gardes, chacune calée sur
 * `app.clock.real` :
 *   (a) un lien de vérification d'e-mail expiré HIER (réel) → `POST /api/register/verify`
 *       → 400 ; avec l'horloge décorée (2000) le lien paraîtrait encore valide ;
 *   (b) une demande de création de club expirée HIER (réel) → `GET`/`POST
 *       /api/club-approvals/{token}` → 410 ; avec l'horloge décorée elle paraîtrait vivante ;
 *   (c) une inscription sous JWT démo → `termsAcceptedAt` calé sur l'instant RÉEL
 *       (jamais 2000/2099 — preuve de consentement RGPD).
 * FALSIFICATION : réinjecter l'horloge décorée (`clock`) dans EmailVerifier /
 * ClubApprovalController / RegisterService fait rougir (a)/(b)/(c) respectivement.
 */
#[Group('phase1')]
#[Group('integration')]
final class DemoClubCaptureTest extends WebTestCase
{
    use VerifiesRegistration;

    private const string SIMULATED_TODAY = '2000-01-01';

    private KernelBrowser $client;

    public function testRealRegistrationDoesNotJoinADemoClubOnTheSameFfbbCode(): void
    {
        $code = 'ARA0000055';
        $demoClubId = $this->seedDemoClubWithActiveMember($code);

        [$token, $realClubId] = $this->register($code);

        self::assertNotSame($demoClubId, $realClubId, 'la vraie inscription ne rejoint PAS le club de démonstration');

        $me = $this->get('/api/me', $token);
        self::assertSame('active', $me['membershipStatus'], 'le vrai inscrit est gestionnaire de SON club réel');
        self::assertFalse($me['club']['isDemo'] ?? true, 'le club du vrai inscrit n\'est pas une démo');
        self::assertSame($code, $me['club']['ffbbClubCode'] ?? null, 'son club réel porte bien le code FFBB saisi');

        // La démo survit, intacte.
        $em = $this->em();
        $demo = $em->getRepository(Club::class)->find($demoClubId);
        self::assertInstanceOf(Club::class, $demo);
        self::assertTrue($demo->isDemo(), 'la démo reste une démo, non capturée');
    }

    public function testEmailVerificationTtlUsesTheRealClockUnderASimulatedDemoClock(): void
    {
        $demoJwt = $this->seedDemoManagerWithSimulatedClock();
        // Un lien de vérification d'un AUTRE utilisateur, expiré HIER (réel). Sous
        // l'horloge décorée du club démo (2000-01-01) il paraîtrait encore valide.
        $raw = $this->seedExpiredVerificationTokenForAnotherUser();

        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $this->client->request('POST', '/api/register/verify', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $demoJwt,
        ], json_encode(['token' => $raw], \JSON_THROW_ON_ERROR));

        self::assertSame(400, $this->client->getResponse()->getStatusCode(), 'lien expiré à l\'heure RÉELLE → 400, jamais prolongé par la date simulée du club démo');
    }

    public function testClubApprovalExpiryUsesTheRealClockUnderASimulatedDemoClock(): void
    {
        $demoJwt = $this->seedDemoManagerWithSimulatedClock();
        $token = $this->seedExpiredClubCreationRequest();

        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $this->client->request('GET', '/api/club-approvals/' . $token, [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $demoJwt,
        ]);
        self::assertSame(410, $this->client->getResponse()->getStatusCode(), 'demande expirée à l\'heure RÉELLE → 410 sur GET, jamais ranimée par la date simulée du club démo');

        $this->client->request('POST', '/api/club-approvals/' . $token, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $demoJwt,
        ], json_encode(['decision' => 'approve'], \JSON_THROW_ON_ERROR));
        self::assertSame(410, $this->client->getResponse()->getStatusCode(), 'demande expirée → 410 sur POST aussi');
    }

    public function testRegistrationConsentTimestampUsesTheRealClockUnderASimulatedDemoClock(): void
    {
        $demoJwt = $this->seedDemoManagerWithSimulatedClock();

        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'consent' . substr(md5(uniqid('', true)), 0, 6);
        $email = $suffix . '@test.fr';
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
            // JWT du gestionnaire démo → TenantFilterListener pose `_club_id` même sur
            // cette route publique, l'horloge décorée simulerait 2000-01-01.
            'HTTP_AUTHORIZATION' => 'Bearer ' . $demoJwt,
        ], json_encode([
            'email' => $email, 'password' => 'Password123!',
            'firstName' => 'Con', 'lastName' => 'Sent', 'ara' => strtoupper($suffix), 'club_name' => 'Club consent', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $em = $this->em();
        $em->clear();
        $created = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $created);
        $acceptedAt = $created->getTermsAcceptedAt();
        self::assertInstanceOf(DateTimeImmutable::class, $acceptedAt, 'preuve de consentement horodatée');
        $realNow = new DateTimeImmutable('now');
        self::assertLessThan(300, abs($acceptedAt->getTimestamp() - $realNow->getTimestamp()), 'termsAcceptedAt calé sur l\'heure RÉELLE, jamais sur la date simulée du club démo (2000-01-01)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** Club DÉMO (is_demo) à `simulated_today` posé + gestionnaire actif ; rend son JWT. */
    private function seedDemoManagerWithSimulatedClock(): string
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        $uid = substr(md5(uniqid('', true)), 0, 8);

        $club = new Club;
        $club->setName('Démo horloge ' . $uid);
        $club->setSlug('demo-horloge-' . $uid);
        $club->setIsDemo(true);
        $club->setSimulatedToday(new DateTimeImmutable(self::SIMULATED_TODAY));
        $em->persist($club);

        $manager = new User;
        $manager->setEmail('demo-mgr-' . $uid . '@test.fr');
        $manager->setFirstName('Démo');
        $manager->setLastName('Gestionnaire');
        $manager->setPasswordHash($hasher->hashPassword($manager, 'Password123!'));
        $em->persist($manager);
        $em->flush();

        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        \assert($tenant instanceof TenantConnectionContext);
        $tenant->setClubId($club->getId());
        try {
            $membership = new ClubUser;
            $membership->setClubId($club->getId());
            $membership->setUserId($manager->getId());
            $membership->setRole('manager');
            $membership->setIsActive(true);
            $em->persist($membership);
            $em->flush();
        } finally {
            $tenant->clear();
        }

        $jwtManager = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($jwtManager instanceof JWTTokenManagerInterface);
        $jwt = $jwtManager->create($manager);
        $em->clear();

        return $jwt;
    }

    /** Lien de vérification d'un AUTRE utilisateur, expiré HIER à l'heure réelle ; rend le token BRUT. */
    private function seedExpiredVerificationTokenForAnotherUser(): string
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        $uid = substr(md5(uniqid('', true)), 0, 8);

        $other = new User;
        $other->setEmail('pending-' . $uid . '@test.fr');
        $other->setFirstName('En');
        $other->setLastName('Attente');
        $other->setPasswordHash($hasher->hashPassword($other, 'Password123!'));
        $em->persist($other);
        $em->flush();

        $raw = bin2hex(random_bytes(32));
        $realNow = new DateTimeImmutable('now');
        $token = new EmailVerificationToken(
            $other,
            hash('sha256', $raw),
            $realNow->modify('-1 day'),
            $realNow->modify('-2 days'),
            'ARA' . substr($uid, 0, 7),
            'Club en attente',
        );
        $em->persist($token);
        $em->flush();
        $em->clear();

        return $raw;
    }

    /** Demande de création de club PENDING, expirée HIER à l'heure réelle ; rend son token public. */
    private function seedExpiredClubCreationRequest(): string
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        $uid = substr(md5(uniqid('', true)), 0, 8);

        $requester = new User;
        $requester->setEmail('requester-' . $uid . '@test.fr');
        $requester->setFirstName('Deman');
        $requester->setLastName('Deur');
        $requester->setPasswordHash($hasher->hashPassword($requester, 'Password123!'));
        $em->persist($requester);
        $em->flush();

        $request = new ClubCreationRequest;
        $request->setUserId($requester->getId());
        $request->setAra('ARA' . substr($uid, 0, 7));
        $request->setClubName('Club à approuver');
        $request->setExpiresAt(new DateTimeImmutable('now')->modify('-1 day'));
        $em->persist($request);
        $em->flush();
        $token = $request->getToken();
        $em->clear();

        return $token;
    }

    private function seedDemoClubWithActiveMember(string $code): string
    {
        $em = $this->em();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $club = new Club;
        $club->setName('Démo capture');
        $club->setSlug('demo-capture-' . substr(md5(uniqid('', true)), 0, 8));
        $club->setFfbbClubCode($code);
        $club->setIsDemo(true);
        $em->persist($club);

        $animator = new User;
        $animator->setEmail('demo-animator-' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr');
        $animator->setFirstName('Démo');
        $animator->setLastName('Animateur');
        $animator->setPasswordHash($hasher->hashPassword($animator, 'Password123!'));
        $em->persist($animator);
        // Club (hors RLS) + User (global) flushés d'abord ; le club_user ensuite,
        // sous le GUC tenant du club (RLS WITH CHECK).
        $em->flush();

        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        \assert($tenant instanceof TenantConnectionContext);
        $tenant->setClubId($club->getId());
        try {
            $membership = new ClubUser;
            $membership->setClubId($club->getId());
            $membership->setUserId($animator->getId());
            $membership->setRole('manager');
            $membership->setIsActive(true);
            $em->persist($membership);
            $em->flush();
        } finally {
            $tenant->clear();
        }

        $clubId = $club->getId();
        $em->clear();

        return $clubId;
    }

    /**
     * @return array{0: string, 1: string} [token, clubId]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'realowner' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'Real', 'lastName' => 'Owner', 'ara' => $ara, 'club_name' => 'Vrai club', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $me = $this->get('/api/me', $token);

        return [$token, $me['club']['id']];
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $uri, string $token, array $body = []): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], [] === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function get(string $uri, string $token): array
    {
        $this->request('GET', $uri, $token);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
