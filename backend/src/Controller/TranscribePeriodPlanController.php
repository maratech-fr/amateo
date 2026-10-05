<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\SchedulePlanType;
use App\Service\ManagementAccessGuard;
use App\Service\PeriodPlanTranscriber;
use App\Service\SchedulePlanProvisioner;
use App\Service\SocleGuard;
use App\Service\WriteTargetSeasonResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « L'adaptation naît comme une COPIE du socle » — le geste EXPLICITE « transcrire depuis le socle »
 * sur un plan de PÉRIODE sans aucune version : sa V1 transcrit la version pointée du socle, filtrée
 * des réglages de la période (cf. {@see PeriodPlanTranscriber}). Le plan reste NON validé (le
 * gestionnaire valide ensuite, comme aujourd'hui).
 *
 * Sous les gardes standard : management (SEC-07), tenant (le plan doit appartenir au club courant),
 * saison non archivée (`SeasonScopedWriteInterface` → `SeasonReadonlyGuardListener`), et le socle
 * doit être en vigueur (`SocleGuard::assertSeasonPlanChosen` — on en copie la version choisie). La
 * réponse porte la liste « à replacer » : le front ne redérive rien.
 */
#[AsController]
final class TranscribePeriodPlanController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly SocleGuard $socleGuard,
        private readonly PeriodPlanTranscriber $transcriber,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    // SEC-13 — la cible est le SchedulePlan (de période) nommé dans l'URL.
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedulePlan($id) : null;
    }

    #[Route('/api/schedule_plans/{id}/transcribe-from-socle', name: 'api_schedule_plan_transcribe_from_socle', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        // Contexte du plan en SQL brut (filter-free) : RLS scope le club, un plan d'un autre club
        // rend null. Le contrôleur re-vérifie le club en défense (tenant).
        $context = $this->schedulePlanProvisioner->fetchPlanContext($id);
        if (null === $context) {
            return $this->json(['error' => 'Plan introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $context['clubId'] !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        if (SchedulePlanType::SEASON === $context['type'] || null === $context['calendarEntryId']) {
            return $this->json(['error' => 'La transcription depuis le socle ne s\'applique qu\'à un plan de période.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Bâtir SUR le socle exige qu'il soit pointé (409 nommé sinon) — on en copie la version choisie.
        $this->socleGuard->assertSeasonPlanChosen($context['seasonId']);

        // La garde « plan vierge » + la copie sont ATOMIQUES sous le verrou de portée du plan (le
        // service relit l'absence de version sous le verrou). Les refus nommés (409/422) remontent
        // en HttpException depuis le service.
        $result = $this->transcriber->transcribe($id);

        return $this->json([
            'id' => $result->scheduleId,
            'versionNumber' => $result->versionNumber,
            'copiedCount' => $result->copiedCount,
            'toReplace' => $result->toReplace,
        ], Response::HTTP_CREATED);
    }
}
