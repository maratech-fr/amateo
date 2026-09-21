<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FbiCorrection;
use App\Enum\FbiCorrectionField;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FbiCorrection>
 */
final class FbiCorrectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FbiCorrection::class);
    }

    /**
     * L'entrée OUVERTE d'un (rencontre, champ) — l'unicité partielle garantit au
     * plus une. Les filtres tenant/saison Doctrine scopent déjà au club courant.
     */
    public function findOpen(string $fixtureId, FbiCorrectionField $field): ?FbiCorrection
    {
        return $this->findOneBy(['fixtureId' => $fixtureId, 'field' => $field, 'closedAt' => null]);
    }

    /**
     * Toutes les entrées OUVERTES du club+saison, triées date de match croissante
     * côté serveur impossible (pas de jointure fixture ici) — le contrôleur ordonne.
     *
     * @return list<FbiCorrection>
     */
    public function findOpenBySeason(string $seasonId): array
    {
        return $this->findBy(['seasonId' => $seasonId, 'closedAt' => null]);
    }

    /**
     * Les entrées OUVERTES d'une rencontre (tous champs) — pour fermer/rafraîchir en
     * bloc lors d'un dépôt.
     *
     * @return list<FbiCorrection>
     */
    public function findOpenByFixture(string $fixtureId): array
    {
        return $this->findBy(['fixtureId' => $fixtureId, 'closedAt' => null]);
    }

    /**
     * Les entrées OUVERTES qu'un même conflit du radar (une empreinte) a ouvertes dans
     * la saison — pour ne garder qu'UNE déclaration « erreur FBI » vivante par conflit
     * (lot N). Les filtres tenant/saison Doctrine scopent déjà au club courant.
     *
     * @return list<FbiCorrection>
     */
    public function findOpenByConflictFingerprint(string $seasonId, string $conflictFingerprint): array
    {
        return $this->findBy(['seasonId' => $seasonId, 'conflictFingerprint' => $conflictFingerprint, 'closedAt' => null]);
    }
}
