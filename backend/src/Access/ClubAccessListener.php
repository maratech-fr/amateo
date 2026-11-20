<?php

declare(strict_types=1);

namespace App\Access;

use App\Security\PublicApiRouteCatalog;
use App\Service\ManagementAccessGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le contrôle central du rôle : refuse en 403 une fonction « gestionnaire » appelée par un Membre,
 * et toute route `^/api/` qui ne dit pas à qui elle est ouverte.
 *
 * Place : `kernel.request`, priorité 4 — après le routeur (32), le pare-feu (8), le locataire (7,
 * qui a posé `_club_id` et la saison), le débit (6), les caps et le crédit (5) ; avant
 * `kernel.controller` (garde « saison archivée ») et avant tout contrôleur : rien n'est lu ni
 * écrit pour un Membre refusé. Requête principale seulement : la sous-requête du rendu d'erreur
 * d'API Platform n'est pas contrôlée une seconde fois.
 *
 * Ordre de lecture d'une route `^/api/` : console (`/api/admin/`, son propre pare-feu), dev
 * (`/api/dev/`), cadre d'API Platform, publique (`PublicApiRouteCatalog`), `api_login` (porte du
 * pare-feu `login`, sans contrôleur), puis la déclaration — absente, refusée à tous.
 *
 * Méthode : celle que le routeur a vue (`Request::getMethod()`, en-tête de surcharge compris sur un
 * POST) ; GET et HEAD lisent, toute autre méthode écrit. Une fonction « compte »
 * (`#[AccountFunction]`) n'est pas contrôlée ici : elle ne dépend d'aucun club.
 */
final class ClubAccessListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly AccessDeclarationResolver $resolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return;
        }

        $method = $request->getMethod();
        if (str_starts_with($path, '/api/admin/')
            || str_starts_with($path, '/api/dev/')
            || $this->resolver->isFrameworkRoute($request)
            || $this->isApiPlatformRoute($request)
            || PublicApiRouteCatalog::matches($path, $method)
            || 'api_login' === $request->attributes->get('_route')) {
            return;
        }

        $declaration = $this->resolver->for($request);
        if (null === $declaration) {
            throw new AccessDeniedHttpException(ManagementAccessGuard::REFUSAL);
        }

        if (Audience::MANAGER === $this->audienceOf($declaration, $method) && !$this->managementAccessGuard->isManager()) {
            throw new AccessDeniedHttpException(ManagementAccessGuard::REFUSAL);
        }
    }

    /** Une route servie par le cadre lui-même (contrôleur sous `ApiPlatform\`). */
    private function isApiPlatformRoute(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        return \is_string($controller) && str_starts_with($controller, 'ApiPlatform\\');
    }

    private function audienceOf(ClubFunction|ClubResource|AccountFunction $declaration, string $method): ?Audience
    {
        $read = \in_array($method, ['GET', 'HEAD'], true);

        return match (true) {
            $declaration instanceof ClubFunction => $declaration->audience,
            $declaration instanceof ClubResource => $read ? $declaration->read : $declaration->write,
            default => null,
        };
    }
}
