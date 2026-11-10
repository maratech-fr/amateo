<?php

declare(strict_types=1);

namespace App\Service;

use App\Doctrine\Filter\SeasonFilter;
use App\Doctrine\Filter\TenantFilter;
use App\EventListener\TenantFilterListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le périmètre tenant d'une requête côté Doctrine : filtres `tenant_filter` (club) et
 * `season_filter` (saison) + GUC RLS `app.club_id`. Posé par {@see TenantFilterListener},
 * seul poseur de ces filtres.
 *
 * Il mémorise ce qu'il a posé pour le REPOSER après {@see resetManager} : réinitialiser
 * l'EntityManager (rattrapage d'un verrou optimiste périmé, qui l'a fermé) rend un manager
 * NEUF, sans aucun filtre. Les lectures `findBy([])` qui suivent dans la même requête
 * (accueil Matchs, vue « Déjà dans FBI », guichet de placement, import FBI) ramèneraient
 * alors toutes les saisons du club — et, sans la RLS, tous les clubs. Le GUC est reposé
 * aussi : la connexion DBAL survit au reset, mais on ne fait pas dépendre la frontière
 * club de ce détail.
 *
 * Hors requête HTTP (worker, commande) rien n'est mémorisé : aucun filtre n'y était posé,
 * le reset n'en perd aucun. `reset()` (kernel.reset, et début de chaque requête) oublie
 * le périmètre précédent.
 */
final class TenantDoctrineScope implements ResetInterface
{
    private ?string $clubId = null;

    private ?string $seasonId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {}

    /** Filtre tenant + GUC RLS sur le club. Le GUC doit précéder toute lecture de saison (RLS). */
    public function scopeClub(string $clubId): void
    {
        $this->clubId = $clubId;
        $this->applyClub($clubId);
    }

    /** Filtre saison. */
    public function scopeSeason(string $seasonId): void
    {
        $this->seasonId = $seasonId;
        $this->applySeason($seasonId);
    }

    /**
     * Réinitialise l'EntityManager (fermé par un échec de flush) PUIS repose sur le manager
     * neuf le périmètre mémorisé — filtres et paramètres à l'identique, GUC compris.
     */
    public function resetManager(): void
    {
        $this->managerRegistry->resetManager();

        if (null !== $this->clubId) {
            $this->applyClub($this->clubId);
        }
        if (null !== $this->seasonId) {
            $this->applySeason($this->seasonId);
        }
    }

    public function reset(): void
    {
        $this->clubId = null;
        $this->seasonId = null;
    }

    private function applyClub(string $clubId): void
    {
        /** @var TenantFilter $filter */
        $filter = $this->entityManager->getFilters()->enable('tenant_filter');
        $filter->setParameter('club_id', $clubId, 'uuid');

        // Session-scoped GUC read by the RLS policies (the old SET LOCAL was a
        // no-op outside a transaction — see TenantConnectionContext).
        $this->tenantConnectionContext->setClubId($clubId);
    }

    private function applySeason(string $seasonId): void
    {
        /** @var SeasonFilter $filter */
        $filter = $this->entityManager->getFilters()->enable('season_filter');
        $filter->setParameter('season_id', $seasonId, 'uuid');
    }
}
