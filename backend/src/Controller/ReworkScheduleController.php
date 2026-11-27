<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ScheduleStatus;
use App\Plans\PendingChanges;
use App\Plans\PlanStates;
use App\Service\ScheduleCapabilityResolver;
use App\Service\SchedulePlanProvisioner;
use App\Service\WriteTargetSeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * « Retravailler » le planning de saison (lot 5) : copie la version en vigueur en une version de travail — ses
 * séances, sa copie du froid, son entrée figée — et y pose l'étoile (la version chargée). Le pointeur ne bouge pas :
 * la copie n'a aucun effet tant qu'elle n'est pas validée. L'état de travail n'est pas remis : des modifications déjà
 * en attente sont gardées.
 *
 * La copie naît à jour quand rien n'attend : son empreinte est alors celle du plan ; sinon elle garde celle de la
 * version en vigueur (elle se dit périmée, ce qui attend n'a pas été généré).
 *
 * Refus : version inconnue ou d'un autre club (le même 404) ; version qui n'est pas celle en vigueur d'un plan de
 * saison (409) ; génération en cours dans la saison (409).
 */
#[AsController]
#[ClubFunction(module: Module::PLANNING, audience: Audience::MANAGER)]
final class ReworkScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public const string NOT_IN_FORCE_MESSAGE = 'Seule la version en vigueur du planning de saison se retravaille.';

    /** Colonnes d'une séance qui ne se recopient pas : son identité et sa version. */
    private const array SLOT_IDENTITY = ['id', 'scheduleId', 'version', 'createdAt', 'updatedAt'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ScheduleCapabilityResolver $capabilityResolver,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
        private readonly PlanStates $planStates,
        private readonly PendingChanges $pendingChanges,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/rework', name: 'api_schedule_rework', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $inForce = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $inForce = null;
        }

        // Une version introuvable OU d'un autre club répond le MÊME 404 : on ne révèle jamais qu'elle existe.
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$inForce instanceof Schedule || (null !== $currentClubId && $inForce->getClubId() !== $currentClubId)) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if (ScheduleStatus::COMPLETED !== $inForce->getStatus() || !$this->schedulePlanProvisioner->isSeasonSchedule($inForce)) {
            return $this->json(['error' => self::NOT_IN_FORCE_MESSAGE], Response::HTTP_CONFLICT);
        }

        // Une génération réécrirait l'étoile à sa complétion : la copie de travail serait aussitôt déchargée.
        if ($this->capabilityResolver->inFlightInSeason($inForce->getClubId(), $inForce->getSeasonId())) {
            return $this->json(['code' => 'generation_in_progress', 'error' => 'Une génération est en cours — attendez sa fin avant de retravailler le planning.'], Response::HTTP_CONFLICT);
        }

        $planId = $inForce->getSchedulePlanId();

        return $this->entityManager->wrapInTransaction(function () use ($inForce, $planId): JsonResponse {
            // Sous le verrou de la saison : le pointeur relu (une validation concurrente a pu le déplacer).
            $this->schedulePlanProvisioner->lockPlanScope(SchedulePlanProvisioner::seasonScopeKey($inForce->getSeasonId()));
            if ($inForce->getId() !== $this->schedulePlanProvisioner->chosenOfSeasonPlan($inForce->getSeasonId())) {
                return $this->json(['error' => self::NOT_IN_FORCE_MESSAGE], Response::HTTP_CONFLICT);
            }

            $upToDate = $this->pendingChanges->changesOf($planId, $inForce->getId())->isEmpty();
            $state = $this->planStates->of($inForce->getId());
            $work = (new Schedule)
                ->setClubId($inForce->getClubId())
                ->setSeasonId($inForce->getSeasonId())
                ->setSchedulePlanId($planId)
                ->setName($inForce->getName())
                ->setStatus(ScheduleStatus::COMPLETED)
                ->setScore($inForce->getScore())
                ->setSolverSeed($inForce->getSolverSeed())
                ->setSnapshotData($inForce->getSnapshotData())
                ->setSnapshotHash($upToDate ? $this->schedulePlanProvisioner->structureHashOfPlan($planId) : $inForce->getSnapshotHash())
                ->setPayloadGraft($inForce->getPayloadGraft())
                ->setSolverVersion($inForce->getSolverVersion())
                ->setConstraintVersion($inForce->getConstraintVersion())
                ->setScoreFormulaVersion($inForce->getScoreFormulaVersion());
            $work->setManuallyEditedSinceGeneration($inForce->isManuallyEditedSinceGeneration());
            $this->entityManager->persist($work);
            $this->schedulePlanProvisioner->linkSchedule($work);
            $this->entityManager->flush();

            $this->copySlots($inForce->getId(), $work->getId());
            $this->planStates->store($work, $state);
            $this->schedulePlanProvisioner->pointLockSource($planId, $work->getId());

            return $this->json(['id' => $work->getId()], Response::HTTP_CREATED);
        });
    }

    /** Les séances de la version en vigueur, recopiées telles quelles (place, coach, verrou, groupe) dans la copie. */
    private function copySlots(string $fromId, string $toId): void
    {
        $metadata = $this->entityManager->getClassMetadata(ScheduleSlotTemplate::class);
        $columns = [];
        foreach ($metadata->getFieldNames() as $field) {
            if (!\in_array($field, self::SLOT_IDENTITY, true)) {
                $columns[] = $metadata->getColumnName($field);
            }
        }
        $list = implode(', ', $columns);
        $this->entityManager->getConnection()->executeStatement(
            \sprintf(
                'INSERT INTO %s (id, schedule_id, version, created_at, updated_at, %s) SELECT gen_random_uuid(), :to, 1, now(), now(), %s FROM %s WHERE schedule_id = :from',
                $metadata->getTableName(),
                $list,
                $list,
                $metadata->getTableName(),
            ),
            ['to' => $toId, 'from' => $fromId],
        );
    }
}
