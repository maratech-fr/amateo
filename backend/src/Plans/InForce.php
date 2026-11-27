<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\TeamCoach;
use App\Structure\FamilyCatalog;
use App\Structure\Level;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use LogicException;

/**
 * La porte de l'en vigueur du socle (lot 5) : la version en vigueur du plan de saison et les lignes d'une famille
 * froide, pour les lecteurs que le plan du lot nomme (balayage `InForceDoorTest`). Elle couvre le socle seul : le
 * pointeur d'une période se lit ailleurs.
 *
 * Aujourd'hui elle rend les lignes de travail, telles que les lecteurs les lisaient en direct :
 * - une famille de niveau saison : ses lignes de la saison ;
 * - une famille de niveau plan : ses lignes du plan de saison de la saison.
 *
 * Lecture en SQL natif, saison en clause explicite : hors des filtres Doctrine (le `season_filter` épinglerait la
 * lecture à la saison active de la requête, or la saison visée n'est pas forcément celle-là) ; la RLS borne le club.
 * Les objets rendus sont les entités gérées par l'EntityManager, comme une lecture par dépôt.
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

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SoclePlan $soclePlan,
        private FamilyCatalog $familyCatalog,
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
     * Les lignes en vigueur d'une famille, pour une saison.
     *
     * @template T of object
     *
     * @param class-string<T> $familyClass
     *
     * @return list<T>
     */
    public function rows(string $seasonId, string $familyClass): array
    {
        $level = $this->familyCatalog->of($familyClass)->level;
        $metadata = $this->entityManager->getClassMetadata($familyClass);

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
}
