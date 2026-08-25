<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Season;
use App\Entity\User;
use App\Service\EntryDeadlineOutlook;
use App\Service\SeasonResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The entry-deadline cockpit outlook (RMM-6) — read-only, open to any member
 * (patron MatchModuleVisitController : le Membre ouvre le module aussi). Serves
 * the J-7 windows and, when one is open, the current user's guardian delta,
 * WITHOUT stamping the visit. All the semantics live in {@see EntryDeadlineOutlook};
 * this controller only resolves the club/season/user context.
 */
#[AsController]
final class EntryDeadlineOutlookController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly EntryDeadlineOutlook $outlook,
    ) {}

    #[Route('/api/matches/deadline-outlook', name: 'api_matches_deadline_outlook', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        $seasonId = $season instanceof Season ? $season->getId() : null;

        return $this->json($this->outlook->compute($clubId, $seasonId, $user->getId()));
    }
}
