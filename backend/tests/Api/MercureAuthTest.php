<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Controller\MercureAuthController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * FRT-04 — le jeton de souscription Mercure (`GET /api/mercure/auth`).
 *
 * Le SCOPE de ce jeton est une frontière tenant : signé du secret du hub, il
 * autorise `subscribe` sur `club:{clubId}:schedule:{id}` — le club du MEMBRE
 * AUTHENTIFIÉ, jamais un paramètre client. Un jeton mal borné ouvrirait les
 * événements de génération d'un autre club (statuts, scores, warnings) à
 * n'importe quel compte : c'est l'axe tenant, donc phase1.
 */
#[Group('phase1')]
#[Group('integration')]
final class MercureAuthTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAnonymousIsRejected(): void
    {
        $this->client->request('GET', '/api/mercure/auth');

        self::assertSame(401, $this->client->getResponse()->getStatusCode(), 'sans JWT applicatif, aucun jeton hub');
    }

    public function testTheCookieIsScopedToTheAuthenticatedUsersClubOnly(): void
    {
        [$tokenA, $clubA] = $this->memberOfAFreshClub('a');
        [$tokenB, $clubB] = $this->memberOfAFreshClub('b');

        $cookieA = $this->fetchAuthCookie($tokenA);
        $cookieB = $this->fetchAuthCookie($tokenB);

        // Le claim `subscribe` est EXACTEMENT les deux topics du club du porteur (génération
        // + calcul des trajets, C6) — pas de wildcard global, pas le club du voisin.
        self::assertSame([\sprintf('club:%s:schedule:{id}', $clubA), \sprintf('club:%s:travel', $clubA)], $this->subscribeClaim($cookieA->getValue()));
        self::assertSame([\sprintf('club:%s:schedule:{id}', $clubB), \sprintf('club:%s:travel', $clubB)], $this->subscribeClaim($cookieB->getValue()));
        self::assertNotSame($clubA, $clubB);
    }

    /**
     * Défense en profondeur du sélecteur (revue sécurité FRT-04). Le listener
     * refuse déjà un club non canonique (`TenantIsolationTest`) ; ici on épingle
     * que MÊME si `_club_id` arrivait dégradé par un autre chemin, cette route ne
     * signerait pas un sélecteur à deux variables URI-template — celui qui, en
     * vivant, délivrait les événements de tous les clubs.
     */
    public function testANonCanonicalClubIdNeverReachesTheSelector(): void
    {
        // Le contrôleur est invoqué DIRECTEMENT avec un `_club_id` dégradé dans la
        // pile de requêtes : par le header, le listener l'arrêterait avant (403,
        // gardé par TenantIsolationTest) — le sujet ici est sa garde propre.
        $controller = self::getContainer()->get(MercureAuthController::class);
        $requestStack = self::getContainer()->get(RequestStack::class);
        $request = Request::create('/api/mercure/auth');
        $request->attributes->set('_club_id', '{710a290720ce40ffa67fc2674ca0dbe7}');
        $requestStack->push($request);

        try {
            $response = $controller();
        } finally {
            $requestStack->pop();
        }

        self::assertSame(400, $response->getStatusCode(), 'une forme non canonique ne doit JAMAIS être signée dans un sélecteur');
        self::assertStringNotContainsString('mercureAuthorization', (string) $response->headers);
    }

    public function testTheTokenIsSignedWithTheHubSecretAndDeliveredHttpOnlyToTheHubPath(): void
    {
        [$token, $clubId] = $this->memberOfAFreshClub('sig');

        $cookie = $this->fetchAuthCookie($token);

        // Signature vérifiée avec le secret du HUB : c'est elle qui fait autorité
        // côté Mercure — un jeton signé d'autre chose serait simplement ignoré.
        [$header, $payload, $signature] = explode('.', $cookie->getValue());
        $secret = (string) ($_ENV['MERCURE_JWT_SECRET'] ?? $_SERVER['MERCURE_JWT_SECRET'] ?? '');
        self::assertNotSame('', $secret, 'MERCURE_JWT_SECRET doit exister dans l’environnement de test');
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, $secret, true)), '+/', '-_'), '=');
        self::assertSame($expected, $signature, 'le jeton doit être signé HS256 du MÊME secret que le publieur');

        // httpOnly + path borné : jamais lisible par le JS (le localStorage est déjà
        // le point faible du JWT applicatif — on n'ajoute pas un second jeton exposé),
        // et le navigateur ne l'envoie qu'au hub, pas à l'API.
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());

        // La réponse expose le template comme TOPIC d'abonnement : le front ne
        // connaît pas son clubId (tenant résolu serveur), c'est sa seule source.
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(\sprintf('club:%s:schedule:{id}', $clubId), $body['topicTemplate'] ?? null);
        // C6 — champ additif : le topic FIXE du calcul des trajets.
        self::assertSame(\sprintf('club:%s:travel', $clubId), $body['travelTopic'] ?? null);
    }

    /**
     * SEC-16 — le flag `Secure` de CE cookie suit la configuration, pas le
     * protocole vu par PHP.
     *
     * Le nginx de production écoute en 80 derrière la terminaison TLS et réécrit
     * `X-Forwarded-Proto` avec `$scheme` : `$request->isSecure()` y répond FAUX,
     * et le cookie serait parti **sans `Secure`** — le jeton de souscription
     * lisible en clair par un attaquant réseau. Le test force l'inverse (requête
     * VUE comme https) et exige que le cookie ne suive PAS la requête : en
     * environnement de test `JWT_COOKIE_SECURE` vaut `false`, donc un contrôleur
     * qui interrogerait la requête rendrait ici un cookie `Secure` — et rougirait.
     */
    public function testTheCookieSecureFlagFollowsTheConfigurationNotTheRequestProtocol(): void
    {
        [$token] = $this->memberOfAFreshClub('proto');

        $this->client->request('GET', '/api/mercure/auth', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTPS' => 'on',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $candidate) {
            if ('mercureAuthorization' === $candidate->getName()) {
                $cookie = $candidate;
            }
        }
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertFalse(
            $cookie->isSecure(),
            'le flag doit venir de JWT_COOKIE_SECURE (false en test), jamais de $request->isSecure() — '
            . 'sinon la prod, où nginx écoute en 80, poserait le cookie sans Secure',
        );
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function fetchAuthCookie(string $token): Cookie
    {
        $this->client->request('GET', '/api/mercure/auth', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('mercureAuthorization' === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('la réponse doit poser le cookie mercureAuthorization');
    }

    /** @return list<string> le claim `mercure.subscribe` du JWT hub */
    private function subscribeClaim(string $jwt): array
    {
        [, $payload] = explode('.', $jwt);
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsArray($claims['mercure'] ?? null, 'le JWT hub doit porter le claim mercure');

        return $claims['mercure']['subscribe'] ?? [];
    }

    /** @return array{string, string} [JWT applicatif, clubId] d'un gestionnaire actif d'un club neuf */
    private function memberOfAFreshClub(string $tag): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club Mercure ' . $tag);
        $club->setSlug('club-mercure-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('MRC' . strtoupper(substr(md5($tag . $uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('mercure-' . $tag . $uid . '@test.com');
        $user->setFirstName('Mercure');
        $user->setLastName(ucfirst($tag));
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [self::getContainer()->get(JWTTokenManagerInterface::class)->create($user), $club->getId()];
    }
}
