<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\Constraint;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Structure\FamilyCatalog;
use App\Structure\Level;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use LogicException;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * La porte de l'en vigueur du socle (lot 5) : la version en vigueur du plan de saison et les lignes d'une famille
 * froide, pour les lecteurs que le plan du lot nomme (balayage `InForceDoorTest`). Elle couvre le socle seul : le
 * pointeur d'une période se lit ailleurs.
 *
 * Règles de lecture :
 * - aucun socle validé, ou une version en vigueur sans copie (née avant le lot, que la conversion n'a pas
 *   capturée) : les lignes de travail (niveau saison : celles de la saison ; niveau plan : celles du plan de
 *   saison) ;
 * - une famille que porte la copie de la version en vigueur ({@see PlanStates}) : les lignes de la copie, moins
 *   celles dont l'équipe, le gymnase ou le coach a été supprimé depuis ({@see PlanStates::withoutDeadReferences}),
 *   leurs champs froids pris dans la copie, le libre lu sur la ligne vivante (une ligne supprimée depuis renaît de la
 *   copie, son libre à ses défauts). Une ligne née depuis la validation ne vaut pas, sauf si elle désigne une équipe
 *   ou un gymnase créés depuis (lus en direct, en entier, leurs liens compris). Une ligne hors du filtre froid de sa
 *   famille (un adjoint, une contrainte datée) est libre : lue en direct ;
 * - équipe et gymnase (la structure) : les lignes vivantes, leurs champs froids pris dans la copie ; une équipe ou un
 *   gymnase créé depuis la validation est lu en direct ;
 * - une famille que la copie ne porte pas : en direct.
 *
 * Les objets rendus sont détachés : des copies que l'EntityManager ne gère pas ; les modifier n'écrit rien.
 *
 * Lecture en SQL natif, saison en clause explicite : hors des filtres Doctrine (le `season_filter` épinglerait la
 * lecture à la saison active de la requête, or la saison visée n'est pas forcément celle-là) ; la RLS borne le club.
 *
 * Ordre : par identifiant ; les liens équipe-coach par date de création puis identifiant, l'ordre qui départage qui
 * tient une séance ou un match.
 */
final readonly class InForce
{
    /** @var array<class-string, list<string>> */
    private const array ORDER = [
        TeamCoach::class => ['createdAt', 'id'],
    ];

    /** La structure du club : lue vivante, ses champs froids pris dans la copie. */
    private const array STRUCTURE = [Team::class, Venue::class];

    /**
     * Le nom d'une règle qui renaît de la copie (supprimée depuis la validation) : la copie ne porte pas le nom
     * (libre), la règle reçoit celui de sa nature.
     */
    private const array RULE_NAMES = [
        'TIME' => 'Règle d\'horaire',
        'DAY' => 'Règle de jours',
        'FACILITY' => 'Règle de gymnase',
        'COACH_AVAILABILITY' => 'Disponibilité de coach',
        'SEASON_SLOTS' => 'Garder ses créneaux de saison',
    ];

    /**
     * @param (Closure(): PlanStates)|null $planStates les copies des versions, résolues à la lecture (elles dépendent
     *                                                 de la porte, par la composition des plans)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SoclePlan $soclePlan,
        private FamilyCatalog $familyCatalog,
        #[AutowireServiceClosure(PlanStates::class)]
        private ?Closure $planStates = null,
    ) {}

    /** La version en vigueur du socle de la saison ; null sans saison, ou sans version validée. */
    public function socleVersionId(?string $seasonId): ?string
    {
        if (null === $seasonId) {
            return null;
        }

        $chosen = $this->entityManager->getConnection()->fetchOne(
            'SELECT chosen_schedule_id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        return \is_string($chosen) ? $chosen : null;
    }

    /**
     * Les lignes en vigueur d'une famille, pour une saison : des objets détachés, jamais persistés.
     *
     * @template T of object
     *
     * @param class-string<T> $familyClass
     *
     * @return list<T>
     */
    public function rows(string $seasonId, string $familyClass): array
    {
        $metadata = $this->entityManager->getClassMetadata($familyClass);
        /** @var list<T> $live */
        $live = array_map(static fn (object $row): object => clone $row, $this->liveRows($seasonId, $familyClass, $metadata));

        $versionId = $this->socleVersionId($seasonId);
        if (null === $versionId) {
            return $live;
        }
        if (!$this->planStates instanceof Closure) {
            throw new LogicException('La porte de l\'en vigueur lit la copie de la version en vigueur : elle demande les copies des versions.');
        }
        $planStates = ($this->planStates)();
        if (!$planStates->has($versionId)) {
            return $live;
        }
        $copy = $planStates->withoutDeadReferences($this->soclePlan->idOf($seasonId), $planStates->of($versionId))->toArray();
        $key = $this->familyKey($familyClass);
        if (!\array_key_exists($key, $copy)) {
            return $live;
        }
        $copied = $copy[$key];
        $family = $this->familyCatalog->of($familyClass);
        $structure = \in_array($familyClass, self::STRUCTURE, true);

        $rows = [];
        $seen = [];
        foreach ($live as $row) {
            $id = (string) $metadata->getFieldValue($row, 'id');
            if (isset($copied[$id])) {
                $this->apply($metadata, $row, [...$copied[$id], ...$family->coldRows]);
                $rows[] = $row;
                $seen[$id] = true;

                continue;
            }
            if ($structure
                || !$this->matchesColdRows($metadata, $row, $family->coldRows)
                || $this->designatesStructureBornSince($familyClass, $metadata, $row, $copy)) {
                $rows[] = $row;
            }
        }
        if (!$structure) {
            $planId = Level::PLAN === $family->level ? $this->soclePlan->idOf($seasonId) : null;
            foreach ($copied as $id => $fields) {
                if (!isset($seen[(string) $id])) {
                    /** @var T $reborn */
                    $reborn = $this->reborn($metadata, (string) $id, $seasonId, $planId, [...$fields, ...$family->coldRows]);
                    $rows[] = $reborn;
                }
            }
        }

        return $this->sorted($familyClass, $metadata, $rows);
    }

    /**
     * Les lignes de travail de la famille, telles que l'EntityManager les gère.
     *
     * @template T of object
     *
     * @param class-string<T>  $familyClass
     * @param ClassMetadata<T> $metadata
     *
     * @return list<T>
     */
    private function liveRows(string $seasonId, string $familyClass, ClassMetadata $metadata): array
    {
        $level = $this->familyCatalog->of($familyClass)->level;

        $where = ['f.' . $metadata->getColumnName('seasonId') . ' = :season'];
        $parameters = ['season' => $seasonId];
        if (Level::PLAN === $level) {
            $where[] = 'f.' . $metadata->getColumnName('schedulePlanId') . ' = :plan';
            $parameters['plan'] = $this->soclePlan->idOf($seasonId);
        } elseif (Level::SEASON !== $level) {
            throw new LogicException(\sprintf('%s n\'est ni de niveau saison ni de niveau plan : la porte de l\'en vigueur ne la lit pas.', $familyClass));
        }

        $order = array_map(
            static fn (string $field): string => 'f.' . $metadata->getColumnName($field),
            self::ORDER[$familyClass] ?? ['id'],
        );

        $mapping = new ResultSetMappingBuilder($this->entityManager);
        $mapping->addRootEntityFromClassMetadata($familyClass, 'f');
        $table = $this->entityManager->getConfiguration()->getQuoteStrategy()->getTableName($metadata, $this->entityManager->getConnection()->getDatabasePlatform());
        $sql = \sprintf('SELECT %s FROM %s f WHERE %s ORDER BY %s', $mapping->generateSelectClause(), $table, implode(' AND ', $where), implode(', ', $order));

        /** @var list<T> $rows */
        $rows = array_values($this->entityManager->createNativeQuery($sql, $mapping)->setParameters($parameters)->getResult());

        return $rows;
    }

    /**
     * La ligne est-elle dans le filtre froid de sa famille (le coach principal, une contrainte permanente) ?
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $coldRows
     */
    private function matchesColdRows(ClassMetadata $metadata, object $row, array $coldRows): bool
    {
        foreach ($coldRows as $field => $value) {
            $current = $metadata->getFieldValue($row, $field);
            if (($current instanceof BackedEnum ? $current->value : $current) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * La ligne, née depuis la validation, désigne-t-elle une équipe ou un gymnase eux aussi nés depuis ? Elle se lit
     * alors en direct, avec eux.
     *
     * @param class-string                                       $familyClass
     * @param ClassMetadata<object>                              $metadata
     * @param array<string, array<string, array<string, mixed>>> $copy
     */
    private function designatesStructureBornSince(string $familyClass, ClassMetadata $metadata, object $row, array $copy): bool
    {
        foreach ($this->familyCatalog->referencesFrom($familyClass) as $reference) {
            if (!\in_array($reference['family'], self::STRUCTURE, true)) {
                continue;
            }
            $structure = $copy[$this->familyKey($reference['family'])] ?? null;
            $value = $metadata->getFieldValue($row, $reference['field']);
            if (\is_array($structure) && \is_string($value) && !isset($structure[$value])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pose sur l'objet les champs froids de la copie.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $fields
     */
    private function apply(ClassMetadata $metadata, object $row, array $fields): void
    {
        foreach ($fields as $field => $value) {
            if ($metadata->hasField($field)) {
                $metadata->setFieldValue($row, $field, $this->phpValue($metadata, $field, $value));
            }
        }
    }

    /**
     * Une ligne de la copie supprimée depuis la validation : elle renaît, détachée, sous son identifiant, ses champs
     * froids pris dans la copie, le reste à ses défauts.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $fields
     */
    private function reborn(ClassMetadata $metadata, string $id, string $seasonId, ?string $planId, array $fields): object
    {
        $class = $metadata->getName();
        $row = new $class;
        $identity = ['id' => $id, 'seasonId' => $seasonId];
        if ($metadata->hasField('clubId')) {
            $identity['clubId'] = (string) $this->entityManager->getConnection()->fetchOne('SELECT club_id FROM season WHERE id = ?', [$seasonId]);
        }
        if (null !== $planId) {
            $identity['schedulePlanId'] = $planId;
        }
        foreach ($identity as $field => $value) {
            if ($metadata->hasField($field)) {
                $metadata->setFieldValue($row, $field, $value);
            }
        }
        $this->apply($metadata, $row, $fields);

        foreach ($metadata->getFieldNames() as $field) {
            if (new ReflectionProperty($class, $field)->isInitialized($row)) {
                continue;
            }
            $metadata->setFieldValue($row, $field, match (true) {
                Constraint::class === $class && 'name' === $field => self::RULE_NAMES[\is_string($fields['family'] ?? null) ? $fields['family'] : ''] ?? 'Règle',
                $metadata->isNullable($field) => null,
                default => $this->blankOf((string) $metadata->getTypeOfField($field)),
            });
        }

        return $row;
    }

    private function blankOf(string $type): mixed
    {
        return match (true) {
            str_contains($type, 'int') => 0,
            'boolean' === $type => false,
            str_contains($type, 'json') => [],
            default => '',
        };
    }

    /**
     * La valeur PHP d'un champ de la copie : énumération par sa valeur, heure, date et instant relus, tableau tel quel.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function phpValue(ClassMetadata $metadata, string $field, mixed $value): mixed
    {
        if (null === $value || \is_array($value)) {
            return $value;
        }
        $mapping = $metadata->getFieldMapping($field);
        if (null !== $mapping->enumType && (\is_string($value) || \is_int($value))) {
            return $mapping->enumType::from($value);
        }

        return Type::getType($mapping->type)->convertToPHPValue($value, $this->entityManager->getConnection()->getDatabasePlatform());
    }

    /**
     * @template T of object
     *
     * @param class-string          $familyClass
     * @param ClassMetadata<object> $metadata
     * @param list<T>               $rows
     *
     * @return list<T>
     */
    private function sorted(string $familyClass, ClassMetadata $metadata, array $rows): array
    {
        $fields = self::ORDER[$familyClass] ?? ['id'];
        $text = static function (mixed $value): string {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s.u');
            }

            return \is_scalar($value) ? (string) $value : '';
        };
        usort($rows, static function (object $a, object $b) use ($fields, $metadata, $text): int {
            foreach ($fields as $field) {
                $order = strcmp($text($metadata->getFieldValue($a, $field)), $text($metadata->getFieldValue($b, $field)));
                if (0 !== $order) {
                    return $order;
                }
            }

            return 0;
        });

        return $rows;
    }

    /** @param class-string $class */
    private function familyKey(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
