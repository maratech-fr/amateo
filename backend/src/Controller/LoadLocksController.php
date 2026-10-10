<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Service\ClubGenerationLock;
use App\Service\ManagementAccessGuard;
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
 * V1bis — « Charger » pour un plan de PÉRIODE. Pose le POINTEUR
 * {@see SchedulePlanProvisioner::pointLockSource} : la version nommée devient la SOURCE
 * des verrous de son plan (les données « en cours »), celle dont la prochaine « Régénérer »
 * repart. Il NE génère PAS — il rend la phrase « chargez d'abord ses données avec Charger »
 * VRAIE pour une période, là où « Charger cette version » (RegenerateFromVersionController)
 * reste réservé à la saison (il restaure une structure, concept de plan SEASON).
 *
 * Patron de RegenerateFromVersionController : manager (SEC-07), club tenant (404 byte-identique),
 * puis les refus NOMMÉS — période / terminée / plan non validé / génération en cours. Idempotent :
 * re-pointer sur la version déjà source est un no-op accepté (le front, lui, n'offre pas le geste
 * alors — capability canLoadLocks false).
 */
#[AsController]
final class LoadLocksController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ClubGenerationLock $clubGenerationLock,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/load-locks', name: 'api_schedule_load_locks', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        try {
            $source = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $source = null;
        }

        // Tenant : une version introuvable OU d'un autre club répond le MÊME 404 (le filtre
        // Doctrine + RLS la masquent déjà ; la garde club ci-jointe est la ceinture, et elle
        // rend le MÊME corps — on ne révèle jamais l'existence d'une version d'un autre club).
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$source instanceof Schedule || (null !== $currentClubId && $source->getClubId() !== $currentClubId)) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Plan de PÉRIODE uniquement : « Charger » les verrous est un geste d'overlay. Le socle a son
        // propre « Charger cette version » (restaure une structure), jamais ce pointeur de verrous.
        if ($this->schedulePlanProvisioner->isSeasonSchedule($source)) {
            return $this->json(['error' => 'Charger les verrous ne concerne qu\'un planning de période.'], Response::HTTP_CONFLICT);
        }

        // Une version DRAFT/FAILED/en vol n'a pas de verrous arrêtés à offrir comme source.
        if (ScheduleStatus::COMPLETED !== $source->getStatus()) {
            return $this->json(['error' => 'Seule une version terminée peut devenir la source des verrous.'], Response::HTTP_CONFLICT);
        }

        // Plan validé (une version en vigueur) = calendrier arrêté, lecture seule : rouvrir d'abord,
        // comme RegenerateFromVersionController refuse de recharger sous une version choisie.
        if (null !== $this->schedulePlanProvisioner->chosenScheduleIdOfPlan($source->getSchedulePlanId())) {
            return $this->json(['error' => 'Ce planning de période est validé. Rouvrez-le avant de charger une autre version.'], Response::HTTP_CONFLICT);
        }

        // Une génération en cours réécrirait le pointeur à sa complétion (elle devient la source) :
        // poser un pointeur maintenant serait aussitôt écrasé. On refuse, code machine au front.
        if ($this->clubGenerationLock->isGenerating($source->getClubId())) {
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        }

        // Pose le pointeur (ne génère pas). Idempotent si déjà source.
        $this->schedulePlanProvisioner->pointLockSource($source->getSchedulePlanId(), $source->getId());

        return $this->json(['id' => $source->getId(), 'status' => $source->getStatus()->value], Response::HTTP_OK);
    }
}
