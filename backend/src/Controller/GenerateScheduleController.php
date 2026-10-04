<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\GenerationComplexityGuard;
use App\Service\ManagementAccessGuard;
use App\Service\OrphanPinGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SocleGuard;
use App\Service\WriteTargetSeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

#[AsController]
final class GenerateScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly GenerationComplexityGuard $complexityGuard,
        private readonly OrphanPinGuard $orphanPinGuard,
        private readonly ClockInterface $clock,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $schedule = null;
        }

        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId();
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // ADR-0002 inv. 1 : la version CHOISIE est le planning en vigueur — la
        // régénérer l'écraserait. Rouvrir (dépointer) d'abord.
        if ($this->schedulePlanProvisioner->isChosen($schedule->getId())) {
            return $this->json(['error' => 'La version choisie est le planning en vigueur. Rouvrez-le avant de régénérer.'], Response::HTTP_CONFLICT);
        }

        // A secondary plan (period overlay) can only be generated once the season's
        // main plan is validated. Generating the main plan itself is always allowed
        // — that is how the socle gets created in the first place. ADR-0002 C4 :
        // « socle ? » = plan.type === SEASON (isSeasonSchedule), plus calendarEntryId.
        if (!$this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
            $this->socleGuard->assertSeasonPlanChosen($schedule->getSeasonId());
        }

        // A10: reject an over-complex problem BEFORE queuing it, so a "generation bomb"
        // never dispatches, never reaches the engine, and never holds the club's single
        // generation slot for the whole solver timeout. Status is left untouched (no
        // PENDING, no onboarding completion, no flush) on rejection.
        $violation = $this->complexityGuard->firstViolation($schedule->getClubId(), $schedule->getSeasonId());
        if (null !== $violation) {
            return $this->json([
                'error' => \sprintf(
                    'Génération bloquée : trop de %s (%d, limite %d).',
                    $violation['cap'],
                    $violation['count'],
                    $violation['limit'],
                ),
                'cap' => $violation['cap'],
                'count' => $violation['count'],
                'limit' => $violation['limit'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // #8 — un verrou ou une réservation qui ne retombe sur aucun créneau de la période
        // (grille refaite : page blanche, recopie du modèle de saison, gymnase désactivé).
        // On refuse et on NOMME le problème plutôt que de l'escamoter : le gestionnaire
        // redéfinit ses créneaux ou retire l'épinglage (décision fondateur 2026-07-24).
        $orphanPin = $this->orphanPinGuard->firstOrphanMessage($schedule);
        if (null !== $orphanPin) {
            return $this->json(['error' => $orphanPin], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Launching the first generation completes onboarding (the wizard is done);
        // done at queue time so the UI can leave /wizard for the work loop right away,
        // regardless of whether the solve ends up feasible.
        $club = $this->entityManager->getRepository(Club::class)->find($schedule->getClubId());

        // P2-9bis (planning lifecycle) : défense en profondeur du socle en vigueur. Ce
        // contrôleur n'avait NI transaction NI verrou : on enveloppe le passage en PENDING
        // et le flush dans une transaction qui prend d'abord le verrou de plan-scope de la
        // saison — sans lui la garde ne serait qu'un TOCTOU. On N'ARME l'assertion QUE pour
        // une version de saison ; un overlay a déjà passé assertSeasonPlanChosen plus haut,
        // qui exige l'INVERSE (les deux ne s'appliquent jamais au même appel). Le dispatch
        // reste APRÈS le commit : le worker ne doit jamais consommer avant que la ligne soit
        // visible.
        $isSeasonSchedule = $this->schedulePlanProvisioner->isSeasonSchedule($schedule);
        $seasonId = $schedule->getSeasonId();
        $this->entityManager->wrapInTransaction(function () use ($schedule, $club, $isSeasonSchedule, $seasonId): void {
            if ($isSeasonSchedule) {
                $this->schedulePlanProvisioner->lockPlanScope(SchedulePlanProvisioner::seasonScopeKey($seasonId));
                $this->socleGuard->assertSeasonPlanNotChosen($seasonId);
            }
            $schedule->setStatus(ScheduleStatus::PENDING);
            // P5-10 — instant de mise en file (attente queue → generating mesurable).
            $schedule->setQueuedAt(DateTimeImmutable::createFromInterface($this->clock->now()));
            if ($club instanceof Club) {
                $club->setLastActivityAt(DateTimeImmutable::createFromInterface($this->clock->now()));
                if (!$club->getOnboardingCompleted()) {
                    $club->setOnboardingCompleted(true);
                }
            }
            $this->entityManager->flush();
        });

        $this->messageBus->dispatch(
            new GenerateScheduleMessage(
                scheduleId: $schedule->getId(),
                clubId: $schedule->getClubId(),
            ),
        );

        return $this->json(['message' => 'Schedule generation queued'], Response::HTTP_ACCEPTED);
    }

    private function resolveCurrentClubId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        $clubId = $request?->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        return null;
    }
}
