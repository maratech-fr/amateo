<?php

declare(strict_types=1);

namespace App\Tests;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Construit un service du conteneur de test en remplaçant certaines dépendances par des
 * doublures, choisies par TYPE (jamais par position) : un constructeur qui gagne ou réordonne
 * un argument ne casse pas le test qui ne regarde que ses doublures (hub Mercure espion, mailer
 * espion, moteur simulé, horloge figée…).
 *
 * Résolution de chaque paramètre, dans l'ordre : doublure du même type → classe à construire
 * elle aussi avec les mêmes doublures (`$buildAlso`, p. ex. un notifier qui doit recevoir le
 * mailer espion) → `#[Autowire(service: …)]` → service du conteneur pour ce type → valeur par
 * défaut.
 *
 * Utilisable depuis un `KernelTestCase` / `WebTestCase` (noyau démarré).
 */
trait BuildsServiceWithDoublesTrait
{
    /**
     * @template T of object
     *
     * @param class-string<T>            $class
     * @param array<string, object|null> $doubles   doublures, indexées par le type (FQCN) du paramètre
     * @param list<string>               $buildAlso classes à construire récursivement avec les mêmes doublures
     *
     * @return T
     */
    private function buildWithDoubles(string $class, array $doubles, array $buildAlso = []): object
    {
        $constructor = new ReflectionClass($class)->getConstructor();
        $arguments = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if (null !== $typeName && \array_key_exists($typeName, $doubles)) {
                $arguments[] = $doubles[$typeName];

                continue;
            }

            if (null !== $typeName && \in_array($typeName, $buildAlso, true)) {
                $arguments[] = $this->buildWithDoubles($typeName, $doubles, $buildAlso);

                continue;
            }

            $autowire = $parameter->getAttributes(Autowire::class)[0] ?? null;
            if (null !== $autowire) {
                $value = $autowire->newInstance()->value;
                if ($value instanceof Reference) {
                    $arguments[] = static::getContainer()->get((string) $value);

                    continue;
                }
                if (\is_string($value) && 1 === preg_match('/^%env\((?:[a-z]+:)?([A-Z0-9_]+)\)%$/', $value, $m)) {
                    $arguments[] = (string) ($_SERVER[$m[1]] ?? $_ENV[$m[1]] ?? getenv($m[1]));

                    continue;
                }
            }

            if (null !== $typeName && (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) && static::getContainer()->has($typeName)) {
                $arguments[] = static::getContainer()->get($typeName);

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            throw new RuntimeException(\sprintf('Impossible de résoudre le paramètre $%s de %s pour le test.', $parameter->getName(), $class));
        }

        return new $class(...$arguments);
    }
}
