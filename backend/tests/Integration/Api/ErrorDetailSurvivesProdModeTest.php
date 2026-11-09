<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use App\Tests\Security\ClubApprovalFlowTest;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\Unit\EventListener\HttpExceptionDetailListenerTest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * En PRODUCTION (`debug: false`, le noyau que sert `APP_DEBUG=0`), un refus 4xx VOULU, levé par NOTRE
 * code sur une route Symfony simple, arrive au client avec son motif dans `detail` — jamais la seule
 * reason-phrase (« Conflict ») que Symfony rend hors debug. Et rien d'autre ne change : les
 * exceptions CRÉÉES par le framework (firewall, routeur, fabriques d'`AbstractController`, y compris
 * appelées depuis src/) gardent la reason-phrase — leur message anglais peut porter l'URL, donc le
 * jeton d'une page publique —, API Platform rend toujours lui-même ses opérations, une navigation
 * navigateur garde la page HTML de Symfony, et le seul message anglais de src/ joignable par une
 * route simple (le 429 du limiteur d'API) est francisé.
 *
 * Tous les tests tournent sur un noyau `debug: false` (patron `tests/Security/DemoWindowTest.php`) :
 * c'est la seule façon de voir la prod — PHPUnit et Behat tournent en debug, où Symfony relaie déjà
 * le message et masque le défaut. Requêtes JSON = `Accept: application/json` (ce qu'envoie le
 * client ky avec `.json()`) ; sans Accept, BrowserKit envoie l'Accept HTML d'un navigateur.
 * Règle condition par condition : {@see HttpExceptionDetailListenerTest} ; parité 404 des pages à
 * jeton en prod : {@see ClubApprovalFlowTest::testUnknownAndConsumedTokensStayByteIdenticalWhenDebugIsOff}.
 */
#[Group('integration')]
final class ErrorDetailSurvivesProdModeTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use TenantGucTrait;

    /** La phrase du 429 du limiteur d'API, mot pour mot (`ApiRateLimitSubscriber`) — même phrase que le repli 429 de l'écran. */
    private const string RATE_LIMIT_MESSAGE = 'Trop de requêtes d\'affilée. Patientez quelques instants avant de réessayer.';

    /** Le plafond du limiteur `api` en test (`rate_limiter.yaml`, when@test). */
    private const int TEST_RATE_LIMIT = 30;

    private const string PROBLEM_TYPE = 'https://tools.ietf.org/html/rfc2616#section-10';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Le 409 du socle, levé par `SocleGuard` depuis le contrôleur Symfony de la revue des matchs
     * (`POST /api/fixtures/review`, `ReviewFixturesController`), sort en prod avec son motif, au format
     * problem+json du debug d'aujourd'hui (sans `class` ni `trace`).
     */
    public function testAConflictFromASymfonyRouteKeepsItsMessageWhenDebugIsOff(): void
    {
        [$manager, $season] = $this->clubWithoutSocle();

        $this->client->request('POST', '/api/fixtures/review', [], [], $this->jsonHeaders($manager, $season), '{}');

        self::assertSame(409, $this->statusCode());
        self::assertSame([
            'type' => self::PROBLEM_TYPE,
            'title' => 'An error occurred',
            'status' => 409,
            'detail' => SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE,
        ], $this->body(), 'le motif du refus, pas « Conflict »');
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * Une opération API Platform (`POST /api/fixtures`, garde du socle dans `FixtureStateProcessor`)
     * porte DÉJÀ son message en prod : API Platform la rend lui-même, le relais ne s'y substitue pas
     * (son corps garde le `type` d'API Platform, `/errors/409`). Jumeau Behat en debug :
     * `le-socle-commande-les-plans.feature` (`SoclePlansContext` « l'ouverture est refusée… »).
     */
    public function testAnApiPlatformOperationStillCarriesItsMessageWhenDebugIsOff(): void
    {
        [$manager, $season] = $this->clubWithoutSocle();

        $this->client->request('POST', '/api/fixtures', [], [], $this->jsonHeaders($manager, $season), (string) json_encode([
            'teamId' => Uuid::v4()->toRfc4122(),
            'matchDate' => '2026-12-20',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Les Loups',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(409, $this->statusCode());
        $body = $this->body();
        self::assertSame(SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE, $body['detail'] ?? null);
        self::assertSame('/errors/409', $body['type'] ?? null, 'corps rendu par API Platform (`type` /errors/409), pas par le relais des routes Symfony');
    }

    /**
     * 422 de NOTRE code sur une route Symfony : fermeture déjà commencée qu'on tenterait de
     * raccourcir sous le plancher du passé (`POST /api/schedule_plans/{id}/window`,
     * `ClosureWindowPlanner::floorViolation`) — le motif sort, pas « Unprocessable Content ».
     */
    public function testAnUnprocessableRefusalFromASymfonyRouteKeepsItsMessageWhenDebugIsOff(): void
    {
        [$manager, $planId] = $this->startedClosure();
        $preview = $this->preview($manager, $planId, $this->mon(-2), $this->mon(-1));

        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->jsonHeaders($manager), (string) json_encode([
            'startDate' => $this->mon(-2), 'endDate' => $this->mon(-1), 'previewToken' => $preview,
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $this->statusCode());
        self::assertSame('On ne raccourcit jamais une fermeture sous le plancher du passé.', $this->body()['detail'] ?? null);
    }

    /**
     * Le 409 « aperçu périmé » de la fermeture (`ClosureWindowApplyController`) : l'écran le lit en
     * `detail` pour redemander l'aperçu (`cockpit/api.ts`, `asPreviewStale`) — en prod il doit lire le
     * motif, pas « Conflict ».
     */
    public function testTheStalePreviewConflictOfAClosureKeepsItsMessageWhenDebugIsOff(): void
    {
        [$manager, $planId] = $this->startedClosure();

        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window', [], [], $this->jsonHeaders($manager), (string) json_encode([
            'startDate' => $this->mon(-2), 'endDate' => $this->sun(2), 'previewToken' => 'jeton-perime',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(409, $this->statusCode());
        self::assertSame('La fermeture a changé depuis l\'aperçu : redemandez l\'aperçu des effets avant de confirmer.', $this->body()['detail'] ?? null);
    }

    /**
     * Les fabriques d'`AbstractController` (`createNotFoundException`, `createAccessDeniedException`)
     * créent l'exception sous vendor/ même appelées depuis src/ : jamais relayées. Le 403 en est la
     * preuve tranchante — « Access Denied. » n'est pas la reason-phrase, seule l'ORIGINE l'écarte.
     */
    public function testAVendorFactoryCalledFromSrcIsNotRelayed(): void
    {
        [$manager] = $this->clubWithoutSocle();

        $this->client->request('GET', '/api/mailbox/' . Uuid::v4()->toRfc4122(), [], [], $this->jsonHeaders($manager));
        self::assertSame(404, $this->statusCode(), '`MailboxController::detail` → createNotFoundException()');
        self::assertSame('Not Found', $this->body()['detail'] ?? null);

        $this->client->request('GET', '/api/mailbox', [], [], $this->jsonHeaders($this->userWithoutClub()));
        self::assertSame(403, $this->statusCode(), '`MailboxController::currentClubId` → createAccessDeniedException()');
        self::assertSame('Forbidden', $this->body()['detail'] ?? null, 'le « Access Denied. » du framework ne sort jamais');
    }

    /** Sans JWT, `/api/me` répond le 401 de lexik d'aujourd'hui, octet pour octet. */
    public function testAnUnauthenticatedCallKeepsTodaysBodyWhenDebugIsOff(): void
    {
        $this->client->request('GET', '/api/me', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(401, $this->statusCode());
        self::assertSame('{"code":401,"message":"JWT Token not found"}', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le firewall `admin` n'a pas de point d'entrée : un appel anonyme y lève un 401 CRÉÉ par le
     * framework (« Full authentication is required… ») — jamais relayé, la reason-phrase reste.
     */
    public function testAnAnonymousCallOnAdminRoutesIsNotRelayedWhenDebugIsOff(): void
    {
        $this->client->request('GET', '/api/admin/deployments', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(401, $this->statusCode());
        self::assertSame('Unauthorized', $this->body()['detail'] ?? null);
        self::assertStringNotContainsString('Full authentication', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le 405 du routeur sur une page publique à jeton porte l'URL — donc le JETON — dans son message
     * (« No route found for "PUT …/api/club-approvals/<jeton>" ») : il ne sort jamais.
     */
    public function testAMethodNotAllowedOnATokenPageNeverEchoesTheTokenWhenDebugIsOff(): void
    {
        $token = bin2hex(random_bytes(32));

        $this->client->request('PUT', '/api/club-approvals/' . $token, [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{}');

        self::assertSame(405, $this->statusCode());
        self::assertSame('Method Not Allowed', $this->body()['detail'] ?? null);
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent(), 'le jeton ne ressort jamais dans la réponse');
    }

    /** Le 404 du routeur (« No route found for "GET …" ») garde la reason-phrase. */
    public function testAnUnknownRouteKeepsTheStatusTextWhenDebugIsOff(): void
    {
        $this->client->request('GET', '/api/nope', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(404, $this->statusCode());
        self::assertSame('Not Found', $this->body()['detail'] ?? null);
        self::assertStringNotContainsString('No route found', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Le 429 du limiteur d'API (`ApiRateLimitSubscriber`, levé en kernel.request depuis src/) sort en
     * prod — donc en français, avec son Retry-After. Jumeau du plafond lui-même :
     * `tests/Security/ApiRateLimitTest.php`.
     */
    public function testTheRateLimitRefusalIsFrenchWhenDebugIsOff(): void
    {
        [$manager] = $this->clubWithoutSocle();
        $headers = $this->jsonHeaders($manager);

        for ($i = 0; $i <= self::TEST_RATE_LIMIT; ++$i) {
            $this->client->request('GET', '/api/me', [], [], $headers);
        }

        self::assertSame(429, $this->statusCode(), 'le plafond de test est franchi');
        self::assertSame(self::RATE_LIMIT_MESSAGE, $this->body()['detail'] ?? null);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * Une navigation navigateur (Accept HTML) sur la même route que le 409 relayé garde la page
     * d'erreur HTML de Symfony d'aujourd'hui — sans le motif.
     */
    public function testABrowserNavigationKeepsTheSymfonyErrorPageWhenDebugIsOff(): void
    {
        [$manager, $season] = $this->clubWithoutSocle();
        $headers = ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'] + $this->jsonHeaders($manager, $season);

        $this->client->request('POST', '/api/fixtures/review', [], [], $headers, '{}');

        self::assertSame(409, $this->statusCode());
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringNotContainsString('Validez le planning de saison', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Un appel qui ne demande PAS de JSON explicitement — sans Accept (fetch nu) ou `Accept: *\/*`
     * (ky sans `.json()`), sans corps JSON — reçoit quand même le motif en problem+json : le format
     * préféré par défaut est json, seule une navigation qui demande du HTML garde la page Symfony.
     * BrowserKit injecte par défaut l'Accept HTML d'un navigateur (`Request::create`) : il est
     * écrasé ici pour que l'absence d'Accept soit réelle.
     */
    public function testAConflictIsRelayedWithoutAJsonAcceptWhenDebugIsOff(): void
    {
        [$manager, $season] = $this->clubWithoutSocle();
        $authenticated = $this->jsonHeaders($manager, $season);
        unset($authenticated['CONTENT_TYPE']);

        foreach (['sans Accept' => '', 'Accept */*' => '*/*'] as $case => $accept) {
            $this->client->request('POST', '/api/fixtures/review', [], [], ['HTTP_ACCEPT' => $accept] + $authenticated);

            self::assertSame(409, $this->statusCode(), $case);
            self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'), $case);
            self::assertSame(SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE, $this->body()['detail'] ?? null, $case);
        }
    }

    protected function setUp(): void
    {
        $this->client = self::createClient(['debug' => false]);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    // ── Décor ──

    /**
     * Un club, sa gestionnaire, la saison courante ACTIVE et AUCUN planning de saison choisi : tout
     * geste bâti sur le socle est refusé 409 par `SocleGuard`.
     *
     * @return array{0: User, 1: Season}
     */
    private function clubWithoutSocle(?DateTimeImmutable $today = null): array
    {
        $uid = uniqid('', true);
        $club = new Club()->setName('Les Hérons ' . $uid)->setSlug('herons-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $club->setFfbbClubCode('HER' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $manager = $this->newUser('nadia', $uid);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist(new ClubUser()->setClubId($club->getId())->setUserId($manager->getId())->setRole('admin')->setIsActive(true));
        $year = SeasonResolver::seasonYear($today ?? new DateTimeImmutable('today'));
        $season = new Season()->setClubId($club->getId())->setName($year . '-' . ($year + 1))
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$manager, $season];
    }

    /** Un compte authentifiable sans aucun club (pas de `_club_id` résolu). */
    private function userWithoutClub(): User
    {
        $user = $this->newUser('solo', uniqid('', true));
        $this->em->flush();

        return $user;
    }

    private function newUser(string $prefix, string $uid): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User()->setEmail($prefix . $uid . '@test.com')->setFirstName('Nadia')->setLastName('Gestion');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $user->setTermsAcceptedAt(new DateTimeImmutable);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Une fermeture de gymnase déjà commencée, adaptée (plan CLOSURE né par le geste réel) :
     * horloge épinglée sur un LUNDI connu, fenêtre [lundi -2 sem., dimanche +1 sem.]
     * (patron `tests/Security/ClosureWindowExtendTest.php`).
     *
     * @return array{0: User, 1: string} [gestionnaire, id du plan de fermeture]
     */
    private function startedClosure(): array
    {
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-01-05T12:00:00+00:00'));
        [$manager, $season] = $this->clubWithoutSocle(new DateTimeImmutable('2026-01-05'));
        self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $this->em->flush();

        $this->client->request('POST', '/api/calendar_entries', [], [], $this->jsonHeaders($manager), (string) json_encode([
            'kind' => 'period', 'periodType' => 'closure', 'title' => 'Gymnase en travaux',
            'startDate' => $this->mon(-2), 'endDate' => $this->sun(1),
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->statusCode(), (string) $this->client->getResponse()->getContent());
        $entryId = $this->body()['id'] ?? null;
        self::assertIsString($entryId);

        $this->client->request('POST', '/api/schedule_plans', [], [], $this->jsonHeaders($manager), (string) json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->statusCode(), (string) $this->client->getResponse()->getContent());
        $planId = $this->body()['id'] ?? null;
        self::assertIsString($planId);

        return [$manager, $planId];
    }

    /** L'aperçu des effets d'une nouvelle fenêtre — rend son jeton. */
    private function preview(User $manager, string $planId, string $start, string $end): string
    {
        $this->client->request('POST', '/api/schedule_plans/' . $planId . '/window-preview', [], [], $this->jsonHeaders($manager), (string) json_encode([
            'startDate' => $start, 'endDate' => $end,
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->statusCode(), (string) $this->client->getResponse()->getContent());
        $token = $this->body()['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /** Lundi n semaines depuis le lundi épinglé (2026-01-05). */
    private function mon(int $n): string
    {
        return new DateTimeImmutable('2026-01-05')->modify(\sprintf('%+d days', 7 * $n))->format('Y-m-d');
    }

    /** Dimanche de la semaine +n. */
    private function sun(int $n): string
    {
        return new DateTimeImmutable('2026-01-05')->modify(\sprintf('%+d days', 7 * $n + 6))->format('Y-m-d');
    }

    // ── HTTP ──

    /** @return array<string, string> une requête JSON authentifiée, comme l'envoie le client ky (`.json()`). */
    private function jsonHeaders(User $user, ?Season $season = null): array
    {
        $this->startFreshBrowserSession($this->client);
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($season instanceof Season) {
            $headers['HTTP_X-Season-Id'] = $season->getId();
        }

        return $headers;
    }

    private function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'corps JSON attendu : ' . substr((string) $this->client->getResponse()->getContent(), 0, 200));

        return $decoded;
    }
}
