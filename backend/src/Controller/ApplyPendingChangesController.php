<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Entity\SchedulePlan;
use App\Enum\SchedulePlanType;
use App\Plans\PendingChanges;
use App\Plans\ValidationImpact;
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
 * « Valider ces modifications » (lot 5) : la version en vigueur du planning de saison adopte ce qui attend, sans
 * régénérer ({@see PendingChanges::apply}). Seul guichet qui écrit la version en vigueur, et seulement sa copie du
 * froid, le coach de ses séances, son entrée figée et son empreinte.
 *
 * Exige le jeton de la liste vue (`confirmChanges`, celui de `GET …/pending-impact`) : sans lui, ou sur une liste qui a
 * changé depuis, 409 `changes_to_confirm` avec l'annonce du jour, rien écrit. Refus 409 aussi sur un plan de période,
 * sans version en vigueur, sans rien en attente, pendant une génération dans la saison. Un plan inconnu ou d'un autre
 * club répond le même 404. Le jeton est relu sous le verrou de la saison.
 */
#[AsController]
#[ClubFunction(module: Module::PLANNING, audience: Audience::MANAGER)]
final class ApplyPendingChangesController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ScheduleCapabilityResolver $capabilityResolver,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
        private readonly PendingChanges $pendingChanges,
        private readonly ValidationImpact $validationImpact,
    ) {}

    // SEC-13 — la cible est le plan nommé dans l'URL ; sa saison gouverne le garde « archivée ».
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedulePlan($id) : null;
    }

    #[Route('/api/schedule_plans/{id}/apply-pending-changes', name: 'api_schedule_plan_apply_pending_changes', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        try {
            $plan = $this->entityManager->getRepository(SchedulePlan::class)->find($id);
        } catch (Throwable) {
            $plan = null;
        }
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (!$plan instanceof SchedulePlan || (null !== $currentClubId && $plan->getClubId() !== $currentClubId)) {
            return $this->json(['error' => 'Plan introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (SchedulePlanType::SEASON !== $plan->getType()) {
            return $this->json(['error' => 'Seul le planning de saison valide des modifications sans régénérer.'], Response::HTTP_CONFLICT);
        }
        $clubId = (string) $plan->getClubId();
        $seasonId = $plan->getSeasonId();
        if ($this->capabilityResolver->inFlightInSeason($clubId, $seasonId)) {
            return $this->json(['code' => 'generation_in_progress', 'error' => 'Une génération est en cours — attendez sa fin avant de valider ces modifications.'], Response::HTTP_CONFLICT);
        }
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $token = \is_string($body['confirmChanges'] ?? null) ? $body['confirmChanges'] : null;

        $outcome = $this->entityManager->wrapInTransaction(function () use ($id, $seasonId, $clubId, $token): string|JsonResponse {
            $this->schedulePlanProvisioner->lockPlanScope(SchedulePlanProvisioner::seasonScopeKey($seasonId));
            $inForceId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);
            if (null === $inForceId) {
                return $this->json(['error' => 'Aucun planning de saison n\'est en vigueur : validez d\'abord une version.'], Response::HTTP_CONFLICT);
            }
            $impact = $this->validationImpact->ofPendingChanges($id, $clubId, $seasonId);
            if ($impact['changes']->isEmpty()) {
                return $this->json(['error' => 'Aucune modification n\'attend la validation.'], Response::HTTP_CONFLICT);
            }
            if ($impact['token'] !== $token) {
                return 'changes_to_confirm';
            }
            $this->pendingChanges->apply($id);

            return $inForceId;
        });
        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }
        if ('changes_to_confirm' === $outcome) {
            // L'annonce du jour, contrôle d'incohérences compris, hors du verrou (le moteur peut prendre du temps).
            return $this->json([
                'code' => 'changes_to_confirm',
                'error' => 'Ce qui change a bougé depuis l\'annonce : confirmez la liste du jour.',
                ...$this->validationImpact->pendingBody($id, $clubId, $seasonId),
            ], Response::HTTP_CONFLICT);
        }

        return $this->json(['id' => $outcome], Response::HTTP_OK);
    }
}
