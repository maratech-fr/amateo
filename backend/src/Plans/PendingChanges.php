<?php

declare(strict_types=1);

namespace App\Plans;

use App\Enum\SchedulePlanType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qui attend la validation du planning de saison (lot 5) : l'état de travail du plan comparé à la copie de sa
 * version en vigueur, sur les seuls champs froids ({@see PlanState::changesTo}), et la présence d'une version de
 * travail chargée. Rien n'attend sans version en vigueur, ni pour un plan de période (la porte ne couvre que le
 * socle).
 */
final readonly class PendingChanges
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlanStates $planStates,
    ) {}

    /**
     * @return array{pending: bool, hasWorkVersion: bool, changes: list<array{family: string, count: int, destroys: bool}>}
     */
    public function of(string $planId): array
    {
        $plan = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT type, chosen_schedule_id, lock_source_schedule_id FROM schedule_plan WHERE id = ?',
            [$planId],
        );
        $inForce = false === $plan ? null : $plan['chosen_schedule_id'];
        if (false === $plan || SchedulePlanType::SEASON->value !== $plan['type'] || !\is_string($inForce)) {
            return ['pending' => false, 'hasWorkVersion' => false, 'changes' => []];
        }

        $changes = $this->changesOf($planId, $inForce)->lines();
        $loaded = $plan['lock_source_schedule_id'];
        $hasWorkVersion = \is_string($loaded) && $loaded !== $inForce;

        return ['pending' => [] !== $changes || $hasWorkVersion, 'hasWorkVersion' => $hasWorkVersion, 'changes' => $changes];
    }

    /**
     * Ce qui diffère entre la copie de la version en vigueur et l'état de travail du plan. Des deux côtés, une ligne
     * dont l'équipe, le gymnase ou le coach n'existe plus ne compte pas (une ligne orpheline de l'état de travail n'est
     * pas une modification).
     */
    public function changesOf(string $planId, string $inForceId): PlanChanges
    {
        return $this->planStates->withoutDeadReferences($planId, $this->planStates->of($inForceId))
            ->changesTo($this->planStates->withoutDeadReferences($planId, $this->planStates->capture($planId)));
    }
}
