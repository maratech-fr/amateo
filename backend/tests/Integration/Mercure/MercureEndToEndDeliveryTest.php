<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mercure;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Mercure\ClubTopicUpdate;
use App\Mercure\MercureTopic;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

/**
 * NR d'axe GENERATION PIPELINE (controller → messenger → engine → import → Mercure) — le
 * dernier maillon : l'avancement DOIT ARRIVER au navigateur. Ce test ferme la seule chose
 * qu'aucun autre niveau ne prouve : qu'une update PRIVÉE publiée sur un topic de club est
 * RÉELLEMENT DÉLIVRÉE par le HUB à un abonné du club, et JAMAIS à un autre club.
 *
 * Pourquoi ce niveau et pas plus bas (charte `docs/testing/tests-par-parcours.md`) :
 * - `MercurePrivateUpdateTest` prouve que l'update porte `private: true` et un topic `club:` —
 *   mais avec un hub MOQUÉ : il ne prouve RIEN de la délivrance ni de l'autorisation par topic.
 * - `MercureAuthTest` prouve la FORME du jeton de souscription (claims, scope tenant) — mais
 *   sans hub : un jeton bien formé qu'un hub REFUSE (mauvais `iss`/`aud`, protocole incompatible)
 *   passerait vert. La bascule 0.x → 1.0 change précisément le protocole, le jeton ET le hub : la
 *   seule preuve qui tienne est de bout en bout, contre le HUB RÉEL.
 * - jsdom/Vitest ne voient pas le réseau ; seul un abonnement HTTP réel au hub le voit.
 *
 * Config-agnostique par construction : le jeton d'abonné vient de la VRAIE route
 * `GET /api/mercure/auth` (quels que soient les `iss`/`aud`/`client_id` que le contrôleur pose en
 * 1.0) et la publication passe par le VRAI {@see HubInterface} (jeton publieur du bundle) — rien
 * n'est deviné ici. Le test échoue tant que jeton ET hub ne parlent pas le MÊME protocole.
 *
 * ⚠ Abonnement en protocole 1.0 : paramètre `match=<topic>` pour un topic EXACT (travel,
 * placement), `match_urlpattern=<gabarit>` pour le gabarit de génération. Jeton porté par
 * l'en-tête `Authorization: Bearer` (le paramètre `authorization` de requête a disparu en 1.0,
 * l'en-tête prime sur le cookie). Un hub 0.x reçoit `match=` sans `topic=` → 400 → ce test est
 * ROUGE tant que le hub n'est pas 1.0 ET que le contrôleur ne signe pas un jeton 1.0.
 *
 * Le hub est démarré et sain dans le job `blocking-tests` (ci.yml : `docker compose up php-fpm`
 * tire `mercure` via `depends_on: service_healthy`), joignable à `MERCURE_URL`.
 */
#[Group('phase1')]
#[Group('integration')]
final class MercureEndToEndDeliveryTest extends WebTestCase
{
    use TenantGucTrait;

