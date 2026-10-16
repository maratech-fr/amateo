<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mercure\MercureTopic;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Routing\Attribute\Route;

/**
 * FRT-04 — le JETON DE SOUSCRIPTION Mercure, posé en cookie httpOnly.
 *
 * Le backend publie l'avancement des générations sur des topics PRIVÉS
 * (`/clubs/{clubId}/schedules/{id}`, SEC-05/06) depuis des mois — dans le vide :
 * aucun client ne pouvait s'y abonner. Ce contrôleur ferme la boucle : un
 * membre authentifié du club reçoit un JWT hub signé du MÊME secret que le
 * publieur, dont l'autorisation `subscribe` est bornée à SES générations
 * (`/clubs/{clubId}/schedules/:id` où seul `:id` varie) + ses deux topics FIXES
 * (trajets, placement). Rien d'autre : pas de wildcard global, pas d'autre club
 * (docs/security/mercure.md).
 *
 * Mercure 1.0 (lot 4 PR C) — le jeton est un ACCESS TOKEN RFC 9068 : en-tête
 * `typ: at+jwt`, autorisations portées par le claim `authorization_details`
 * (RFC 9396) et claims obligatoires `iss`/`aud`/`sub`/`client_id`. Le hub (1.x)
 * refuse (401) un jeton 0.x. Construit par {@see LcobucciFactory} en
 * {@see ProtocolVersion::V1} (même secret HMAC-SHA256 que le publieur) pour que
 * la forme colle EXACTEMENT à ce qu'attend le hub.
 * - `iss` = émetteur de confiance du hub (`MERCURE_TRUSTED_ISSUERS`, même valeur
 *   des deux côtés) ; `aud` = identifiant de ressource du hub (`MERCURE_PUBLIC_URL`,
 *   épinglé côté hub par `resource_identifier`) — un décalage d'`iss`/`aud` = 401.
 *
 * COOKIE httpOnly, jamais un token rendu au JS : le front n'a rien à stocker
 * (le JWT applicatif en localStorage est déjà le point faible SEC-16-audit —
 * on n'en ajoute pas un second). Path borné au hub ; le navigateur ne l'envoie
 * qu'à `/.well-known/mercure`, même origine (proxys vite/nginx dev et prod).
 * Le NOM du cookie suit l'environnement (C1) : `__Secure-mercure_access_token`
 * quand le cookie est sécurisé (prod HTTPS), `mercure_access_token` sans préfixe
 * sinon (dev HTTP / tests) — un navigateur rejette un cookie `__Secure-` posé
 * sans `Secure`, et la directive `cookie_name` du hub suit la même règle par
 * environnement. TTL court : l'EventSource se reconnecte, le front rappelle
 * cette route.
 */
