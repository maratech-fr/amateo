<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Structure\Family;
use App\Structure\FamilyCatalog;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axe tenant isolation (purges de saison et effacement RGPD) : « ce qui doit suivre un
 * objet se déclare une fois, et une famille non déclarée rougit ».
 *
 * Balayage des métadonnées Doctrine, jamais d'une liste écrite à la main :
 *  1. toute entité porte `#[Family]`, et le catalogue rend exactement l'ensemble des entités ;
 *  2. toute colonne `guid` hors `id`, `clubId`, `seasonId` porte au moins un `#[RefersTo]` ;
 *     `#[RefersTo]` ne se pose que sur une colonne `guid`, jamais sur ces trois-là, vise une famille
 *     déclarée, et porte sa raison quand il dit KEEP ou CUSTOM ; seule exception, nommée avec sa
 *     raison : une colonne qui désigne une table hors Doctrine (`NON_DOCTRINE_TARGETS`) ;
 *  3. aucun cycle entre références « la ligne meurt avec sa cible » (DELETE) — une auto-référence
 *     n'en est pas un : une famille s'efface en une seule requête, elle n'impose aucun ordre entre
 *     familles ; un cycle trouvé ici est un retour au fondateur (plan du lot 1, §12) ;
 *  4. le catalogue rend les références telles que déclarées, et range dans la purge de saison chaque
 *     enfant avant le parent dont il meurt.
 *
 * Angle jumeau, cité : `PurgeCompletenessTest` (toute entité tenant purgée ou exclue avec sa raison,
 * lu dans les constantes des purgers) — ici la déclaration, là la couverture de la purge.
 */
#[Group('phase1')]
final class FamilyDeclarationSweepTest extends KernelTestCase
{
    /** Les trois colonnes que le niveau de la famille porte : jamais une référence. */
    private const array LEVEL_COLUMNS = ['id', 'clubId', 'seasonId'];

    /**
     * Colonnes `guid` qui désignent une table HORS Doctrine : aucune famille ne peut être leur cible.
     * Égalité stricte dans les deux sens : une ligne qui ne désigne plus une colonne `guid` sans
     * `#[RefersTo]` rougit.
     *
     * @var array<string, string> Classe::$champ => raison
     */
    private const array NON_DOCTRINE_TARGETS = [
        'App\Entity\ScheduledDeployment::$createdBy' => 'id d\'un super-admin : la table super_admin est tenue en DBAL, SuperAdmin n\'est pas une entité Doctrine',
    ];

    public function testEveryEntityDeclaresItsFamilyAndTheCatalogListsExactlyThem(): void
    {
        $entities = $this->entityClasses();

        $undeclared = array_values(array_filter(
            $entities,
            static fn (string $class): bool => [] === new ReflectionClass($class)->getAttributes(Family::class),
        ));
        self::assertSame([], $undeclared, \sprintf(
            "Ces entités ne déclarent pas leur famille (#[Family]) :\n  - %s\n"
            . 'Une famille non déclarée échappe aux purges qui liront la déclaration.',
            implode("\n  - ", $undeclared),
        ));

        $catalogued = $this->catalog()->all();
        sort($catalogued);
        self::assertSame($entities, $catalogued, 'FamilyCatalog::all() doit rendre exactement les entités Doctrine.');
    }

