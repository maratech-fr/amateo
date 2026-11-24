<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamPeriodOverrideResource;
use App\Dto\TeamPeriodOverrideInput;
use App\Entity\PlanTeam;
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
 * Le réglage d'une équipe pour une période (lot 4, PR 3a) : chaque équipe a déjà sa ligne dans chaque plan de période.
 * Publier le réglage d'un couple (plan, équipe) met sa ligne à jour, même réponse qu'une création ; `null` vaut la
 * valeur du socle du moment. Supprimer rend à la ligne les valeurs du socle. Le plan et l'équipe doivent être du club :
 * un couple sans ligne est refusé, rien n'est écrit. Le socle ne se règle jamais par cette route.
 *
 * @extends AbstractStateProcessor<PlanTeam, TeamPeriodOverrideInput, TeamPeriodOverrideResource>
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
        private readonly PlanComposition $planComposition,
        private readonly SoclePlan $socle,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return PlanTeam::class;
    }

    /**
     * @param TeamPeriodOverrideInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        // P4-34 — filet de la COURSE (voir AssertsSchedulePlanExistsTrait) : le plan peut disparaître entre le contrôle
        // d'existence et le flush ; la clé remonterait un 500 opaque là où le 422 est la bonne réponse.
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
    }

    /**
     * Le couple (plan, équipe) a déjà sa ligne : la publier la met à jour.
     *
     * @param TeamPeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): PlanTeam
    {
        if (null === $input->schedulePlanId || null === $input->teamId) {
            $this->refuse('Le réglage d\'une équipe pour une période nomme la période et l\'équipe.');
        }
        // P4-34 — l'ANCRE doit exister (et être du club : lue sous la RLS).
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        if ($this->socle->isSeasonPlan($input->schedulePlanId)) {
            $this->refuse('Le planning de saison ne se règle pas comme une période : modifiez l\'équipe.');
        }
        $line = $this->entityManager->getRepository(PlanTeam::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'teamId' => $input->teamId]);
        if (!$line instanceof PlanTeam) {
            $this->refuse('Cette équipe n\'appartient pas à la saison de cette période.');
        }
        $this->writeInput($line, $input);

        return $line;
    }

    /**
     * @param PlanTeam                $entity
     * @param TeamPeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->assertPeriodLine($entity);
        // schedulePlanId + teamId identify the row — not remapped on edit.
        $this->writeInput($entity, $input);
    }

    /**
     * Supprimer le réglage : la ligne reprend les valeurs du socle, elle ne disparaît pas (toute équipe a sa ligne).
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $line = $this->entityManager->find(PlanTeam::class, $uriVariables['id'] ?? null);
        if (!$line instanceof PlanTeam || (null !== $clubId && $line->getClubId() !== $clubId)) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
        $this->assertPeriodLine($line);
        $socle = $this->socleLineOf($line);
        $line->setParticipating(true)
            ->setSessionsPerWeek($socle->getSessionsPerWeek())
            ->setMaxSessionsPerDay($socle->getMaxSessionsPerDay())
            ->setSource(null);
        $this->entityManager->flush();

        // RGPD — retirer un réglage d'équipe de période reste une suppression tracée (patron du parent).
        $actor = $this->actorSecurity?->getUser();
        $this->auditTrail?->record(AuditAction::ENTITY_DELETED, $actor instanceof User ? $actor->getId() : null, $clubId, 'TeamPeriodOverride', $line->getId());
    }

    /**
     * @param PlanTeam $entity
     */
    protected function mapEntityToOutput(object $entity): TeamPeriodOverrideResource
    {
        return TeamPeriodOverrideResource::fromEntity($entity, $this->socleLineOf($entity));
    }

    /** Participe, séances, plafond : `null` = la valeur du socle du moment. */
    private function writeInput(PlanTeam $line, TeamPeriodOverrideInput $input): void
    {
        $socle = $this->socleLineOf($line);
        $line->setParticipating($input->isActive)
            ->setSessionsPerWeek($input->sessionsPerWeek ?? $socle->getSessionsPerWeek())
            ->setMaxSessionsPerDay($input->maxSessionsPerDay ?? $socle->getMaxSessionsPerDay());
    }

    /** Une ligne du socle n'est pas un réglage de période : introuvable par cette route. */
    private function assertPeriodLine(PlanTeam $line): void
    {
        if ($this->socle->isSeasonPlan($line->getSchedulePlanId())) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
    }

    private function socleLineOf(PlanTeam $line): PlanTeam
    {
        return $this->planComposition->socleLinesOf([$line->getTeamId()])[$line->getTeamId()];
    }
}