final class MercureAuthController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    private const int TTL_SECONDS = 3600;

    /** Le `client_id` (RFC 9068) du porteur du jeton de souscription : le front applicatif. */
    private const string CLIENT_ID = 'amateo-frontend';

    public function __construct(
        #[Autowire(env: 'MERCURE_JWT_SECRET')]
        private readonly string $mercureSecret,
        #[Autowire(env: 'bool:JWT_COOKIE_SECURE')]
        private readonly bool $cookieSecure,
        // `aud` du jeton 1.0 : l'identifiant de ressource du hub, épinglé côté hub par
        // `resource_identifier` sur cette MÊME valeur — un `aud` qui n'y correspond pas = 401.
        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private readonly string $mercurePublicUrl,
        // `iss` du jeton 1.0 : l'émetteur de confiance du hub (`issuer` du Caddyfile),
        // la MÊME valeur des deux côtés (une seule source d'env, hub ↔ backend).
        #[Autowire(env: 'MERCURE_TRUSTED_ISSUERS')]
        private readonly string $trustedIssuer,
        private readonly RequestStack $requestStack,
        // BCK-34 — horloge RÉELLE : le TTL du jeton de souscription est une durée de
        // SÉCURITÉ. Sous l'horloge décorée, un membre d'un club démo à date simulée
        // PASSÉE aurait un jeton déjà expiré, à date FUTURE un jeton quasi éternel.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/api/mercure/auth', name: 'api_mercure_auth', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        // Le club vient du TENANT RÉSOLU (listener après firewall) — jamais d'un
        // paramètre client : le scope du jeton EST la frontière de sécurité.
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        // Défense en profondeur (revue sécu FRT-04) : le sélecteur EST la frontière,
        // donc ce qu'on y interpole se revalide ici. Une forme non canonique
        // (`{32hex}` — que Postgres accepte, mais qui est un varname URI-template
        // valide) ferait matcher les topics de tous les clubs. Le listener la
        // refuse déjà en 403 ; si un jour un autre chemin pose `_club_id`, cette
        // route ne signera toujours qu'un UUID canonique.
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clubId)) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $this->mercureSecret) {
            // Secret absent = hub inconfiguré : dire 503 plutôt que signer du vide.
            return $this->json(['error' => 'Mercure is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        // `:id` est un groupe nommé URLPattern : le hub matche
        // `/clubs/X/schedules/abc` contre ce sélecteur — et RIEN d'un autre club.
        $topicTemplate = MercureTopic::selectorForClub($clubId);
        // C6 — le topic du CALCUL DES TRAJETS, FIXE (pas de joker) et scopé au club : le
        // même JWT autorise l'abonnement aux flux (génération + trajets + placement), aucun autre
        // club (le club est déjà revalidé canonique ci-dessus, aucune interpolation variable).
        $travelTopic = MercureTopic::forTravel($clubId);
        // Le topic du PLACEMENT ASYNCHRONE des matchs, FIXE et scopé au club (un seul placement
        // à la fois) — ajouté au MÊME endroit, même garde canonique, jamais un joker inter-club.
        $placementTopic = MercureTopic::forPlacement($clubId);

        // UNE entrée `authorization_details` (`subscribe`) portant les trois topics : le gabarit
        // de génération apparié en URLPattern, les deux topics fixes en EXACT (apparié littéral).
        $factory = new LcobucciFactory($this->mercureSecret, 'hmac.sha256', null, '', ProtocolVersion::V1);
        $token = $factory->create(
            [new Grant([Grant::ACTION_SUBSCRIBE], ['urlpattern' => [$topicTemplate], 'exact' => [$travelTopic, $placementTopic]])],
            [
                'iss' => $this->trustedIssuer,
                'aud' => $this->mercurePublicUrl,
                'sub' => $clubId,
                'client_id' => self::CLIENT_ID,
                // iat/exp sur l'horloge RÉELLE (BCK-34) — on les pose explicitement plutôt que
                // laisser la fabrique les déduire, pour que la durée de sécurité ne suive JAMAIS
                // l'« aujourd'hui » simulé d'un club démo.
                'iat' => $now,
                'exp' => $now->modify(\sprintf('+%d seconds', self::TTL_SECONDS)),
            ],
        );

        // Le template est AUSSI le topic auquel s'abonner : le front ne connaît pas
        // son clubId (tenant résolu côté serveur, aucun header) — il s'abonne au
        // sélecteur tel quel (`match_urlpattern`) et reçoit les updates de TOUTES ses
        // générations avec un seul EventSource. `travelTopic`/`placementTopic` (champs
        // ADDITIFS) : les topics FIXES auxquels il s'abonne en EXACT (`match`).
        $response = $this->json(['expiresIn' => self::TTL_SECONDS, 'topicTemplate' => $topicTemplate, 'travelTopic' => $travelTopic, 'placementTopic' => $placementTopic]);
        $response->headers->setCookie(new Cookie(
            // C1 — un cookie `__Secure-` EXIGE le flag `Secure` (contrat de préfixe appliqué par
            // Symfony\Cookie) : on ne peut le poser qu'en prod HTTPS. En dev HTTP / tests le nom
            // perd le préfixe, sinon le navigateur le rejetterait en silence. La directive
            // `cookie_name` du hub suit la même règle, par environnement.
            name: $this->cookieSecure ? '__Secure-mercure_access_token' : 'mercure_access_token',
            value: $token,
            expire: $now->getTimestamp() + self::TTL_SECONDS,
            // Le hub est servi SOUS L'ORIGINE du front par les proxys (vite dev,
            // nginx dev/prod) : le cookie ne part que vers lui, jamais vers l'API.
            path: '/.well-known/mercure',
            // SEC-16 : MÊME source que le cookie du JWT applicatif — une variable
            // d'env, jamais `$request->isSecure()`. Le nginx de prod écoute en 80
            // derrière la terminaison TLS et réécrit `X-Forwarded-Proto` avec
            // `$scheme` (docker/frontend/nginx.prod.conf:57) : `isSecure()` y répond
            // FAUX, et ce cookie serait parti sans `Secure`. Le nom de la variable
            // parle du cookie « JWT » — c'en est un ici aussi (jeton de souscription
            // signé), et le réglage est le même : une seule question, une seule
            // réponse (docs/security/jwt-cookie.md).
            secure: $this->cookieSecure,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        ));

        return $response;
    }
}
