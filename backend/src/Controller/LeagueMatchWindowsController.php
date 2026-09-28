<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubLeagueWindow;
use App\Entity\LeagueWindowInterface;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Repository\ClubRepository;
use App\Repository\LeagueMatchWindowRepository;
use App\Service\LeagueEnvelopeResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Match-window catalog inherited by the club (spec gestion-matchs §6bis): the
 * federation-imposed HARD envelope of allowed kickoff windows for the club's
 * derived league, falling back to the federation default (AURA) when that
 * league is not catalogued yet. GLOBAL reference — read-only, display feed for
 * the placement grid / conflict radar.
 *
 * P1-4 PR E2 (dette iv): the response also carries `resolvedTeamWindows` —
 * per-team window ids resolved by the SAME LeagueEnvelopeResolver the solver
 * and the graded diagnostic use. The join exists in ONE place; the frontend
 * looks it up instead of re-deriving it (absent team = unmapped = advisory).
 */
final class LeagueMatchWindowsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly LeagueMatchWindowRepository $windowRepository,
        private readonly ClubRepository $clubRepository,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly LeagueEnvelopeResolver $envelopeResolver,
    ) {}

    #[Route('/api/league-match-windows', name: 'api_league_match_windows', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $league = $this->clubRepository->find($clubId)?->getLeague();
        $effectiveLeague = $this->windowRepository->effectiveLeague($league);

        // P4-272 ① — les fenêtres servies sont la COPIE club (scopée club+saison par
        // les filtres Doctrine), plus le catalogue global : le placement, le radar
        // et cet écran lisent la MÊME maison. Copie vide = liste vide (le placement
        // n'applique alors aucune règle fédérale).
        /** @var list<ClubLeagueWindow> $windows */
        $windows = $this->entityManager->getRepository(ClubLeagueWindow::class)->findBy(
            [],
            ['category' => 'ASC', 'level' => 'ASC', 'dayOfWeek' => 'ASC', 'kickoffMin' => 'ASC'],
        );

        $items = array_map(
            static fn (ClubLeagueWindow $w): array => [
                'id' => $w->getId(),
                'league' => $w->getLeague(),
                'category' => $w->getCategory(),
                'level' => $w->getLevel(),
                'gender' => $w->getGender(),
                'dayOfWeek' => $w->getDayOfWeek(),
                'kickoffMin' => $w->getKickoffMin()->format('H:i'),
                'kickoffMax' => $w->getKickoffMax()->format('H:i'),
            ],
            $windows,
        );

        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy([]);
        /** @var list<SportCategory> $categories */
        $categories = $this->entityManager->getRepository(SportCategory::class)->findBy([]);
        $resolvedTeamWindows = array_map(
            static fn (array $teamWindows): array => array_map(static fn (LeagueWindowInterface $w): string => $w->getId(), $teamWindows),
            $this->envelopeResolver->resolve($teams, $categories, $windows),
        );

        return $this->json(['league' => $effectiveLeague, 'items' => $items, 'resolvedTeamWindows' => $resolvedTeamWindows]);
    }
}
