<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SocleDeviationCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * P2-44 PR-5 (ADR-0004) — LES ÉCARTS NOMMÉS : sur l'écran embarqué de génération d'un plan de
 * PÉRIODE de type FERMETURE, une fois qu'une version existe (transcrite, comblée, ou solvée), cette
 * route de LECTURE re-appelable NOMME ce qui a changé vs le socle — séances déplacées et séances non
 * replacées. Le front est présentation pure (il ne redérive rien) ; l'agrégat « N déplacées, M à
 * replacer » est une longueur de liste côté écran, pas une règle servie.
 *
 * Cible = la VERSION (`Schedule`), pas le plan : l'écran affiche une version précise (une V1
 * transcrite ≠ une V+1 comblée), miroir de `/api/schedules/{id}/fill`, et « plan sans version »
 * devient impossible par construction.
 *
 * ── LECTURE, ouverte au Membre ─────────────────────────────────────────────────────────────────
 * PAS de garde management (patron {@see CalendarEntryConflictsController}), PAS de
 * `SeasonScopedWriteInterface` (aucune écriture ; SEC-13 ne la concerne pas). AUCUN persist, AUCUN
 * flush. Défense club en profondeur : RLS scope déjà le club (find rend null pour un autre club →
 * 404), et on re-vérifie le club (403).
 *
 * ── Refus nommés ───────────────────────────────────────────────────────────────────────────────
 *  - 404 : version inconnue ou d'un autre club ;
 *  - 403 : club en défense ;
 *  - 422 : plan SEASON (le socle n'a pas d'écart avec lui-même) ; periodType ≠ FERMETURE (une
 *    reprise de vacances est un planning TOUT nouveau, aucun écart n'a de sens — ADR-0004) ;
 *  - 409 : version non COMPLETED (un DRAFT/en vol n'a pas de placement stable à comparer) ;
 *  - 409 : socle NON pointé. CLAUDE.md §6 dit qu'à l'étape Génération d'une période le socle a
 *    forcément une version pointée (`SocleGuard`) — MAIS cette route est une lecture appelable HORS
 *    de ce moment : rouvrir le socle ne détruit que les plans de période FUTURS (`startDate > today`),
 *    une période DÉJÀ COMMENCÉE survit et peut donc se retrouver face à un socle sans version
 *    pointée. Refus FRANC, pas du code mort.
 */
#[AsController]
final class SocleDeviationController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly SocleDeviationCalculator $calculator,
    ) {}

    #[Route('/api/schedules/{id}/socle-deviation', name: 'api_schedule_socle_deviation', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $schedule = null;
        }
        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        $context = $this->schedulePlanProvisioner->fetchPlanContext($schedule->getSchedulePlanId());
        if (null === $context || SchedulePlanType::SEASON === $context['type'] || null === $context['calendarEntryId']) {
            return $this->json(['error' => 'Les écarts avec le planning de saison ne concernent qu\'un planning de période.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $entryId = $context['calendarEntryId'];

        $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($entryId);
        if (!$entry instanceof CalendarEntry) {
            return $this->json(['error' => 'La période n\'existe plus.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (CalendarEntryPeriodType::CLOSURE !== $entry->getPeriodType()) {
            return $this->json(['error' => 'Les écarts avec le planning de saison ne concernent qu\'une fermeture.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (ScheduleStatus::COMPLETED !== $schedule->getStatus()) {
            return $this->json(['error' => 'Seule une version terminée peut être comparée au planning de saison.'], Response::HTTP_CONFLICT);
        }

        $chosenSocleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($context['seasonId']);
        if (null === $chosenSocleId) {
            return $this->json(['error' => 'Validez le planning de la saison pour comparer cette période avec lui.'], Response::HTTP_CONFLICT);
        }

        $result = $this->calculator->calculate($context['clubId'], $context['seasonId'], $schedule->getSchedulePlanId(), $entry, $chosenSocleId, $schedule->getId());

        return $this->json([
            'socleScheduleId' => $result->socleScheduleId,
            'moved' => $result->moved,
            'unplaced' => $result->unplaced,
        ]);
    }
}
