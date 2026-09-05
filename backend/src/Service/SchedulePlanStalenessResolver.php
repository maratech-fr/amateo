<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SchedulePlanStaleness;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P4-173 — LE calcul de « ce plan est-il à régénérer ? ».
 *
 * SOURCE UNIQUE de la péremption servie par `SchedulePlanResource::staleness` : le mapping de
 * sortie s'en sert pour SERVIR le bloc au cockpit (règle d'or : le backend dit, le front affiche).
 * La péremption est celle de la version POINTÉE (`chosenScheduleId`) — une V2 régénérée et pointée
 * éteint le signal même si une V1 marquée survit. Trois régimes rendent `null` (aucun bloc) :
 *  - le plan ne pointe aucune version (workspace pas encore validé) ;
 *  - la fenêtre du plan est déjà révolue (`endDate` < aujourd'hui, horloge serveur) — « à
 *    régénérer » y serait un faux appel à l'action, la modale « Tous les plannings » liste le passé.
 * Sinon le bloc porte les trois drapeaux de la version pointée (tous peuvent être faux).
 *
 * Anti-N+1 : le mapping de sortie tourne aussi sur la collection (GET /api/schedule_plans). Plutôt
 * qu'une requête par plan (lire sa version pointée), on lit UNE fois l'ensemble des versions
 * pointées du club (RLS + tenant_filter scopent le club) par une seule requête `id IN (chosen ids)`,
 * mémoïsée pour la durée de la requête HTTP courante (clé = l'objet Request). Chaque test est ensuite
 * un O(1) en mémoire — une requête pour toute la page, aucune mémoïsation hors d'un contexte HTTP.
 */
class SchedulePlanStalenessResolver
{
    /** @var array<string, SchedulePlanStaleness>|null péremption par id de version pointée, mémoïsée pour la requête courante */
    private ?array $memo = null;

    private ?int $memoRequestId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
    ) {}

    public function stalenessFor(SchedulePlan $plan): ?SchedulePlanStaleness
    {
        $chosen = $plan->getChosenScheduleId();
        if (null === $chosen) {
            return null;
        }

        // Fenêtre révolue : le plan appartient au passé — pas de faux « à régénérer ».
        if ($plan->getEndDate()->format('Y-m-d') < $this->clock->now()->format('Y-m-d')) {
            return null;
        }

        return $this->clubChosenStalenessSet()[$chosen] ?? null;
    }

    /**
     * L'ensemble des versions POINTÉES du club (RLS), avec leur péremption, mémoïsé pour la requête
     * HTTP courante. Hors requête (CLI) : jamais mémoïsé, relu à chaque appel — aucune fuite entre
     * contextes. Une nouvelle requête HTTP (nouvel objet Request) reconstruit l'ensemble.
     *
     * @return array<string, SchedulePlanStaleness>
     */
    private function clubChosenStalenessSet(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $requestId = $request instanceof Request ? spl_object_id($request) : null;
        if (null !== $requestId && null !== $this->memo && $requestId === $this->memoRequestId) {
            return $this->memo;
        }

        // DQL scalaire : l'hydratation applique le type `boolean` de chaque colonne (bool PHP franc,
        // pas la représentation brute du driver). tenant_filter + RLS bornent le club.
        $rows = $this->entityManager->createQuery(
            'SELECT s.id AS id, s.manuallyEditedSinceGeneration AS m, '
            . 's.constraintsChangedSinceGeneration AS c, s.resourcesChangedSinceGeneration AS r '
            . 'FROM ' . Schedule::class . ' s '
            . 'WHERE s.id IN ('
            . 'SELECT p.chosenScheduleId FROM ' . SchedulePlan::class . ' p WHERE p.chosenScheduleId IS NOT NULL'
            . ')',
        )->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $set[(string) $row['id']] = new SchedulePlanStaleness(
                (bool) $row['m'],
                (bool) $row['c'],
                (bool) $row['r'],
            );
        }

        if (null !== $requestId) {
            $this->memo = $set;
            $this->memoRequestId = $requestId;
        }

        return $set;
    }
}
