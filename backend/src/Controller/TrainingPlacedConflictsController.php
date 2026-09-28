<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Season;
use App\Service\ManagementAccessGuard;
use App\Service\PlacedSessionPersonConflictDetector;
use App\Service\SeasonResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P4-269 — le radar « une personne à deux endroits en même temps » sur le planning
 * d'entraînement EN VIGUEUR (version pointée du plan SEASON). Recalculé à chaque appel
 * depuis les séances placées + les liens COURANTS (coach MAIN/ASSISTANT, joueurs) —
 * rien n'est persisté. Feed d'affichage en lecture seule, consommé par l'étape Coachs
 * du wizard, le bandeau de `/planning` et la pastille du plan de saison du cockpit.
 *
 * Tenant : tout est chargé via les repositories mappés, donc les filtres Doctrine
 * club+saison s'appliquent (+ RLS) — un club ne voit jamais que ses propres conflits
 * (gardé par TrainingPlacedConflictsApiTest). Réservé aux gestionnaires (SEC-07).
 */
final class TrainingPlacedConflictsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly PlacedSessionPersonConflictDetector $detector,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Route('/api/training/placed-conflicts', name: 'api_training_placed_conflicts', methods: ['GET'])]
    public function conflicts(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 first, so 403 wins.

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        $result = $this->detector->detect($clubId, $season?->getId());

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season instanceof Season ? $season->getId() : null,
            // false = aucune version pointée : PAS de planning en vigueur à scanner, donc
            // `conflicts: []` ne signifie pas « tout va bien ». Même prudence que le radar
            // des matchs (`seasonPlanChosen`).
            'seasonPlanChosen' => $result['seasonPlanChosen'],
            'conflicts' => $result['conflicts'],
        ]);
    }
}
