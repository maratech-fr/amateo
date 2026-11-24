<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ConstraintPeriodOverrideResource;
use App\Dto\ConstraintPeriodOverrideInput;
use App\Entity\Constraint;
use App\Entity\PlanConstraint;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Plans\PlanComposition;
use App\Plans\SoclePlan;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La coche d'une règle de saison pour une période (lot 4, PR 4) : chaque contrainte permanente a déjà sa ligne dans
 * chaque plan de période. Publier la coche d'un couple (plan, règle) met sa ligne à jour, même réponse qu'une création ;
 * la supprimer rend la ligne au défaut de la nature du plan. Le plan et la règle doivent être du club et de la saison :
 * un couple sans ligne est refusé, rien n'est écrit. Le socle ne se règle jamais par cette route, et une règle datée ne
 * se décoche pas (elle appartient à sa période).
 *
 * @extends AbstractStateProcessor<PlanConstraint, ConstraintPeriodOverrideInput, ConstraintPeriodOverrideResource>
 */
class ConstraintPeriodOverrideStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly PlanComposition $planComposition,
        private readonly SoclePlan $socle,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return PlanConstraint::class;
    }

    /**
     * P4-34 — filet de la COURSE (voir {@see AssertsSchedulePlanExistsTrait}) : le
     * contrôle d'existence de l'ancre est un check-then-insert ; entre lui et le
     * flush le plan peut disparaître (suppression de période, reprise du socle), et
     * la FK remonterait alors une violation que personne n'attrape → 500 opaque.
     * On rend le MÊME 422 que si le plan avait déjà disparu au contrôle.
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
    }

    /**
     * Le couple (plan, règle) a déjà sa ligne : la publier la met à jour.
     *
     * @param ConstraintPeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): PlanConstraint
    {
        if (null === $input->schedulePlanId || null === $input->constraintId) {
            $this->refuse('La coche d\'une règle pour une période nomme la période et la règle.');
        }

        // P2-59 — modèle FAIT/GENÈSE : une contrainte DATÉE (portant un calendarEntryId)
        // appartient à sa période, elle n'est jamais décochée — un fait est un fait, une
        // genèse ne vaut que pour son plan. Seules les PERMANENTES se décochent.
        $constraint = $this->entityManager->getRepository(Constraint::class)->find($input->constraintId);
        if ($constraint instanceof Constraint && null !== $constraint->getCalendarEntryId()) {
            $this->refuse('Une contrainte datée appartient à sa période : modifiez-la ou supprimez-la — elle ne se décoche pas.');
        }

        // P4-34 — l'ANCRE doit exister (et être du club : lue sous la RLS).
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        if ($this->socle->isSeasonPlan($input->schedulePlanId)) {
            $this->refuse('Le planning de saison applique toutes ses règles : une règle ne s\'y décoche pas.');
        }
        // La ligne est lue sous les filtres du club et de la saison : une règle d'un autre club, ou le plan d'une autre
        // saison, n'en a aucune.
        $line = $this->entityManager->getRepository(PlanConstraint::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'constraintId' => $input->constraintId]);
        if (!$line instanceof PlanConstraint) {
            $this->refuse('Cette règle n\'appartient pas à la saison de cette période.');
        }
        $line->setApplies($input->isActive);

        return $line;
    }

    /**
     * @param PlanConstraint                $entity
     * @param ConstraintPeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // schedulePlanId + constraintId identify the row — not remapped on edit.
        $entity->setApplies($input->isActive);
    }

    /**
     * Supprimer la coche : la ligne reprend le défaut de la nature du plan, elle ne disparaît pas (toute règle
     * permanente a sa ligne dans chaque plan de période).
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $line = $this->entityManager->find(PlanConstraint::class, $uriVariables['id'] ?? null);
        if (!$line instanceof PlanConstraint || (null !== $clubId && $line->getClubId() !== $clubId)) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
        $line->setApplies($this->planComposition->ruleDefaultOf($line));
        $this->entityManager->flush();

        // RGPD — retirer la coche d'une règle de période reste une suppression tracée (patron du parent).
        $actor = $this->actorSecurity?->getUser();
        $this->auditTrail?->record(AuditAction::ENTITY_DELETED, $actor instanceof User ? $actor->getId() : null, $clubId, 'ConstraintPeriodOverride', $line->getId());
    }

    /**
     * @param PlanConstraint $entity
     */
    protected function mapEntityToOutput(object $entity): ConstraintPeriodOverrideResource
    {
        return ConstraintPeriodOverrideResource::fromEntity($entity);
    }
}
