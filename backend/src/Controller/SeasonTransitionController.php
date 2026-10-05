<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use App\Repository\SeasonRepository;
use App\Service\SeasonAlreadyTransitionedException;
use App\Service\SeasonTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Season transition entry point (spec transition-de-saison §2): copies the
 * current season's ENTRIES into a fresh N+1 draft. Management-gated like
 * reset-season — preparing next season is a structural club action.
 */
#[AsController]
#[Route('/api/seasons/{id}/transition', name: 'season_transition', methods: ['POST'])]
final class SeasonTransitionController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly SeasonRepository $seasonRepository,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonTransitionService $transitionService,
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        // RLS already hides foreign seasons; the explicit club check keeps the
        // 404 semantics honest even off-RLS (tests, admin connection).
        $source = $this->seasonRepository->find($id);
        if (!$source instanceof Season || $source->getClubId() !== $clubId) {
            return $this->json(['error' => 'Saison introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $membership = $user instanceof User
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $clubId)
            : null;
        if (!$membership instanceof ClubUser || !$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Cette action est réservée aux gestionnaires.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $target = $this->transitionService->transition($source);
        } catch (SeasonAlreadyTransitionedException $e) {
            // Idempotent-ish: hand back the existing successor so the caller
            // can simply switch to it.
            return $this->json([
                'error' => $e->getMessage(),
                'existingSeasonId' => $e->getExistingSeasonId(),
            ], Response::HTTP_CONFLICT);
        } catch (ConflictHttpException $e) {
            // Préconditions du service (saison non courante, saison suivante non
            // payée — P1-5) : un 409 JSON qui PORTE le message, pas la page
            // d'erreur générique — le frontend l'affiche tel quel.
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'seasonId' => $target->getId(),
            'name' => $target->getName(),
            'startDate' => $target->getStartDate()->format('Y-m-d'),
            'endDate' => $target->getEndDate()->format('Y-m-d'),
            'counts' => $target->getTransitionData()['counts'] ?? [],
        ], Response::HTTP_CREATED);
    }
}
