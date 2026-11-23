<?php

declare(strict_types=1);

namespace App\Structure;

use App\Entity\Person;
use App\Entity\Place;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Balaie les fiches d'identité du club que plus aucun usage ne désigne : une {@see Person} sans
 * coach dans aucune saison, un {@see Place} sans gymnase dans aucune saison. Supprimer un coach ou un
 * gymnase garde sa fiche ou son lieu (« Charger » une version d'avant le ramène dessus) ; ils partent
 * ici, après la purge de la saison : remise à zéro et purge de rétention. L'effacement du club
 * emporte toutes ses fiches et tous ses lieux par le catalogue des familles.
 *
 * Joué sous le contexte de club de l'appelant (RLS), le club en clause explicite.
 */
final readonly class UnusedIdentitySweeper
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /** @return array{people: int, places: int} lignes supprimées */
    public function sweep(string $clubId): array
    {
        $people = $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM person p WHERE p.club_id = ? AND NOT EXISTS (SELECT 1 FROM coach c WHERE c.person_id = p.id AND c.club_id = p.club_id)',
            [$clubId],
        );

        $places = $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM place p WHERE p.club_id = ? AND NOT EXISTS (SELECT 1 FROM venue v WHERE v.place_id = p.id AND v.club_id = p.club_id)',
            [$clubId],
        );

        return ['people' => (int) $people, 'places' => (int) $places];
    }
}