    public function testEveryReferenceColumnDeclaresWhatItPointsAt(): void
    {
        $entities = $this->entityClasses();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $missing = [];
        $misplaced = [];
        $unknownTarget = [];
        $withoutReason = [];
        $exemptionsInUse = [];
        foreach ($entities as $class) {
            $metadata = $em->getClassMetadata($class);
            foreach (new ReflectionClass($class)->getProperties() as $property) {
                $field = $property->getName();
                $isGuid = $metadata->hasField($field) && Types::GUID === $metadata->getTypeOfField($field);
                $declarations = $this->refersToOf($property);

                if ($isGuid && !\in_array($field, self::LEVEL_COLUMNS, true) && [] === $declarations) {
                    if (isset(self::NON_DOCTRINE_TARGETS[$class . '::$' . $field])) {
                        $exemptionsInUse[] = $class . '::$' . $field;
                    } else {
                        $missing[] = $class . '::$' . $field;
                    }
                }
                if ([] !== $declarations && (!$isGuid || \in_array($field, self::LEVEL_COLUMNS, true))) {
                    $misplaced[] = $class . '::$' . $field;
                }
                foreach ($declarations as $refersTo) {
                    if (!\in_array($refersTo->family, $entities, true)) {
                        $unknownTarget[] = $class . '::$' . $field . ' → ' . $refersTo->family;
                    }
                    if (\in_array($refersTo->onDelete, [OnDelete::KEEP, OnDelete::CUSTOM], true) && '' === trim((string) $refersTo->reason)) {
                        $withoutReason[] = $class . '::$' . $field;
                    }
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "Ces colonnes guid désignent un autre objet sans le déclarer (#[RefersTo]) :\n  - %s",
            implode("\n  - ", $missing),
        ));
        $exemptions = array_keys(self::NON_DOCTRINE_TARGETS);
        sort($exemptions);
        sort($exemptionsInUse);
        self::assertSame($exemptions, $exemptionsInUse, 'NON_DOCTRINE_TARGETS ne nomme que des colonnes guid sans #[RefersTo], toutes encore présentes.');
        foreach (self::NON_DOCTRINE_TARGETS as $column => $reason) {
            self::assertNotSame('', trim($reason), 'Exemption sans raison : ' . $column);
        }
        self::assertSame([], $misplaced, \sprintf(
            "#[RefersTo] ne se pose que sur une colonne guid, jamais sur id, clubId ni seasonId :\n  - %s",
            implode("\n  - ", $misplaced),
        ));
        self::assertSame([], $unknownTarget, \sprintf(
            "Ces références visent une classe qui n'est pas une famille déclarée :\n  - %s",
            implode("\n  - ", $unknownTarget),
        ));
        self::assertSame([], $withoutReason, \sprintf(
            "Une référence KEEP ou CUSTOM porte sa raison :\n  - %s",
            implode("\n  - ", $withoutReason),
        ));
    }

    public function testNoCycleBetweenReferencesThatDieWithTheirTarget(): void
    {
        $cycle = $this->findDeleteCycle($this->deleteEdges());

        self::assertNull($cycle, \sprintf(
            "Cycle entre références « meurt avec » (DELETE) : %s\n"
            . 'Aucun ordre de purge n\'existe — retour au fondateur (plan du lot 1, §12).',
            implode(' → ', $cycle ?? []),
        ));
    }

    /** La garde du cycle trouve un cycle quand il y en a un, et ignore l'auto-référence. */
    public function testTheCycleFinderSeesACycleAndIgnoresASelfReference(): void
    {
        self::assertSame(['A', 'B', 'C', 'A'], $this->findDeleteCycle(['A' => ['B'], 'B' => ['C'], 'C' => ['A']]));
        self::assertNull($this->findDeleteCycle(['A' => ['A', 'B'], 'B' => ['C'], 'C' => []]));
    }

    public function testTheCatalogRendersTheDeclaredReferences(): void
    {
        $declared = [];
        foreach ($this->entityClasses() as $class) {
            foreach (new ReflectionClass($class)->getProperties() as $property) {
                foreach ($this->refersToOf($property) as $refersTo) {
                    $declared[$refersTo->family][] = ['class' => $class, 'field' => $property->getName(), 'onDelete' => $refersTo->onDelete];
                }
            }
        }

        $catalog = $this->catalog();
        foreach ($this->entityClasses() as $target) {
            $expected = $this->canonical($declared[$target] ?? []);
            self::assertSame($expected, $this->canonical($catalog->referencesTo($target)), \sprintf(
                'FamilyCatalog::referencesTo(%s) doit rendre exactement les #[RefersTo] qui la visent.',
                $target,
            ));
        }
    }

    public function testTheSeasonPurgeRemovesEachChildBeforeTheParentItDiesWith(): void
    {
        $order = array_flip($this->catalog()->purgedWithSeason());

        $inverted = [];
        foreach ($this->deleteEdges() as $child => $parents) {
            foreach ($parents as $parent) {
                if ($child !== $parent && isset($order[$child], $order[$parent]) && $order[$child] > $order[$parent]) {
                    $inverted[] = $child . ' après ' . $parent;
                }
            }
        }

        self::assertSame([], $inverted, \sprintf(
            "FamilyCatalog::purgedWithSeason() efface un parent avant l'enfant qui meurt avec lui :\n  - %s",
            implode("\n  - ", $inverted),
        ));
    }

    public function testAnUndeclaredClassIsRefusedLoudly(): void
    {
        // Le catalogue d'abord : une erreur du conteneur est aussi une LogicException.
        $catalog = $this->catalog();
        $this->expectException(LogicException::class);

        $catalog->of(stdClass::class);
    }

    /**
     * Le premier cycle trouvé (chemin fermé), ou null. Les auto-références sont ignorées.
     *
     * @param array<string, list<string>> $edges enfant => parents dont il meurt
     *
     * @return list<string>|null
     */
    private function findDeleteCycle(array $edges): ?array
    {
        $state = [];
        $path = [];

        $visit = static function (string $node) use (&$visit, &$state, &$path, $edges): ?array {
            $state[$node] = 'open';
            $path[] = $node;
            foreach ($edges[$node] ?? [] as $next) {
                if ($next === $node) {
                    continue;
                }
                if ('open' === ($state[$next] ?? null)) {
                    return [...\array_slice($path, (int) array_search($next, $path, true)), $next];
                }
                if (!isset($state[$next])) {
                    $found = $visit($next);
                    if (null !== $found) {
                        return $found;
                    }
                }
            }
            array_pop($path);
            $state[$node] = 'done';

            return null;
        };

        $nodes = array_keys($edges);
        sort($nodes);
        foreach ($nodes as $node) {
            if (!isset($state[$node])) {
                $found = $visit($node);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @return array<string, list<string>> enfant => familles dont il meurt (DELETE) */
    private function deleteEdges(): array
    {
        $edges = [];
        foreach ($this->entityClasses() as $class) {
            $edges[$class] = [];
            foreach (new ReflectionClass($class)->getProperties() as $property) {
                foreach ($this->refersToOf($property) as $refersTo) {
                    if (OnDelete::DELETE === $refersTo->onDelete) {
                        $edges[$class][] = $refersTo->family;
                    }
                }
            }
        }

        return $edges;
    }

    /** @return list<RefersTo> */
    private function refersToOf(ReflectionProperty $property): array
    {
        return array_map(
            static fn (ReflectionAttribute $attribute): RefersTo => $attribute->newInstance(),
            $property->getAttributes(RefersTo::class),
        );
    }

    /**
     * @param list<array{class: string, field: string, onDelete: OnDelete}> $references
     *
     * @return list<string>
     */
    private function canonical(array $references): array
    {
        $lines = array_map(
            static fn (array $reference): string => $reference['class'] . '::$' . $reference['field'] . ' ' . $reference['onDelete']->name,
            $references,
        );
        sort($lines);

        return $lines;
    }

    /** @return list<class-string> les entités Doctrine, triées */
    private function entityClasses(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $classes = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!$metadata->isMappedSuperclass && !$metadata->isEmbeddedClass) {
                $classes[] = $metadata->getName();
            }
        }
        sort($classes);
        self::assertNotEmpty($classes, 'Aucune entité Doctrine trouvée.');

        return $classes;
    }

    private function catalog(): FamilyCatalog
    {
        self::bootKernel();

        return self::getContainer()->get(FamilyCatalog::class);
    }
}