    private const float STREAM_TIMEOUT = 3.0;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * La promesse NOMINALE : les TROIS topics privés d'un club (génération, calcul des trajets,
     * placement async) sont délivrés à un abonné authentifié de CE club.
     */
    public function testEachOfTheThreeClubTopicsIsDeliveredToAnAuthenticatedSubscriberOfThatClub(): void
    {
        [$token, $clubId] = $this->memberOfAFreshClub('deliver');
        $selectors = $this->fetchSubscriberToken($token);
        $hub = $this->hub();

        // Génération — topic EXACT publié (`/clubs/X/schedules/<id>`), abonnement au GABARIT du club.
        $scheduleId = Uuid::v4()->toRfc4122();
        $scheduleTopic = MercureTopic::for($clubId, $scheduleId);
        $schedulePayload = $this->firstSsePayload($selectors['hubToken'], 'match_urlpattern', $selectors['topicTemplate'], fn (): string => $hub->publish(ClubTopicUpdate::private($scheduleTopic, json_encode(['scheduleId' => $scheduleId, 'status' => 'COMPLETED'], \JSON_THROW_ON_ERROR))));
        self::assertNotNull($schedulePayload, 'une update de génération publiée sur le topic du club doit être délivrée à son abonné');
        self::assertStringContainsString('COMPLETED', $schedulePayload);

        // Calcul des trajets — topic EXACT.
        $travelPayload = $this->firstSsePayload($selectors['hubToken'], 'match', $selectors['travelTopic'], fn (): string => $hub->publish(ClubTopicUpdate::private(MercureTopic::forTravel($clubId), json_encode(['scope' => 'OPPONENTS', 'done' => 1, 'total' => 1, 'terminal' => true], \JSON_THROW_ON_ERROR))));
        self::assertNotNull($travelPayload, 'une update de calcul des trajets doit être délivrée à l’abonné du club');
        self::assertStringContainsString('OPPONENTS', $travelPayload);

        // Placement async — topic EXACT.
        $placementPayload = $this->firstSsePayload($selectors['hubToken'], 'match', $selectors['placementTopic'], fn (): string => $hub->publish(ClubTopicUpdate::private(MercureTopic::forPlacement($clubId), json_encode(['runId' => 'run-e2e', 'status' => 'COMPLETED'], \JSON_THROW_ON_ERROR))));
        self::assertNotNull($placementPayload, 'une update de placement async doit être délivrée à l’abonné du club');
        self::assertStringContainsString('run-e2e', $placementPayload);
    }

    /**
     * Chemin d'abus NOMMÉ — frontière tenant : un abonné d'un AUTRE club, muni d'un jeton VALIDE,
     * ne reçoit RIEN des topics privés du premier. C'est l'invariant SEC-05/06 prouvé au hub, pas
     * seulement dans la forme du claim : même connecté au bon topic, le hub ne délivre une update
     * privée qu'aux porteurs d'un jeton qui l'autorise.
     */
    public function testASubscriberOfAnotherClubNeverReceivesAClubsPrivateUpdates(): void
    {
        [$tokenA, $clubA] = $this->memberOfAFreshClub('owner');
        [$tokenB] = $this->memberOfAFreshClub('intruder');
        $this->fetchSubscriberToken($tokenA); // réchauffe la route pour A (jeton non utilisé ici)
        $intruder = $this->fetchSubscriberToken($tokenB);
        $hub = $this->hub();

        // B s'abonne EXPRÈS au topic de trajets de A (le hub autorise l'abonnement ; seule la
        // délivrance d'une update PRIVÉE est filtrée par l'autorisation du jeton).
        $leaked = $this->firstSsePayload($intruder['hubToken'], 'match', MercureTopic::forTravel($clubA), fn (): string => $hub->publish(ClubTopicUpdate::private(MercureTopic::forTravel($clubA), json_encode(['scope' => 'OPPONENTS', 'done' => 1, 'total' => 1, 'terminal' => true], \JSON_THROW_ON_ERROR))));

        self::assertNull($leaked, 'le hub ne doit JAMAIS délivrer une update privée d’un club à l’abonné d’un autre club (SEC-05/06)');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function hub(): HubInterface
    {
        $hub = self::getContainer()->get(HubInterface::class);
        \assert($hub instanceof HubInterface);

        return $hub;
    }

    /**
     * Ouvre un abonnement SSE RÉEL au hub, publie l'update, et rend le premier payload `data:`
     * reçu — ou `null` si rien n'arrive avant le timeout (preuve de NON-délivrance).
     *
     * La séquence compte : on force l'ouverture de la connexion (lecture du code HTTP) AVANT de
     * publier — un abonné SSE ne reçoit que ce qui est émis APRÈS sa connexion, jamais l'historique.
     */
    private function firstSsePayload(string $hubToken, string $matchParam, string $selector, callable $publish): ?string
    {
        $base = (string) (getenv('MERCURE_URL') ?: $_ENV['MERCURE_URL'] ?? $_SERVER['MERCURE_URL'] ?? '');
        self::assertNotSame('', $base, 'MERCURE_URL doit pointer le hub dans l’environnement de test');

        $client = HttpClient::create();
        $url = $base . '?' . $matchParam . '=' . rawurlencode($selector);
        $response = $client->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $hubToken, 'Accept' => 'text/event-stream'],
            'buffer' => false,
            'timeout' => self::STREAM_TIMEOUT,
        ]);

        // Force l'envoi de la requête et la réception des en-têtes : la connexion SSE est ouverte.
        self::assertSame(200, $response->getStatusCode(), 'le hub doit accepter l’abonnement (jeton 1.0 valide, protocole commun)');

        $publish();

        $buffer = '';
        $payload = null;
        $deadline = microtime(true) + self::STREAM_TIMEOUT;
        foreach ($client->stream($response, self::STREAM_TIMEOUT) as $chunk) {
            if ($chunk->isTimeout() || $chunk->isLast()) {
                break;
            }
            $buffer .= $chunk->getContent();
            foreach (explode("\n", $buffer) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $payload = trim(substr($line, 5));
                    break 2;
                }
            }
            if (microtime(true) > $deadline) {
                break;
            }
        }
        $response->cancel();

        return $payload;
    }

    /**
     * Appelle la VRAIE route d'auth et rend le jeton hub (valeur du cookie, lu par CHEMIN et non
     * par nom — le nom du cookie est une décision 1.0 encore ouverte) + les 3 sélecteurs servis.
     *
     * @return array{hubToken: string, topicTemplate: string, travelTopic: string, placementTopic: string}
     */
    private function fetchSubscriberToken(string $jwt): array
    {
        $this->client->request('GET', '/api/mercure/auth', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $hubToken = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('/.well-known/mercure' === $cookie->getPath()) {
                $hubToken = (string) $cookie->getValue();
            }
        }
        self::assertNotNull($hubToken, 'la route d’auth doit poser le jeton de souscription en cookie sur le chemin du hub');

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($body));

        return [
            'hubToken' => $hubToken,
            'topicTemplate' => (string) ($body['topicTemplate'] ?? ''),
            'travelTopic' => (string) ($body['travelTopic'] ?? ''),
            'placementTopic' => (string) ($body['placementTopic'] ?? ''),
        ];
    }

    /** @return array{string, string} [JWT applicatif, clubId] d'un gestionnaire actif d'un club neuf */
    private function memberOfAFreshClub(string $tag): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club Mercure ' . $tag);
        $club->setSlug('club-mercure-e2e-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('MRE' . strtoupper(substr(md5($tag . $uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('mercure-e2e-' . $tag . $uid . '@test.com');
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
