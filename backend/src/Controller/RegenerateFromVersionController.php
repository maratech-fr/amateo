<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Service\ManagementAccessGuard;
use App\Service\ScheduleCapabilityResolver;
use App\Service\SchedulePlanProvisioner;
use App\Service\SocleGuard;
use App\Service\StructureRestorer;
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
 * planning-versions: LOAD a version's context ("Charger cette version"). Restore
 * that version's structure photo (D2) into the club's live structure and mark it
 * as the season's loaded context (★) — WITHOUT solving. The manager then works
 * from that version's plan (already COMPLETED) and hits "Régénérer" to produce a
 * new version if wanted. The current structure is replaced (the client confirms
 * the impact first). Overlays and versions with no photo (pre-D2) are refused.
 */
#[AsController]
final class RegenerateFromVersionController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly StructureRestorer $structureRestorer,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly SocleGuard $socleGuard,
        private readonly ScheduleCapabilityResolver $capabilityResolver,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/regenerate-from', name: 'api_schedule_regenerate_from', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        try {
            $source = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $source = null;
        }

        if (!$source instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $source->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Season plans only — an overlay carries no restorable club structure.
        // ADR-0002 C4 : « socle ? » = plan.type === SEASON, plus calendarEntryId.
        if (!$this->schedulePlanProvisioner->isSeasonSchedule($source)) {
            return $this->json(['error' => 'Seule une version de saison peut servir de base à une régénération.'], Response::HTTP_CONFLICT);
        }

        // A DRAFT/FAILED/in-flight version has no meaningful structure to restore.
        if (ScheduleStatus::COMPLETED !== $source->getStatus()) {
            return $this->json(['error' => 'Seule une version terminée peut être régénérée.'], Response::HTTP_CONFLICT);
        }
        // ADR-0002 inv. 1 — la version CHOISIE est le planning en vigueur, et le
        // restore écrase la structure du club : on rouvre avant d'y toucher. Le
        // statut VALIDATED portait cette garde ; seul le pointeur la porte désormais.
        if ($this->schedulePlanProvisioner->isChosen($source->getId())) {
            return $this->json(['error' => 'La version choisie est le planning en vigueur. Rouvrez-le avant de charger une autre version.'], Response::HTTP_CONFLICT);
        }

        // The restore wipes the club structure — refuse while ANY version of the
        // season is still solving, or a concurrent import would land slots
        // referencing teams/venues the wipe just deleted (the ClubGenerationLock
        // only serialises solves, it does not guard this destructive write).
        // Délégué à ScheduleCapabilityResolver (P2-8) : le bloc `capabilities` lit le
        // MÊME prédicat — canRegenerateFrom false ⇔ ce 409.
        if ($this->capabilityResolver->inFlightInSeason($source->getClubId(), $source->getSeasonId())) {
            return $this->json(['error' => 'Une génération est en cours — attendez sa fin avant de charger une autre version.'], Response::HTTP_CONFLICT);
        }

        // Read the photo (409 if none) BEFORE any destructive change.
        $data = $this->structureRestorer->readSnapshot($source);
        $clubId = $source->getClubId();
        $seasonId = $source->getSeasonId();
        $sourceId = $source->getId();
        $sourceStatus = $source->getStatus();

        // ATOMIC: the destructive restore + re-pointing the loaded context (★)
        // commit together. No solve is launched — the source version is already
        // COMPLETED, so its plan is shown as-is; "Régénérer" produces a new
        // version later if wanted.
        $this->entityManager->wrapInTransaction(function () use ($clubId, $seasonId, $sourceId, $data): void {
            // P2-9bis (planning lifecycle) : défense en profondeur du socle en vigueur.
            // SOUS le verrou de plan-scope de la saison, AVANT le restore destructif : tant
            // que le plan SEASON pointe une version choisie, on refuse d'en charger une autre
            // (rouvrir d'abord). Une garde qui lit hors verrou n'est qu'un TOCTOU.
            $this->schedulePlanProvisioner->lockPlanScope(SchedulePlanProvisioner::seasonScopeKey($seasonId));
            $this->socleGuard->assertSeasonPlanNotChosen($seasonId);
            $this->structureRestorer->apply($clubId, $seasonId, $data);
            // apply() clears the identity map — reload the season AFTER it to
            // re-point the ★ (a pre-loaded instance would be detached).
            $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
            if ($season instanceof Season) {
                $season->setLiveContextScheduleId($sourceId);
                $this->entityManager->flush();
            }
        });

        return $this->json(['id' => $sourceId, 'status' => $sourceStatus->value], Response::HTTP_OK);
    }
}
