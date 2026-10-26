<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\PublicApiRouteCatalog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * D3 — un cookie de session mort ne bloque plus les pages publiques.
 *
 * Le firewall `api` (stateless, `jwt: ~`) fait tourner l'authentificateur lexik dès qu'un
 * jeton est PRÉSENT — même sur une route `PUBLIC_ACCESS`. Un jeton pourri (expiré, signature
 * invalide, compte supprimé) y échoue AVANT que `access_control` n'accorde l'accès public,
 * et le handler d'échec court-circuite la requête en 401. « Se déconnecter » sur une session
 * expirée, la page publique d'invitation rouverte dans un onglet où traîne un vieux cookie,
 * l'inscription d'un navigateur qui rejoue un jeton mort : tous bloqués par un cookie que le
 * JS ne peut même pas effacer (httpOnly).
 *
 * Correctif : AVANT le firewall, sur toute requête qui matche {@see PublicApiRouteCatalog}
 * (le miroir gardé des règles `PUBLIC_ACCESS` du firewall `api`), on RETIRE le cookie `BEARER`
 * ET l'en-tête `Authorization`. L'authentificateur ne voit alors plus aucun jeton, `supports()`
 * répond faux, et `access_control` accorde l'accès public — la réponse redevient byte-identique
 * au cas sans jeton.
 *
 * ⚠ NEUTRALISATION INCONDITIONNELLE, même un jeton VALIDE (D-b) : aucune route publique ne lit
 * l'identité (le contrat vaut par construction). Un jeton valide sur une route publique est
 * donc ignoré — aucune identité résolue, aucun GUC tenant posé, aucune session glissante.
 *
 * ⚠ Priorité 9, AVANT le firewall `api` (priorité 8) : il faut retirer les crédentiels avant
 * que l'extracteur de jeton ne les lise. Borné au firewall `api` par le catalogue, qui exclut
 * nominativement `^/api/admin/auth/*` et `^/api/login` : le firewall admin (SA0) et le firewall
 * de login ne sont jamais touchés.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class PublicApiCredentialNeutralizer
{
    public function __construct(
        #[Autowire(param: 'app.jwt_cookie_name')]
        private string $cookieName,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!PublicApiRouteCatalog::matches($request->getPathInfo(), $request->getMethod())) {
            return;
        }

        $request->cookies->remove($this->cookieName);
        $request->headers->remove('Authorization');
    }
}
