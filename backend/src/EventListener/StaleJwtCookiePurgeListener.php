<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\JwtCookieFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D3 — un cookie de session mort est PURGÉ au premier refus.
 *
 * Sur une route PROTÉGÉE, le neutraliseur ({@see PublicApiCredentialNeutralizer}) ne joue
 * pas : un cookie `BEARER` mort y reçoit légitimement 401. Mais le navigateur garde alors un
 * jeton que le JS ne peut pas voir (httpOnly), donc pas effacer : chaque requête re-tente et
 * re-échoue. Ce listener EFFACE ce cookie dès le 401, aux attributs EXACTS de la pose
 * ({@see JwtCookieFactory::clear()}), pour que le navigateur reparte propre.
 *
 * Les trois événements d'échec lexik couvrent les trois morts d'un jeton :
 *  - {@see Events::JWT_EXPIRED} : jeton expiré ;
 *  - {@see Events::JWT_INVALID} : signature invalide, charge malformée, ET compte supprimé
 *    (`UserNotFoundException` levée par le provider → l'authentificateur la route en JWT_INVALID,
 *    pas en expiré) ;
 *  - {@see Events::AUTHENTICATION_FAILURE} : filet pour tout autre échec d'auth portant le cookie.
 * Jamais `JWT_NOT_FOUND` : sans jeton, il n'y a rien à purger.
 *
 * ⚠ SEULEMENT le cookie du NAVIGATEUR : on purge SSI le cookie `BEARER` est présent ET aucun
 * en-tête `Authorization` n'accompagne la requête. Le flux Bearer (scripts d'ops, contexts
 * Behat, helpers e2e) re-signe lui-même ses jetons — un script n'est pas un navigateur, et la
 * présence de l'en-tête suffit à suspendre la purge, même avec un cookie mort à côté.
 */
#[AsEventListener(event: Events::JWT_INVALID, method: 'onAuthenticationFailure')]
#[AsEventListener(event: Events::JWT_EXPIRED, method: 'onAuthenticationFailure')]
#[AsEventListener(event: Events::AUTHENTICATION_FAILURE, method: 'onAuthenticationFailure')]
final readonly class StaleJwtCookiePurgeListener
{
    public function __construct(
        private JwtCookieFactory $cookieFactory,
        #[Autowire(param: 'app.jwt_cookie_name')]
        private string $cookieName,
    ) {}

    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request instanceof Request) {
            return;
        }

        // Un en-tête Authorization = un script qui gère son propre jeton : jamais de purge.
        if ($request->headers->has('Authorization')) {
            return;
        }

        $cookie = $request->cookies->get($this->cookieName);
        if (!\is_string($cookie) || '' === $cookie) {
            return;
        }

        $response = $event->getResponse();
        if (!$response instanceof Response) {
            return;
        }

        $response->headers->setCookie($this->cookieFactory->clear());
        $event->setResponse($response);
    }
}
