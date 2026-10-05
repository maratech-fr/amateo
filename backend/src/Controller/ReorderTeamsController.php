<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Team;
use App\Service\ManagementAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Bulk team reorder — set (priorityTierId, tierOrder) for many teams in a single
 * transaction. Replaces the N-concurrent-PUT approach of the sort UI, which raced
 * on Team's optimistic-lock version and dropped updates. See TeamsStep sort mode.
 */
final class ReorderTeamsController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    /**
     * SEC-13 — écriture de COLLECTION (réordonner des équipes en masse) : aucune
     * ressource unique dont dériver la saison. Le header/saison courante gouverne,
     * comme avant. Choix écrit et revu, exigé par SeasonScopedWriteInterface.
     */
    public function writeTargetSeasonId(Request $request): ?string
    {
        return null;
    }

    #[Route('/api/teams/reorder', name: 'api_teams_reorder', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $data = json_decode($request->getContent(), true);
        $items = \is_array($data) ? ($data['items'] ?? $data) : null;
        if (!\is_array($items)) {
            return $this->json(['error' => 'Expected a list of { id, priorityTierId, tierOrder }.'], Response::HTTP_BAD_REQUEST);
        }

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        $repository = $this->entityManager->getRepository(Team::class);
        $updated = 0;

        foreach ($items as $item) {
            if (!\is_array($item) || !isset($item['id'], $item['priorityTierId'], $item['tierOrder'])) {
                return $this->json(['error' => 'Each item needs id, priorityTierId, tierOrder.'], Response::HTTP_BAD_REQUEST);
            }

            try {
                $team = $repository->find((string) $item['id']);
            } catch (Throwable) {
                $team = null;
            }
            if (!$team instanceof Team) {
                continue;
            }
            if (null !== $currentClubId && $team->getClubId() !== $currentClubId) {
                return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
            }

            $team->setPriorityTierId((int) $item['priorityTierId']);
            $team->setTierOrder((int) $item['tierOrder']);
            ++$updated;
        }

        $this->entityManager->flush();

        return $this->json(['updated' => $updated], Response::HTTP_OK);
    }
}
