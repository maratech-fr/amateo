<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\Venue;
use App\Enum\FixtureStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-52 (RMM-10) — LE prédicat unique « quels matchs pointent une salle disparue ».
 *
 * Un même prédicat, deux consommateurs, PARITÉ PAR CONSTRUCTION : la route de lecture
 * `GET /api/schedules/{id}/validate-impact` (qui ANNONCE le nombre avant que le gestionnaire
 * confirme) et la gâchette de VALIDATION (qui DÉPOINTE exactement ces matchs). Puisque les deux
 * appellent {@see orphanedFixtures}, la route ne peut pas annoncer autre chose que ce que la
 * validation détruit.
 *
 * « Salle disparue » = `venueId` posé mais AUCUN `Venue` de ce club+saison ne le porte (le
 * gymnase a été supprimé, ou une exploration « Charger cette version » a laissé un pointeur
 * pendouillant). Le tenant est déjà borné par le filtre Doctrine + RLS ; la borne club+saison
 * explicite est une défense en profondeur, à la manière des contrôleurs d'export.
 */
final class OrphanedFixtureFinder
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * @return list<Fixture>
     */
    public function orphanedFixtures(string $clubId, string $seasonId): array
    {
        /** @var list<Fixture> $fixtures */
        $fixtures = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fixture::class, 'f')
            ->where('f.clubId = :clubId')
            ->andWhere('f.seasonId = :seasonId')
            ->andWhere('f.venueId IS NOT NULL')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . Venue::class . ' v WHERE v.id = f.venueId)')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->getResult();

        return $fixtures;
    }

    /**
     * L'impact ANNONCÉ : combien de matchs perdront leur salle (`orphanedFixtures`) et, parmi
     * eux, combien sont DÉJÀ DÉCLARÉS à la fédération (`declaredOrphanedFixtures`, à re-soumettre).
     *
     * @return array{orphanedFixtures: int, declaredOrphanedFixtures: int}
     */
    public function impact(string $clubId, string $seasonId): array
    {
        $orphaned = $this->orphanedFixtures($clubId, $seasonId);
        $declared = 0;
        foreach ($orphaned as $fixture) {
            if (\in_array($fixture->getStatus(), [FixtureStatus::SUBMITTED, FixtureStatus::VALIDATED], true)) {
                ++$declared;
            }
        }

        return ['orphanedFixtures' => \count($orphaned), 'declaredOrphanedFixtures' => $declared];
    }
}
