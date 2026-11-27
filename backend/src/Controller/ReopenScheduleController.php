<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Entity\Schedule;
use App\Service\SchedulePlanProvisioner;
use App\Service\WriteTargetSeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Reopen the version a PERIOD plan points at → the plan un-points it and becomes an
 * "espace de travail" again, editable (ADR-0002 inv. 2). The inverse of
 * ValidateScheduleController. The season plan no longer reopens (lot 5): it is reworked
 * (ReworkScheduleController) and its version in force stays in force — 409 here.
 * See planning-lifecycle-validated.md.
 */
#[ClubFunction(module: Module::PLANNING, audience: Audience::MANAGER)]
final class ReopenScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public const string SEASON_PLAN_MESSAGE = 'Le planning de saison ne se rouvre plus : retravaillez-le.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/reopen', name: 'api_schedule_reopen', methods: ['POST'])]
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

        // Le planning de saison ne se rouvre plus (lot 5) : on le retravaille, la version en vigueur le reste.
        if ($this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
            return $this->json(['error' => self::SEASON_PLAN_MESSAGE], Response::HTTP_CONFLICT);
        }

        // Tout ce qui décide, puis le dépointage, sous le verrou de portée du plan et
        // DANS la transaction — même raison qu'à la validation, qui sérialise sur ce
        // verrou ET supprime des versions dessous. Sans lui, un reopen lisait « je suis
        // la version choisie » sur une photo qu'une validation concurrente invalidait
        // aussitôt, puis annonçait 200 alors que son UPDATE ne touchait plus rien : le
        // gestionnaire croyait son planning rouvert et se faisait refuser chaque édition.
        return $this->entityManager->wrapInTransaction(function () use ($schedule): JsonResponse {
            $this->schedulePlanProvisioner->lockPlanScope($this->schedulePlanProvisioner->planScopeOf($schedule));

            // ADR-0002 inv. 1/2 : « validé » = le plan pointe cette version. Rouvrir =
            // dépointer. Une version que le plan ne pointe pas n'a rien à rouvrir.
            if (!$this->schedulePlanProvisioner->isChosen($schedule->getId())) {
                return $this->json(['error' => 'Seule la version choisie d\'un planning peut être rouverte.'], Response::HTTP_CONFLICT);
            }

            // inv. 2 : rouvrir dépointe — le plan redevient un espace de travail.
            if (!$this->schedulePlanProvisioner->releaseSchedule($schedule->getId())) {
                // Aucun plan ne la pointait plus : ne pas annoncer une réouverture qui
                // n'a pas eu lieu (le 200 mensonger laissait le gestionnaire éditer un
                // planning que le serveur refusait à chaque geste).
                return $this->json(['error' => 'Ce planning n\'est plus la version en vigueur — rechargez la page.'], Response::HTTP_CONFLICT);
            }

            return $this->json(['id' => $schedule->getId(), 'chosen' => false], Response::HTTP_OK);
        });
    }
}
