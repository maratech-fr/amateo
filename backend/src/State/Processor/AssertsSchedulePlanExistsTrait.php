<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\Entity\SchedulePlan;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Refuse une ancre `schedulePlanId` qui ne désigne aucun plan (P4-30).
 *
 * Avant la FK, une ancre inventée créait une ligne ORPHELINE en silence :
 * jamais lue (`StructureSnapshotter` filtre `IS NULL`), jamais nettoyée. Depuis
 * la FK, la même requête remonterait une
 * `ForeignKeyConstraintViolationException` — donc un **500** sur une saisie
 * simplement invalide. Ni l'un ni l'autre n'est acceptable : c'est un 422.
 *
 * `null` reste licite et signifie « ligne de base, partagée par la saison »
 * (inv. 6) — surtout ne pas le confondre avec une ancre absente.
 *
 * Le filtre tenant scope déjà la lecture au club courant : un plan d'un AUTRE
 * club est donc introuvable ici et se solde par le même 422, sans révéler qu'il
 * existe ailleurs.
 */
trait AssertsSchedulePlanExistsTrait
{
    /**
     * Le filet de la COURSE, complément indispensable du contrôle ci-dessous.
     *
     * `assertSchedulePlanExists` est un check-then-insert : entre son SELECT et le
     * flush, une autre requête peut supprimer le plan (suppression de période, ou
     * `OverlayManager::deletePeriodPlanForEntry(force: true)` déclenché par une
     * validation/réouverture du plan de saison). La FK remonterait alors une
     * `ForeignKeyConstraintViolationException` que personne n'attrape → 500 opaque,
     * là où avant la FK la ligne s'écrivait simplement.
     *
     * On ne prend PAS de verrou : le résultat utile est le même 422 que si le plan
     * avait déjà disparu au moment du contrôle, et sérialiser toute écriture ancrée
     * sur le verrou de cycle de vie des plans coûterait plus que le cas ne le vaut.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function rejectingConcurrentPlanDeletion(callable $write): mixed
    {
        try {
            return $write();
        } catch (ForeignKeyConstraintViolationException $e) {
            throw new UnprocessableEntityHttpException('Ce planning n\'existe plus — rechargez la page.', $e);
        }
    }

    private function assertSchedulePlanExists(EntityManagerInterface $entityManager, ?string $schedulePlanId): void
    {
        if (null === $schedulePlanId) {
            return;
        }

        // ABSENT (null) ≠ PRÉSENT-MAIS-VIDE. `Assert\Uuid` laisse passer la chaîne
        // vide (le validateur Symfony sort immédiatement dessus), et `find('')`
        // enverrait `WHERE id = ''` contre une PK `uuid` native → 22P02, soit
        // exactement le 500 que ce garde existe pour supprimer.
        if ('' === $schedulePlanId) {
            throw new UnprocessableEntityHttpException('Ce planning n\'existe plus — rechargez la page.');
        }

        if (!$entityManager->getRepository(SchedulePlan::class)->find($schedulePlanId) instanceof SchedulePlan) {
            throw new UnprocessableEntityHttpException('Ce planning n\'existe plus — rechargez la page.');
        }
    }
}
