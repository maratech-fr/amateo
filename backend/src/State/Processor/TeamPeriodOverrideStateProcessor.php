<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamPeriodOverrideResource;
use App\Dto\TeamPeriodOverrideInput;
use App\Entity\TeamPeriodOverride;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProcessor<TeamPeriodOverride, TeamPeriodOverrideInput, TeamPeriodOverrideResource>
 */
class TeamPeriodOverrideStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return TeamPeriodOverride::class;
    }

    /**
     * Le 1er override d'une période marque son PLAN comme configuré (garde de seed du
     * wizard). Délégué à SchedulePlanProvisioner : le code en fait le SEUL point qui
     * écrit des lignes `schedule_plan`, et son docblock porte le pourquoi du SQL brut.
     *
     * ATOMIQUE avec l'override, et écrit APRÈS lui : le flag ne doit pas pouvoir rester
     * vrai sans qu'aucun override n'existe — le wizard cesserait alors de seeder une
     * période pourtant vierge.
     *
     * @param TeamPeriodOverrideInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            // P4-34 — filet de la COURSE (voir AssertsSchedulePlanExistsTrait) : le
            // plan peut disparaître entre le contrôle d'existence et le flush ; la FK
            // remonterait un 500 opaque là où le 422 est la bonne réponse.
            $output = $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
            if (null !== $input->schedulePlanId) {
                $this->schedulePlanProvisioner->markPlanTeamSelectionInitialized($input->schedulePlanId);
            }

            return $output;
        });
    }

    /**
     * @param TeamPeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): TeamPeriodOverride
    {
        // One override per (period, team) — the DB unique index would otherwise
        // surface as a 500 on a double-submit; give a clean 422 instead (edit via PUT).
        if (!\in_array(null, [$input->schedulePlanId, $input->teamId, $this->entityManager->getRepository(TeamPeriodOverride::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'teamId' => $input->teamId])], true)) {
            $this->refuse('Cette équipe a déjà un réglage pour cette période — modifiez-le.');
        }

        $entity = new TeamPeriodOverride;
        if (null !== $input->schedulePlanId) {
            // P4-34 — l'ANCRE doit exister : sans ce contrôle, un `schedulePlanId`
            // inventé écrivait une ligne 201 rattachée à une période inexistante —
            // invisible à l'écran, jamais lue par une génération, impossible à
            // supprimer par l'UI. Même garde que `Reservation`/`VenueTrainingSlot` (P4-30).
            $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
            $entity->setSchedulePlanId($input->schedulePlanId);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        $entity->setIsActive($input->isActive);
        $entity->setSessionsPerWeek($input->sessionsPerWeek);

        return $entity;
    }

    /**
     * @param TeamPeriodOverride      $entity
     * @param TeamPeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // schedulePlanId + teamId identify the row — not remapped on edit.
        $entity->setIsActive($input->isActive);
        $entity->setSessionsPerWeek($input->sessionsPerWeek);
    }

    /**
     * @param TeamPeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): TeamPeriodOverrideResource
    {
        return TeamPeriodOverrideResource::fromEntity($entity);
    }
}
