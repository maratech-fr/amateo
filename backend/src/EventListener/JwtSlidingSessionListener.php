<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Security\JwtCookieFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTEncodeFailureException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * P4-291 — SESSION GLISSANTE du JWT applicatif de club.
 *
 * Problème : le JWT en cookie httpOnly (SEC-16, {@see JwtCookieFactory}) vit
 * `token_ttl` (1 h). Un gestionnaire qui travaille activement est déconnecté
 * toutes les heures, en plein geste — friction pure, sans gain de sécurité : il
 * se reconnecte aussitôt. On veut une fenêtre qui GLISSE tant qu'on s'en sert,
 * mais JAMAIS éternelle — un poste partagé laissé ouvert doit finir par se
 * fermer. D'où deux bornes : 1 h d'inactivité (le TTL hérité) ET 12 h absolues
 * depuis l'authentification initiale, quoi qu'il arrive.
 *
 * ⚠ CE QUI NE GLISSE PAS, par construction :
 *  - le flux Bearer (scripts d'ops, contexts Behat, helpers e2e) : il porte un
 *    en-tête `Authorization`, jamais le cookie seul — un script n'est pas un
 *    navigateur, il re-signe ses jetons lui-même (docs/security/jwt-cookie.md) ;
 *  - la console super-admin (`^/api/admin`) : identité et firewall séparés
 *    (SA0), hors périmètre de ce correctif ;
 *  - `^/api/login` et `^/api/logout` : lexik y pose/efface déjà le cookie.
 *
 * ⚠ HORLOGE RÉELLE obligatoire (`app.clock.real`, jamais le service `clock`
 * décoré par {@see \App\Clock\ClubClock}) : `TenantFilterListener` pose
 * `_club_id` même sur un club démo, dont l'horloge SIMULÉE peut être calée en
 * l'an 2000 — mesurer la fenêtre de session dessus la casserait. Les `iat`/`exp`
 * des jetons sont eux aussi des `time()` réels (lexik + JwtCookieFactory), la
 * comparaison doit rester sur le même référentiel. Même invariant que les durées
 * de sécurité de P4-304 ({@see \App\Service\ClubInvitationManager}).
 */
final class JwtSlidingSessionListener implements EventSubscriberInterface
{
    /** En deçà de cet âge (s), on ne re-signe pas : évite une re-signature à CHAQUE requête. */
    private const int REISSUE_MIN_AGE = 300;

    /** Borne ABSOLUE (s) depuis l'authentification initiale : 12 h, plafond infranchissable. */
    private const int ABSOLUTE_LIFETIME = 43200;

    public function __construct(
        private readonly Security $security,
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly JwtCookieFactory $cookieFactory,
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.jwt_cookie_name')]
        private readonly string $cookieName,
        #[Autowire(param: 'lexik_jwt_authentication.token_ttl')]
        private readonly int $ttl,
    ) {}

    /** @return array<string, string|array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [
            // Pose `auth_at` à la NAISSANCE du jeton (login lexik, verifyEmail, démo) :
            // l'origine que la borne 12 h mesurera, reportée ensuite verbatim.
            Events::JWT_CREATED => 'onJwtCreated',
            // Priorité basse : le token de sécurité est encore en place pendant toute
            // la réponse d'un firewall stateless, et rien ne doit effacer notre cookie après.
            KernelEvents::RESPONSE => ['onKernelResponse', -16],
        ];
    }

    /**
     * Claim d'origine `auth_at`, posé une seule fois à la création. À ce stade
     * `iat` n'est pas encore dans le payload (lexik l'ajoute à l'encodage) : on
     * se cale sur l'horloge réelle, qui vaut le `time()` que l'encodeur posera.
     * Le `isset` garantit qu'un jeton déjà porteur n'est jamais réinitialisé.
     */
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $data = $event->getData();
        if (isset($data['auth_at'])) {
            return;
        }

        $data['auth_at'] = $this->claimAsInt($data['iat'] ?? null) ?? $this->clock->now()->getTimestamp();
        $event->setData($data);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isSlidablePath($request)) {
            return;
        }

        // Seul le NAVIGATEUR glisse : cookie BEARER présent ET aucun en-tête
        // Authorization. Le flux Bearer (scripts/Behat) ne glisse jamais.
        if ($request->headers->has('Authorization')) {
            return;
        }

        $jwt = $request->cookies->get($this->cookieName);
        if (!\is_string($jwt) || '' === $jwt) {
            return;
        }

        // Identité CLUB authentifiée seulement ; un jeton expiré n'a pas d'utilisateur
        // (le firewall a déjà rendu 401) → zéro ré-émission, zéro Set-Cookie.
        if (!$this->security->getUser() instanceof User) {
            return;
        }

        try {
            $payload = $this->jwtEncoder->decode($jwt);
        } catch (JWTDecodeFailureException) {
            return;
        }

        $iat = $this->claimAsInt($payload['iat'] ?? null);
        if (null === $iat) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($now - $iat < self::REISSUE_MIN_AGE) {
            return;
        }

        // Origine : `auth_at` si présent, sinon repli migration-safe sur `iat`
        // (jeton déjà en circulation avant ce correctif).
        $authAt = $this->claimAsInt($payload['auth_at'] ?? null) ?? $iat;

        $deadline = $authAt + self::ABSOLUTE_LIFETIME;
        if ($deadline <= $now) {
            // 12 h atteintes : le glissement s'arrête ici. Le dernier cookie émis
            // a déjà son `exp` plafonné à cette échéance, il meurt donc exactement à 12 h.
            return;
        }

        $payload['auth_at'] = $authAt; // reporté verbatim, jamais réinitialisé
        $payload['iat'] = $now;
        // Plafonnement : le jeton rejoué en continu meurt EXACTEMENT à 12 h,
        // pas « à la dernière heure entamée ».
        $payload['exp'] = min($now + $this->ttl, $deadline);

        try {
            $reissued = $this->jwtEncoder->encode($payload);
        } catch (JWTEncodeFailureException) {
            return;
        }

        $event->getResponse()->headers->setCookie($this->cookieFactory->create($reissued));
    }

    private function isSlidablePath(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api')) {
            return false;
        }

        foreach (['/api/admin', '/api/login', '/api/logout'] as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return false;
            }
        }

        return true;
    }

    private function claimAsInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_numeric($value) ? (int) $value : null;
    }
}
