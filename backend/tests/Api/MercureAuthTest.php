<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Controller\MercureAuthController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Mercure\MercureTopic;
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
 *
 * Mercure 1.0 (lot 4 PR C) — le jeton est désormais un ACCESS TOKEN RFC 9068 : en-tête
 * `typ: at+jwt`, autorisations portées par le claim `authorization_details` (RFC 9396) au lieu
 * du claim `mercure.subscribe` legacy. Chaque entrée porte `type`, `actions` (`subscribe`) et
 * `topics` (objets `{match: …}` / `{match: …, match_type: "urlpattern"}`). Le SCOPE reste la
 * frontière tenant : exactement les trois sélecteurs du club du porteur, jamais ceux d'un autre.
 * Le NOM du cookie (prod `__Secure-…` vs dev prefix-less sur HTTP) est une décision encore
 * OUVERTE (cf. rapport TANK) : ce test lit le cookie par son CHEMIN, pas par son nom.
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

        // Les topics AUTORISÉS À S'ABONNER (lus du claim `authorization_details` 1.0) sont
        // EXACTEMENT les trois sélecteurs du club du porteur (génération + calcul des trajets,
        // C6 + placement async) — pas de wildcard global, pas le club du voisin. On compare aux
        // sélecteurs produits par le FOYER unique `MercureTopic` : si la forme du gabarit de
        // génération change (p.ex. `{id}` → `:id` en URLPattern 1.0), le test suit sans réécriture.
        // L'égalité (indépendante de l'ordre : regrouper les topics exacts et le gabarit URLPattern
        // en une ou plusieurs entrées `authorization_details` reste un détail d'implémentation) EST
        // le test négatif inter-club : le topic placement de A ne figure jamais dans le claim de B.
        self::assertEqualsCanonicalizing(
            [MercureTopic::selectorForClub($clubA), MercureTopic::forTravel($clubA), MercureTopic::forPlacement($clubA)],
            $this->subscribeClaim($cookieA->getValue()),
        );
        self::assertEqualsCanonicalizing(
            [MercureTopic::selectorForClub($clubB), MercureTopic::forTravel($clubB), MercureTopic::forPlacement($clubB)],
            $this->subscribeClaim($cookieB->getValue()),
        );
        self::assertNotSame($clubA, $clubB);
        // Explicite : le topic placement d'un club n'est JAMAIS autorisé à l'autre.
        self::assertNotContains(MercureTopic::forPlacement($clubB), $this->subscribeClaim($cookieA->getValue()));
        self::assertNotContains(MercureTopic::forPlacement($clubA), $this->subscribeClaim($cookieB->getValue()));
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
        foreach ($response->headers->getCookies() as $candidate) {
            self::assertNotSame('/.well-known/mercure', $candidate->getPath(), 'aucun jeton de souscription ne doit être posé sur un 400');
        }
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

        // Mercure 1.0 — en-tête `typ: at+jwt` (RFC 9068) : un hub 1.0 refuse (401) un access
        // token sans ce type. C'est la marque du protocole, lisible dès l'en-tête JWT.
        $headerClaims = json_decode((string) base64_decode(strtr($header, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($headerClaims);
        self::assertSame('at+jwt', $headerClaims['typ'] ?? null, 'le jeton de souscription 1.0 doit porter l’en-tête typ=at+jwt');

        // httpOnly + path borné : jamais lisible par le JS (le localStorage est déjà
        // le point faible du JWT applicatif — on n'ajoute pas un second jeton exposé),
        // et le navigateur ne l'envoie qu'au hub, pas à l'API.
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());

        // La réponse expose le template comme TOPIC d'abonnement : le front ne
        // connaît pas son clubId (tenant résolu serveur), c'est sa seule source.
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        // Comparé au FOYER `MercureTopic` : le sélecteur de génération suit sa forme 1.0
        // (URLPattern) sans que ce test ait à connaître `{id}` vs `:id`.
        self::assertSame(MercureTopic::selectorForClub($clubId), $body['topicTemplate'] ?? null);
        // C6 — champ additif : le topic FIXE du calcul des trajets.
        self::assertSame(MercureTopic::forTravel($clubId), $body['travelTopic'] ?? null);
        // Champ additif : le topic FIXE du placement async des matchs.
        self::assertSame(MercureTopic::forPlacement($clubId), $body['placementTopic'] ?? null);
    }

    /**
     * Mercure 1.0 — le jeton est un ACCESS TOKEN RFC 9068 : claim `authorization_details`
     * (RFC 9396) et claims obligatoires `iss`/`aud`/`sub`/`client_id` présents et NON VIDES
     * (un hub 1.0 refuse 401 un token qui en manque). On ne fige PAS leurs VALEURS ici (choix de
     * configuration du hub, cf. rapport TANK) — seulement leur présence et la FORME des entrées.
     */
    public function testTheTokenCarriesAuthorizationDetailsWithTheRequiredRfc9068Claims(): void
    {
        [$token] = $this->memberOfAFreshClub('rfc9068');
        $cookie = $this->fetchAuthCookie($token);

        [, $payload] = explode('.', $cookie->getValue());
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        $details = $claims['authorization_details'] ?? null;
        self::assertIsArray($details);
        self::assertNotEmpty($details, 'le jeton 1.0 doit porter au moins une entrée authorization_details');
        foreach ($details as $entry) {
            self::assertIsArray($entry);
            self::assertSame('https://mercure.rocks/authorization-detail', $entry['type'] ?? null, 'type d’entrée authorization_details Mercure');
            self::assertContains('subscribe', $entry['actions'] ?? [], 'le jeton d’abonnement n’autorise que subscribe');
            self::assertIsArray($entry['topics'] ?? null);
            self::assertNotEmpty($entry['topics']);
            foreach ($entry['topics'] as $topic) {
                self::assertIsArray($topic);
                self::assertIsString($topic['match'] ?? null, 'chaque topic 1.0 est un objet {match: …}');
            }
        }

        foreach (['iss', 'aud', 'sub', 'client_id'] as $required) {
            self::assertArrayHasKey($required, $claims, \sprintf('le claim "%s" est requis par un access token RFC 9068', $required));
            self::assertNotEmpty($claims[$required], \sprintf('le claim "%s" doit être non vide', $required));
        }
        self::assertArrayHasKey('exp', $claims);
        self::assertArrayHasKey('iat', $claims);
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
            if ('/.well-known/mercure' === $candidate->getPath()) {
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
            if ('/.well-known/mercure' === $cookie->getPath()) {
                return $cookie;
            }
        }

        self::fail('la réponse doit poser le jeton de souscription en cookie sur le chemin du hub');
    }

    /**
     * Les topics AUTORISÉS À S'ABONNER, lus du claim `authorization_details` 1.0 (RFC 9396) :
     * le `match` de chaque topic des entrées dont les `actions` incluent `subscribe`, dans
     * l'ordre. Remplace la lecture du claim `mercure.subscribe` legacy (0.x).
     *
     * @return list<string>
     */
    private function subscribeClaim(string $jwt): array
    {
        [, $payload] = explode('.', $jwt);
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsArray($claims['authorization_details'] ?? null, 'le jeton hub 1.0 doit porter le claim authorization_details');

        $topics = [];
        foreach ($claims['authorization_details'] as $entry) {
            if (!\is_array($entry) || !\in_array('subscribe', $entry['actions'] ?? [], true)) {
                continue;
            }
            foreach ($entry['topics'] ?? [] as $topic) {
                if (\is_array($topic) && \is_string($topic['match'] ?? null)) {
                    $topics[] = $topic['match'];
                }
            }
        }

        return $topics;
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
