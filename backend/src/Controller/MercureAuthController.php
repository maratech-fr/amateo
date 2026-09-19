<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mercure\MercureTopic;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * FRT-04 — le JETON DE SOUSCRIPTION Mercure, posé en cookie httpOnly.
 *
 * Le backend publie l'avancement des générations sur des topics PRIVÉS
 * (`club:{clubId}:schedule:{id}`, SEC-05/06) depuis des mois — dans le vide :
 * aucun client ne pouvait s'y abonner. Ce contrôleur ferme la boucle : un
 * membre authentifié du club reçoit un JWT hub signé du MÊME secret que le
 * publieur, dont l'autorisation `subscribe` est un URI template borné à SES
 * générations — `club:{clubId}:schedule:{id}` où seul `{id}` varie. Rien
 * d'autre : pas de wildcard global, pas d'autre club (docs/security/mercure.md).
 *
 * COOKIE httpOnly, jamais un token rendu au JS : le front n'a rien à stocker
 * (le JWT applicatif en localStorage est déjà le point faible SEC-16-audit —
 * on n'en ajoute pas un second). Path borné au hub ; le navigateur ne l'envoie
 * qu'à `/.well-known/mercure`, même origine (proxys vite/nginx dev et prod).
 * TTL court : l'EventSource se reconnecte, le front rappelle cette route.
 */
final class MercureAuthController extends AbstractController
{
    private const int TTL_SECONDS = 3600;

    public function __construct(
        #[Autowire(env: 'MERCURE_JWT_SECRET')]
        private readonly string $mercureSecret,
        #[Autowire(env: 'bool:JWT_COOKIE_SECURE')]
        private readonly bool $cookieSecure,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/api/mercure/auth', name: 'api_mercure_auth', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        // Le club vient du TENANT RÉSOLU (listener après firewall) — jamais d'un
        // paramètre client : le scope du jeton EST la frontière de sécurité.
        $clubId = $request?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
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
        $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText($this->mercureSecret));
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        // `{id}` est une expression URI-template : le hub matche
        // `club:X:schedule:abc` contre ce sélecteur — et RIEN d'un autre club.
        $topicTemplate = MercureTopic::selectorForClub($clubId);
        // C6 — le topic du CALCUL DES TRAJETS, FIXE (pas de joker) et scopé au club : le
        // même JWT autorise l'abonnement aux deux flux (génération + trajets), aucun autre
        // club (le club est déjà revalidé canonique ci-dessus, aucune interpolation variable).
        $travelTopic = MercureTopic::forTravel($clubId);
        $token = $config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify(\sprintf('+%d seconds', self::TTL_SECONDS)))
            ->withClaim('mercure', ['subscribe' => [$topicTemplate, $travelTopic]])
            ->getToken($config->signer(), $config->signingKey());

        // Le template est AUSSI le topic auquel s'abonner : le front ne connaît pas
        // son clubId (tenant résolu côté serveur, aucun header) — il s'abonne au
        // sélecteur tel quel et reçoit les updates de TOUTES ses générations avec
        // un seul EventSource (délivrance template↔topic exact prouvée sur le hub).
        // `travelTopic` (champ ADDITIF, C6) : le topic FIXE du calcul des trajets, auquel le
        // front s'abonne tel quel (il ne connaît pas son clubId — tenant résolu serveur).
        $response = $this->json(['expiresIn' => self::TTL_SECONDS, 'topicTemplate' => $topicTemplate, 'travelTopic' => $travelTopic]);
        $response->headers->setCookie(new Cookie(
            name: 'mercureAuthorization',
            value: $token->toString(),
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
