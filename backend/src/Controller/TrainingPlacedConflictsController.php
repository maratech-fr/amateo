<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\Season;
use App\Service\PlacedSessionPersonConflictDetector;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * P4-269 — le radar « une personne à deux endroits en même temps » sur le planning
 * d'entraînement EN VIGUEUR (version pointée du plan SEASON). Recalculé à chaque appel
 * depuis les séances placées + les liens COURANTS (coach MAIN/ASSISTANT, joueurs) —
 * rien n'est persisté. Feed d'affichage en lecture seule, consommé par l'étape Coachs
 * du wizard, le bandeau de `/planning` et la pastille du plan de saison du cockpit.
 *
 * `scheduleId` (optionnel) : la version AFFICHÉE, sur laquelle se calculent les `absences` (séances
 * sans coach disponible à leur début). `conflicts` et `relays` restent ceux de la version en vigueur.
 *
 * Tenant : tout est chargé via les repositories mappés, donc les filtres Doctrine
 * club+saison s'appliquent (+ RLS) — un club ne voit jamais que ses propres conflits
 * (gardé par TrainingPlacedConflictsApiTest). Lecture pure, ouverte à tout membre actif du club :
 * un Membre consulte le radar (MemberReadsAreSideEffectFreeTest).
 */
final class TrainingPlacedConflictsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly PlacedSessionPersonConflictDetector $detector,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/api/training/placed-conflicts', name: 'api_training_placed_conflicts', methods: ['GET'])]
    public function conflicts(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        $displayed = null;
        $scheduleId = $this->requestStack->getCurrentRequest()?->query->get('scheduleId');
        if (null !== $scheduleId) {
            // Autre club, inconnue ou mal formée : la même réponse, aucun oracle sur l'existence.
            $displayed = Uuid::isValid($scheduleId) ? $this->entityManager->getRepository(Schedule::class)->find($scheduleId) : null;
            if (!$displayed instanceof Schedule || $displayed->getClubId() !== $clubId) {
                return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
            }
        }

        $result = $this->detector->detect($clubId, $season?->getId(), $displayed);

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season instanceof Season ? $season->getId() : null,
            // false = aucune version pointée : PAS de planning en vigueur à scanner, donc
            // `conflicts: []` ne signifie pas « tout va bien ». Même prudence que le radar
            // des matchs (`seasonPlanChosen`).
            'seasonPlanChosen' => $result['seasonPlanChosen'],
            'conflicts' => $result['conflicts'],
            // Chantier H — les RELAIS (paire tolérée + adjoint disponible) : hors du compte de
            // conflits, rendus en avertissement par le front (le coach arrive en retard, l'adjoint ouvre).
            'relays' => $result['relays'],
            'absences' => $result['absences'],
        ]);
    }
}
