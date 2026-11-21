<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\MemberReadOnlyDecorTrait;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * NR BLOQUANT — axe auth & memberships : « le contrôle central refuse au Membre toute fonction
 * “gestionnaire”, AVANT que quoi que ce soit ne lise ou n'écrive pour lui, et refuse toute route
 * qui ne dit pas à qui elle est ouverte ».
 *
 * Ce que ce fichier prouve, et que les balayages ne prouvent pas :
 *  - la PLACE de `ClubAccessListener` : `kernel.request`, priorité 4 — après le routeur (32), le
 *    pare-feu (8), le locataire (7, qui a lu l'adhésion et la saison), le débit (6) et les caps
 *    (5) ; jamais sur `kernel.controller`, donc avant `SeasonReadonlyGuardListener` et avant tout
 *    contrôleur. Preuve par l'EFFET aussi : les écarts E1 (le contrôleur ne lit plus la saison
 *    visée : 404 → 403), E2 (la garde « saison archivée » de `kernel.controller` n'est plus
 *    atteinte : 409 → 403), E3 (le fournisseur d'API Platform ne lit plus la ligne, le validateur
 *    ne lit plus le corps : 404/422 → 403) ;
 *  - une route hors des six familles (déclaration `null`, ni console, ni dev, ni cadre, ni
 *    publique, ni `api_login`) est refusée, gestionnaire compris ;
 *  - une sous-requête (rendu d'erreur d'API Platform, `ErrorListener.php:81-116`) n'est pas
 *    contrôlée une seconde fois : le refus garde son corps JSON, jamais un 500 ;
 *  - l'en-tête `X-HTTP-Method-Override` ne fait jamais passer un Membre : la déclaration lue est
 *    celle de la méthode ROUTÉE (Symfony honore l'en-tête sur un POST, `Request::getMethod()`) ;
 *  - un gestionnaire garde le corps d'erreur de l'API (404, 409, 422) : le contrôle ne vole pas
 *    les refus qui ne sont pas les siens ;
 *  - E4 : le refus porte `detail` = « Cette action est réservée aux gestionnaires. » là où les
 *    gardes à la main rendaient `{"error":"Accès refusé."|"Forbidden."}` ; E5 : la tentative d'un
 *    Membre n'entame plus son seau `rgpd_export`.
 * Les oracles (statuts, message) sont écrits à la main, jamais lus dans le code de production.
 *
 * FALSIFICATION (à jouer après l'implémentation, consignée au rapport de la PR 3b) : écouteur
 * désactivé (`getSubscribedEvents()` vide) → ce fichier rougit, ET `MemberWriteSweepTest`
 * (chaque ligne `403` rend 2xx/404/422, l'empreinte bouge) ET la caractérisation de
 * `AccessDeclarationSweepTest::testTheMemberMeetsEveryClubRouteAsItIsDeclared` rougissent : une
 * fois les gardes à la main retirées, l'écouteur est la seule chose qui ferme.
 *
 * Seaux de débit remis à zéro par `reset()` ciblé — jamais FLUSHALL.
 * Angles jumeaux, cités : `MemberWriteSweepTest` (chaque écriture, l'effet), `AccessDeclarationSweepTest`
 * (chaque route, la déclaration), `MemberReadsAreSideEffectFreeTest` (abus : logo adverse non
 * stocké `:197`, jeton de coach `:141`, caps de génération et de placement `:234`/`:253`) —
 * auth & memberships est un point sensible : ici l'ordre et les écarts, là l'exhaustivité.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubAccessListenerTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use MemberReadOnlyDecorTrait;
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    /** L'oracle du refus, écrit à la main. */
    private const string REFUSAL = 'Cette action est réservée aux gestionnaires.';

    private const string ARCHIVED = 'Cette saison est archivée — elle est en lecture seule.';

    private const string LISTENER = 'App\Access\ClubAccessListener';

    private const string UNKNOWN = '22222222-2222-4222-8222-222222222222';

    private KernelBrowser $client;

    /**
     * La place de l'écouteur : `kernel.request` priorité 4, sous tous ceux qui préparent la requête
     * (routeur, pare-feu, locataire, débit, caps ; le refus « crédits épuisés » vit dans l'écouteur
     * lui-même, après le rôle) ; aucune place sur `kernel.controller`,
     * où vit la garde « saison archivée » — il passe donc avant elle et avant tout contrôleur.
     */
    public function testTheListenerRunsOnKernelRequestAfterTheTenantAndBeforeAnyController(): void
    {
        $dispatcher = $this->dispatcher();
        $mine = $this->listenersOf(KernelEvents::REQUEST, self::LISTENER);
        self::assertNotSame([], $mine, 'aucun ClubAccessListener sur kernel.request');
        foreach ($mine as $listener) {
            self::assertSame(4, $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener), 'ClubAccessListener tire en priorité 4');
        }

        $upstream = [
            'Symfony\Component\HttpKernel\EventListener\RouterListener' => 'le routeur',
            'Symfony\Bundle\SecurityBundle\EventListener\FirewallListener' => 'le pare-feu',
            'App\EventListener\TenantFilterListener' => 'le locataire (adhésion, saison, `_club_id`)',
            'App\EventListener\ApiRateLimitSubscriber' => 'le débit',
            'App\EventListener\ClubQuotaSubscriber' => 'les caps du club',
        ];
        foreach ($upstream as $class => $role) {
            $listeners = $this->listenersOf(KernelEvents::REQUEST, $class);
            self::assertNotSame([], $listeners, \sprintf('témoin : %s écoute kernel.request', $role));
            foreach ($listeners as $listener) {
                self::assertGreaterThan(4, $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener), \sprintf('%s passe avant le contrôle central', $role));
            }
        }

        self::assertNotSame([], $this->listenersOf(KernelEvents::CONTROLLER, 'App\EventListener\SeasonReadonlyGuardListener'), 'témoin : la garde « saison archivée » vit sur kernel.controller');
        self::assertSame([], $this->listenersOf(KernelEvents::CONTROLLER, self::LISTENER), 'le contrôle central ne tire pas sur kernel.controller : il refuse avant');
    }

    /**
     * Un Membre qui appelle une fonction « gestionnaire » — d'API Platform ou d'un contrôleur
     * maison, corps valide — reçoit 403 et le message exact ; rien ne bouge, rien ne part.
     */
    public function testAMemberCallingAManagerFunctionIsRefusedWithTheGuardMessageAndNothingMoves(): void
    {
        $this->decor();
        $before = $this->fingerprint();

        foreach ([
            ['POST', '/api/teams', json_encode(['name' => 'Interdite', 'sportCategoryId' => $this->ids['category'], 'priorityTierId' => 1], \JSON_THROW_ON_ERROR)],
            ['POST', '/api/teams/reorder', json_encode(['teamIds' => [$this->ids['team']]], \JSON_THROW_ON_ERROR)],
            ['DELETE', '/api/teams/' . $this->ids['team'], null],
        ] as [$method, $url, $body]) {
            $this->asMember($method, $url, $body);
            $this->assertRefusedByTheCentralControl($method . ' ' . $url);
            self::assertSame([], $this->messagesSentByLastRequest(), $method . ' ' . $url . ' : un message est parti');
        }

        self::assertSame($before, $this->fingerprint(), 'les tables du club ont bougé sous un Membre');
    }

    /**
     * Une route qui ne tombe dans aucune des six familles (aucune déclaration, ni console, ni dev,
     * ni cadre, ni publique, ni `api_login`) est refusée — au gestionnaire aussi. Jouée sur
     * l'écouteur lui-même (une route de test au routeur rougirait le classement de
     * `AccessDeclarationSweepTest`, c'est voulu) ; témoins dans le même harnais : une lecture
     * « membre » passe pour un Membre, une fonction « gestionnaire » passe pour un gestionnaire.
     */
    public function testARouteOutsideTheSixFamiliesIsRefusedEvenToAManager(): void
    {
        $this->decor();
        $manager = $this->user($this->managerUserId);
        $member = $this->user($this->memberUserId);

        $undeclared = Request::create('/api/hors-familles', 'GET');
        $undeclared->attributes->add(['_route' => 'test_hors_familles', '_controller' => 'App\Controller\HorsFamillesController', '_club_id' => $this->clubId]);
        self::assertSame(403, $this->runListener($undeclared, $manager, HttpKernelInterface::MAIN_REQUEST), 'une route hors des six familles passe le contrôle central');

        self::assertNull($this->runListener($this->routed('GET', '/api/teams'), $member, HttpKernelInterface::MAIN_REQUEST), 'témoin : une lecture « membre » doit passer pour un Membre');
        self::assertNull($this->runListener($this->routed('POST', '/api/seasons/' . self::UNKNOWN . '/transition'), $manager, HttpKernelInterface::MAIN_REQUEST), 'témoin : une fonction « gestionnaire » doit passer pour un gestionnaire');
        self::assertSame(403, $this->runListener($this->routed('POST', '/api/seasons/' . self::UNKNOWN . '/transition'), $member, HttpKernelInterface::MAIN_REQUEST), 'témoin : le harnais voit le refus d\'un Membre');
    }

    /**
     * Le rendu d'erreur d'API Platform rejoue le chemin en sous-requête : elle n'est pas contrôlée
     * une seconde fois (sinon le rendu du 403 lèverait un second 403, page d'erreur cassée). Sur
     * l'écouteur : la même requête d'un Membre, refusée en principale, passe en sous-requête. En
     * HTTP : le refus d'une écriture d'API Platform rend un corps JSON lisible, jamais un 500.
     */
    public function testAnErrorRenderingSubRequestIsNotCheckedASecondTime(): void
    {
        $this->decor();
        $member = $this->user($this->memberUserId);

        self::assertSame(403, $this->runListener($this->routed('POST', '/api/teams'), $member, HttpKernelInterface::MAIN_REQUEST), 'témoin : refusée en requête principale');
        self::assertNull($this->runListener($this->routed('POST', '/api/teams'), $member, HttpKernelInterface::SUB_REQUEST), 'une sous-requête est contrôlée une seconde fois');

        $this->asMember('POST', '/api/teams', json_encode(['name' => 'Interdite', 'sportCategoryId' => $this->ids['category'], 'priorityTierId' => 1], \JSON_THROW_ON_ERROR));
        $this->assertRefusedByTheCentralControl('POST /api/teams (corps du rendu d\'erreur)');
    }

    /**
     * `X-HTTP-Method-Override` (honoré par Symfony sur un POST) ne fait jamais passer un Membre :
     * POST « inviter » déguisé en GET reste une fonction « gestionnaire » (la lecture des
     * invitations l'est aussi) → refusé, aucune invitation, aucun mail ; POST déguisé en GET sur une
     * lecture « membre » → servi en lecture (la méthode lue est bien la méthode routée) ; POST déguisé en DELETE sur
     * une équipe → refusé (403, ou 405 si l'en-tête est désactivé), l'équipe est intacte.
     */
    public function testTheMethodOverrideHeaderNeverLetsAMemberThrough(): void
    {
        $this->decor();
        $invitationsBefore = $this->api($this->managerToken, 'GET', '/api/invitations');
        $before = $this->fingerprint();

        $this->asMemberWith('POST', '/api/invitations', '{"email":"intrus@test.fr","role":"admin"}', ['HTTP_X-HTTP-Method-Override' => 'GET']);
        $this->assertRefusedByTheCentralControl('POST /api/invitations déguisé en GET');
        self::assertSame([], $this->messagesSentByLastRequest(), 'un mail d\'invitation est parti');

        // Témoin de la source de la méthode : POST déguisé en GET sur une lecture « membre » est
        // servi comme la lecture que le routeur a vue. Lire la méthode réelle (`getRealMethod()`)
        // y verrait une écriture et refuserait à tort : seul ce témoin le voit.
        $this->asMemberWith('POST', '/api/teams', null, ['HTTP_X-HTTP-Method-Override' => 'GET']);
        self::assertResponseStatusCodeSame(200, 'POST déguisé en GET sur une lecture « membre » : la déclaration lue n\'est pas celle de la méthode routée');

        $this->asMemberWith('POST', '/api/teams/' . $this->ids['team'], null, ['HTTP_X-HTTP-Method-Override' => 'DELETE']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 405], 'POST déguisé en DELETE par un Membre : ' . mb_substr((string) $this->client->getResponse()->getContent(), 0, 300));

        self::assertSame($before, $this->fingerprint(), 'les tables du club ont bougé sous un Membre déguisant sa méthode');
        self::assertSame($invitationsBefore, $this->api($this->managerToken, 'GET', '/api/invitations'), 'une invitation est née');
        $this->api($this->managerToken, 'GET', '/api/teams/' . $this->ids['team']);
        self::assertResponseStatusCodeSame(200, 'l\'équipe a disparu');
    }

    /** Un gestionnaire garde le corps d'erreur de l'API : 404, 422, 409 ne deviennent jamais 403. */
    public function testAManagerKeepsTheApiErrorBody(): void
    {
        $this->decor();
        $past = $this->pastSeason();

        $this->asManager('PUT', '/api/teams/' . self::UNKNOWN, '{"name":"X"}');
        self::assertResponseStatusCodeSame(404, 'équipe inconnue, gestionnaire');

        $this->asManager('POST', '/api/teams', '{}');
        self::assertResponseStatusCodeSame(422, 'corps invalide, gestionnaire');
        self::assertNotEmpty($this->body()['violations'] ?? [], 'le 422 garde ses violations');

        $this->asManager('POST', '/api/seasons/' . self::UNKNOWN . '/transition', null);
        self::assertResponseStatusCodeSame(404, 'saison inconnue, gestionnaire');
        self::assertSame('Saison introuvable.', $this->body()['error'] ?? null);

        $this->asManager('POST', '/api/teams/reorder', json_encode(['teamIds' => [$this->ids['team']]], \JSON_THROW_ON_ERROR), ['HTTP_X-Season-Id' => $past]);
        self::assertResponseStatusCodeSame(409, 'saison archivée, gestionnaire');
        self::assertSame(self::ARCHIVED, $this->body()['detail'] ?? null);
    }

    /** E1 — `SeasonTransitionController`, saison inconnue, Membre : avant 404 « Saison introuvable. », après 403. */
    public function testE1AMemberPreparingAnUnknownSeasonIsRefusedNot404(): void
    {
        $this->decor();

        $this->asMember('POST', '/api/seasons/' . self::UNKNOWN . '/transition');
        $this->assertRefusedByTheCentralControl('E1 POST /api/seasons/{inconnue}/transition');
    }

    /**
     * E2 — saison archivée, Membre : avant 409 (garde de `kernel.controller` sur un contrôleur
     * `SeasonScopedWriteInterface` ; garde « saison gelée à la main » de
     * `ConflictCoachMailController`), après 403.
     */
    public function testE2AMemberWritingAnArchivedSeasonIsRefusedNot409(): void
    {
        $this->decor();
        $past = $this->pastSeason();

        $this->asMemberWith('POST', '/api/teams/reorder', json_encode(['teamIds' => [$this->ids['team']]], \JSON_THROW_ON_ERROR), ['HTTP_X-Season-Id' => $past]);
        $this->assertRefusedByTheCentralControl('E2 POST /api/teams/reorder sur une saison archivée');
    }

    /** E2 — `ConflictCoachMailController`, saison gelée à la main, Membre : avant 409, après 403, aucun mail. */
    public function testE2AMemberMailingCoachesOnAFrozenSeasonIsRefusedNot409(): void
    {
        $this->decor();

        $this->freezeCurrentSeason();
        $this->asMember('POST', '/api/fixtures/conflicts/mail-coaches', '{}');
        $this->assertRefusedByTheCentralControl('E2 POST /api/fixtures/conflicts/mail-coaches sur une saison gelée');
        self::assertSame([], $this->messagesSentByLastRequest(), 'un mail aux coachs est parti');
    }

    /** E3 — écriture d'API Platform sur un identifiant inconnu, Membre : avant 404, après 403. */
    public function testE3AMemberWritingAnUnknownIdIsRefusedNot404(): void
    {
        $this->decor();

        $this->asMember('PUT', '/api/teams/' . self::UNKNOWN, '{"name":"X"}');
        $this->assertRefusedByTheCentralControl('E3 PUT /api/teams/{inconnue}');
    }

    /** E3 — écriture d'API Platform au corps invalide, Membre : avant 422, après 403. */
    public function testE3AMemberSendingAnInvalidBodyIsRefusedNot422(): void
    {
        $this->decor();

        $this->asMember('POST', '/api/teams', '{}');
        $this->assertRefusedByTheCentralControl('E3 POST /api/teams corps invalide');
    }

    /**
     * E4 — le corps du refus devient `detail` = le message du contrôle, là où les gardes à la main
     * rendaient `{"error":"Accès refusé."}` (adhésions, invitations) ou `{"error":"Forbidden."}`
     * (export RGPD du club, imports d'équipes, de parc, de rencontres). L'écran lit
     * `error ?? message ?? detail` : même toast.
     */
    public function testE4EveryHandGuardRefusalNowCarriesTheCentralDetail(): void
    {
        $this->decor();
        $before = $this->fingerprint();

        $wrong = [];
        foreach ([
            ['POST', '/api/memberships/' . $this->ids['membership'] . '/deactivate', null],
            ['DELETE', '/api/invitations/' . self::UNKNOWN, null],
            ['GET', '/api/club/export', null],
            ['POST', '/api/clubs/' . $this->clubId . '/import-teams', null],
            ['POST', '/api/clubs/' . $this->clubId . '/parc-import/analyze', '{}'],
            ['POST', '/api/fixtures/import', null],
        ] as [$method, $url, $body]) {
            $this->asMember($method, $url, $body);
            $status = $this->client->getResponse()->getStatusCode();
            $served = $this->body();
            if (403 !== $status || self::REFUSAL !== ($served['detail'] ?? null) || \array_key_exists('error', $served)) {
                $wrong[] = \sprintf('%s %s → %d %s', $method, $url, $status, mb_substr((string) $this->client->getResponse()->getContent(), 0, 200));
            }
        }

        self::assertSame([], $wrong, "Refus sans le corps du contrôle central (403, `detail` = le message, plus de clé `error`) :\n  - " . implode("\n  - ", $wrong));
        self::assertSame($before, $this->fingerprint(), 'les tables du club ont bougé sous un Membre');
    }

    /** E5 — l'export RGPD du club tenté par un Membre n'entame plus son seau `rgpd_export` (avant : 10 → 9). */
    public function testE5AMemberClubExportAttemptNoLongerSpendsTheExportBucket(): void
    {
        $this->decor();
        $this->resetBucket('limiter.rgpd_export', $this->memberUserId);
        self::assertSame(10, $this->remaining('limiter.rgpd_export', $this->memberUserId), 'témoin : seau plein');

        $this->asMember('GET', '/api/club/export');
        self::assertResponseStatusCodeSame(403, 'E5 : l\'export du club reste refusé au Membre');

        self::assertSame(10, $this->remaining('limiter.rgpd_export', $this->memberUserId), 'la tentative d\'un Membre a entamé son seau d\'export');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /** Club né par l'inscription, une équipe, un Membre actif. */
    private function decor(): void
    {
        $token = $this->registerManager('ca');
        $this->ids['category'] = (string) $this->api($token, 'GET', '/api/sport_categories')['member'][0]['id'];
        $this->ids['team'] = $this->create($token, '/api/teams', ['name' => 'CA1', 'sportCategoryId' => $this->ids['category'], 'priorityTierId' => 1]);
        $this->addActiveMember();
    }

    private function assertRefusedByTheCentralControl(string $what): void
    {
        $response = $this->client->getResponse();
        $excerpt = mb_substr((string) $response->getContent(), 0, 400);
        self::assertSame(403, $response->getStatusCode(), $what . ' → ' . $excerpt);
        self::assertSame(self::REFUSAL, $this->body()['detail'] ?? null, $what . ' : le refus ne porte pas le message du contrôle → ' . $excerpt);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, string> $headers */
    private function asManager(string $method, string $url, ?string $body, array $headers = []): void
    {
        $this->resetBucket('limiter.api', 'api-user-' . $this->managerUserId);
        $this->startFreshBrowserSession($this->client);
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->managerToken,
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json',
        ] + $headers, $body);
    }

    /**
     * Patron de `asMember` (décor partagé), avec des en-têtes en plus.
     *
     * @param array<string, string> $headers
     */
    private function asMemberWith(string $method, string $url, ?string $body, array $headers): void
    {
        $this->resetBucket('limiter.api', 'api-user-' . $this->memberUserId);
        $this->startFreshBrowserSession($this->client);
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->memberToken,
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json',
        ] + $headers, $body);
    }

    /** Une saison N-1 (archivée au calendrier) ; rend son id. */
    private function pastSeason(): string
    {
        $em = $this->em();
        self::getContainer()->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1;
        $season = new Season;
        $season->setClubId($this->clubId);
        $season->setName($year . '-' . ($year + 1));
        $season->setStartDate(new DateTimeImmutable($year . '-07-15'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-14'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $em->persist($season);
        $em->flush();
        self::getContainer()->get(TenantConnectionContext::class)->clear();

        return $season->getId();
    }

    /** La saison courante gelée à la main (statut « archivée ») — garde de `ConflictCoachMailController`. */
    private function freezeCurrentSeason(): void
    {
        $em = $this->em();
        self::getContainer()->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $this->clubId], ['startDate' => 'DESC']);
        self::assertInstanceOf(Season::class, $season);
        $season->setStatus(SeasonStatus::ARCHIVED);
        $em->flush();
        self::getContainer()->get(TenantConnectionContext::class)->clear();
    }

    private function remaining(string $limiterService, string $key): int
    {
        $limiter = self::getContainer()->get($limiterService);
        \assert($limiter instanceof RateLimiterFactory);

        return $limiter->create($key)->consume(0)->getRemainingTokens();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function user(string $id): User
    {
        $user = $this->em()->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);

        return $dispatcher;
    }

    /**
     * Les écouteurs d'un événement dont l'objet est de la classe donnée (déballés du traceur).
     *
     * @return list<callable>
     */
    private function listenersOf(string $event, string $class): array
    {
        $found = [];
        foreach ($this->dispatcher()->getListeners($event) as $listener) {
            $inner = $listener instanceof WrappedListener ? $listener->getWrappedListener() : $listener;
            $object = \is_array($inner) ? $inner[0] : $inner;
            if ($object instanceof $class) {
                $found[] = $listener;
            }
        }

        return $found;
    }

    /** La requête telle que le routeur la laisse (attributs de la route), `_club_id` posé comme par le locataire. */
    private function routed(string $method, string $path): Request
    {
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $context = $router->getContext();
        $previous = $context->getMethod();
        $context->setMethod($method);
        try {
            $parameters = $router->match($path);
        } finally {
            $context->setMethod($previous);
        }
        $request = Request::create($path, $method);
        $request->attributes->add($parameters);
        $request->attributes->set('_club_id', $this->clubId);

        return $request;
    }

    /**
     * Joue le seul contrôle central sur une requête, l'utilisateur authentifié comme par le pare-feu.
     * Rend le statut du refus (exception HTTP ou réponse posée), `null` s'il laisse passer.
     */
    private function runListener(Request $request, User $user, int $type): ?int
    {
        $listeners = $this->listenersOf(KernelEvents::REQUEST, self::LISTENER);
        self::assertNotSame([], $listeners, 'aucun ClubAccessListener sur kernel.request');
        $container = self::getContainer();
        $stack = $container->get(RequestStack::class);
        \assert($stack instanceof RequestStack);
        $tokens = $container->get('security.token_storage');
        \assert($tokens instanceof TokenStorageInterface);
        $tokens->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        $kernel = self::$kernel;
        \assert($kernel instanceof HttpKernelInterface);
        $event = new RequestEvent($kernel, $request, $type);
        $stack->push($request);
        try {
            foreach ($listeners as $listener) {
                $listener($event, KernelEvents::REQUEST, $this->dispatcher());
                if ($event->hasResponse()) {
                    return $event->getResponse()?->getStatusCode();
                }
            }

            return null;
        } catch (HttpExceptionInterface $refusal) {
            return $refusal->getStatusCode();
        } finally {
            $stack->pop();
            $tokens->setToken(null);
        }
    }
}
