<?php

declare(strict_types=1);

namespace App\Access;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lit la déclaration d'accès de la route d'une requête routée (`_route`, `_controller`,
 * `_api_resource_class`, la méthode HTTP). Aucun effet : à ce stade, rien ne décide d'après elle.
 *
 * Règle de résolution, pour une route d'une ressource sous `App\` :
 *  - servie par API Platform (`main_controller`) ou non exposée (`not_exposed`) → la déclaration
 *    `#[ClubResource]` de la ressource (lecture ou écriture selon la méthode : au lecteur de l'appel) ;
 *  - servie par un contrôleur maison → la déclaration de ce contrôleur, jamais celle de la ressource.
 * Toute autre route → la déclaration de son contrôleur : `#[ClubFunction]` ou `#[AccountFunction]`,
 * celle de la méthode l'emportant sur celle de la classe. Les routes du cadre d'API Platform n'en
 * résolvent aucune.
 */
final class AccessDeclarationResolver
{
    /** Les routes du cadre d'API Platform qui ne portent aucune classe de ressource. */
    public const array FRAMEWORK_ROUTES = ['api_doc', 'api_genid', 'api_validation_errors', 'api_entrypoint', 'api_jsonld_context'];

    private const array RESOURCE_CONTROLLERS = ['api_platform.symfony.main_controller', 'api_platform.action.not_exposed'];

    /** @var array<string, ClubFunction|ClubResource|AccountFunction|null> */
    private array $cache = [];

    public function isFrameworkRoute(Request $request): bool
    {
        return \in_array($request->attributes->get('_route'), self::FRAMEWORK_ROUTES, true)
            || str_starts_with($this->resourceClass($request), 'ApiPlatform\\');
    }

    public function for(Request $request): ClubFunction|ClubResource|AccountFunction|null
    {
        if ($this->isFrameworkRoute($request)) {
            return null;
        }

        $controller = $request->attributes->get('_controller');
        if (!\is_string($controller) || '' === $controller) {
            return null;
        }

        $resource = $this->resourceClass($request);
        if (str_starts_with($resource, 'App\\') && \in_array($controller, self::RESOURCE_CONTROLLERS, true)) {
            return $this->cache['resource:' . $resource] ??= $this->resourceDeclaration($resource);
        }

        if (!\array_key_exists($controller, $this->cache)) {
            $this->cache[$controller] = $this->controllerDeclaration($controller);
        }

        return $this->cache[$controller];
    }

    private function resourceClass(Request $request): string
    {
        $resource = $request->attributes->get('_api_resource_class');

        return \is_string($resource) ? $resource : '';
    }

    private function resourceDeclaration(string $resource): ?ClubResource
    {
        if (!class_exists($resource)) {
            return null;
        }
        $found = new ReflectionClass($resource)->getAttributes(ClubResource::class);

        return [] === $found ? null : $found[0]->newInstance();
    }

    private function controllerDeclaration(string $controller): ClubFunction|AccountFunction|null
    {
        [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new ReflectionClass($class);

        return ($reflection->hasMethod($method) ? $this->declarationOn($reflection->getMethod($method)) : null)
            ?? $this->declarationOn($reflection);
    }

    /** @param ReflectionClass<object>|ReflectionMethod $target */
    private function declarationOn(ReflectionClass|ReflectionMethod $target): ClubFunction|AccountFunction|null
    {
        $club = $target->getAttributes(ClubFunction::class);
        if ([] !== $club) {
            return $club[0]->newInstance();
        }
        $account = $target->getAttributes(AccountFunction::class);

        return [] === $account ? null : $account[0]->newInstance();
    }
}
