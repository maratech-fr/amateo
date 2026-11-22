<?php

declare(strict_types=1);

namespace App\Structure;

use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Balaie les fiches d'identité du club que plus aucun rôle ne désigne : une {@see Person} sans
 * coach dans aucune saison. Supprimer un coach garde sa fiche (« Charger » une version d'avant le
 * ramène dessus) ; elle part ici, après la purge de la saison : remise à zéro et purge de
 * rétention. L'effacement du club emporte toutes ses fiches par le catalogue des familles.
 *
 * Joué sous le contexte de club de l'appelant (RLS), le club en clause explicite. Les lieux
 * (`places`) rejoindront ce balayage avec leur table ; d'ici là, 0.
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

        return ['people' => (int) $people, 'places' => 0];
    }
}
