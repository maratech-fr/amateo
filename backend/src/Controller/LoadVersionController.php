<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Entity\Schedule;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Plans\LoadFeasibility;
use App\Plans\PlanStates;
use App\Service\AuditTrail;
use App\Service\ScheduleCapabilityResolver;
use App\Service\SchedulePlanProvisioner;
use App\Service\SocleGuard;
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
 * « Charger cette version » (lot 5 conception, PR 3) : une seule définition, pour tout plan. Remet l'état de travail
 * du plan de la version d'après sa copie du froid ({@see PlanStates::restore}), puis fait d'elle la version chargée
 * (★) — sans rien générer. La prochaine « Régénérer » repart d'elle, ses verrous compris.
 *
 * Refus nommés, tous avant la moindre écriture : version inconnue ou d'un autre club (le même 404) ; version non
 * terminée ; génération en cours dans la saison ; plan validé (la version en vigueur du socle, ou d'un plan de période,
 * se rouvre d'abord) ; version qui ne se charge pas ({@see LoadFeasibility} : sans copie, ou niveau d'une équipe
 * engagée), `{code: 'load_blocked', error}` — le même texte que la capacité `loadBlockedReason`. Écrit une trace
 * `lock.changed` agrégée (le nombre de verrous de la version, jamais une ligne par verrou).
 */
#[AsController]
#[ClubFunction(module: Module::PLANNING, audience: Audience::MANAGER)]
final class LoadVersionController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly SocleGuard $socleGuard,
        private readonly ScheduleCapabilityResolver $capabilityResolver,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
        private readonly PlanStates $planStates,
        private readonly LoadFeasibility $loadFeasibility,
        private readonly AuditTrail $auditTrail,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/load', name: 'api_schedule_load', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $version = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $version = null;
        }

        // Une version introuvable OU d'un autre club répond le MÊME 404 : on ne révèle jamais qu'elle existe.
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$version instanceof Schedule || (null !== $currentClubId && $version->getClubId() !== $currentClubId)) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if (ScheduleStatus::COMPLETED !== $version->getStatus()) {
            return $this->json(['error' => 'Seule une version terminée peut être chargée.'], Response::HTTP_CONFLICT);
        }

        // Une génération réécrirait l'état et l'étoile à sa complétion : charger maintenant serait écrasé.
        if ($this->capabilityResolver->inFlightInSeason($version->getClubId(), $version->getSeasonId())) {
            return $this->json(['code' => 'generation_in_progress', 'error' => 'Une génération est en cours — attendez sa fin avant de charger une autre version.'], Response::HTTP_CONFLICT);
        }

        $isSeason = $this->schedulePlanProvisioner->isSeasonSchedule($version);
        if ($isSeason) {
            // Jusqu'à « Retravailler » : le socle en vigueur se rouvre avant qu'on en charge une version.
            $this->socleGuard->assertSeasonPlanNotChosen($version->getSeasonId());
        } elseif (null !== $this->schedulePlanProvisioner->chosenScheduleIdOfPlan($version->getSchedulePlanId())) {
            return $this->json(['error' => 'Ce planning de période est validé. Rouvrez-le avant de charger une autre version.'], Response::HTTP_CONFLICT);
        }

        $blocked = $this->loadFeasibility->blockedReason($version);
        if (null !== $blocked) {
            return $this->json(['code' => 'load_blocked', 'error' => $blocked], Response::HTTP_CONFLICT);
        }

        $versionId = $version->getId();
        $planId = $version->getSchedulePlanId();
        $seasonId = $version->getSeasonId();
        $state = $this->planStates->of($versionId);
        $this->entityManager->wrapInTransaction(function () use ($isSeason, $versionId, $planId, $seasonId, $state): void {
            // Sous le verrou de la saison : la garde du socle relue (une garde lue hors verrou n'est qu'un TOCTOU).
            $this->schedulePlanProvisioner->lockPlanScope(SchedulePlanProvisioner::seasonScopeKey($seasonId));
            if ($isSeason) {
                $this->socleGuard->assertSeasonPlanNotChosen($seasonId);
            }
            $this->planStates->restore($planId, $state);
            $this->schedulePlanProvisioner->pointLockSource($planId, $versionId);
        });

        // RGPD — « Charger » adopte en bloc les verrous d'une version : UN événement agrégé portant un compteur. Le
        // re-pointage à la complétion d'une génération (worker, sans acteur) n'est pas ce geste et n'est pas tracé.
        $actor = $this->getUser();
        $this->auditTrail->record(
            AuditAction::LOCK_CHANGED,
            $actor instanceof User ? $actor->getId() : null,
            $version->getClubId(),
            'Schedule',
            $versionId,
            ['count' => $this->countHardLocks($versionId)],
        );

        return $this->json(['id' => $versionId, 'status' => $version->getStatus()->value], Response::HTTP_OK);
    }

    /** Le nombre de séances verrouillées en dur de la version chargée : le compteur de la trace. */
    private function countHardLocks(string $scheduleId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule_slot_template WHERE schedule_id = :sid AND lock_level = :lvl',
            ['sid' => $scheduleId, 'lvl' => LockLevel::HARD->value],
        );
    }
}
