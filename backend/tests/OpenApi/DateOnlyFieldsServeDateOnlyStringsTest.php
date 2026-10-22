<?php

declare(strict_types=1);

namespace App\Tests\OpenApi;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lot bugs Rillieux #16 — une propriété de DATE (jamais d'heure) sert une CHAÎNE `Y-m-d`,
 * jamais un `DateTimeImmutable` sérialisé brut (RFC 3339 « 2026-12-28T00:00:00+00:00 »).
 *
 * Germe du bug « NaN/NaN/NaN » de l'onglet Réglages (retour terrain Rillieux, 2026-10-21) :
 * `SchedulePlanResource` servait `startDate` en RFC 3339, le front faisait `split("-")` dessus
 * et dérivait une semaine « NaN ». Toutes les ressources de date-seule servent désormais `Y-m-d`
 * (`->format('Y-m-d')`), ce que le front parse sans ambiguïté — SAUF à ce que quelqu'un
 * (re)déclare une propriété `*Date`/`*Deadline` en `DateTimeImmutable`. Ce garde rend la règle
 * opposable.
 *
 * Portée : toute propriété PUBLIQUE sérialisée en lecture (`#[Groups(['read'])]`) d'une classe
 * `src/ApiResource/*.php` dont le NOM désigne une date-seule — se termine par `Date` ou
 * `Deadline` (`startDate`, `endDate`, `matchDate`, `deadline`, `entryDeadline`,
 * `effectiveEntryDeadline`…). Les HORODATAGES (`*At` : `createdAt`, `updatedAt`, `reviewedAt`…)
 * portent une heure et restent légitimement `DateTimeImmutable` (RFC 3339) — ils sont HORS
 * portée (un `*At` ne se termine jamais par `Date`/`Deadline`).
 *
 * Exemptions : {@see self::EXEMPTIONS} — NOMINATIVES et MOTIVÉES, jamais en masse (une propriété
 * de date-seule qu'on servirait délibérément en datetime devrait expliquer pourquoi).
 *
 * Régime : garde de CONTRAT statique (réflexion sur les DTO, aucun kernel) — testsuite
 * `Integration`, job CI `backend-tests` (pas un step `blocking-tests`, aucun invariant runtime).
 */
final class DateOnlyFieldsServeDateOnlyStringsTest extends TestCase
{
    private const string API_RESOURCE_DIR = __DIR__ . '/../../src/ApiResource';

    private const string NAMESPACE = 'App\\ApiResource\\';

    public function testEveryDateOnlyPropertyIsTypedString(): void
    {
        $violations = [];

        foreach ($this->apiResourceClasses() as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (!$this->isReadSerialized($property) || !$this->isDateOnlyName($property->getName())) {
                    continue;
                }

                $key = $reflection->getShortName() . '::' . $property->getName();
                if (\array_key_exists($key, $this->exemptions())) {
                    continue;
                }

                $type = $property->getType();
                $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
                if ('string' !== $typeName) {
                    $violations[] = \sprintf('%s : typé %s (devrait être `string`, servi en `->format(\'Y-m-d\')`)', $key, $typeName);
                }
            }
        }

        self::assertSame([], $violations, \sprintf(
            "Des propriétés de DATE-SEULE d'une ressource API ne servent pas une chaîne `Y-m-d` :\n  - %s\n\n"
            . "Une propriété dont le nom finit par `Date`/`Deadline` désigne un jour, pas un instant : la\n"
            . "servir en `DateTimeImmutable` l'envoie en RFC 3339 (« …T00:00:00+00:00 »), que le front\n"
            . "casse sur `split(\"-\")` → « NaN » (bug onglet Réglages, Rillieux 2026-10-21). Typer la\n"
            . "propriété `string` et la remplir avec `->format('Y-m-d')` (patron `CalendarEntryResource`).\n"
            . 'Date servie en datetime À DESSEIN → self::EXEMPTIONS avec sa raison structurelle.',
            implode("\n  - ", $violations),
        ));
    }

    /**
     * Témoin anti-tautologie : le garde DOIT discriminer. Les horodatages (`createdAt`…) RESTENT
     * `DateTimeImmutable` et NE sont PAS attrapés — sinon le garde confondrait date et instant.
     */
    public function testTimestampPropertiesAreNotPoliced(): void
    {
        self::assertFalse($this->isDateOnlyName('createdAt'));
        self::assertFalse($this->isDateOnlyName('updatedAt'));
        self::assertFalse($this->isDateOnlyName('lastReminderAt'));
        self::assertTrue($this->isDateOnlyName('startDate'));
        self::assertTrue($this->isDateOnlyName('endDate'));
        self::assertTrue($this->isDateOnlyName('deadline'));
        self::assertTrue($this->isDateOnlyName('entryDeadline'));
        self::assertTrue($this->isDateOnlyName('matchDate'));
    }

    /**
     * Propriétés de date-seule servies DÉLIBÉRÉMENT autrement que `string` Y-m-d.
     * Clé = `ClasseCourte::propriété`, valeur = la raison structurelle. Aucune aujourd'hui.
     *
     * @return array<string, string>
     */
    private function exemptions(): array
    {
        return [];
    }

    private function isDateOnlyName(string $name): bool
    {
        if (1 === preg_match('/at$/i', $name)) {
            return false;
        }

        return 1 === preg_match('/(?:date|deadline)$/i', $name);
    }

    private function isReadSerialized(ReflectionProperty $property): bool
    {
        foreach ($property->getAttributes(Groups::class) as $attribute) {
            /** @var array{0?: list<string>|string} $args */
            $args = $attribute->getArguments();
            $groups = $args[0] ?? [];
            if (\is_array($groups) && \in_array('read', $groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function apiResourceClasses(): array
    {
        $classes = [];
        /** @var SplFileInfo $file */
        foreach (new FilesystemIterator(self::API_RESOURCE_DIR, FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            /** @var class-string $class */
            $class = self::NAMESPACE . $file->getBasename('.php');
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
